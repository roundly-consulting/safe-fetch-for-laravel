<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Exceptions;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use SensitiveParameter;
use Throwable;

/**
 * `SafeFetch::fetch()` reached the destination but got no usable answer: a non-2xx status, a body
 * over the byte cap, a broken connection, a TLS failure, a timeout or an unreadable answer.
 */
final class FetchFailed extends SafeFetchException
{
    private ?int $status = null;

    private ?int $retryAfter = null;

    public static function forStatus(#[SensitiveParameter] string $url, string $host, int $status, ?int $retryAfter = null): self
    {
        $exception = new self(Reason::Status, $url, $host, null, self::translate(Reason::Status, ['status' => $status]));
        $exception->status = $status;
        $exception->retryAfter = $retryAfter;

        return $exception;
    }

    public static function because(Reason $reason, #[SensitiveParameter] string $url, string $host = '', ?Throwable $previous = null): self
    {
        return new self($reason, $url, $host, $previous);
    }

    /** The HTTP status of a `status` failure; null for every other reason. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** Seconds the server asked to wait (`Retry-After`, capped at a day); null when it did not say. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** 408, 429 and 5xx can heal; any other status cannot. */
    public function retryable(): bool
    {
        if ($this->status !== null) {
            return $this->status === 408 || $this->status === 429 || $this->status >= 500;
        }

        return parent::retryable();
    }
}
