<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\AddressRanges;
use RoundlyConsulting\SafeFetch\Support\PrivateNetworks;
use RoundlyConsulting\SafeFetch\Tests\Support\ContractPolicy;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

it('refuses every cloud metadata address', function (string $address): void {
    $literal = str_contains($address, ':') ? "[{$address}]" : $address;

    $asLiteral = Helpers::refusal(fn () => SafeFetch::vet("http://{$literal}/latest/meta-data/", ContractPolicy::make()));
    SafeFetch::fakeDns(['metadata.example' => $address]);
    $asAnswer = Helpers::refusal(fn () => SafeFetch::vet('http://metadata.example/', ContractPolicy::make()));

    expect($asLiteral->reason())->toBe(Reason::Blocked)
        ->and($asAnswer->reason())->toBe(Reason::Blocked)
        ->and($asAnswer)->toBeInstanceOf(DestinationRejected::class)
        ->and($asAnswer instanceof DestinationRejected ? $asAnswer->blockedBy() : null)->toBe('private_address');
})->with([
    'aws, gcp, azure, openstack' => ['169.254.169.254'],
    'aws over ipv6' => ['fd00:ec2::254'],
    'alibaba' => ['100.100.100.200'],
    'oracle' => ['192.0.0.192'],
    'azure wireserver' => ['168.63.129.16'],
]);

it('judges ipv6 that carries ipv4', function (string $address, bool $private): void {
    expect(PrivateNetworks::isPrivate($address))->toBe($private);
})->with([
    'teredo wholesale' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', true],
    'teredo of a public address' => ['2001:0:4136:e378::1', true],
    '6to4 of metadata' => ['2002:a9fe:a9fe::', true],
    '6to4 of a public address' => ['2002:808:808::', false],
    'nat64 of 8.8.8.8' => ['64:ff9b::808:808', false],
    'nat64 of loopback' => ['64:ff9b::7f00:1', true],
    'mapped loopback' => ['::ffff:127.0.0.1', true],
    'mapped public' => ['::ffff:8.8.8.8', false],
    'translated metadata' => ['::ffff:0:a9fe:a9fe', true],
]);

it('names the ipv4 address an ipv6 one carries', function (): void {
    expect(PrivateNetworks::carriedIpv4('::ffff:127.0.0.1'))->toBe('127.0.0.1')
        ->and(PrivateNetworks::carriedIpv4('2002:808:808::'))->toBe('8.8.8.8')
        ->and(PrivateNetworks::carriedIpv4('2606:4700::1111'))->toBeNull()
        ->and(PrivateNetworks::carriedIpv4('8.8.8.8'))->toBeNull()
        ->and(PrivateNetworks::carriedIpv4('nonsense'))->toBeNull();
});

it('ignores a zone id on an address but refuses it in a url', function (): void {
    expect(PrivateNetworks::isPrivate('fe80::1%eth0'))->toBeTrue()
        ->and(PrivateNetworks::isPrivate('2606:4700::1111%eth0'))->toBeFalse()
        ->and(Helpers::refusal(fn () => SafeFetch::vet('http://[fe80::1%25eth0]/', ContractPolicy::make()))->reason())
        ->toBe(Reason::NonCanonicalHost);
});

it('counts anything unparseable as private', function (string $address): void {
    expect(PrivateNetworks::isPrivate($address))->toBeTrue();
})->with(['', 'localhost', '0177.0.0.1', '2130706433', '[::1]', '1.2.3', 'gggg::1', '8.8.8.8 ']);

it('knows the names that always mean the machine itself', function (string $host, bool $blocked): void {
    expect(PrivateNetworks::isBlockedHostname($host))->toBe($blocked)
        ->and(SafeFetch::networks()->isBlockedHostname($host))->toBe($blocked);
})->with([
    ['localhost', true],
    ['LOCALHOST.', true],
    ['app.localhost', true],
    ['localhost.example', false],
    ['mylocalhost', false],
]);

it('lists the blocked ranges through the facade', function (): void {
    expect(SafeFetch::networks()->blockedRanges())->toBe(PrivateNetworks::BLOCKED);
});

it('validates and matches address ranges', function (): void {
    expect(AddressRanges::isValid('10.0.0.0/8'))->toBeTrue()
        ->and(AddressRanges::isValid('10.0.0.1'))->toBeTrue()
        ->and(AddressRanges::isValid('::1/128'))->toBeTrue()
        ->and(AddressRanges::isValid('::1/129'))->toBeFalse()
        ->and(AddressRanges::isValid('10.0.0.0/33'))->toBeFalse()
        ->and(AddressRanges::isValid('10.0.0.0/x'))->toBeFalse()
        ->and(AddressRanges::isValid('10.0.0.0/0008'))->toBeFalse()
        ->and(AddressRanges::isValid('example.com'))->toBeFalse()
        ->and(AddressRanges::contains(['10.0.0.0/8'], '10.20.30.40'))->toBeTrue()
        ->and(AddressRanges::contains(['10.0.0.0/9'], '10.200.0.1'))->toBeFalse()
        ->and(AddressRanges::contains(['127.0.0.1'], '::ffff:127.0.0.1'))->toBeTrue()
        ->and(AddressRanges::contains(['::1'], '::1'))->toBeTrue()
        ->and(AddressRanges::contains(['10.0.0.0/8'], 'nonsense'))->toBeFalse()
        ->and(AddressRanges::prefixLength('::/0'))->toBe(0)
        ->and(AddressRanges::prefixLength('10.0.0.1'))->toBe(32)
        ->and(AddressRanges::isIpv6('fd00::/8'))->toBeTrue()
        ->and(AddressRanges::bytesInRange((string) inet_pton('10.0.0.1'), 'nonsense/8'))->toBeFalse();
});

it('treats a mapped address as its ipv4', function (): void {
    expect(AddressRanges::same('::ffff:127.0.0.1', '127.0.0.1'))->toBeTrue()
        ->and(AddressRanges::same('127.0.0.1', '::FFFF:7f00:1'))->toBeTrue()
        ->and(AddressRanges::same('::1', '127.0.0.1'))->toBeFalse()
        ->and(AddressRanges::same('64:ff9b::7f00:1', '127.0.0.1'))->toBeFalse()
        ->and(AddressRanges::same('nonsense', 'nonsense'))->toBeFalse();
});
