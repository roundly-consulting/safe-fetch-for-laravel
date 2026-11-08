<?php

declare(strict_types=1);

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;
use RoundlyConsulting\SafeFetch\Tests\Support\LocalHttpServer;
use RoundlyConsulting\SafeFetch\Tests\Support\Network;
use RoundlyConsulting\SafeFetch\Tests\Support\RawHttpServer;

/*
 * Real requests: Laravel's real HTTP client, Guzzle's real curl handler, real curl, `php -S` on
 * loopback. The names end in `.invalid`, which no DNS knows, so a request lands only because the
 * guard pinned it. Every CI job sets SAFE_FETCH_REQUIRE_NETWORK_TESTS=1: a skip is a failure there.
 */

function networkPolicy(): DestinationPolicy
{
    return SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32', '::1/128']);
}

beforeEach(function (): void {
    Network::ensure();
    SafeFetch::fakeDns(['pin.invalid' => '127.0.0.1', 'other.invalid' => '127.0.0.1']);
    $this->server = LocalHttpServer::start();
});

afterEach(function (): void {
    $this->server?->stop();
});

it('the pinning options pass guzzles curl factory', function (): void {
    $deprecations = [];
    // Guzzle 7 reports its deprecations @-suppressed (trigger_deprecation()), which PHPUnit ignores:
    // collect every one ourselves, suppressed or not.
    set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
        if ($level === E_USER_DEPRECATED || $level === E_DEPRECATED) {
            $deprecations[] = $message;
        }

        return true;
    });

    try {
        $response = Http::safe(networkPolicy())->get($this->server->url('pin.invalid', '/hello.txt'));
        $redirected = Http::safe(networkPolicy()->followRedirects())->post($this->server->url('pin.invalid', '/redirect?to=/echo'), ['a' => 1]);
    } finally {
        restore_error_handler();
    }

    expect($response->body())->toBe('hello from the pinned server')
        ->and($response->handlerStats()['primary_ip'] ?? null)->toBe('127.0.0.1')
        ->and($redirected->body())->toBe('POST body={"a":1}')
        ->and($deprecations)->toBe([]);
});

it('a name no dns knows lands only through the pin', function (): void {
    $response = Http::safe(networkPolicy())->get($this->server->url('pin.invalid', '/hello.txt'));

    expect($response->status())->toBe(200)
        ->and($this->server->requests())->toBe(['GET /hello.txt body= host=pin.invalid:'.$this->server->port.' auth=']);
});

it('pins an ipv6 address', function (): void {
    Network::ensure(ipv6: true);
    $server = LocalHttpServer::start('::1');
    SafeFetch::fakeDns(['pin6.invalid' => '::1']);

    try {
        $response = Http::safe(networkPolicy())->get($server->url('pin6.invalid', '/hello.txt'));

        expect($response->body())->toBe('hello from the pinned server')
            ->and($response->handlerStats()['primary_ip'] ?? null)->toBe('::1')
            ->and(SafeFetch::vet($server->url('pin6.invalid'), networkPolicy())->resolveEntry())->toBe("pin6.invalid:{$server->port}:[::1]");
    } finally {
        $server->stop();
    }
});

it('ignores every environment proxy', function (): void {
    $proxy = LocalHttpServer::start(router: 'proxy.php');
    $names = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy'];

    foreach ($names as $name) {
        putenv("{$name}=http://127.0.0.1:{$proxy->port}");
        $_SERVER[$name] = $_ENV[$name] = "http://127.0.0.1:{$proxy->port}";
    }

    try {
        $guarded = Http::safe(networkPolicy())->get($this->server->url('pin.invalid', '/hello.txt'));
        // The control: the same request without the guard goes through the proxy.
        $plain = Http::get("http://127.0.0.1:{$this->server->port}/hello.txt");
    } finally {
        foreach ($names as $name) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }

        $requests = $proxy->requests();
        $proxy->stop();
    }

    expect($guarded->body())->toBe('hello from the pinned server')
        ->and($plain->body())->toBe('via-proxy')
        ->and($requests)->toHaveCount(1)
        ->and($requests[0])->toContain('PROXIED http://127.0.0.1:');
});

it('stops a real redirect to a refused address', function (): void {
    $target = "http://127.0.0.2:{$this->server->port}/hello.txt";

    $refusal = Helpers::refusal(fn () => Http::safe(networkPolicy()->followRedirects())
        ->get($this->server->url('pin.invalid', '/redirect?to='.urlencode($target))));

    expect($refusal->reason())->toBe(Reason::Blocked)
        ->and($this->server->requests())->toHaveCount(1)
        ->and($this->server->requests()[0])->toStartWith('GET /redirect');
});

