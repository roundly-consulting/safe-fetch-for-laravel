<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

/**
 * The raw curl options a caller may still pass to a guarded request, and the credential options a
 * cross-origin redirect drops.
 *
 * An allow-list, not a deny-list: curl has options that connect somewhere other than the pinned
 * address whatever the pin says (a local socket path, a connect-to rewrite, another interface or
 * DNS server, a proxy — and Guzzle 8 accepts several of them), and new ones keep arriving. Listed
 * by name so this file names none of the forbidden ones.
 *
 * @internal
 */
final class CurlOptions
{
    /** @var list<string> */
    public const array CALLER_ALLOWED = [
        'CURLOPT_ENCODING',
        'CURLOPT_HTTPAUTH',
        'CURLOPT_USERPWD',
        'CURLOPT_SSL_CIPHER_LIST',
        'CURLOPT_TLS13_CIPHERS',
        'CURLOPT_SSL_EC_CURVES',
        'CURLOPT_CERTINFO',
        'CURLOPT_LOW_SPEED_LIMIT',
        'CURLOPT_LOW_SPEED_TIME',
        'CURLOPT_TCP_KEEPALIVE',
        'CURLOPT_TCP_KEEPIDLE',
        'CURLOPT_TCP_KEEPINTVL',
        'CURLOPT_TCP_KEEPCNT',
        'CURLOPT_TCP_NODELAY',
        'CURLOPT_TCP_FASTOPEN',
        'CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS',
        'CURLOPT_MAXAGE_CONN',
        'CURLOPT_MAXLIFETIME_CONN',
    ];

    /** Whether a caller's raw curl option `$option` may ride along on a guarded request. */
    public static function callerMay(int|string $option): bool
    {
        foreach (self::CALLER_ALLOWED as $name) {
            if (defined($name) && constant($name) === $option) {
                return true;
            }
        }

        return false;
    }

    /** The name of curl option `$option` (`CURLOPT_UNIX_SOCKET_PATH`), or the number when unknown. */
    public static function nameOf(int|string $option): string
    {
        if (is_int($option)) {
            foreach (get_defined_constants(true)['curl'] ?? [] as $name => $value) {
                if ($value === $option && str_starts_with($name, 'CURLOPT_')) {
                    return $name;
                }
            }
        }

        return (string) $option;
    }

    /**
     * `$curl` without the credentials curl would send (digest, NTLM, basic through curl) — what a
     * redirect to another origin must not carry (CVE-2022-31090, which Guzzle's own redirect
     * middleware fixes and this package's hop loop replaces).
     *
     * @param  array<array-key, mixed>  $curl
     * @return array<array-key, mixed>
     */
    public static function withoutCredentials(array $curl): array
    {
        unset($curl[CURLOPT_HTTPAUTH], $curl[CURLOPT_USERPWD]);

        return $curl;
    }
}
