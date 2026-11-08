<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\Support\HostPattern;

it('matches a wildcard against subdomains only', function (string $host, bool $matches): void {
    expect(HostPattern::matches($host, [HostPattern::normalise('*.example.com')]))->toBe($matches);
})->with([
    ['a.example.com', true],
    ['deep.a.example.com', true],
    ['A.EXAMPLE.COM.', true],
    ['example.com', false],
    ['badexample.com', false],
    ['example.com.evil', false],
]);

it('matches an exact name in any case, with or without a trailing dot', function (): void {
    $patterns = [HostPattern::normalise('Hooks.Example.COM.')];

    expect($patterns)->toBe(['hooks.example.com'])
        ->and(HostPattern::matches('hooks.example.com', $patterns))->toBeTrue()
        ->and(HostPattern::matches('hooks.example.com.', $patterns))->toBeTrue()
        ->and(HostPattern::matches('a.hooks.example.com', $patterns))->toBeFalse();
});

it('compares an international name as punycode', function (): void {
    expect(HostPattern::normalise('bücher.example'))->toBe('xn--bcher-kva.example')
        ->and(HostPattern::normalise('*.bücher.example'))->toBe('*.xn--bcher-kva.example');
});

it('refuses entries that are not host names', function (string $entry): void {
    expect(HostPattern::isValid($entry))->toBeFalse()
        ->and(fn () => HostPattern::normalise($entry))->toThrow(InvalidArgumentException::class);
})->with(['*', '*.', '', 'https://example.com', 'example.com:443', 'example.com/x', '10.0.0.1', 'a.*.example.com', 'user@example.com', 'exa mple.com', '[::1]', 'a..example', 'exa%mple.com', '-'.str_repeat('a', 64).'.example']);
