<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use InvalidArgumentException;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;

/**
 * The host lists of a policy (allowed, blocked, private, the app's own): an exact name or
 * `*.example.com`. Matched in any case, with one trailing dot dropped on both sides and an
 * international name compared as punycode; `*.example.com` matches every **sub**domain, never
 * `example.com` itself or `badexample.com`.
 *
 * @internal
 */
final class HostPattern
{
    /** Whether `$pattern` is a canonical host name, or `*.` and one. */
    public static function isValid(string $pattern): bool
    {
        try {
            self::normalise($pattern);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * `$pattern` as the guard writes host names: lowercase, no trailing dot, punycode.
     *
     * @throws InvalidArgumentException for a bare `*`, an address, a scheme, a port or a path
     */
    public static function normalise(string $pattern): string
    {
        $pattern = trim($pattern);
        $wildcard = str_starts_with($pattern, '*.');
        $name = $wildcard ? substr($pattern, 2) : $pattern;

        if ($name === '' || preg_match('#[*/?\#@:\[\]\s\\\\]#', $name) === 1) {
            throw new InvalidArgumentException("[{$pattern}] is not a host name or a *.host.name pattern.");
        }

        try {
            $canonical = (new UrlCanonicaliser)->canonicalise('http://'.$name.'/');
        } catch (DestinationRejected) {
            throw new InvalidArgumentException("[{$pattern}] is not a host name or a *.host.name pattern.");
        }

        if ($canonical->ipLiteral) {
            throw new InvalidArgumentException("[{$pattern}] is an address: list it under the ranges instead.");
        }

        return ($wildcard ? '*.' : '').$canonical->host;
    }

    /**
     * Whether `$host` (as the guard wrote it) matches any of the normalised `$patterns`.
     *
     * @param  list<string>  $patterns
     */
    public static function matches(string $host, array $patterns): bool
    {
        $host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '*.')
                ? str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1
                : $host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
