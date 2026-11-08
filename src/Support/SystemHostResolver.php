<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\Contracts\HostResolver;

/**
 * The default resolver: IPv4 through the system resolver (the hosts file included), IPv6 through
 * the name's AAAA records.
 *
 * PHP cannot put a deadline on either lookup, and an AAAA entry that only the hosts file holds is
 * not seen — both are documented limits.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
