<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Support\DestinationGuard;

/**
 * Judge a URL under a policy by every rule that needs no DNS lookup — `SafeFetch::precheck()` and
 * `SafeUrl::withoutLookup()`: its shape, scheme, port, the name rules, the allowlist and, for an
 * address literal, the address itself. A name is not resolved, so it is judged again at send time.
 * Every refusal is announced as {@see DestinationRefused}.
 */
final readonly class PrecheckDestinationAction
{
    public function __construct(
        private DestinationGuard $guard,
        private Dispatcher $events,
    ) {}

    /**
     * @throws DestinationRejected
     */
    public function execute(string $url, DestinationPolicy $policy): CanonicalUrl
    {
        try {
            return $this->guard->precheck($url, $policy);
        } catch (DestinationRejected $exception) {
            $this->events->dispatch(new DestinationRefused($exception->reason(), $exception->host(), $exception->blockedBy()));

            throw $exception;
        }
    }
}
