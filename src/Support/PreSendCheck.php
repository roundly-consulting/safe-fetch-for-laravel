<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;

/**
 * curl's `CURLOPT_PREREQFUNCTION` for one hop: called after curl connected and before it sends a
 * byte, it lets the request go only when the connected address (and port) is one the guard vetted.
 * Anything else aborts the transfer there — the server sees a connection and nothing on it — and
 * flags the hop, so the middleware reports it as `cannot_pin` instead of a transport error.
 *
 * @internal
 */
final class PreSendCheck
{
    public bool $refused = false;

    public function __construct(
        public readonly VettedDestination $destination,
    ) {}

    /**
     * `$curl` with this check attached.
     *
     * @param  array<array-key, mixed>  $curl
     * @return array<array-key, mixed>
     */
    public function attachTo(array $curl): array
    {
        $curl[CURLOPT_PREREQFUNCTION] = $this;

        return $curl;
    }

    /**
     * The check attached to `$curl`, if any.
     *
     * @param  array<array-key, mixed>  $curl
     */
    public static function in(array $curl): ?self
    {
        $check = defined('CURLOPT_PREREQFUNCTION') ? ($curl[CURLOPT_PREREQFUNCTION] ?? null) : null;

        return $check instanceof self ? $check : null;
    }

    /** Whether `$option` is the curl option this check rides on. */
    public static function isOption(int|string $option): bool
    {
        return defined('CURLOPT_PREREQFUNCTION') && $option === CURLOPT_PREREQFUNCTION;
    }

    public function __invoke(mixed $handle, string $connectedAddress, string $localAddress = '', int $connectedPort = 0, int $localPort = 0): int
    {
        if ($this->destination->connectsTo($connectedAddress) && $connectedPort === $this->destination->port) {
            return CURL_PREREQFUNC_OK;
        }

        $this->refused = true;

        return CURL_PREREQFUNC_ABORT;
    }
}
