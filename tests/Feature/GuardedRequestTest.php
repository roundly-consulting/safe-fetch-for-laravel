<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Http\GuardedClient;
use RoundlyConsulting\SafeFetch\Http\GuardMiddleware;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\Clock;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PinnedHandler;
use RoundlyConsulting\SafeFetch\Support\PinnedHandlers;
use RoundlyConsulting\SafeFetch\Support\PreSendCheck;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

/*
 * Http::safe() under Http::fake(): the guard middleware runs inside Laravel's stack, so the stub
 * sees the options it set and the recorder records every hop.
 */

beforeEach(function (): void {
    SafeFetch::fakeDns([
        'hooks.example' => '93.184.216.34',
        'other.example' => '151.101.1.1',
        'inward.example' => '10.0.0.5',
        'v6.example' => ['2606:4700::1111', '93.184.216.34'],
    ]);
});

it('vets before sending', function (): void {
    Http::fake();

    $refusal = Helpers::refusal(fn () => Http::safe()->get('https://inward.example/'));

    expect($refusal->reason())->toBe(Reason::Blocked);
    Http::assertNothingSent();
});

it('pins every request', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response('ok');
    });

    Http::safe()->get('https://hooks.example/a');

    $curl = $seen[0]['curl'];

    expect($seen)->toHaveCount(1)
        ->and($seen[0]['proxy'])->toBe('')
        ->and($seen[0]['allow_redirects'])->toBeFalse()
        ->and($seen[0]['protocols'])->toBe(['https'])
        ->and($curl[CURLOPT_RESOLVE])->toBe(['hooks.example:443:93.184.216.34'])
        ->and($curl[CURLOPT_FRESH_CONNECT])->toBeTrue()
        ->and($curl[CURLOPT_FORBID_REUSE])->toBeTrue()
        ->and($seen[0]['safe_fetch.hop'])->toBeInstanceOf(VettedDestination::class)
        ->and(PreSendCheck::in($curl) !== null)->toBe(CurlSupport::preSendCheck());
});

it('sends the rebuilt url', function (): void {
    Http::fake();

    Http::safe()->get('https://HOOKS.example.:443/a?b=c#fragment');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/a?b=c'
        && $request->header('Host') === ['hooks.example']);
});

it('a refusal is not wrapped by laravel', function (): void {
    Http::fake();

    expect(fn () => Http::safe()->get('https://inward.example/'))->toThrow(DestinationRejected::class)
        ->and(fn () => Http::safe()->retry(2, 0)->get('https://nowhere.example/'))->toThrow(DestinationUnavailable::class);
});

it('refuses a proxy loudly', function (Closure $request): void {
    Http::fake();

    expect($request)->toThrow(UnsafeRequestOptions::class, '[proxy]');
    Http::assertNothingSent();
})->with([
    'request option' => [fn () => Http::safe()->withOptions(['proxy' => 'http://proxy.internal:3128'])->get('https://hooks.example/')],
    'per scheme' => [fn () => Http::safe()->withOptions(['proxy' => ['https' => 'http://proxy.internal:3128']])->get('https://hooks.example/')],
    'another scheme key' => [fn () => Http::safe()->withOptions(['proxy' => ['socks' => 'socks5://proxy.internal:1080']])->get('https://hooks.example/')],
    'global option' => [function () {
        Http::globalOptions(['proxy' => 'http://proxy.internal:3128']);

        return Http::safe()->get('https://hooks.example/');
    }],
]);

it('refuses guzzle redirects', function (): void {
    Http::fake();

    expect(fn () => Http::safe()->maxRedirects(5)->get('https://hooks.example/'))
        ->toThrow(UnsafeRequestOptions::class, '[allow_redirects]');
    Http::assertNothingSent();
});

it('refuses options that reroute the connection', function (string $option): void {
    Http::fake();

    expect(fn () => Http::safe()->withOptions(['curl' => [constant($option) => 'x']])->get('https://hooks.example/'))
        ->toThrow(UnsafeRequestOptions::class, "[curl.{$option}]");
})->with(array_values(array_filter([
    'CURLOPT_RESOLVE', 'CURLOPT_CONNECT_TO', 'CURLOPT_UNIX_SOCKET_PATH', 'CURLOPT_ABSTRACT_UNIX_SOCKET',
    'CURLOPT_INTERFACE', 'CURLOPT_PREREQFUNCTION', 'CURLOPT_PROXY', 'CURLOPT_PRE_PROXY', 'CURLOPT_PROXYTYPE',
    'CURLOPT_HTTPPROXYTUNNEL', 'CURLOPT_DNS_SERVERS', 'CURLOPT_DNS_INTERFACE', 'CURLOPT_DNS_LOCAL_IP4',
    'CURLOPT_DNS_CACHE_TIMEOUT', 'CURLOPT_LOCALPORT', 'CURLOPT_MAXCONNECTS', 'CURLOPT_FOLLOWLOCATION',
    'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_FRESH_CONNECT',
], defined(...))));

