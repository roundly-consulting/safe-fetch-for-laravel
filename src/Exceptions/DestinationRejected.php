<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Exceptions;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use SensitiveParameter;
use Throwable;

/**
 * The guard refused the destination: trying again will not help. {@see self::blockedBy()} says,
 * for an operator, which rule refused a `blocked` one.
 */
final class DestinationRejected extends SafeFetchException
{
    private ?string $blockedBy = null;

    /**
     * @param  'hostname'|'blocked_host'|'app_host'|'blocked_range'|'private_address'|null  $blockedBy
     */
    public static function because(
        Reason $reason,
        #[SensitiveParameter] string $url,
        string $host = '',
        ?string $blockedBy = null,
        ?Throwable $previous = null,
    ): self {
        $exception = new self($reason, $url, $host, $previous);
        $exception->blockedBy = $blockedBy;

        return $exception;
    }

    /**
     * Which rule refused a `blocked` destination: `hostname` (localhost), `blocked_host`,
     * `app_host`, `blocked_range` or `private_address`; null for every other reason. For logs and
     * operators only — never show it to the person who sent the URL.
     */
    public function blockedBy(): ?string
    {
        return $this->blockedBy;
    }
}
