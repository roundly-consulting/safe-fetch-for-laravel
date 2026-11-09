<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\SafeFetch\Actions\BuildHandlerStackAction;
use RoundlyConsulting\SafeFetch\Actions\CreateGuzzleClientAction;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\Clock;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PinnedHandler;
use RoundlyConsulting\SafeFetch\Testing\SafeFetchFake;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

/*
 * A library's own Guzzle client, guarded: SafeFetch::handlerStack() / guzzleClient(). The stack is
 * the one a guarded Laravel request sends through, so Http::fake() answers and records it.
 */

beforeEach(function (): void {
    // A frozen clock: the time limit a hop gets is exact.
    app()->instance(Clock::class, new Clock(static fn (): float => 1_000.0));
    SafeFetch::fakeDns([
        'hooks.example' => '93.184.216.34',
        'other.example' => '151.101.1.1',
        'inward.example' => '10.0.0.5',
    ]);
});

it('guards a client the library builds on the stack', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok')]);
    $client = new Client(['handler' => SafeFetch::handlerStack()]);

    $refusal = Helpers::refusal(fn () => $client->get('https://inward.example/'));

    expect($client->get('https://hooks.example/a')->getBody()->__toString())->toBe('ok')
        ->and($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and($refusal->reason())->toBe(Reason::Blocked);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/a');
});

it('pins every request of the stack', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response('ok');
    });

    SafeFetch::guzzleClient()->get('https://hooks.example/a');

    $curl = $seen[0]['curl'];

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['proxy'])->toBe('')
        ->and($seen[0]['allow_redirects'])->toBeFalse()
        ->and($seen[0]['protocols'])->toBe(['https'])
        ->and($curl[CURLOPT_RESOLVE])->toBe(['hooks.example:443:93.184.216.34'])
        ->and($curl[CURLOPT_FRESH_CONNECT])->toBeTrue()
        ->and($curl[CURLOPT_FORBID_REUSE])->toBeTrue()
        ->and($seen[0]['safe_fetch.hop'])->toBeInstanceOf(VettedDestination::class);
});

it('sends through the guards own curl handler', function (): void {
    $stack = SafeFetch::handlerStack();
    $handler = (new ReflectionProperty(HandlerStack::class, 'handler'))->getValue($stack);

    $names = array_column((new ReflectionProperty(HandlerStack::class, 'stack'))->getValue($stack), 1);

    expect($handler)->toBeInstanceOf(PinnedHandler::class)
        ->and($names[0])->toBe('safe_fetch.client_options');
});

it('turns a clients own redirects off and returns a redirect unfollowed by default', function (): void {
    Http::fake(['hooks.example/*' => Http::response('', 302, ['Location' => 'https://other.example/'])]);

    // Guzzle's default client follows redirects itself; on the guarded stack the policy decides.
    $response = (new Client(['handler' => SafeFetch::handlerStack()]))->get('https://hooks.example/a');

    expect($response->getStatusCode())->toBe(302);
    Http::assertSentCount(1);
});

it('follows redirects under the policy, vetting every hop', function (): void {
    $dns = SafeFetch::fakeDns();
    Http::fake([
        'hooks.example/start' => Http::response('', 307, ['Location' => 'https://other.example/next']),
        'other.example/next' => Http::response('done'),
        'other.example/inward' => Http::response('', 307, ['Location' => 'https://inward.example/']),
    ]);
    $client = SafeFetch::guzzleClient(policy: SafeFetch::policy()->followRedirects());

    $refusal = Helpers::refusal(fn () => $client->get('https://other.example/inward'));

    expect($client->get('https://hooks.example/start')->getBody()->__toString())->toBe('done')
        ->and($refusal->reason())->toBe(Reason::Blocked);
    $dns->assertLookedUp('hooks.example', 1);
    Http::assertSentCount(3);
});

