<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Illuminate\Contracts\Events\Dispatcher;
use WeakMap;

/**
 * Hands out the curl handler each guarded request is sent through — never the host's own handler.
 * Every guarded request gets a fresh one, except the guarded members of one `Http::pool()`: they
 * share one (keyed by the handler the pool gave them), so the pool stays concurrent. Fresh
 * connections, no reuse and the per-hop pre-send check keep each member inside its own vetted set.
 *
 * @internal
 */
final class PinnedHandlers
{
    /** @var WeakMap<object, PinnedHandler> */
    private WeakMap $shared;

    private bool $refuseToDial = false;

    public function __construct(
        private readonly Dispatcher $events,
    ) {
        $this->shared = new WeakMap;
    }

    /** The handler for a request whose current handler is `$current` (null: Laravel's default). */
    public function for(mixed $current): PinnedHandler
    {
        if (! is_object($current)) {
            return new PinnedHandler($this, $this->events);
        }

        return $this->shared[$current] ??= new PinnedHandler($this, $this->events);
    }

    /**
     * `SafeFetch::fake()`: a guarded request that reaches the network (no `Http::fake()` answered
     * it) is refused before dialling, unless real requests were allowed again.
     */
    public function refuseToDial(bool $refuse = true): void
    {
        $this->refuseToDial = $refuse;
    }

    public function refusesToDial(): bool
    {
        return $this->refuseToDial;
    }
}
