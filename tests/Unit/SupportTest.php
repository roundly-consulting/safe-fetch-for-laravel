<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Support\CappedSink;
use RoundlyConsulting\SafeFetch\Support\CurlOptions;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\SystemHostResolver;

it('resolves through the system resolver', function (): void {
    $resolver = new SystemHostResolver;

    expect($resolver->resolve('localhost'))->toContain('127.0.0.1')
        ->and($resolver->resolve('nothing-here.invalid'))->toBe([]);
});

it('caps what it keeps and resets between hops', function (): void {
    $sink = CappedSink::open(5);

    expect(fwrite($sink->resource, 'abc'))->toBe(3)
        ->and(fwrite($sink->resource, 'defgh'))->toBe(2)
        ->and($sink->exceeded)->toBeTrue()
        ->and($sink->write('more'))->toBe(0)
        ->and($sink->contents())->toBe('abcde')
        ->and(fstat($sink->resource))->toBeArray()
        ->and(fflush($sink->resource))->toBeTrue()
        ->and(ftruncate($sink->resource, 2))->toBeTrue()
        ->and(rewind($sink->resource))->toBeTrue()
        ->and(fread($sink->resource, 10))->toBe('ab')
        ->and(feof($sink->resource))->toBeTrue()
        ->and(ftell($sink->resource))->toBe(2);

    $sink->reset();

    expect($sink->exceeded)->toBeFalse()
        ->and($sink->written)->toBe(0)
        ->and($sink->contents())->toBe('')
        ->and(fclose($sink->resource))->toBeTrue();
});

it('names curl options and keeps credentials apart', function (): void {
    expect(CurlOptions::nameOf(CURLOPT_TCP_KEEPALIVE))->toBe('CURLOPT_TCP_KEEPALIVE')
        ->and(CurlOptions::nameOf('weird'))->toBe('weird')
        ->and(CurlOptions::nameOf(-12345))->toBe('-12345')
        ->and(CurlOptions::callerMay(CURLOPT_TCP_KEEPALIVE))->toBeTrue()
        ->and(CurlOptions::callerMay(CURLOPT_RESOLVE))->toBeFalse()
        ->and(CurlOptions::withoutCredentials([CURLOPT_HTTPAUTH => 1, CURLOPT_USERPWD => 'a:b', CURLOPT_TCP_KEEPALIVE => 1]))
        ->toBe([CURLOPT_TCP_KEEPALIVE => 1]);
});

it('reports the curl it runs on', function (): void {
    expect(CurlSupport::available())->toBeTrue()
        ->and(CurlSupport::curlVersion())->toMatch('/^\d+\.\d+/');

    CurlSupport::assume(available: false);

    expect(CurlSupport::available())->toBeFalse()
        ->and(CurlSupport::preSendCheck())->toBeFalse();
});

it('says what was refused even without a translator', function (): void {
    $previous = Container::getInstance();
    Container::setInstance(new Container);

    try {
        $message = DestinationRejected::because(Reason::Blocked, 'https://10.0.0.1/')->getMessage();
    } finally {
        Container::setInstance($previous);
    }

    expect($message)->toBe('The request was refused (blocked).');
});

it('knows which reasons can heal', function (): void {
    $retryable = array_values(array_filter(Reason::cases(), static fn (Reason $reason): bool => $reason->retryable()));

    expect($retryable)->toBe([Reason::Unresolved, Reason::Timeout, Reason::Connection])
        ->and(UnsafeRequestOptions::option('proxy', 'why.')->getMessage())->toBe('A guarded request cannot use the [proxy] option: why.');
});

it('keeps the verdict distinguishable for code', function (): void {
    $blocked = DestinationRejected::because(Reason::Blocked, 'https://intranet.example/', 'intranet.example', 'private_address');
    $unresolved = DestinationUnavailable::unresolved('https://nowhere.example/', 'nowhere.example');

    expect($blocked->getMessage())->toBe($unresolved->getMessage())
        ->and($blocked->reason())->toBe(Reason::Blocked)
        ->and($unresolved->reason())->toBe(Reason::Unresolved)
        ->and($blocked->retryable())->toBeFalse()
        ->and($unresolved->retryable())->toBeTrue()
        ->and($blocked->blockedBy())->toBe('private_address');
});
