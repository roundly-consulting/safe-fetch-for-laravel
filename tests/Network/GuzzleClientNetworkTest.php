<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;
use RoundlyConsulting\SafeFetch\Tests\Support\LocalHttpServer;
use RoundlyConsulting\SafeFetch\Tests\Support\Network;

/*
 * A library's own Guzzle client on the guarded stack, over real curl to `php -S` on loopback. The
 * names end in `.invalid`, which no DNS knows: a request lands only because the guard pinned it.
 */

function guzzlePolicy(): DestinationPolicy
{
    return SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32', '::1/128']);
}

/** A resolver that answers `$first` once, then `$later` — DNS rebinding between check and connect. */
function rebindingResolver(string $first, string $later): HostResolver
{
    return new class($first, $later) implements HostResolver
    {
        public int $lookups = 0;

        public function __construct(private readonly string $first, private readonly string $later) {}

        public function resolve(string $host): array
        {
            return [$this->lookups++ === 0 ? $this->first : $this->later];
        }
    };
}

beforeEach(function (): void {
    Network::ensure();
    SafeFetch::fakeDns(['pin.invalid' => '127.0.0.1']);
    $this->server = LocalHttpServer::start();
});

afterEach(function (): void {
    $this->server?->stop();
});

it('lands only through the pin, from a client the library builds or one it is given', function (): void {
    $connected = [];
    $stats = static function (TransferStats $transfer) use (&$connected): void {
        $connected[] = $transfer->getHandlerStat('primary_ip');
    };
    $url = $this->server->url('pin.invalid', '/hello.txt');

    $built = (new Client(['handler' => SafeFetch::handlerStack(guzzlePolicy()), 'on_stats' => $stats]))->get($url);
    $given = SafeFetch::guzzleClient(['on_stats' => $stats], guzzlePolicy())->get($url);

    expect((string) $built->getBody())->toBe('hello from the pinned server')
        ->and((string) $given->getBody())->toBe('hello from the pinned server')
        ->and($connected)->toBe(['127.0.0.1', '127.0.0.1'])
        ->and($this->server->requests())->toHaveCount(2);
});

it('dials the address it vetted when dns rebinds between check and connect', function (): void {
    // Vetted as loopback (allowed here); every later lookup answers the cloud metadata address.
    $resolver = rebindingResolver('127.0.0.1', '169.254.169.254');
    app()->instance(HostResolver::class, $resolver);
    $client = SafeFetch::guzzleClient(policy: guzzlePolicy());
    $url = $this->server->url('rebind.invalid', '/hello.txt');

    $first = $client->get($url);
    $second = Helpers::refusal(fn () => $client->get($url));

    // One lookup per request, by the guard: curl never resolved the name itself, so the check and
    // the connection saw the same answer — and the rebound answer is refused, not dialled.
    expect((string) $first->getBody())->toBe('hello from the pinned server')
        ->and($second->reason())->toBe(Reason::Blocked)
        ->and($resolver->lookups)->toBe(2)
        ->and($this->server->requests())->toHaveCount(1);
});

it('refuses rather than mixes concurrent requests whose vetted sets differ for one host and port', function (): void {
    app()->instance(HostResolver::class, rebindingResolver('127.0.0.1', '127.0.0.2'));
    // 127.0.0.2 is not routed on every host (macOS): a short connect timeout keeps the test fast.
    $client = SafeFetch::guzzleClient(['connect_timeout' => 2], guzzlePolicy()->allowRanges(['127.0.0.0/8']));
    $url = $this->server->url('rebind.invalid', '/hello.txt');
    $connected = [];
    $stats = static function (TransferStats $transfer) use (&$connected): void {
        $connected[] = $transfer->getHandlerStat('primary_ip');
    };

    $results = PromiseUtils::settle([
        $client->getAsync($url, ['on_stats' => $stats]),
        $client->getAsync($url, ['on_stats' => $stats]),
    ])->wait();

    // The first was vetted for 127.0.0.1 only, the second for 127.0.0.2 only (where nothing
    // listens): neither may come back with an answer from the other's address.
    expect($results[1]['state'])->toBe('rejected')
        ->and($results[0]['state'] === 'fulfilled'
            ? in_array('127.0.0.1', $connected, true)
            : $results[0]['reason'] instanceof DestinationRejected && $results[0]['reason']->reason() === Reason::CannotPin)->toBeTrue();
});

it('refuses a request middleware the library pushed onto the stack re pointed after vetting', function (): void {
    $stack = SafeFetch::handlerStack(guzzlePolicy());
    $elsewhere = "http://127.0.0.1:{$this->server->port}/hello.txt";
    $stack->push(Middleware::mapRequest(static fn (RequestInterface $request): RequestInterface => $request->withUri(new Uri($elsewhere))));

    $refusal = Helpers::refusal(fn () => (new Client(['handler' => $stack]))->get($this->server->url('pin.invalid', '/hello.txt')));

    expect($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and($refusal->reason())->toBe(Reason::CannotPin)
        ->and($this->server->requests())->toBe([]);
});

it('follows a real redirect under the policy and stops one to a refused address', function (): void {
    $client = SafeFetch::guzzleClient(policy: guzzlePolicy()->followRedirects());
    $target = "http://127.0.0.2:{$this->server->port}/hello.txt";

    $followed = $client->post($this->server->url('pin.invalid', '/redirect?to=/echo'), ['body' => 'payload']);
    $refusal = Helpers::refusal(fn () => $client->get($this->server->url('pin.invalid', '/redirect?to='.urlencode($target))));

    expect((string) $followed->getBody())->toBe('POST body=payload')
        ->and($refusal->reason())->toBe(Reason::Blocked)
        ->and($this->server->requests())->toHaveCount(3);
});

it('applies the configured time limit to a client without a timeout of its own', function (Closure $send, string $exception): void {
    config()->set('safe-fetch.request.timeout', 1);
    $started = microtime(true);

    $thrown = Helpers::thrown(fn () => $send($this->server->url('pin.invalid', '/endless')));

    expect($thrown)->toBeInstanceOf($exception)
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
})->with([
    'a guzzle client' => [fn (string $url) => SafeFetch::guzzleClient(policy: SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']))->get($url), TransferException::class],
    'Http::safe()' => [fn (string $url) => Http::safe(SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']))->get($url), ConnectionException::class],
]);

it('caps a real body by configuration', function (): void {
    config()->set('safe-fetch.request.max_bytes', 4096);

    $response = SafeFetch::guzzleClient(['timeout' => 20], SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']))
        ->get($this->server->url('pin.invalid', '/endless'));

    expect(strlen((string) $response->getBody()))->toBe(4096)
        ->and(SafeFetch::truncated($response))->toBeTrue();
});