it('refuses request level transport sharing and a client of its own', function (): void {
    Http::fake();

    expect(fn () => Http::safe()->withOptions(['transport_sharing' => 'none'])->get('https://hooks.example/'))
        ->toThrow(UnsafeRequestOptions::class, '[transport_sharing]')
        ->and(fn () => Http::setClient(new Client)->safe())->toThrow(UnsafeRequestOptions::class, '[client]')
        ->and(fn () => Http::safe()->safe())->toThrow(UnsafeRequestOptions::class, 'already guarded');
});

it('sends through the guarded client, which laravel still asks for', function (): void {
    // The guard relies on Laravel sending through `buildClient()`, which returns the `client`
    // property it set. If an upgrade types that property or stops using it, this fails loudly —
    // a request option `handler` would get around the guard again on Guzzle 7.
    $property = new ReflectionProperty(PendingRequest::class, 'client');
    $type = $property->getType();
    Http::fake();
    $request = Http::safe();

    expect($type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed'))->toBeTrue()
        ->and($property->getValue($request))->toBeInstanceOf(GuardedClient::class)
        ->and($request->buildClient())->toBe($property->getValue($request))
        ->and(fn () => $request->withOptions(['handler' => static fn () => Create::promiseFor(new PsrResponse(200))])->get('https://hooks.example/'))
        ->toThrow(UnsafeRequestOptions::class, '[handler]')
        ->and(fn () => SafeFetch::guard(Http::withOptions(['handler' => new CurlHandler])))
        ->toThrow(UnsafeRequestOptions::class, '[handler]');
    Http::assertNothingSent();
});

it('lets the curl options that keep the connection where it is through', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options;

        return Http::response('ok');
    });

    Http::safe()->withOptions(['curl' => [CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_ENCODING => '']])->get('https://hooks.example/');

    expect($seen[0]['curl'][CURLOPT_TCP_KEEPALIVE])->toBe(1)
        ->and($seen[0]['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example:443:93.184.216.34']);
});

it('returns a redirect unfollowed by default', function (): void {
    Http::fake(['hooks.example/*' => Http::response('', 307, ['Location' => 'https://other.example/'])]);

    $response = Http::safe()->get('https://hooks.example/a');

    expect($response->status())->toBe(307);
    Http::assertSentCount(1);
});

it('follows method preserving redirects vetting every hop', function (): void {
    $dns = SafeFetch::fakeDns();
    Http::fake([
        'hooks.example/start' => Http::response('', 307, ['Location' => 'https://other.example/next']),
        'other.example/next' => Http::response('', 308, ['Location' => '/final']),
        'other.example/final' => Http::response('done'),
    ]);

    $response = Http::safe(SafeFetch::policy()->followRedirects())->post('https://hooks.example/start', ['a' => 1]);

    expect($response->body())->toBe('done');
    Http::assertSentCount(3);
    $dns->assertLookedUp('hooks.example', 1);
    $dns->assertLookedUp('other.example', 2);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://other.example/final' && $request->method() === 'POST');
});

it('leaves other redirects to the caller', function (int $status): void {
    Http::fake(['hooks.example/*' => Http::response('', $status, ['Location' => 'https://other.example/'])]);

    expect(Http::safe(SafeFetch::policy()->followRedirects())->get('https://hooks.example/')->status())->toBe($status);
    Http::assertSentCount(1);
})->with([301, 302, 303]);

it('refuses a redirect to a private address', function (): void {
    Http::fake(['hooks.example/*' => Http::response('', 307, ['Location' => 'https://10.0.0.1/'])]);

    $refusal = Helpers::refusal(fn () => Http::safe(SafeFetch::policy()->followRedirects())->get('https://hooks.example/'));

    expect($refusal->reason())->toBe(Reason::Blocked);
    Http::assertSentCount(1);
});

it('refuses a downgrade redirect', function (): void {
    Http::fake([
        'hooks.example/*' => Http::response('', 307, ['Location' => 'http://other.example/plain']),
        'other.example/*' => Http::response('plain'),
    ]);

    $refusal = Helpers::refusal(fn () => Http::safe(SafeFetch::policy()->allowHttp()->followRedirects())->get('https://hooks.example/'));
    $allowed = SafeFetch::policy()->allowHttp()->redirects(new RedirectPolicy(enabled: true, allowDowngrade: true));

    expect($refusal->reason())->toBe(Reason::RedirectDowngrade)
        ->and(Http::safe($allowed)->get('https://hooks.example/')->body())->toBe('plain');
});

it('stops after the redirect limit', function (): void {
    Http::fake(['hooks.example/*' => static fn (Request $request) => Http::response('', 307, ['Location' => $request->url().'x'])]);

    $refusal = Helpers::refusal(fn () => Http::safe(SafeFetch::policy()->followRedirects(3))->get('https://hooks.example/'));

    expect($refusal->reason())->toBe(Reason::TooManyRedirects);
    Http::assertSentCount(4);
});

