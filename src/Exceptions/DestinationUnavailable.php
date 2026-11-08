<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Exceptions;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use SensitiveParameter;
use Throwable;

/**
 * The destination cannot be reached right now: its name does not resolve, or the time budget ran
 * out between hops. Both can heal, so {@see self::retryable()} is true.
 */
final class DestinationUnavailable extends SafeFetchException
{
    public static function unresolved(#[SensitiveParameter] string $url, string $host = '', ?Throwable $previous = null): self
    {
        return new self(Reason::Unresolved, $url, $host, $previous);
    }

    public static function timedOut(#[SensitiveParameter] string $url, string $host = ''): self
    {
        return new self(Reason::Timeout, $url, $host);
    }
}