it('applies the clients cookie jar per hop', function (): void {
    SafeFetch::fakeDns(['a.example' => '93.184.216.34', 'b.example' => '151.101.1.1']);
    $sent = [];
    Http::fake(static function (Request $request) use (&$sent) {
        $sent[$request->url()] = $request->header('Cookie');

        return match ($request->url()) {
            'https://a.example/login' => Http::response('', 307, ['Location' => '/next', 'Set-Cookie' => 'session=abc; Path=/']),
            'https://a.example/next' => Http::response('', 307, ['Location' => 'https://b.example/x']),
            default => Http::response('ok'),
        };
    });

    $client = SafeFetch::guzzleClient(['cookies' => true], SafeFetch::policy()->followRedirects());
    $client->get('https://a.example/login');
    $client->get('https://a.example/next');

    expect($sent['https://a.example/next'])->toBe(['session=abc'])
        ->and($sent['https://b.example/x'])->toBe([]);
});

it('honours the clients http errors setting', function (): void {
    Http::fake(['hooks.example/*' => Http::response('nope', 404)]);

    expect(fn () => SafeFetch::guzzleClient()->get('https://hooks.example/'))->toThrow(ClientException::class)
        ->and(SafeFetch::guzzleClient(['http_errors' => false])->get('https://hooks.example/')->getStatusCode())->toBe(404);
});

it('refuses a handler or guzzles own redirects on the guarded client', function (array $config, string $option): void {
    expect(fn () => SafeFetch::guzzleClient($config))->toThrow(UnsafeRequestOptions::class, "[{$option}]");
})->with([
    'a handler' => [['handler' => HandlerStack::create()], 'handler'],
    'a null handler' => [['handler' => null], 'handler'],
    'redirects on' => [['allow_redirects' => true], 'allow_redirects'],
    'redirect settings' => [['allow_redirects' => ['max' => 5]], 'allow_redirects'],
]);

it('takes a client config without redirects', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok')]);

    $client = SafeFetch::guzzleClient(['base_uri' => 'https://hooks.example/api/', 'allow_redirects' => false, 'headers' => ['X-Lib' => '1']]);

    expect($client->get('items')->getBody()->__toString())->toBe('ok');
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/api/items' && $request->header('X-Lib') === ['1']);
});

it('refuses per request options that take the request around the guard', function (array $options, string $option): void {
    Http::fake();

    expect(fn () => SafeFetch::guzzleClient()->get('https://hooks.example/', $options))->toThrow(UnsafeRequestOptions::class, "[{$option}]");
    Http::assertNothingSent();
})->with([
    'a proxy' => [['proxy' => 'http://proxy.example:3128'], 'proxy'],
    'a raw curl option' => [['curl' => [CURLOPT_CONNECT_TO => ['hooks.example:443:10.0.0.5:443']]], 'curl.CURLOPT_CONNECT_TO'],
]);

it('caps a response body by policy and by configuration', function (): void {
    Http::fake(['hooks.example/*' => Http::response(str_repeat('x', 8192))]);

    $byPolicy = SafeFetch::guzzleClient(policy: SafeFetch::policy()->maxResponseBytes(100))->get('https://hooks.example/');
    config()->set('safe-fetch.request.max_bytes', 50);
    $byConfig = SafeFetch::guzzleClient()->get('https://hooks.example/');
    $laravel = Http::safe()->get('https://hooks.example/');

    expect(strlen($byPolicy->getBody()->__toString()))->toBe(100)
        ->and(SafeFetch::truncated($byPolicy))->toBeTrue()
        ->and(strlen($byConfig->getBody()->__toString()))->toBe(50)
        ->and(strlen($laravel->body()))->toBe(50)
        ->and(SafeFetch::truncated($laravel))->toBeTrue()
        ->and(fn () => Http::safe()->sink(tempnam(sys_get_temp_dir(), 'sink'))->get('https://hooks.example/'))
        ->toThrow(UnsafeRequestOptions::class, '[sink]');
});