it('keeps headers away from another origin', function (): void {
    Http::fake([
        'hooks.example/*' => Http::response('', 307, ['Location' => 'https://other.example/next']),
        'other.example/*' => Http::response('ok'),
    ]);
    $policy = SafeFetch::policy()->redirects(new RedirectPolicy(enabled: true, forwardHeaders: ['Webhook-Signature']));

    Http::safe($policy)->withToken('secret')->withHeaders([
        'Cookie' => 'session=1', 'X-Custom' => 'yes', 'Webhook-Signature' => 'sig', 'Accept' => 'text/plain', 'User-Agent' => 'Agent/1',
    ])->post('https://hooks.example/start', ['a' => 1]);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://other.example/next'
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie')
        && ! $request->hasHeader('X-Custom')
        && $request->header('Webhook-Signature') === ['sig']
        && $request->header('Accept') === ['text/plain']
        && $request->header('User-Agent') === ['Agent/1']
        && $request->header('Content-Type') === ['application/json']);
});

it('keeps cookies per hop, like guzzles own redirects', function (Closure $start, array $first): void {
    SafeFetch::fakeDns(['a.example' => '93.184.216.34', 'b.example' => '151.101.1.1']);
    $sent = [];
    Http::fake(static function (Request $request) use (&$sent) {
        $sent[$request->url()] = $request->header('Cookie');

        return match ($request->url()) {
            'https://a.example/login' => Http::response('', 307, ['Location' => '/next', 'Set-Cookie' => 'session=abc; Path=/']),
            'https://a.example/next' => Http::response('', 307, ['Location' => 'https://b.example/x']),
            default => Http::response('ok', 200, ['Set-Cookie' => ['evil=1; Domain=a.example; Path=/', 'own=1; Path=/']]),
        };
    });

    $response = $start()->safe(SafeFetch::policy()->followRedirects())->get('https://a.example/login');
    $jar = array_map(static fn (array $cookie): string => $cookie['Name'].'@'.$cookie['Domain'], $response->cookies()->toArray());
    sort($jar);

    expect($sent['https://a.example/login'])->toBe($first)
        ->and($sent['https://a.example/next'])->toBe([implode('; ', [...$first, 'session=abc'])])
        ->and($sent['https://b.example/x'])->toBe([])
        // b.example cannot plant a cookie for a.example; its own cookie stays its own.
        ->and($jar)->toBe($first === [] ? ['own@b.example', 'session@a.example'] : ['own@b.example', 'pre@a.example', 'session@a.example']);
})->with([
    'the request\'s own jar' => [fn () => Http::withOptions([]), []],
    'a jar from withCookies()' => [fn () => Http::withCookies(['pre' => '1'], 'a.example'), ['pre=1']],
]);

it('sends no cookies when the request turns them off', function (): void {
    Http::fake([
        'hooks.example/next' => Http::response('ok'),
        'hooks.example/*' => Http::response('', 307, ['Location' => '/next', 'Set-Cookie' => 'session=abc; Path=/']),
    ]);

    $response = Http::withOptions(['cookies' => false])->safe(SafeFetch::policy()->followRedirects())->get('https://hooks.example/start');

    expect($response->body())->toBe('ok');
    Http::assertSent(static fn (Request $request): bool => ! $request->hasHeader('Cookie'));
});

it('writes a stubbed followed answer into the callers sink resource', function (): void {
    Http::fake([
        'hooks.example/next' => Http::response('final'),
        'hooks.example/*' => Http::response('', 307, ['Location' => '/next']),
    ]);
    $sink = fopen('php://temp', 'w+b');

    Http::safe(SafeFetch::policy()->followRedirects())->withOptions(['sink' => $sink])->get('https://hooks.example/start');

    expect(stream_get_contents($sink, offset: 0))->toBe('final');
});

it('keeps headers on the same origin', function (): void {
    Http::fake([
        'hooks.example/start' => Http::response('', 308, ['Location' => '/next']),
        'hooks.example/next' => Http::response('ok'),
    ]);

    Http::safe(SafeFetch::policy()->followRedirects())->withToken('secret')->withHeaders(['X-Custom' => 'yes'])->get('https://hooks.example/start');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/next'
        && $request->header('Authorization') === ['Bearer secret']
        && $request->header('X-Custom') === ['yes']);
});

