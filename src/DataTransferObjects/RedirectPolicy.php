<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

use InvalidArgumentException;
use RoundlyConsulting\SafeFetch\Support\RedirectLocation;

/**
 * How a guarded request treats a redirect. Off by default; when on, every hop is vetted and pinned
 * again, headers (and credentials) go to the first origin only, and https never drops to http
 * unless `allowDowngrade`.
 *
 * `statuses` are the redirects followed. Left unset (`null`) they are the method-preserving 307 and
 * 308 for `Http::safe()`; `SafeFetch::fetch()`, which only sends GETs, follows all five then.
 */
final readonly class RedirectPolicy
{
    /** @var list<int> the statuses followed — 307 and 308 unless given */
    public array $statuses;

    /** @var list<string> header names (lowercase) also sent to another origin a redirect leads to */
    public array $forwardHeaders;

    /** Whether `statuses` was given, rather than defaulted. */
    public bool $statusesGiven;

    /**
     * @param  list<int>|null  $statuses  a subset of 301, 302, 303, 307, 308; null = 307 and 308
     * @param  list<string>  $forwardHeaders
     *
     * @throws InvalidArgumentException for a max outside 0–10, an unknown or empty status set, or a header name that is not one
     */
    public function __construct(
        public bool $enabled = false,
        public int $max = 3,
        ?array $statuses = null,
        public bool $allowDowngrade = false,
        array $forwardHeaders = [],
    ) {
        if ($max < 0 || $max > 10) {
            throw new InvalidArgumentException("A redirect limit must be between 0 and 10, [{$max}] given.");
        }

        $this->statusesGiven = $statuses !== null;
        $this->statuses = self::statuses($statuses ?? [307, 308]);
        $this->forwardHeaders = self::headers($forwardHeaders);
    }

    /** Redirects are returned to the caller, never followed. */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Follow 307 and 308 (method and body kept), at most `$max` hops, also sending
     * `$forwardHeaders` to another origin a redirect leads to (a webhook's signature headers).
     *
     * @param  list<string>  $forwardHeaders
     */
    public static function methodPreserving(int $max = 3, array $forwardHeaders = []): self
    {
        return new self(enabled: true, max: $max, statuses: [307, 308], forwardHeaders: $forwardHeaders);
    }

    /** The same policy, followed (`true`) or not, keeping every other setting. */
    public function enabled(bool $enabled = true, ?int $max = null): self
    {
        return new self($enabled, $max ?? $this->max, $this->statusesGiven ? $this->statuses : null, $this->allowDowngrade, $this->forwardHeaders);
    }

    /**
     * The same policy following exactly `$statuses`.
     *
     * @param  list<int>  $statuses
     */
    public function withStatuses(array $statuses): self
    {
        return new self($this->enabled, $this->max, $statuses, $this->allowDowngrade, $this->forwardHeaders);
    }

    /**
     * The same policy, sending exactly `$headers` to another origin a redirect leads to — it
     * **replaces** the list. Credentials never go: name only headers that are safe to repeat.
     *
     * @param  list<string>  $headers
     *
     * @throws InvalidArgumentException for an entry that is not a header name
     */
    public function forwardHeaders(array $headers): self
    {
        return new self($this->enabled, $this->max, $this->statusesGiven ? $this->statuses : null, $this->allowDowngrade, $headers);
    }

    /** The same policy, following a redirect from https to http (`true`) or not. */
    public function allowingDowngrade(bool $allow = true): self
    {
        return new self($this->enabled, $this->max, $this->statusesGiven ? $this->statuses : null, $allow, $this->forwardHeaders);
    }

    /**
     * The same policy, following at most `$max` hops (0–10).
     *
     * @throws InvalidArgumentException for a max outside 0–10
     */
    public function max(int $max): self
    {
        return new self($this->enabled, $max, $this->statusesGiven ? $this->statuses : null, $this->allowDowngrade, $this->forwardHeaders);
    }

    /** Whether a `$status` answer (with a `Location`) is followed. */
    public function follows(int $status): bool
    {
        return $this->enabled && in_array($status, $this->statuses, true);
    }

    /**
     * @param  array<mixed>  $statuses
     * @return list<int>
     */
    private static function statuses(array $statuses): array
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('Follow at least one redirect status, or turn redirects off.');
        }

        $followed = [];

        foreach ($statuses as $status) {
            if (! is_int($status) || ! in_array($status, RedirectLocation::STATUSES, true)) {
                $shown = is_scalar($status) ? (string) $status : get_debug_type($status);

                throw new InvalidArgumentException("A followed redirect status must be one of 301, 302, 303, 307 or 308, [{$shown}] given.");
            }

            $followed[$status] = $status;
        }

        ksort($followed);

        return array_values($followed);
    }

    /**
     * @param  array<mixed>  $headers
     * @return list<string>
     */
    private static function headers(array $headers): array
    {
        $names = [];

        foreach ($headers as $header) {
            if (! is_string($header) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $header) !== 1) {
                $shown = is_scalar($header) ? (string) $header : get_debug_type($header);

                throw new InvalidArgumentException("[{$shown}] is not a header name.");
            }

            $names[] = strtolower($header);
        }

        return array_values(array_unique($names));
    }
}
