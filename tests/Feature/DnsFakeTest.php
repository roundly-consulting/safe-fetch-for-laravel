<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;
use RoundlyConsulting\SafeFetch\Testing\FakeHostLookup;
use RoundlyConsulting\SafeFetch\Testing\FakeHostResolver;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

/*
 * `SafeFetch::fakeDns()`: the public way for a host's tests to answer the guard's DNS lookups —
 * the surface media-library's DnsFake had, through the public API only.
 */

it('fake dns resolves a pinned host so fetch() succeeds under Http::fake()', function (): void {
    SafeFetch::fakeDns()->resolve('docs.example', '93.184.216.34');
    Http::fake(['docs.example/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain'])]);

    $page = SafeFetch::fetch('https://docs.example/readme.txt');

    expect($page->body)->toBe('hello')
        ->and($page->url)->toBe('https://docs.example/readme.txt')
        ->and(SafeFetch::vet('https://docs.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(app(HostResolver::class))->toBeInstanceOf(FakeHostLookup::class);
    Http::assertSentCount(1);
});

it('fake dns refuses a host pinned to a private address before any request', function (string $address): void {
    SafeFetch::fakeDns()->resolve('intranet.example', $address);
    Http::fake();

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://intranet.example/'));

    expect($failure)->toBeInstanceOf(DestinationRejected::class)
        ->and($failure->reason())->toBe(Reason::Blocked);
    Http::assertNothingSent();
})->with([
    'rfc 1918' => ['10.0.0.5'],
    'loopback' => ['127.0.0.1'],
    'metadata' => ['169.254.169.254'],
    'ipv6 loopback' => ['::1'],
]);

it('fake dns treats a name it was not given as unresolvable', function (): void {
    SafeFetch::fakeDns()->resolve('docs.example', '93.184.216.34');
    Http::fake();

    $failure = Helpers::refusal(fn () => SafeFetch::fetch('https://elsewhere.example/'));

    expect($failure)->toBeInstanceOf(DestinationUnavailable::class)
        ->and($failure->reason())->toBe(Reason::Unresolved)
        ->and($failure->retryable())->toBeTrue();
    Http::assertNothingSent();
});

it('fake dns turns a pinned name unresolvable again', function (): void {
    $dns = SafeFetch::fakeDns()->resolve('docs.example', '93.184.216.34');

    expect($dns->unresolvable('docs.example'))->toBe($dns)
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://docs.example/'))->reason())->toBe(Reason::Unresolved);
});

it('fake dns takes a table of records', function (): void {
    SafeFetch::fakeDns([
        'docs.example' => ['93.184.216.34', '2606:2800:0220:0001:0248:1893:25c8:1946'],
        'cdn.example' => '151.101.1.1',
        'gone.example' => [],
    ]);

    expect(SafeFetch::vet('https://docs.example/')->addresses)->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
        ->and(SafeFetch::vet('https://cdn.example/')->addresses)->toBe(['151.101.1.1'])
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://gone.example/'))->reason())->toBe(Reason::Unresolved);
});

it('fake dns adds pins up across calls and lets a later pin replace an earlier one', function (): void {
    $first = SafeFetch::fakeDns(['docs.example' => '93.184.216.34']);
    $second = SafeFetch::fakeDns(['cdn.example' => '151.101.1.1']);
    $second->resolve('docs.example', ['151.101.1.2', '151.101.1.2']);

    expect($second)->toBe($first)
        ->and(SafeFetch::vet('https://docs.example/')->addresses)->toBe(['151.101.1.2'])
        ->and(SafeFetch::vet('https://cdn.example/')->addresses)->toBe(['151.101.1.1']);
});

