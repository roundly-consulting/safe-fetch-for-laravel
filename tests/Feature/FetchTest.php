<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\TransportFailure;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

beforeEach(function (): void {
    SafeFetch::fakeDns([
        'docs.example' => '93.184.216.34',
        'cdn.example' => '151.101.1.1',
        'inward.example' => '10.0.0.5',
    ]);
});

it('fetches a url', function (): void {
    Http::fake(['docs.example/*' => Http::response('<p>hi</p>', 200, ['Content-Type' => 'text/html', 'X-Extra' => ['a', 'b']])]);

    $page = SafeFetch::fetch('https://DOCS.example/page#top', accept: 'text/html', headers: ['X-Trace' => '1']);

    expect($page)->toBeInstanceOf(FetchedResponse::class)
        ->and($page->status)->toBe(200)
        ->and($page->body)->toBe('<p>hi</p>')
        ->and($page->contentType)->toBe('text/html')
        ->and($page->url)->toBe('https://docs.example/page')
        ->and($page->headers['X-Extra'])->toBe(['a', 'b'])
        ->and($page->header('x-extra'))->toBe('a')
        ->and($page->header('missing'))->toBeNull();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->header('Accept') === ['text/html']
        && $request->header('X-Trace') === ['1']);
});

it('reports no content type when there is none', function (): void {
    Http::fake(['docs.example/*' => Http::response('raw', 200)]);

    expect(SafeFetch::fetch('https://docs.example/')->contentType)->toBeNull();
});