it('drops credentials on a cross origin redirect', function (Closure $authenticate, bool $visibleOnFirstHop): void {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [$request->url(), $request->header('Authorization'), $options['auth'] ?? null, array_intersect_key($options['curl'] ?? [], [CURLOPT_HTTPAUTH => 1, CURLOPT_USERPWD => 1])];

        return str_contains($request->url(), '/start')
            ? Http::response('', 307, ['Location' => str_contains($request->url(), 'hooks.example/start') ? 'https://other.example/start2' : '/done'])
            : Http::response('ok');
    });

    $authenticate(Http::safe(SafeFetch::policy()->followRedirects()))->get('https://hooks.example/start');

    [$first, $second] = $seen;

    // Digest: Guzzle 7 hands it to curl (visible here), Guzzle 8 answers the challenge in its own
    // middleware, outside this one — either way the second hop carries nothing.
    expect($first[1] !== [] || $first[2] !== null || $first[3] !== [])->toBe($visibleOnFirstHop || ($first[3] !== []))
        ->and($second[0])->toBe('https://other.example/start2')
        ->and($second[1])->toBe([])
        ->and($second[2])->toBeNull()
        ->and($second[3])->toBe([]);
})->with([
    'basic' => [fn (PendingRequest $request) => $request->withBasicAuth('user', 'secret'), true],
    'digest' => [fn (PendingRequest $request) => $request->withDigestAuth('user', 'secret'), false],
    'raw curl' => [fn (PendingRequest $request) => $request->withOptions(['curl' => [CURLOPT_HTTPAUTH => CURLAUTH_NTLM, CURLOPT_USERPWD => 'user:secret']]), true],
]);

it('preserves method and body on 307 and 308', function (int $status): void {
    Http::fake([
        'hooks.example/start' => Http::response('', $status, ['Location' => '/next']),
        'hooks.example/next' => Http::response('ok'),
    ]);

    Http::safe(SafeFetch::policy()->followRedirects())->withBody('payload', 'text/plain')->put('https://hooks.example/start');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/next'
        && $request->method() === 'PUT' && $request->body() === 'payload');
})->with([307, 308]);

it('switches to get where http says so', function (int $status, string $method): void {
    Http::fake([
        'hooks.example/start' => Http::response('', $status, ['Location' => '/next']),
        'hooks.example/next' => Http::response('ok'),
    ]);
    $policy = SafeFetch::policy()->redirects(new RedirectPolicy(enabled: true, statuses: [301, 302, 303]));

    Http::safe($policy)->withBody('payload', 'text/plain')->send($method, 'https://hooks.example/start');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/next'
        && $request->method() === 'GET' && $request->body() === '' && ! $request->hasHeader('Content-Type'));
})->with([[303, 'PUT'], [301, 'POST'], [302, 'POST']]);

it('does not replay a body it cannot rewind', function (): void {
    Http::fake(['hooks.example/*' => Http::response('', 307, ['Location' => '/next'])]);
    $nonSeekable = new NoSeekStream(Utils::streamFor('once'));

    $response = Http::safe(SafeFetch::policy()->followRedirects())->withBody($nonSeekable, 'text/plain')->post('https://hooks.example/start');

    expect($response->status())->toBe(307);
    Http::assertSentCount(1);
});

it('spends one timeout budget across hops', function (): void {
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

    $refusal = Helpers::refusal(fn () => Http::safe(SafeFetch::policy()->followRedirects(5))->timeout(10)->get('https://hooks.example/'));

    expect($refusal)->toBeInstanceOf(DestinationUnavailable::class)
        ->and($refusal->reason())->toBe(Reason::Timeout)
        ->and($refusal->retryable())->toBeTrue()
        ->and($timeouts)->toBe([10.0, 6.0, 2.0]);
});

it('guards pooled requests', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok')]);

    $responses = Http::pool(static fn (Pool $pool): array => [
        $pool->safe()->get('https://hooks.example/a'),
        $pool->safe()->get('https://inward.example/b'),
    ]);

    expect($responses[0]->body())->toBe('ok')
        ->and($responses[1])->toBeInstanceOf(DestinationRejected::class);
    Http::assertSentCount(1);
});

it('chains in either order', function (): void {
    Http::fake();

    Http::withToken('t')->safe()->get('https://hooks.example/a');
    Http::safe()->withToken('t')->get('https://hooks.example/b');

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/a' && $request->header('Authorization') === ['Bearer t']);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/b' && $request->header('Authorization') === ['Bearer t']);
});

it('resolves a relative location against the rebuilt url', function (): void {
    Http::fake([
        'hooks.example/dir/start' => Http::response('', 307, ['Location' => '../next?x=1']),
        'hooks.example/next*' => Http::response('ok'),
    ]);

    Http::safe(SafeFetch::policy()->followRedirects())->get('https://HOOKS.example./dir/start#frag');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/next?x=1');
});

it('refuses a location no parser agrees on', function (): void {
    Http::fake(['hooks.example/*' => Http::response('', 307, ['Location' => '/\\evil.example/'])]);

    expect(Helpers::refusal(fn () => Http::safe(SafeFetch::policy()->followRedirects())->get('https://hooks.example/'))->reason())
        ->toBe(Reason::InvalidUrl);
});

