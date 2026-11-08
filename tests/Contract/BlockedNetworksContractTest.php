<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\PrivateNetworks;
use RoundlyConsulting\SafeFetch\Support\RedirectLocation;
use RoundlyConsulting\SafeFetch\Tests\Support\ContractPolicy;

/*
 * The blocked-network contract. This package holds the reference copy:
 * `tests/Fixtures/contract/blocked-networks.json`. Other implementations of the contract fixture
 * use it byte-identical and run every vector, so no implementation can disagree about what a
 * server may dial.
 *
 * The URL vectors run with the contract policy (http + https, any port, no lists) and the
 * fixture's own DNS: a name it does not list does not resolve.
 */

/** @return array<string, mixed> */
function blockedNetworksContract(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contract/blocked-networks.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('holds exactly the contract table', function (): void {
    $contract = blockedNetworksContract();
    $sorted = static function (array $list): array {
        sort($list);

        return $list;
    };

    expect($contract['version'])->toBe(1)
        ->and($sorted(PrivateNetworks::BLOCKED))->toBe($sorted($contract['blocked_ranges']))
        ->and(PrivateNetworks::IPV4_EMBEDDING)->toBe($contract['ipv4_embedding'])
        ->and($sorted(PrivateNetworks::BLOCKED_HOSTNAMES))->toBe($sorted($contract['blocked_hostnames']))
        ->and($sorted(PrivateNetworks::BLOCKED_HOSTNAME_SUFFIXES))->toBe($sorted($contract['blocked_hostname_suffixes']))
        ->and(PrivateNetworks::BLOCKED)->toHaveCount(26);
});

it('judges every contract address', function (string $address, bool $blocked): void {
    expect(PrivateNetworks::isPrivate($address))->toBe($blocked)
        ->and(SafeFetch::networks()->isPrivate($address))->toBe($blocked);
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['addresses'] as $vector) {
        $vectors['"'.$vector['address'].'"'] = [$vector['address'], $vector['blocked']];
    }

    return $vectors;
});

it('gives every contract url its verdict', function (string $url, string $verdict): void {
    SafeFetch::fakeDns(blockedNetworksContract()['dns']);

    try {
        $vetted = SafeFetch::vet($url, ContractPolicy::make());
        $actual = 'ok';
    } catch (SafeFetchException $exception) {
        $actual = $exception->reason()->value;
    }

    expect($actual)->toBe($verdict);

    if ($actual === 'ok') {
        expect($vetted)->toBeInstanceOf(VettedDestination::class);
    }
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['urls'] as $vector) {
        $vectors['"'.$vector['url'].'"'] = [$vector['url'], $vector['verdict']];
    }

    return $vectors;
});

it('recognises the same redirect statuses', function (): void {
    expect(RedirectLocation::STATUSES)->toBe(blockedNetworksContract()['redirect_statuses']);
});

it('resolves every contract redirect location the same way', function (string $base, string $location, ?string $next, ?string $refused): void {
    try {
        $actual = SafeFetch::urls()->resolveRedirect($base, $location);
    } catch (SafeFetchException $exception) {
        $actual = null;
        expect($exception->reason()->value)->toBe($refused);
    }

    expect($actual)->toBe($next);
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['redirects'] as $vector) {
        $vectors[json_encode([$vector['base'], $vector['location']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = [
            $vector['base'], $vector['location'], $vector['next'] ?? null, $vector['refused'] ?? null,
        ];
    }

    return $vectors;
});

it('covers every verdict and the edges the plan asks for', function (): void {
    $contract = blockedNetworksContract();
    $verdicts = array_unique(array_column($contract['urls'], 'verdict'));
    sort($verdicts);

    expect($verdicts)->toBe(['blocked', 'credentials', 'invalid_url', 'non_canonical_host', 'ok', 'port', 'scheme', 'unresolved'])
        ->and(count($contract['addresses']))->toBeGreaterThanOrEqual(100)
        ->and(array_filter($contract['addresses'], static fn (array $vector): bool => $vector['blocked']))->not->toBeEmpty()
        ->and(array_filter($contract['addresses'], static fn (array $vector): bool => ! $vector['blocked']))->not->toBeEmpty()
        ->and($contract['urls'])->toHaveCount(84)
        ->and($contract['redirects'])->toHaveCount(24);
});

it('holds only the shared fixture in the contract dir', function (): void {
    // Every file here is shared with other implementations of the contract: nothing Laravel-only.
    $files = array_values(array_diff(scandir(__DIR__.'/../Fixtures/contract') ?: [], ['.', '..']));

    expect($files)->toBe(['blocked-networks.json']);
});
