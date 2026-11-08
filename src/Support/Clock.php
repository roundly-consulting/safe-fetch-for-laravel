<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Closure;

/**
 * Seconds since the epoch, to the microsecond, for the one time budget a guarded request spends
 * across its hops. A seam: tests bind one that returns what they say.
 *
 * @internal
 */
final readonly class Clock
{
    /**
     * @param  (Closure(): float)|null  $now
     */
    public function __construct(
        private ?Closure $now = null,
    ) {}

    public function now(): float
    {
        return $this->now === null ? microtime(true) : ($this->now)();
    }
}
