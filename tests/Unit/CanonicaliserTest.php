<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\UrlCanonicaliser;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

it('rebuilds the url it sends', function (string $url, string $rebuilt, string $origin): void {
    $canonical = SafeFetch::urls()->canonicalise($url);

    expect($canonical->toString())->toBe($rebuilt)
        ->and($canonical->origin())->toBe($origin);
})->with([
    'fragment dropped' => ['https://example.com/a?b=c#frag', 'https://example.com/a?b=c', 'https://example.com:443'],
    'default port dropped' => ['https://example.com:443/', 'https://example.com/', 'https://example.com:443'],
    'leading zero port' => ['http://example.com:0080/', 'http://example.com/', 'http://example.com:80'],
    'other port kept' => ['http://example.com:8080', 'http://example.com:8080/', 'http://example.com:8080'],
    'trailing dot dropped' => ['https://Example.COM./x', 'https://example.com/x', 'https://example.com:443'],
    'scheme lowercased' => ['HTTPS://example.com/Path', 'https://example.com/Path', 'https://example.com:443'],
    'ipv6 compressed' => ['http://[0:0:0:0:0:0:0:1]:8080/', 'http://[::1]:8080/', 'http://[::1]:8080'],
    'empty path' => ['https://example.com?x=1', 'https://example.com/?x=1', 'https://example.com:443'],
]);

it('reads the host before a fragment the way curl does', function (): void {
    expect(SafeFetch::urls()->canonicalise('http://127.0.0.1#@a.com/')->host)->toBe('127.0.0.1');
});

it('canonicalises through the facade', function (): void {
    $canonical = SafeFetch::urls()->canonicalise('https://[2606:4700::1111]/x');

    expect($canonical)->toBeInstanceOf(CanonicalUrl::class)
        ->and($canonical->host)->toBe('2606:4700::1111')
        ->and($canonical->ipLiteral)->toBeTrue()
        ->and($canonical->port)->toBe(443)
        ->and(Helpers::refusal(fn () => SafeFetch::urls()->canonicalise('ftp://example.com/'))->reason())->toBe(Reason::Scheme)
        ->and(Helpers::refusal(fn () => SafeFetch::urls()->canonicalise('http://user:pw@example.com/'))->reason())->toBe(Reason::Credentials);
});

it('needs intl for an international host', function (): void {
    $refusal = Helpers::refusal(fn () => (new UrlCanonicaliser(intl: false))->canonicalise('https://bücher.example/'));

    expect($refusal->reason())->toBe(Reason::NonCanonicalHost)
        ->and((new UrlCanonicaliser(intl: false))->canonicalise('https://example.com/')->host)->toBe('example.com')
        ->and((new UrlCanonicaliser(intl: true))->canonicalise('https://bücher.example/')->host)->toBe('xn--bcher-kva.example');
});

it('bounds labels and host length', function (): void {
    $label63 = str_repeat('a', 63);
    $host253 = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)]);

    expect(SafeFetch::urls()->canonicalise("https://{$label63}.example/")->host)->toBe("{$label63}.example")
        ->and(Helpers::refusal(fn () => SafeFetch::urls()->canonicalise('https://'.str_repeat('a', 64).'.example/'))->reason())->toBe(Reason::NonCanonicalHost)
        ->and(strlen($host253))->toBe(253)
        ->and(SafeFetch::urls()->canonicalise("https://{$host253}/")->host)->toBe($host253)
        ->and(Helpers::refusal(fn () => SafeFetch::urls()->canonicalise("https://{$host253}a/"))->reason())->toBe(Reason::NonCanonicalHost);
});

it('refuses what parsers read two ways', function (string $url, Reason $reason): void {
    expect(Helpers::refusal(fn () => SafeFetch::urls()->canonicalise($url))->reason())->toBe($reason);
})->with([
    'backslash at sign' => ['http://a.com\\@127.0.0.1/', Reason::NonCanonicalHost],
    'no scheme' => ['//example.com/', Reason::InvalidUrl],
    'no authority' => ['http:example.com', Reason::InvalidUrl],
    'empty authority' => ['http:///x', Reason::InvalidUrl],
    'tab' => ["http://exa\tmple.com/", Reason::InvalidUrl],
    'backslash in path' => ['https://example.com/a\\b', Reason::InvalidUrl],
    'non-digit port' => ['https://example.com:8o/', Reason::InvalidUrl],
    'port zero' => ['https://example.com:0/', Reason::Port],
    'port too big' => ['https://example.com:65536/', Reason::Port],
    'unbracketed ipv6' => ['http://::1/', Reason::NonCanonicalHost],
    'stray bracket' => ['http://a]b.com/', Reason::NonCanonicalHost],
    'junk after bracket' => ['http://[::1]x/', Reason::NonCanonicalHost],
    'unclosed bracket' => ['http://[::1/', Reason::NonCanonicalHost],
    'bracketed ipv4' => ['http://[127.0.0.1]/', Reason::NonCanonicalHost],
    'empty host with port' => ['http://:80/', Reason::InvalidUrl],
    'decimal ipv4' => ['http://2130706433/', Reason::NonCanonicalHost],
    'hex ipv4' => ['http://0x7f.0.0.1/', Reason::NonCanonicalHost],
    'octal ipv4' => ['http://0177.0.0.1/', Reason::NonCanonicalHost],
    'short ipv4' => ['http://127.1/', Reason::NonCanonicalHost],
    'big octet' => ['http://256.0.0.1/', Reason::NonCanonicalHost],
    'fullwidth ipv4' => ['http://８.８.８.８/', Reason::NonCanonicalHost],
    'invalid punycode' => ['http://xn--localhost/', Reason::NonCanonicalHost],
    'nbsp' => ["http://exa\u{00A0}mple.com/", Reason::NonCanonicalHost],
    'percent host' => ['http://%6c%6f%63%61%6c%68%6f%73%74/', Reason::NonCanonicalHost],
]);
