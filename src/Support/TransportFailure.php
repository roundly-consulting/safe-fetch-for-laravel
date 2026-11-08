<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * A transport failure of `SafeFetch::fetch()` as a status-aware exception, by its curl code. The
 * underlying exception is not chained as is — its message can name the URL and the address it
 * connected to — but as a cause that names only its class and curl code, for whoever operates it.
 *
 * @internal
 */
final class TransportFailure
{
    /** curl errors that mean the peer's TLS could not be verified: retrying cannot help. */
    private const array TLS_ERRORS = [51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public static function from(Throwable $exception, #[SensitiveParameter] string $url, string $host): FetchFailed|DestinationUnavailable
    {
        $code = self::curlCode($exception);
        $cause = new RuntimeException($exception::class.($code === null ? '' : " (cURL error {$code})"));

        return match (true) {
            $code === 28 => FetchFailed::because(Reason::Timeout, $url, $host, $cause),
            $code === 6 => DestinationUnavailable::unresolved($url, $host, $cause),
            $code !== null && in_array($code, self::TLS_ERRORS, true) => FetchFailed::because(Reason::Tls, $url, $host, $cause),
            $code !== null => FetchFailed::because(Reason::Connection, $url, $host, $cause),
            // No curl code: the answer arrived but was not HTTP Guzzle could read (a status outside 100–599).
            default => FetchFailed::because(Reason::Protocol, $url, $host, $cause),
        };
    }

    /** The curl error code anywhere in the chain (`cURL error 28: …`), or null. */
    public static function curlCode(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/cURL error (\d+)/', $current->getMessage(), $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }
}
