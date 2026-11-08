<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

/**
 * The addresses no untrusted URL may reach: private, loopback, link-local (cloud metadata at
 * 169.254.169.254), carrier-grade NAT, multicast, documentation and reserved ranges, IPv4 and IPv6
 * alike, plus the host names that always mean the machine itself. An IPv6 address that carries an
 * IPv4 one (IPv4-mapped `::ffff:a.b.c.d`, IPv4-compatible, NAT64, IPv4-translated, 6to4) is judged
 * by the IPv4 address it carries.
 *
 * This table is a contract: `tests/Fixtures/contract/blocked-networks.json` holds it and its
 * vectors, byte-identical in the Node twin (safe-fetch-for-node), so both sides block the same
 * destinations. Change them together.
 *
 * Pure and static — no configuration is read here. The policy (allowed and blocked ranges, private
 * hosts) is applied on top of it by the guard.
 */
final class PrivateNetworks
{
    /** @var list<string> */
    public const array BLOCKED = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT (Alibaba Cloud metadata at 100.100.100.200)
        '127.0.0.0/8',      // loopback
        '168.63.129.16/32', // Azure WireServer: public address space, reachable from every Azure VM and AKS node
        '169.254.0.0/16',   // link-local: AWS, GCP, Azure, OpenStack metadata at 169.254.169.254
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments (Oracle Cloud metadata at 192.0.0.192)
        '192.0.2.0/24',     // documentation
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, broadcast
        '64:ff9b:1::/48',   // NAT64, local use
        '100::/64',         // discard
        '2001::/23',        // IETF protocol assignments (Teredo included)
        '2001:db8::/32',    // documentation
        '3fff::/20',        // documentation (RFC 9637)
        '5f00::/16',        // SRv6 SIDs (RFC 9602)
        'fc00::/7',         // unique local (AWS metadata over IPv6 at fd00:ec2::254)
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local
        'ff00::/8',         // multicast
    ];

    /**
     * IPv6 prefixes that carry an IPv4 address, and the byte it starts at. The first match decides.
     *
     * @var list<array{range: string, offset: int}>
     */
    public const array IPV4_EMBEDDING = [
        ['range' => '::ffff:0:0/96', 'offset' => 12],    // IPv4-mapped
        ['range' => '::/96', 'offset' => 12],            // IPv4-compatible, incl. :: and ::1
        ['range' => '64:ff9b::/96', 'offset' => 12],     // NAT64
        ['range' => '::ffff:0:0:0/96', 'offset' => 12],  // IPv4-translated (RFC 2765)
        ['range' => '2002::/16', 'offset' => 2],         // 6to4: 2002:AABB:CCDD::/48 carries AA.BB.CC.DD
    ];

    /**
     * The cloud-metadata endpoints. All are in the table above; these are also never exempted by a
     * private host (`hosts.private`) — only an allowed range names them back in. A name trusted to
     * resolve privately that answers with one is misconfigured or poisoned DNS, and these are the
     * addresses that hand out credentials.
     *
     * @var list<string>
     */
    public const array METADATA = [
        '169.254.169.254',  // AWS, GCP, Azure, OpenStack
        'fd00:ec2::254',    // AWS over IPv6
        '100.100.100.200',  // Alibaba Cloud
        '192.0.0.192',      // Oracle Cloud
        '168.63.129.16',    // Azure WireServer
    ];

    /** @var list<string> Host names refused by spelling, before any lookup (a trailing dot stripped, any case). */
    public const array BLOCKED_HOSTNAMES = ['localhost'];

    /** @var list<string> Host name endings refused by spelling (RFC 6761: every `*.localhost` is loopback). */
    public const array BLOCKED_HOSTNAME_SUFFIXES = ['.localhost'];

    /**
     * Whether `$address` is one no untrusted fetch may reach. Anything unparseable counts as one;
     * an IPv6 zone id (`fe80::1%eth0`) is ignored, the address judged without it.
     */
    public static function isPrivate(string $address): bool
    {
        if (str_contains($address, ':') && str_contains($address, '%')) {
            $address = substr($address, 0, (int) strpos($address, '%'));
        }

        $bytes = AddressRanges::bytes($address);

        if ($bytes === null) {
            return true;
        }

        $embedded = self::carriedIpv4Bytes($bytes);

        if ($embedded !== null) {
            return self::isPrivate((string) inet_ntop($embedded));
        }

        foreach (self::BLOCKED as $range) {
            if (AddressRanges::bytesInRange($bytes, $range)) {
                return true;
            }
        }

        return false;
    }

    /** Whether `$address` — or the IPv4 address it carries — is a cloud-metadata endpoint ({@see self::METADATA}). */
    public static function isMetadata(string $address): bool
    {
        return AddressRanges::contains(self::METADATA, $address);
    }

    /** Whether a host name always means the machine itself (`localhost`, `*.localhost`). */
    public static function isBlockedHostname(string $host): bool
    {
        $host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return true;
        }

        foreach (self::BLOCKED_HOSTNAME_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The IPv4 address an IPv6 address carries (mapped, compatible, NAT64, translated, 6to4), in
     * dotted form; null for an IPv4 address, one that carries none, or anything unparseable.
     */
    public static function carriedIpv4(string $address): ?string
    {
        $bytes = AddressRanges::bytes($address);
        $embedded = $bytes === null ? null : self::carriedIpv4Bytes($bytes);

        return $embedded === null ? null : (string) inet_ntop($embedded);
    }

    /** The four bytes of the IPv4 address `$bytes` (a packed IPv6 address) carries, or null. */
    private static function carriedIpv4Bytes(string $bytes): ?string
    {
        if (strlen($bytes) !== 16) {
            return null;
        }

        foreach (self::IPV4_EMBEDDING as $embedding) {
            if (AddressRanges::bytesInRange($bytes, $embedding['range'])) {
                return substr($bytes, $embedding['offset'], 4);
            }
        }

        return null;
    }
}