it('stops at the byte cap', function (): void {
    Http::fake([
        'docs.example/declared' => Http::response('small', 200, ['Content-Length' => '999999']),
        'docs.example/streamed' => Http::response(str_repeat('x', 2048)),
        'docs.example/exact' => Http::response(str_repeat('y', 1024)),
    ]);

    expect(Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/declared', maxBytes: 1024))->reason())->toBe(Reason::TooLarge)
        ->and(Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/streamed', maxBytes: 1024))->reason())->toBe(Reason::TooLarge)
        ->and(SafeFetch::fetch('https://docs.example/exact', maxBytes: 1024)->body)->toBe(str_repeat('y', 1024));
});

it('classifies failed statuses', function (int $status, bool $retryable, string $retryAfter, ?int $seconds): void {
    CarbonImmutable::setTestNow('2026-11-08 12:00:00');
    Http::fake(['docs.example/*' => Http::response('nope', $status, $retryAfter === '' ? [] : ['Retry-After' => $retryAfter])]);

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/'));

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::Status)
        ->and($failure instanceof FetchFailed ? $failure->status() : null)->toBe($status)
        ->and($failure instanceof FetchFailed ? $failure->retryAfter() : null)->toBe($seconds)
        ->and($failure->retryable())->toBe($retryable)
        ->and($failure->getMessage())->toBe("The URL cannot be fetched: the server answered with status {$status}.");

    CarbonImmutable::setTestNow();
})->with([
    '404' => [404, false, '', null],
    '410 ignores retry-after' => [410, false, '30', null],
    '408' => [408, true, '', null],
    '429 seconds' => [429, true, '120', 120],
    '503 http date' => [503, true, 'Sun, 08 Nov 2026 12:01:00 GMT', 60],
    '503 date in the past' => [503, true, 'Sun, 08 Nov 2026 11:00:00 GMT', 0],
    '503 capped at a day' => [503, true, '999999', 86_400],
    '502 unreadable' => [502, true, 'soon', null],
    '304' => [304, false, '', null],
]);

it('classifies transport failures by curl code', function (int $code, Reason $reason, string $class, bool $retryable): void {
    $exception = new ConnectionException("cURL error {$code}: something about https://docs.example/ at 93.184.216.34", 0, new ConnectException('x', new PsrRequest('GET', '/')));

    $failure = TransportFailure::from($exception, 'https://docs.example/', 'docs.example');

    expect($failure)->toBeInstanceOf($class)
        ->and($failure->reason())->toBe($reason)
        ->and($failure->retryable())->toBe($retryable)
        ->and($failure->getPrevious())->toBeNull();
})->with([
    'timeout' => [28, Reason::Timeout, FetchFailed::class, true],
    'unresolved' => [6, Reason::Unresolved, DestinationUnavailable::class, true],
    'tls' => [60, Reason::Tls, FetchFailed::class, false],
    'tls handshake' => [35, Reason::Connection, FetchFailed::class, true],
    'refused' => [7, Reason::Connection, FetchFailed::class, true],
]);

it('finds the curl code anywhere in the chain, and calls an answer without one unreadable', function (): void {
    $wrapped = new RuntimeException('outer', 0, new RuntimeException('cURL error 28: timed out'));

    expect(TransportFailure::curlCode($wrapped))->toBe(28)
        ->and(TransportFailure::from(new RuntimeException('Status code must be an integer value between 1xx and 5xx.'), 'https://docs.example/', 'docs.example')->reason())
        ->toBe(Reason::Protocol);
});

it('never names a url or address', function (string $locale): void {
    app()->setLocale($locale);
    $url = 'https://secret-host.internal:8443/token=abc';

    foreach (Reason::cases() as $reason) {
        $exception = $reason === Reason::Status
            ? FetchFailed::forStatus($url, 'secret-host.internal', 503)
            : DestinationRejected::because($reason, $url, 'secret-host.internal');

        expect($exception->getMessage())->not->toContain('secret-host')
            ->not->toContain('token=abc')
            ->not->toContain('10.0.0.5')
            ->not->toBeEmpty()
            ->and($exception->url())->toBe($url);
    }
})->with(['en', 'sk']);

it('keeps only the final body', function (): void {
    Http::fake([
        'docs.example/start' => Http::response('redirect body', 302, ['Location' => 'https://cdn.example/final']),
        'cdn.example/final' => Http::response('final body'),
    ]);

    $page = SafeFetch::fetch('https://docs.example/start', followRedirects: true);

    expect($page->body)->toBe('final body')
        ->and($page->url)->toBe('https://cdn.example/final');
});

it('refuses a host header', function (): void {
    Http::fake();

    expect(fn () => SafeFetch::fetch('https://docs.example/', headers: ['host' => 'inward.example']))
        ->toThrow(UnsafeRequestOptions::class, '[headers.Host]');
    Http::assertNothingSent();
});

it('follows any redirect for a get', function (int $status): void {
    Http::fake([
        'docs.example/start' => Http::response('', $status, ['Location' => '/final']),
        'docs.example/final' => Http::response('done'),
    ]);

    expect(SafeFetch::fetch('https://docs.example/start', followRedirects: true)->body)->toBe('done');
})->with([301, 302, 303, 307, 308]);

it('keeps the statuses a policy names when fetching', function (): void {
    config()->set('safe-fetch.redirects.enabled', true);
    config()->set('safe-fetch.redirects.statuses', [307]);
    Http::fake([
        'docs.example/start' => Http::response('', 301, ['Location' => '/final']),
        'docs.example/final' => Http::response('done'),
    ]);

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/start'));

    expect($failure instanceof FetchFailed ? $failure->status() : null)->toBe(301);
});

it('does not follow when told not to', function (): void {
    config()->set('safe-fetch.redirects.enabled', true);
    Http::fake(['docs.example/*' => Http::response('', 302, ['Location' => '/final'])]);

    expect(Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/', followRedirects: false))->reason())->toBe(Reason::Status);
});

it('vets every hop of a fetch', function (): void {
    Http::fake(['docs.example/*' => Http::response('', 302, ['Location' => 'https://inward.example/'])]);

    expect(Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/', followRedirects: true))->reason())->toBe(Reason::Blocked);
    Http::assertSentCount(1);
});

it('refuses a fetch it could not run as asked', function (Closure $fetch, string $message): void {
    expect($fetch)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no bytes' => [fn () => SafeFetch::fetch('https://docs.example/', maxBytes: 0), 'at least 1'],
    'no time' => [fn () => SafeFetch::fetch('https://docs.example/', timeout: 0), 'between 1 and 600'],
    'too long' => [fn () => SafeFetch::fetch('https://docs.example/', timeout: 601), 'between 1 and 600'],
]);

it('surfaces a refusal as the guard threw it', function (): void {
    Http::fake();

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://inward.example/'));

    expect($failure)->toBeInstanceOf(DestinationRejected::class)
        ->and($failure)->toBeInstanceOf(SafeFetchException::class);
});

it('maps a transport failure under the fake', function (): void {
    Http::fake(['docs.example/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://docs.example/'));

    expect($failure->reason())->toBe(Reason::Timeout)
        ->and($failure->host())->toBe('docs.example')
        ->and($failure->retryable())->toBeTrue();
});

it('decodes a fetched json body', function (): void {
    Http::fake([
        'docs.example/json' => Http::response(['data' => ['items' => [['id' => 7]], 'name' => 'feed']], 200),
        'docs.example/broken' => Http::response('{not json', 200),
        'docs.example/scalar' => Http::response('42', 200),
    ]);

    $page = SafeFetch::fetch('https://docs.example/json');
    $broken = SafeFetch::fetch('https://docs.example/broken');

    expect($page->json())->toBe(['data' => ['items' => [['id' => 7]], 'name' => 'feed']])
        ->and($page->json('data.name'))->toBe('feed')
        ->and($page->json('data.items.0.id'))->toBe(7)
        ->and($page->json('data.missing', 'fallback'))->toBe('fallback')
        ->and($page->json('data.name'))->toBe('feed')
        ->and($broken->json())->toBeNull()
        ->and($broken->json('a', 'fallback'))->toBe('fallback')
        ->and(SafeFetch::fetch('https://docs.example/scalar')->json())->toBe(42)
        ->and((new FetchedResponse(200, '', null, 'https://docs.example/', []))->json())->toBeNull();
});