it('allows answers without throwing', function (string $url, Reason $reason): void {
    config()->set('safe-fetch.ports', [443]);

    expect(SafeFetch::allows($url))->toBeFalse()
        ->and(Helpers::refusal(fn () => SafeFetch::vet($url))->reason())->toBe($reason);
})->with([
    ['not a url', Reason::InvalidUrl],
    ['ftp://hooks.example/', Reason::Scheme],
    ['https://u:p@hooks.example/', Reason::Credentials],
    ['https://0x7f.1/', Reason::NonCanonicalHost],
    ['https://hooks.example:8443/', Reason::Port],
    ['https://inward.example/', Reason::Blocked],
    ['https://nowhere.example/', Reason::Unresolved],
]);

it('re vets every retry', function (): void {
    $dns = SafeFetch::fakeDns();
    Http::fake(['hooks.example/*' => Http::sequence()->push('down', 503)->push('up', 200)]);

    expect(Http::safe()->retry(2, 0)->get('https://hooks.example/')->body())->toBe('up');
    $dns->assertLookedUp('hooks.example', 2);

    // Laravel retries any throwable unless `when` says no — a refusal included.
    $lookups = count($dns->lookups());
    Helpers::refusal(fn () => Http::safe()->retry(3, 0)->get('https://nowhere.example/'));
    $retried = count($dns->lookups()) - $lookups;

    Helpers::refusal(fn () => Http::safe()->retry(3, 0, static fn (Throwable $exception): bool => ! $exception instanceof SafeFetchException)->get('https://nowhere.example/'));

    expect($retried)->toBe(3)
        ->and(count($dns->lookups()) - $lookups - $retried)->toBe(1);
});

it('gives every guarded request its own handler', function (): void {
    $handler = static fn (PendingRequest $request): mixed => (new ReflectionProperty(PendingRequest::class, 'handler'))->getValue($request);

    $first = $handler(Http::safe());
    $second = $handler(Http::safe());

    expect($first)->toBeInstanceOf(PinnedHandler::class)
        ->and($second)->toBeInstanceOf(PinnedHandler::class)
        ->and($first)->not->toBe($second);
});

it('shares one handler between the guarded members of one pool', function (): void {
    $handlers = app(PinnedHandlers::class);
    $poolHandler = static fn (): mixed => null;
    $other = static fn (): mixed => null;

    expect($handlers->for($poolHandler))->toBe($handlers->for($poolHandler))
        ->and($handlers->for($poolHandler))->not->toBe($handlers->for($other))
        ->and($handlers->for(null))->not->toBe($handlers->for(null));
});

it('the safe macro is ours', function (): void {
    $macros = (new ReflectionProperty(PendingRequest::class, 'macros'))->getValue();

    expect($macros)->toHaveKey('safe')
        ->and((new ReflectionFunction($macros['safe']))->getFileName())->toEndWith('src/SafeFetchServiceProvider.php');
});

it('refuses to send without curl', function (): void {
    CurlSupport::assume(available: false);
    Http::fake();

    $vetted = SafeFetch::vet('https://hooks.example/');

    expect(Helpers::refusal(fn () => $vetted->pinningOptions())->reason())->toBe(Reason::CannotPin)
        ->and(Helpers::refusal(fn () => Http::safe())->reason())->toBe(Reason::CannotPin)
        ->and(Helpers::refusal(fn () => SafeFetch::fetch('https://hooks.example/'))->reason())->toBe(Reason::CannotPin)
        ->and(SafeFetch::allows('https://hooks.example/'))->toBeTrue();
    Http::assertNothingSent();
});

it('caps a guarded response', function (): void {
    Http::fake([
        'hooks.example/big' => Http::response(str_repeat('x', 1_048_576), 200, ['Content-Type' => 'text/plain', 'X-Kept' => 'yes']),
        'hooks.example/exact' => Http::response(str_repeat('y', 4096)),
    ]);
    $policy = SafeFetch::policy()->maxResponseBytes(4096);

    $big = Http::safe($policy)->get('https://hooks.example/big');
    $exact = Http::safe($policy)->get('https://hooks.example/exact');

    expect($big->status())->toBe(200)
        ->and(strlen($big->body()))->toBe(4096)
        ->and($big->header('Safe-Fetch-Truncated'))->toBe('1')
        ->and($big->header('X-Kept'))->toBe('yes')
        ->and($exact->body())->toBe(str_repeat('y', 4096))
        ->and($exact->hasHeader('Safe-Fetch-Truncated'))->toBeFalse()
        ->and(fn () => Http::safe($policy)->sink(tempnam(sys_get_temp_dir(), 'sink'))->get('https://hooks.example/big'))
        ->toThrow(UnsafeRequestOptions::class, '[sink]');
});