it('follows a real 307 with its body', function (): void {
    $response = Http::safe(networkPolicy()->followRedirects())
        ->withBody('payload', 'text/plain')
        ->post($this->server->url('pin.invalid', '/redirect?to=/echo'));

    expect($response->body())->toBe('POST body=payload')
        ->and($this->server->requests())->toHaveCount(2)
        ->and($this->server->requests()[1])->toStartWith('POST /echo body=payload');
});

it('streams through the pinned curl handler', function (): void {
    $response = Http::safe(networkPolicy())->withOptions(['stream' => true])->get($this->server->url('pin.invalid', '/big'));
    $body = $response->toPsrResponse()->getBody();

    // curl handlers ignore `stream`: the whole body is downloaded before the caller sees it — why
    // maxResponseBytes exists.
    expect($response->status())->toBe(200)
        ->and($response->handlerStats()['primary_ip'] ?? null)->toBe('127.0.0.1')
        ->and($body->getSize())->toBe(2 * 1048576);
});

it('fetch caps a real body', function (): void {
    $started = microtime(true);
    $refusal = Helpers::refusal(fn () => SafeFetch::fetch($this->server->url('pin.invalid', '/endless'), maxBytes: 100_000, timeout: 20, policy: networkPolicy()));

    expect($refusal)->toBeInstanceOf(FetchFailed::class)
        ->and($refusal->reason())->toBe(Reason::TooLarge)
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('fetch stops at a declared length over the cap before reading the body', function (): void {
    $refusal = Helpers::refusal(fn () => SafeFetch::fetch($this->server->url('pin.invalid', '/hello.txt'), maxBytes: 5, policy: networkPolicy()));

    expect($refusal->reason())->toBe(Reason::TooLarge);
});

it('stops a compressed bomb', function (): void {
    $refusal = Helpers::refusal(fn () => SafeFetch::fetch($this->server->url('pin.invalid', '/gzip-bomb'), maxBytes: 1_048_576, policy: networkPolicy()));

    expect($refusal->reason())->toBe(Reason::TooLarge);
});

it('fetch classifies a real failed status without reading its body', function (): void {
    $refusal = Helpers::refusal(fn () => SafeFetch::fetch($this->server->url('pin.invalid', '/unavailable'), policy: networkPolicy()));

    expect($refusal)->toBeInstanceOf(FetchFailed::class)
        ->and($refusal instanceof FetchFailed ? $refusal->status() : null)->toBe(503)
        ->and($refusal instanceof FetchFailed ? $refusal->retryAfter() : null)->toBe(7)
        ->and($refusal->retryable())->toBeTrue();
});

it('fetch follows real redirects of every status for a get', function (int $status): void {
    $page = SafeFetch::fetch($this->server->url('pin.invalid', "/redirect?status={$status}&to=/hello.txt"), followRedirects: true, policy: networkPolicy());

    expect($page->body)->toBe('hello from the pinned server')
        ->and($page->url)->toBe($this->server->url('pin.invalid', '/hello.txt'))
        ->and($page->contentType)->toBe('text/plain;charset=UTF-8');
})->with([301, 302, 303, 307, 308]);

it('fetch passes over informational answers', function (): void {
    $raw = RawHttpServer::start("HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nfinal");

    try {
        $page = SafeFetch::fetch("http://pin.invalid:{$raw->port}/", policy: networkPolicy());
    } finally {
        $raw->stop();
    }

    expect($page->status)->toBe(200)->and($page->body)->toBe('final');
});

it('fetch calls an unreadable answer a protocol failure', function (): void {
    $raw = RawHttpServer::start("HTTP/1.1 999 Nope\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

    try {
        $refusal = Helpers::refusal(fn () => SafeFetch::fetch("http://pin.invalid:{$raw->port}/", policy: networkPolicy()));
    } finally {
        $raw->stop();
    }

    expect($refusal->reason())->toBe(Reason::Protocol)
        ->and($refusal->retryable())->toBeFalse();
});

it('fetch calls a refused connection a retryable connection failure', function (): void {
    $port = $this->server->port;
    $this->server->stop();
    $this->server = null;

    $refusal = Helpers::refusal(fn () => SafeFetch::fetch("http://pin.invalid:{$port}/", policy: networkPolicy()));

    expect($refusal->reason())->toBe(Reason::Connection)
        ->and($refusal->retryable())->toBeTrue();
});

it('nothing reaches an address the guard did not vet', function (): void {
    Network::ensure(ipv6: true);
    $v6 = LocalHttpServer::start('::1');
    SafeFetch::fakeDns(['pin6.invalid' => '::1']);
    $elsewhere = "http://127.0.0.1:{$this->server->port}/hello.txt";

    try {
        // Middleware added after safe() re-points the vetted request: the pinned handler refuses it.
        $byHandler = Helpers::refusal(fn () => Http::safe(networkPolicy())
            ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withUri(new Uri($elsewhere)))
            ->get($v6->url('pin6.invalid', '/hello.txt')));

        // The handler swapped out after safe() (a documented non-guarantee): the pre-send check alone
        // still aborts after connecting, before a byte is sent.
        $byPreSend = CurlSupport::preSendCheck()
            ? Helpers::refusal(fn () => Http::safe(networkPolicy())
                ->setHandler(new CurlHandler)
                ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withUri(new Uri($elsewhere)))
                ->get($v6->url('pin6.invalid', '/hello.txt')))
            : null;
    } finally {
        $v6->stop();
    }

    expect($byHandler)->toBeInstanceOf(DestinationRejected::class)
        ->and($byHandler->reason())->toBe(Reason::CannotPin)
        ->and($byPreSend?->reason())->toBe(CurlSupport::preSendCheck() ? Reason::CannotPin : null)
        ->and($this->server->requests())->toBe([]);
});

it('pools guarded requests', function (): void {
    // One server per member, so the servers cannot serialise what the client sends concurrently.
    $servers = [LocalHttpServer::start(), LocalHttpServer::start(), LocalHttpServer::start()];

    try {
        $started = microtime(true);
        $responses = Http::pool(static fn (Pool $pool): array => [
            $pool->safe(networkPolicy())->get($servers[0]->url('pin.invalid', '/slow')),
            $pool->safe(networkPolicy())->get($servers[1]->url('pin.invalid', '/slow')),
            $pool->safe(networkPolicy())->get($servers[2]->url('other.invalid', '/slow')),
        ]);
        $elapsed = microtime(true) - $started;
    } finally {
        foreach ($servers as $server) {
            $server->stop();
        }
    }

    ksort($responses);

    expect(array_map(static fn (mixed $response): mixed => $response instanceof Response ? $response->body() : $response::class, $responses))
        ->toBe(['slow', 'slow', 'slow'])
        // Each answer takes a second: one after another would be three.
        ->and($elapsed)->toBeLessThan(2.0);
});

it('refuses rather than mixes pooled members whose vetted sets differ for one host and port', function (): void {
    // A rebinding resolver: the same name answers differently on each lookup.
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        private int $lookups = 0;

        public function resolve(string $host): array
        {
            return [$this->lookups++ === 0 ? '127.0.0.1' : '127.0.0.2'];
        }
    });
    $policy = networkPolicy()->allowRanges(['127.0.0.0/8']);
    $url = $this->server->url('rebind.invalid', '/hello.txt');

    $responses = Http::pool(static fn (Pool $pool): array => [
        $pool->safe($policy)->get($url),
        $pool->safe($policy)->get($url),
    ]);

    $first = $responses[0];
    $second = $responses[1];

    // The first member was vetted for 127.0.0.1 only, the second for 127.0.0.2 only: neither may
    // come back with an answer from the other's address.
    expect($second)->not->toBeInstanceOf(Response::class)
        ->and($first instanceof Response
            ? ($first->handlerStats()['primary_ip'] ?? null) === '127.0.0.1'
            : $first instanceof DestinationRejected && $first->reason() === Reason::CannotPin)->toBeTrue();
});

