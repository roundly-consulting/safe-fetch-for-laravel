<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\CappedStream;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\StreamedBody;
use RoundlyConsulting\SafeFetch\Support\StreamedSink;
use RoundlyConsulting\SafeFetch\Support\StreamedTransfer;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

/*
 * Streamed requests (`stream => true`) without the network: what the guard hands its curl handler,
 * and `Http::fake()` answering them under the same byte cap a real stream reads through.
 */

beforeEach(function (): void {
    if (! CurlSupport::preSendCheck()) {
        $this->markTestSkipped('streams only with the pre-send check (libcurl 7.80+)');
    }

    SafeFetch::fakeDns(['hooks.example' => '93.184.216.34']);
});

/** A streamed transfer that never sent anything, for its body's own behaviour. */
function idleTransfer(): StreamedTransfer
{
    $transfer = (new ReflectionClass(StreamedTransfer::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(StreamedTransfer::class, '__construct'))->invoke($transfer, SafeFetch::vet('https://hooks.example/'), null, null);

    return $transfer;
}

it('streams only with stream on, where the pre-send check runs', function (): void {
    expect(StreamedTransfer::wanted(['stream' => true]))->toBeTrue()
        ->and(StreamedTransfer::wanted(['stream' => false]))->toBeFalse()
        ->and(StreamedTransfer::wanted([]))->toBeFalse();

    CurlSupport::assume(guzzleVersion: '7.12.3');

    expect(StreamedTransfer::wanted(['stream' => true]))->toBeFalse();

    CurlSupport::forget();
});

it('hands a streamed hop its cap and idle limit, and no sink of the guards', function (array $options, ?int $cap, ?int $policyIdle, float $idle): void {
    $seen = [];
    Http::fake(function (Request $request, array $hop) use (&$seen) {
        $seen = $hop;

        return Http::response('ok');
    });
    $policy = SafeFetch::policy()->maxResponseBytes($cap)->readTimeout($policyIdle);

    Http::withOptions(['stream' => true, ...$options])->safe($policy)->get('https://hooks.example/');

    expect($seen[StreamedTransfer::MAX_BYTES])->toBe($cap)
        ->and($seen['read_timeout'])->toBe($idle)
        ->and($seen)->not->toHaveKey('sink');
})->with([
    'no limits' => [[], null, null, 0.0],
    'the policys' => [[], 4096, 30, 30.0],
    'the requests' => [['read_timeout' => 2.5], null, null, 2.5],
    'the shorter request' => [['read_timeout' => 2], 10, 30, 2.0],
    'the shorter policy' => [['read_timeout' => 60], 10, 30, 30.0],
    'a request without one' => [['read_timeout' => 0], null, 5, 5.0],
]);

it('leaves a hop without stream mode as it was', function (): void {
    $seen = [];
    Http::fake(function (Request $request, array $hop) use (&$seen) {
        $seen = $hop;

        return Http::response('ok');
    });

    Http::withOptions(['read_timeout' => 3])->safe(SafeFetch::policy()->maxResponseBytes(10)->readTimeout(1))->get('https://hooks.example/');

    expect($seen)->not->toHaveKey(StreamedTransfer::MAX_BYTES)
        ->and($seen['read_timeout'])->toBe(3)
        ->and($seen['sink'])->toBeResource();
});

it('answers a streamed request from the fake', function (): void {
    Http::fake(['hooks.example/*' => Http::response('all of it')]);

    $response = Http::withOptions(['stream' => true])->safe()->get('https://hooks.example/a');

    expect($response->body())->toBe('all of it');
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example/a');
});

it('reads a stubbed stream through the cap', function (): void {
    Http::fake(['hooks.example/*' => Http::response(str_repeat('x', 25))]);
    $policy = SafeFetch::policy()->maxResponseBytes(10);

    $body = Http::withOptions(['stream' => true])->safe($policy)->get('https://hooks.example/a')->toPsrResponse()->getBody();
    $first = $body->read(4);
    $second = $body->read(100);
    $failure = Helpers::refusal(fn () => $body->read(1));
    $whole = Helpers::refusal(fn () => Http::withOptions(['stream' => true])->safe($policy)->get('https://hooks.example/a')->body());

    expect($body)->toBeInstanceOf(CappedStream::class)
        ->and($first)->toBe('xxxx')
        ->and($second)->toBe('xxxxxx')
        ->and($body->tell())->toBe(10)
        ->and($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::TooLarge)
        ->and($whole->reason())->toBe(Reason::TooLarge);
});

it('reads a stubbed stream exactly at the cap whole', function (): void {
    Http::fake(['hooks.example/*' => Http::response(str_repeat('y', 10))]);

    $response = Http::withOptions(['stream' => true])->safe(SafeFetch::policy()->maxResponseBytes(10))->get('https://hooks.example/a');

    expect($response->body())->toBe(str_repeat('y', 10));
});

it('fails a stubbed stream that declares a body over the cap', function (): void {
    Http::fake(['hooks.example/*' => Http::response('tiny', 200, ['Content-Length' => '5000'])]);

    $failure = Helpers::refusal(fn () => Http::withOptions(['stream' => true])->safe(SafeFetch::policy()->maxResponseBytes(10))->get('https://hooks.example/a'));

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::TooLarge)
        ->and($failure->host())->toBe('hooks.example');
});

