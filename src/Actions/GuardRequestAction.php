<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use ReflectionProperty;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Http\GuardMiddleware;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\Clock;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PinnedHandler;
use RoundlyConsulting\SafeFetch\Support\PinnedHandlers;

/**
 * Guard a Laravel HTTP request — `Http::safe()`, `SafeFetch::guard()` / `http()`: send it through
 * a curl handler the guard owns, with Guzzle's redirects off and the guard middleware that vets,
 * pins and checks every hop.
 */
final readonly class GuardRequestAction
{
    public function __construct(
        private SafeFetchManager $manager,
        private PinnedHandlers $handlers,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws DestinationRejected (`cannot_pin`) without ext-curl: nothing could be pinned
     * @throws UnsafeRequestOptions for a request that is already guarded or sent through a client of its own
     */
    public function execute(PendingRequest $request, DestinationPolicy $policy): PendingRequest
    {
        if (! CurlSupport::available()) {
            throw DestinationRejected::because(Reason::CannotPin, '');
        }

        // A client set on the request replaces the whole handler stack, guard included.
        if ((new ReflectionProperty(PendingRequest::class, 'client'))->getValue($request) !== null) {
            throw UnsafeRequestOptions::option('client', 'a client set with setClient() replaces the handler stack the guard runs in.');
        }

        $current = (new ReflectionProperty(PendingRequest::class, 'handler'))->getValue($request);

        if ($current instanceof PinnedHandler) {
            throw UnsafeRequestOptions::option('safe', 'the request is already guarded.');
        }

        return $request
            ->setHandler($this->handlers->for($current))
            ->withoutRedirecting()
            ->withMiddleware(new GuardMiddleware($this->manager, $policy, $this->clock, $this->events));
    }
}