it('caps a real guarded response', function (): void {
    $started = microtime(true);
    $response = Http::safe(networkPolicy()->maxResponseBytes(4096))->timeout(20)->get($this->server->url('pin.invalid', '/endless'));

    expect($response->status())->toBe(200)
        ->and(strlen($response->body()))->toBe(4096)
        ->and($response->header('Safe-Fetch-Truncated'))->toBe('1')
        ->and($response->header('Content-Type'))->toBe('application/octet-stream')
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('keeps the connected address on a truncated response', function (): void {
    $response = SafeFetch::guard(Http::timeout(20), networkPolicy()->maxResponseBytes(4096))->get($this->server->url('pin.invalid', '/endless'));

    expect(SafeFetch::truncated($response))->toBeTrue()
        ->and(strlen($response->body()))->toBe(4096)
        ->and($response->handlerStats()['primary_ip'] ?? null)->toBe('127.0.0.1');
});

it('leaves a real body under the cap untouched', function (): void {
    $response = Http::safe(networkPolicy()->maxResponseBytes(4096))->get($this->server->url('pin.invalid', '/hello.txt'));

    expect($response->body())->toBe('hello from the pinned server')
        ->and($response->hasHeader('Safe-Fetch-Truncated'))->toBeFalse();
});

it('network tests cannot be skipped on ci', function (): void {
    expect(Network::missing(ipv6: true))->toBeNull();

    if (Network::required()) {
        expect(Network::hasIpv6Loopback())->toBeTrue();
    }
});