it('fake dns matches names the way the guard writes them', function (): void {
    SafeFetch::fakeDns()->resolve('Docs.EXAMPLE.', '93.184.216.34')->resolve('bücher.example', '93.184.216.35');

    expect(SafeFetch::vet('https://docs.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(SafeFetch::vet('https://DOCS.example./')->addresses)->toBe(['93.184.216.34'])
        ->and(SafeFetch::vet('https://xn--bcher-kva.example/')->addresses)->toBe(['93.184.216.35']);
});

it('fake dns may pin localhost, which a trusted private host looks up', function (): void {
    config()->set('app.url', 'https://myapp.test');
    SafeFetch::fakeDns(['localhost' => '127.0.0.1', 'app.localhost' => '127.0.0.1']);
    $policy = SafeFetch::policy()->trustPrivateHosts(['localhost', '*.localhost']);

    expect(SafeFetch::vet('https://localhost/', $policy)->addresses)->toBe(['127.0.0.1'])
        ->and(SafeFetch::vet('https://app.localhost/', $policy)->addresses)->toBe(['127.0.0.1']);
});

it('fake dns refuses a pin it could never be asked for, or an address that is not one', function (string $host, mixed $addresses, string $message): void {
    expect(fn () => SafeFetch::fakeDns()->resolve($host, $addresses))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an address literal' => ['93.184.216.34', '93.184.216.34', 'address'],
    'a bracketed ipv6 literal' => ['[::1]', '93.184.216.34', 'host name'],
    'a port' => ['docs.example:8080', '93.184.216.34', 'host name'],
    'a path' => ['docs.example/x', '93.184.216.34', 'host name'],
    'credentials' => ['user@docs.example', '93.184.216.34', 'host name'],
    'an empty name' => ['', '93.184.216.34', 'host name'],
    'a name the guard refuses' => ['docs..example', '93.184.216.34', 'host name'],
    'no address' => ['docs.example', [], 'unresolvable()'],
    'not an address' => ['docs.example', 'docs.example', 'not an IP address'],
    'a bracketed address' => ['docs.example', '[::1]', 'not an IP address'],
    'a non-string address' => ['docs.example', [42], 'not an IP address'],
]);

it('fake dns refuses an all-digit name in the table, not a TypeError', function (): void {
    expect(fn () => SafeFetch::fakeDns(['123' => '93.184.216.34']))
        ->toThrow(InvalidArgumentException::class, 'Cannot pin [123]: not a host name the guard would look up.')
        ->and(fn () => SafeFetch::fakeDns()->unresolvable('10.0.0.1'))->toThrow(InvalidArgumentException::class);
});

it('fake dns answers the rule through the same table', function (): void {
    SafeFetch::fakeDns(['docs.example' => '93.184.216.34', 'intranet.example' => '10.0.0.5']);

    $passes = static fn (string $url): bool => Validator::make(['url' => $url], ['url' => [new SafeUrl]])->passes();

    expect($passes('https://docs.example/a'))->toBeTrue()
        ->and($passes('https://intranet.example/a'))->toBeFalse()
        ->and($passes('https://unknown.example/a'))->toBeFalse();
});

it('fake dns never looks up an address literal', function (): void {
    $dns = SafeFetch::fakeDns();
    Http::fake(['*' => Http::response('ok', 200)]);

    expect(SafeFetch::fetch('https://93.184.216.34/')->body)->toBe('ok');
    $dns->assertNothingLookedUp();
});

it('fake dns records every name looked up, in order', function (): void {
    $dns = SafeFetch::fakeDns(['docs.example' => '93.184.216.34']);

    SafeFetch::vet('https://docs.example/a');
    SafeFetch::vet('https://DOCS.example/b');
    Helpers::refusal(fn () => SafeFetch::vet('https://unknown.example/'));

    expect($dns->lookups())->toBe(['docs.example', 'docs.example', 'unknown.example']);

    $dns->assertLookedUp('docs.example');
    $dns->assertLookedUp('Docs.Example.', times: 2);
    $dns->assertLookedUp('unknown.example', times: 1);
    $dns->assertNotLookedUp('cdn.example');
});

it('fake dns fails assertLookedUp for a name never looked up, or a different count', function (): void {
    $dns = SafeFetch::fakeDns(['docs.example' => '93.184.216.34']);
    SafeFetch::vet('https://docs.example/');

    expect(fn () => $dns->assertLookedUp('cdn.example'))
        ->toThrow(ExpectationFailedException::class, 'Expected [cdn.example] to be looked up, but it was not.')
        ->and(fn () => $dns->assertLookedUp('docs.example', times: 2))
        ->toThrow(ExpectationFailedException::class, 'Expected [docs.example] to be looked up 2 time(s), but it was looked up 1 time(s).');
});

it('fake dns fails assertNotLookedUp and assertNothingLookedUp once a name was looked up', function (): void {
    $dns = SafeFetch::fakeDns();
    $dns->assertNothingLookedUp();
    $dns->assertNotLookedUp('docs.example');

    Helpers::refusal(fn () => SafeFetch::vet('https://docs.example/'));

    expect(fn () => $dns->assertNotLookedUp('docs.example'))
        ->toThrow(ExpectationFailedException::class, 'Expected [docs.example] not to be looked up, but it was looked up 1 time(s).')
        ->and(fn () => $dns->assertNothingLookedUp())
        ->toThrow(ExpectationFailedException::class, 'Expected no DNS lookups, but 1 were made: docs.example.');
});

it('fake dns ends with the test', function (): void {
    expect(app()->bound(FakeHostResolver::class))->toBeFalse();

    $dns = SafeFetch::fakeDns();

    expect($dns->lookups())->toBe([])
        ->and(app(FakeHostResolver::class))->toBe($dns);
});