it('tells a truncated response from a whole one', function (): void {
    Http::fake([
        'hooks.example/big' => Http::response(str_repeat('x', 8192)),
        'hooks.example/exact' => Http::response(str_repeat('y', 4096)),
    ]);
    $policy = SafeFetch::policy()->maxResponseBytes(4096);

    $big = SafeFetch::http($policy)->get('https://hooks.example/big');
    $exact = SafeFetch::http($policy)->get('https://hooks.example/exact');

    expect(SafeFetchManager::TRUNCATED_HEADER)->toBe('Safe-Fetch-Truncated')
        ->and(GuardMiddleware::TRUNCATED_HEADER)->toBe(SafeFetchManager::TRUNCATED_HEADER)
        ->and(SafeFetch::truncated($big))->toBeTrue()
        ->and(SafeFetch::truncated($exact))->toBeFalse()
        ->and(SafeFetch::truncated($big->toPsrResponse()))->toBeTrue()
        ->and(SafeFetch::truncated($exact->toPsrResponse()))->toBeFalse()
        ->and(app(SafeFetchManager::class)->truncated(new PsrResponse(200, ['Safe-Fetch-Truncated' => '0'])))->toBeFalse()
        ->and(app(SafeFetchManager::class)->truncated(new PsrResponse(200, ['safe-fetch-truncated' => '1'])))->toBeTrue();
});

it('composes with middleware in either order', function (): void {
    Http::fake([
        'hooks.example/start' => Http::response('', 307, ['Location' => '/next']),
        'hooks.example/next' => Http::response('ok'),
    ]);
    $calls = 0;
    $counting = static function (callable $handler) use (&$calls): Closure {
        return static function (RequestInterface $request, array $options) use ($handler, &$calls) {
            $calls++;

            return $handler($request, $options);
        };
    };
    $policy = SafeFetch::policy()->followRedirects();

    Http::withMiddleware($counting)->safe($policy)->get('https://hooks.example/start');
    $outside = $calls;
    $calls = 0;
    Http::safe($policy)->withMiddleware($counting)->get('https://hooks.example/start');

    $throwing = static fn (callable $handler): Closure => static fn (): never => throw new LogicException('rate limited');

    expect($outside)->toBe(1)
        ->and($calls)->toBe(2)
        ->and(fn () => Http::safe($policy)->withMiddleware($throwing)->get('https://hooks.example/start'))->toThrow(LogicException::class, 'rate limited');
});

it('rejects a connection outside the vetted set', function (): void {
    Event::fake([DestinationRefused::class]);
    $reported = [];
    app(ExceptionHandler::class)->reportable(static function (DestinationRejected $exception) use (&$reported): void {
        $reported[] = $exception->reason();
    });

    $result = guardedThroughStats('10.0.0.5');

    expect($result)->toBeInstanceOf(DestinationRejected::class)
        ->and($result->reason())->toBe(Reason::CannotPin)
        ->and($reported)->toBe([Reason::CannotPin]);
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->reason === Reason::CannotPin && $event->host === 'hooks.example' && $event->via === 'request');
});

it('treats a handler without a connected address as unpinned', function (): void {
    expect(guardedThroughStats(null))->toBeInstanceOf(DestinationRejected::class)
        ->and(guardedThroughStats('93.184.216.34'))->toBeInstanceOf(PsrResponse::class)
        ->and(guardedThroughStats('::ffff:93.184.216.34'))->toBeInstanceOf(PsrResponse::class)
        ->and(guardedThroughStats(false))->toBeInstanceOf(PsrResponse::class);
});

it('keeps the callers on stats', function (): void {
    $seen = null;

    $result = guardedThroughStats('93.184.216.34', ['on_stats' => static function (TransferStats $stats) use (&$seen): void {
        $seen = $stats->getHandlerStat('primary_ip');
    }]);

    expect($result)->toBeInstanceOf(PsrResponse::class)->and($seen)->toBe('93.184.216.34');
});

