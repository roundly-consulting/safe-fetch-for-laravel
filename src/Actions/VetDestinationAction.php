<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Support\DestinationGuard;

/**
 * Judge a URL under a policy — `SafeFetch::vet()` / `allows()` and every hop of `Http::safe()`.
 * Every refusal is announced as {@see DestinationRefused}.
 */
final readonly class VetDestinationAction
{
    public function __construct(
        private DestinationGuard $guard,
        private Dispatcher $events,
    ) {}

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     */
    public function execute(string $url, DestinationPolicy $policy): VettedDestination
    {
        try {
            return $this->guard->vet($url, $policy);
        } catch (SafeFetchException $exception) {
            $this->events->dispatch(new DestinationRefused(
                $exception->reason(),
                $exception->host(),
                $exception instanceof DestinationRejected ? $exception->blockedBy() : null,
            ));

            throw $exception;
        }
    }
}