it('limits the time a request may take', function (Closure $send, array $expected): void {
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('ok');
    });
    config()->set('safe-fetch.request.timeout', 10);

    $send();

    expect($timeouts)->toBe($expected);
})->with([
    'a guzzle client without a timeout' => [fn () => SafeFetch::guzzleClient()->get('https://hooks.example/'), [10.0]],
    'a guzzle client with a shorter one' => [fn () => SafeFetch::guzzleClient(['timeout' => 4])->get('https://hooks.example/'), [4.0]],
    'a guzzle client with a longer one' => [fn () => SafeFetch::guzzleClient(['timeout' => 60])->get('https://hooks.example/'), [10.0]],
    'laravels 30 second default' => [fn () => Http::safe()->get('https://hooks.example/'), [10.0]],
    'laravel without a timeout' => [fn () => Http::safe()->timeout(0)->get('https://hooks.example/'), [10.0]],
    'laravel with a shorter one' => [fn () => Http::safe()->timeout(3)->get('https://hooks.example/'), [3.0]],
    'lifted for one call' => [fn () => Http::safe(SafeFetch::policy()->timeout(null))->timeout(45)->get('https://hooks.example/'), [45.0]],
]);

it('spends the time limit across hops', function (): void {
    $now = 1_000.0;
    app()->instance(Clock::class, new Clock(static function () use (&$now): float {
        return $now;
    }));
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$now, &$timeouts) {
        $timeouts[] = $options['timeout'];
        $now += 4.0;

        return Http::response('', 307, ['Location' => '/again']);
    });

    $refusal = Helpers::refusal(fn () => SafeFetch::guzzleClient(policy: SafeFetch::policy()->followRedirects(5)->timeout(10))->get('https://hooks.example/'));

    expect($refusal->reason())->toBe(Reason::Timeout)
        ->and($timeouts)->toBe([10.0, 6.0, 2.0]);
});

it('keeps fetch on its own caps', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options['timeout'];

        return Http::response(str_repeat('x', 200));
    });
    config()->set('safe-fetch.request.timeout', 5);
    config()->set('safe-fetch.request.max_bytes', 10);

    expect(strlen(SafeFetch::fetch('https://hooks.example/')->body))->toBe(200)
        ->and($seen)->toBe([30.0]);
});

it('guards concurrent requests of one client', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok')]);
    $client = SafeFetch::guzzleClient();

    $results = PromiseUtils::settle([
        $client->getAsync('https://hooks.example/a'),
        $client->getAsync('https://inward.example/b'),
        $client->getAsync('https://hooks.example/c'),
    ])->wait();

    expect(array_column($results, 'state'))->toBe(['fulfilled', 'rejected', 'fulfilled'])
        ->and($results[1]['reason'])->toBeInstanceOf(DestinationRejected::class);
    Http::assertSentCount(2);
});

it('is recorded by the fake and refused before dialling without an http fake', function (): void {
    $fake = SafeFetch::fake();

    $refusal = Helpers::thrown(fn () => SafeFetch::guzzleClient()->get('https://hooks.example/a'));
    Http::fake(['hooks.example/*' => Http::response('ok')]);
    SafeFetch::guzzleClient()->get('https://hooks.example/b');

    expect($fake)->toBeInstanceOf(SafeFetchFake::class)
        ->and($refusal)->toBeInstanceOf(LogicException::class)
        ->and($refusal->getMessage())->toContain('Http::fake()');
    SafeFetch::assertVetted('https://hooks.example/b');
});

it('refuses to build without curl', function (): void {
    CurlSupport::assume(available: false);

    expect(Helpers::refusal(fn () => SafeFetch::handlerStack())->reason())->toBe(Reason::CannotPin)
        ->and(Helpers::refusal(fn () => SafeFetch::guzzleClient())->reason())->toBe(Reason::CannotPin);
});

it('runs through the facade, an injected manager and the raw actions', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok')]);
    $manager = app(SafeFetchManager::class);
    $send = static fn (Client $client): string => $client->get('https://hooks.example/')->getBody()->__toString();

    expect($send(SafeFetch::guzzleClient()))->toBe('ok')
        ->and($send(new Client(['handler' => SafeFetch::handlerStack()])))->toBe('ok')
        ->and($send($manager->guzzleClient()))->toBe('ok')
        ->and($send(new Client(['handler' => $manager->handlerStack()])))->toBe('ok')
        ->and($send(app(CreateGuzzleClientAction::class)->execute([], SafeFetch::policy())))->toBe('ok')
        ->and($send(new Client(['handler' => app(BuildHandlerStackAction::class)->execute(SafeFetch::policy())])))->toBe('ok');
});
