<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Handles;

use RoundlyConsulting\SafeFetch\Support\PrivateNetworks;

/**
 * The blocked-network table — `SafeFetch::networks()`. Pure: no policy, no lookup.
 */
final readonly class Networks
{
    /**
     * Whether no untrusted fetch may reach `$address`: private, loopback, link-local (cloud
     * metadata), carrier-grade NAT, multicast, documentation or reserved — an IPv6 address judged by
     * the IPv4 address it carries. Anything unparseable counts as private.
     */
    public function isPrivate(string $address): bool
    {
        return PrivateNetworks::isPrivate($address);
    }

    /** Whether a host name always means the machine itself (`localhost`, `*.localhost`). */
    public function isBlockedHostname(string $host): bool
    {
        return PrivateNetworks::isBlockedHostname($host);
    }

    /**
     * Every blocked range, IPv4 and IPv6.
     *
     * @return list<string>
     */
    public function blockedRanges(): array
    {
        return PrivateNetworks::BLOCKED;
    }
}
