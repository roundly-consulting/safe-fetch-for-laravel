<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Contracts;

/**
 * Resolves a host name to every address it has, once per hop: the guard judges all of them and
 * pins the connection to exactly those, so a second answer (DNS rebinding) never reaches curl.
 *
 * Bind your own in `safe-fetch.resolver` (any class implementing this). `SafeFetch::fakeDns()`
 * replaces it in tests.
 */
interface HostResolver
{
    /**
     * @param  string  $host  a host name as the guard writes it: lowercase, no trailing dot, punycode
     * @return list<string> every IPv4 and IPv6 address it resolves to (IPv6 without brackets); empty when it does not resolve
     */
    public function resolve(string $host): array;
}