it('refuses a request re pointed after vetting', function (Closure $tamper): void {
    $inner = 0;
    $handlers = app(PinnedHandlers::class);
    $handler = new PinnedHandler($handlers, app('events'));
    $curl = (new ReflectionProperty(PinnedHandler::class, 'curl'));
    $curl->setValue($handler, static function () use (&$inner) {
        $inner++;

        return Create::promiseFor(new PsrResponse(200));
    });
    $vetted = SafeFetch::vet('https://hooks.example/a');
    $options = $vetted->pinningOptions();
    $check = new PreSendCheck($vetted);
    $options['curl'] = CurlSupport::preSendCheck() ? $check->attachTo($options['curl']) : $options['curl'];
    $options['safe_fetch.hop'] = $vetted;

    [$request, $options] = $tamper(new PsrRequest('GET', 'https://hooks.example/a'), $options);

    expect(fn () => $handler($request, $options)->wait())->toThrow(DestinationRejected::class)
        ->and($inner)->toBe(0);
})->with([
    'another host' => [fn ($request, $options) => [$request->withUri(new Uri('https://other.example/a')), $options]],
    'another port' => [fn ($request, $options) => [$request->withUri(new Uri('https://hooks.example:8443/a')), $options]],
    'another scheme' => [fn ($request, $options) => [$request->withUri(new Uri('http://hooks.example/a')), $options]],
    'a proxy' => [fn ($request, $options) => [$request, ['proxy' => 'http://proxy.internal'] + $options]],
    'redirects back on' => [fn ($request, $options) => [$request, ['allow_redirects' => true] + $options]],
    'pin dropped' => [function ($request, $options) {
        unset($options['curl'][CURLOPT_RESOLVE]);

        return [$request, $options];
    }],
    'pin changed' => [function ($request, $options) {
        $options['curl'][CURLOPT_RESOLVE] = ['hooks.example:443:10.0.0.5'];

        return [$request, $options];
    }],
    'reuse allowed' => [function ($request, $options) {
        $options['curl'][CURLOPT_FORBID_REUSE] = false;

        return [$request, $options];
    }],
    'socket path added' => [function ($request, $options) {
        $options['curl'][CURLOPT_UNIX_SOCKET_PATH] = '/tmp/x.sock';

        return [$request, $options];
    }],
    'no hop' => [function ($request, $options) {
        unset($options['safe_fetch.hop']);

        return [$request, $options];
    }],
    'transport sharing' => [fn ($request, $options) => [$request, ['transport_sharing' => 'none'] + $options]],
    'another host header' => [fn ($request, $options) => [$request->withHeader('Host', 'evil.internal'), $options]],
    'another port in the host header' => [fn ($request, $options) => [$request->withHeader('Host', 'hooks.example:8443'), $options]],
    'a second host header' => [fn ($request, $options) => [$request->withAddedHeader('Host', 'evil.internal'), $options]],
    'a host header that is no authority' => [fn ($request, $options) => [$request->withHeader('Host', 'hooks.example:x'), $options]],
    'pre-send check of another hop' => [function ($request, $options) {
        if (! CurlSupport::preSendCheck()) {
            test()->markTestSkipped('needs CURLOPT_PREREQFUNCTION');
        }

        $options['curl'][CURLOPT_PREREQFUNCTION] = new PreSendCheck(SafeFetch::vet('https://other.example/'));

        return [$request, $options];
    }],
]);

it('dials an intact request', function (): void {
    $handler = new PinnedHandler(app(PinnedHandlers::class), app('events'));
    (new ReflectionProperty(PinnedHandler::class, 'curl'))->setValue($handler, static fn () => Create::promiseFor(new PsrResponse(204)));
    $vetted = SafeFetch::vet('https://[2606:4700::1111]/a');
    $options = $vetted->pinningOptions();
    $options['curl'] = CurlSupport::preSendCheck() ? (new PreSendCheck($vetted))->attachTo($options['curl']) : $options['curl'];
    $options['safe_fetch.hop'] = $vetted;

    expect($handler(new PsrRequest('GET', 'https://[2606:4700:0::1111]/a'), $options)->wait()->getStatusCode())->toBe(204);
});

it('dials a request whose host header names the vetted authority', function (string $url, ?string $host): void {
    $handler = new PinnedHandler(app(PinnedHandlers::class), app('events'));
    (new ReflectionProperty(PinnedHandler::class, 'curl'))->setValue($handler, static fn () => Create::promiseFor(new PsrResponse(204)));
    $vetted = SafeFetch::vet($url);
    $options = $vetted->pinningOptions();
    $options['curl'] = CurlSupport::preSendCheck() ? (new PreSendCheck($vetted))->attachTo($options['curl']) : $options['curl'];
    $options['safe_fetch.hop'] = $vetted;
    $request = new PsrRequest('GET', $url);
    $request = $host === null ? $request->withoutHeader('Host') : $request->withHeader('Host', $host);

    expect($handler($request, $options)->wait()->getStatusCode())->toBe(204);
})->with([
    'none' => ['https://hooks.example/a', null],
    'another case' => ['https://hooks.example/a', 'HOOKS.Example'],
    'the default port spelled out' => ['https://hooks.example/a', 'hooks.example:443'],
    'another port' => ['https://hooks.example:8443/a', 'hooks.example:8443'],
    'an ipv6 literal' => ['https://[2606:4700::1111]/a', '[2606:4700:0::1111]'],
]);

it('refuses a host header middleware set after safe', function (): void {
    SafeFetch::fake();

    $refusal = Helpers::thrown(fn () => Http::safe()
        ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withHeader('Host', 'evil.internal'))
        ->get('https://hooks.example/'));

    expect($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and($refusal instanceof DestinationRejected ? $refusal->reason() : null)->toBe(Reason::CannotPin);
});

it('refuses to dial under the fake unless real requests are allowed', function (): void {
    $fake = SafeFetch::fake();

    $refusal = null;

    try {
        Http::safe()->get('https://hooks.example/');
    } catch (LogicException $exception) {
        $refusal = $exception;
    }

    expect($refusal?->getMessage())->toContain('answer it with Http::fake()')
        ->and($fake->allowRealRequests())->toBe($fake)
        ->and(app(PinnedHandlers::class)->refusesToDial())->toBeFalse();
});

