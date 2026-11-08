<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;

/**
 * @return array<string, string>
 */
function safeFetchLines(string $locale, string $file): array
{
    return Arr::dot(require __DIR__."/../../resources/lang/{$locale}/{$file}.php");
}

/**
 * @return list<string>
 */
function placeholdersOf(string $line): array
{
    preg_match_all('/:([a-z_]+)/', $line, $matches);
    $placeholders = $matches[1];
    sort($placeholders);

    return $placeholders;
}

it('ships the same keys and placeholders in english and slovak', function (string $file): void {
    $en = safeFetchLines('en', $file);
    $sk = safeFetchLines('sk', $file);

    expect(array_keys($sk))->toBe(array_keys($en));

    foreach ($en as $key => $line) {
        expect(placeholdersOf($sk[$key]))->toBe(placeholdersOf($line), "placeholders of {$file}.{$key}");
    }
})->with(['messages', 'validation']);

it('has one message per reason', function (): void {
    $keys = array_keys(safeFetchLines('en', 'messages'));
    $reasons = array_map(static fn (Reason $reason): string => $reason->value, Reason::cases());
    sort($keys);
    sort($reasons);

    expect($keys)->toBe($reasons)->and($keys)->toHaveCount(17);
});

it('speaks slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('safe-fetch::messages.blocked'))->toBe('URL adresu nie je možné načítať: jej cieľ nie je povolený alebo nie je dostupný.')
        ->and(DestinationRejected::because(Reason::Port, 'https://x.example:1/')->getMessage())->toBe('URL adresu nie je možné načítať: jej port nie je povolený.');
});

it('gives blocked and unresolved the same message in every locale', function (string $locale, string $message): void {
    app()->setLocale($locale);

    $blocked = DestinationRejected::because(Reason::Blocked, 'https://intranet.example/', 'intranet.example', 'private_address');
    $unresolved = DestinationUnavailable::unresolved('https://nowhere.example/', 'nowhere.example');

    expect($blocked->getMessage())->toBe($message)
        ->and($unresolved->getMessage())->toBe($message)
        ->and(__('safe-fetch::messages.unresolved'))->toBe(__('safe-fetch::messages.blocked'));
})->with([
    ['en', 'The URL cannot be fetched: its destination is not allowed or cannot be reached.'],
    ['sk', 'URL adresu nie je možné načítať: jej cieľ nie je povolený alebo nie je dostupný.'],
]);
