<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

/**
 * Address and CIDR arithmetic for the policy's allowed and blocked ranges, and the one place an
 * address is compared with another (a connected address against the vetted set).
 *
 * @internal
 */
final class AddressRanges
{
    /**
     * The packed form of an address PHP's own parser accepts (never only the platform's: BSD libc
     * reads `0177.0.0.1` as an address); null for anything else.
     */
    public static function bytes(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bytes = @inet_pton($address);

        return $bytes === false ? null : $bytes;
    }

    /** Whether `$entry` is an address or a well-formed `address/prefix` range. */
    public static function isValid(string $entry): bool
    {
        [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $bytes = self::bytes((string) $network);

        if ($bytes === null) {
            return false;
        }

        return $prefix === null || (ctype_digit($prefix) && strlen($prefix) <= 3 && (int) $prefix <= strlen($bytes) * 8);
    }

    /** `$entry` as a range: an address on its own covers itself (`/32`, `/128`). */
    public static function asRange(string $entry): string
    {
        if (str_contains($entry, '/')) {
            return $entry;
        }

        return $entry.'/'.(str_contains($entry, ':') ? 128 : 32);
    }

    /** The prefix length of a valid entry (an address alone: 32 or 128). */
    public static function prefixLength(string $entry): int
    {
        return (int) explode('/', self::asRange($entry), 2)[1];
    }

    /**
     * How much of the IPv4 space a valid entry covers, as an IPv4 prefix length: an IPv4 entry its
     * own prefix; an IPv6 one inside a prefix that carries IPv4 ({@see PrivateNetworks::IPV4_EMBEDDING})
     * the prefix of the IPv4 addresses it carries (`::ffff:10.0.0.0/104` is `/8`); an IPv6 one that
     * holds such a prefix whole 0 — every IPv4 address under another name. Null for an IPv6 entry
     * that carries no IPv4 address.
     */
    public static function ipv4PrefixLength(string $entry): ?int
    {
        [$network, $prefix] = explode('/', self::asRange($entry), 2);
        $prefix = (int) $prefix;

        if (! self::isIpv6($entry)) {
            return $prefix;
        }

        $bytes = (string) inet_pton($network);

        foreach (PrivateNetworks::IPV4_EMBEDDING as $embedding) {
            [$carrier, $carrierPrefix] = explode('/', $embedding['range'], 2);

            if ($prefix <= (int) $carrierPrefix && self::bytesInRange((string) inet_pton($carrier), "{$network}/{$prefix}")) {
                return 0;
            }

            if ($prefix > (int) $carrierPrefix && self::bytesInRange($bytes, $embedding['range'])) {
                return min(32, $prefix - $embedding['offset'] * 8);
            }
        }

        return null;
    }

    /** Whether a valid entry covers every IPv4 address — `0.0.0.0/0`, under any name. */
    public static function coversEveryIpv4Address(string $entry): bool
    {
        return self::ipv4PrefixLength($entry) === 0;
    }

    /** Whether the entry is an IPv6 one. */
    public static function isIpv6(string $entry): bool
    {
        return str_contains(explode('/', $entry, 2)[0], ':');
    }

    /**
     * Whether any of `$ranges` covers `$address` — or the IPv4 address it carries, so
     * `127.0.0.1/32` covers `::ffff:127.0.0.1`.
     *
     * @param  list<string>  $ranges
     */
    public static function contains(array $ranges, string $address): bool
    {
        $bytes = self::bytes($address);

        if ($bytes === null) {
            return false;
        }

        $candidates = [$bytes];
        $carried = PrivateNetworks::carriedIpv4($address);

        if ($carried !== null) {
            $candidates[] = (string) inet_pton($carried);
        }

        foreach ($ranges as $range) {
            foreach ($candidates as $candidate) {
                if (self::bytesInRange($candidate, self::asRange($range))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether two addresses are the same, compared as bytes: `::FFFF:7f00:1`, `::ffff:127.0.0.1`
     * and `127.0.0.1` are one address (curl reports a v4 connection on a dual-stack socket either
     * way). Anything unparseable is the same as nothing.
     */
    public static function same(string $a, string $b): bool
    {
        $a = self::comparable($a);

        return $a !== null && $a === self::comparable($b);
    }

    public static function bytesInRange(string $bytes, string $range): bool
    {
        [$network, $prefix] = explode('/', $range, 2);
        $networkBytes = @inet_pton($network);

        if ($networkBytes === false || strlen($networkBytes) !== strlen($bytes)) {
            return false;
        }

        $bits = (int) $prefix;
        $whole = intdiv($bits, 8);

        if (substr($bytes, 0, $whole) !== substr($networkBytes, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bytes[$whole]) & $mask) === (ord($networkBytes[$whole]) & $mask);
    }

    /** The bytes an address is compared by: an IPv4-mapped IPv6 address as its IPv4 bytes. */
    private static function comparable(string $address): ?string
    {
        $bytes = self::bytes($address);

        if ($bytes !== null && strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xFF\xFF")) {
            return substr($bytes, 12);
        }

        return $bytes;
    }
}