it('treats a mapped address as its ipv4', function (): void {
    if (! defined('CURL_PREREQFUNC_OK')) {
        $this->markTestSkipped('needs CURLOPT_PREREQFUNCTION (libcurl 7.80+)');
    }

    $v4 = new PreSendCheck(new VettedDestination('https://a.example/', 'https', 'a.example', 443, false, ['::ffff:127.0.0.1']));
    $v6 = new PreSendCheck(new VettedDestination('https://a.example/', 'https', 'a.example', 443, false, ['127.0.0.1']));
    $wrongPort = new PreSendCheck(new VettedDestination('https://a.example/', 'https', 'a.example', 443, false, ['127.0.0.1']));
    $elsewhere = new PreSendCheck(new VettedDestination('https://a.example/', 'https', 'a.example', 443, false, ['127.0.0.1']));

    expect($v4(null, '127.0.0.1', '', 443))->toBe(CURL_PREREQFUNC_OK)
        ->and($v6(null, '::ffff:127.0.0.1', '', 443))->toBe(CURL_PREREQFUNC_OK)
        ->and($wrongPort(null, '127.0.0.1', '', 8443))->toBe(CURL_PREREQFUNC_ABORT)
        ->and($wrongPort->refused)->toBeTrue()
        ->and($elsewhere(null, '127.0.0.2', '', 443))->toBe(CURL_PREREQFUNC_ABORT)
        ->and($v4->refused)->toBeFalse();
});

it('skips the pre send check where guzzle deprecates it', function (): void {
    CurlSupport::assume(guzzleVersion: '7.12.3');

    expect(CurlSupport::preSendCheck())->toBeFalse()
        ->and(CurlSupport::guzzleVersion())->toBe('7.12.3');

    CurlSupport::assume(guzzleVersion: '7.13.0');

    expect(CurlSupport::preSendCheck())->toBe(defined('CURLOPT_PREREQFUNCTION'));

    CurlSupport::forget();

    expect(CurlSupport::guzzleVersion())->toMatch('/^\d+\./');
});

/**
 * Run one guarded request through the middleware against an inner handler that reports
 * `$primaryIp` in its transfer stats (`false`: Laravel's stub stats, no time, no handler stats).
 *
 * @param  array<string, mixed>  $options
 */
function guardedThroughStats(string|false|null $primaryIp, array $options = []): mixed
{
    $middleware = new GuardMiddleware(app(SafeFetchManager::class), SafeFetch::policy(), new Clock, app('events'));
    $inner = static function (RequestInterface $request, array $hopOptions) use ($primaryIp) {
        $response = new PsrResponse(200, [], 'body');
        $stats = $primaryIp === false
            ? new TransferStats($request, $response)
            : new TransferStats($request, $response, 0.01, null, $primaryIp === null ? ['total_time' => 0.01] : ['primary_ip' => $primaryIp]);
        ($hopOptions['on_stats'])($stats);

        return Create::promiseFor($response);
    };

    try {
        return $middleware($inner)(new PsrRequest('GET', 'https://hooks.example/'), ['allow_redirects' => false, 'timeout' => 5] + $options)->wait();
    } catch (Throwable $exception) {
        return $exception;
    }
}

it('passes over informational answers and leaves a short unseekable body alone', function (): void {
    $middleware = new GuardMiddleware(app(SafeFetchManager::class), SafeFetch::policy()->maxResponseBytes(100), new Clock, app('events'));
    $inner = static function (RequestInterface $request, array $options) {
        ($options['on_headers'])(new PsrResponse(103));
        ($options['on_headers'])(new PsrResponse(200));

        return Create::promiseFor(new PsrResponse(200, [], new NoSeekStream(Utils::streamFor('short'))));
    };

    $response = $middleware($inner)(new PsrRequest('GET', 'https://hooks.example/'), ['allow_redirects' => false])->wait();

    // An answer from code inside the guard that never wrote to its sink is cut here.
    $long = static fn (RequestInterface $request, array $options) => Create::promiseFor(new PsrResponse(200, ['X-Kept' => 'yes'], str_repeat('z', 500)));
    $cut = $middleware($long)(new PsrRequest('GET', 'https://hooks.example/'), ['allow_redirects' => false])->wait();

    expect((string) $response->getBody())->toBe('short')
        ->and($response->hasHeader(GuardMiddleware::TRUNCATED_HEADER))->toBeFalse()
        ->and((string) $cut->getBody())->toBe(str_repeat('z', 100))
        ->and($cut->getHeaderLine(GuardMiddleware::TRUNCATED_HEADER))->toBe('1')
        ->and($cut->getHeaderLine('X-Kept'))->toBe('yes');
});