it('keeps a capped stub read only and unseekable', function (): void {
    $stream = new CappedStream(Utils::streamFor('abc'), 10, SafeFetch::vet('https://hooks.example/'));

    expect($stream->isSeekable())->toBeFalse()
        ->and($stream->isWritable())->toBeFalse()
        ->and($stream->isReadable())->toBeTrue()
        ->and($stream->getSize())->toBeNull()
        ->and($stream->getMetadata('mode'))->toBe('w+b')
        ->and((string) $stream)->toBe('abc')
        ->and($stream->eof())->toBeTrue()
        ->and(fn () => $stream->seek(0))->toThrow(RuntimeException::class)
        ->and(fn () => $stream->rewind())->toThrow(RuntimeException::class)
        ->and(fn () => $stream->write('x'))->toThrow(RuntimeException::class)
        ->and($stream->detach())->toBeResource();

    $closed = new CappedStream(Utils::streamFor('abc'), 10, SafeFetch::vet('https://hooks.example/'));
    $closed->close();

    expect($closed->isReadable())->toBeFalse();
});

it('keeps a streamed body read only and unseekable', function (): void {
    $body = new StreamedBody(idleTransfer());

    expect($body->isSeekable())->toBeFalse()
        ->and($body->isWritable())->toBeFalse()
        ->and($body->isReadable())->toBeTrue()
        ->and($body->getSize())->toBeNull()
        ->and($body->tell())->toBe(0)
        ->and($body->getMetadata())->toBe(['safe_fetch_streamed' => true])
        ->and($body->getMetadata('safe_fetch_streamed'))->toBeTrue()
        ->and($body->getMetadata('uri'))->toBeNull()
        ->and((string) $body)->toBe('')
        ->and($body->eof())->toBeTrue()
        ->and(fn () => $body->seek(0))->toThrow(RuntimeException::class)
        ->and(fn () => $body->rewind())->toThrow(RuntimeException::class)
        ->and(fn () => $body->write('x'))->toThrow(RuntimeException::class)
        ->and($body->detach())->toBeNull()
        ->and($body->isReadable())->toBeFalse()
        ->and($body->eof())->toBeTrue();
});

it('keeps the sink write only, for curl', function (): void {
    $transfer = idleTransfer();
    $sink = new StreamedSink($transfer);

    expect($sink->write('chunk'))->toBe(5)
        ->and($transfer->read(100))->toBe('chunk')
        ->and($sink->isWritable())->toBeTrue()
        ->and($sink->isReadable())->toBeFalse()
        ->and($sink->isSeekable())->toBeFalse()
        ->and($sink->getSize())->toBeNull()
        ->and((string) $sink)->toBe('')
        ->and($sink->eof())->toBeFalse()
        ->and($sink->detach())->toBeNull()
        ->and($sink->getMetadata())->toBe([])
        ->and($sink->getMetadata('uri'))->toBeNull()
        ->and(fn () => $sink->tell())->toThrow(RuntimeException::class)
        ->and(fn () => $sink->seek(0))->toThrow(RuntimeException::class)
        ->and(fn () => $sink->rewind())->toThrow(RuntimeException::class)
        ->and(fn () => $sink->read(1))->toThrow(RuntimeException::class)
        ->and(fn () => $sink->getContents())->toThrow(RuntimeException::class);

    $sink->close();
});

it('rejects a streamed hop curl refuses to start', function (): void {
    $vetted = SafeFetch::vet('https://hooks.example/');

    $result = Helpers::thrown(fn () => StreamedTransfer::send(new PsrRequest('GET', 'https://hooks.example/'), ['verify' => '/nowhere/ca-bundle.pem'], $vetted)->wait());

    expect($result)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($result->getMessage())->toContain('SSL CA bundle not found');
});

it('holds what curl writes past the cap back, and fails the read after it', function (): void {
    $transfer = (new ReflectionClass(StreamedTransfer::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(StreamedTransfer::class, '__construct'))->invoke($transfer, new VettedDestination('https://hooks.example/', 'https', 'hooks.example', 443, false, ['93.184.216.34']), 6, null);

    expect($transfer->receive('abcd'))->toBe(4)
        ->and($transfer->receive('efgh'))->toBe(2)
        ->and($transfer->eof())->toBeFalse()
        ->and($transfer->read(100))->toBe('abcdef')
        ->and(Helpers::refusal(fn () => $transfer->read(1))->reason())->toBe(Reason::TooLarge)
        ->and($transfer->eof())->toBeTrue();
});
