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
use RoundlyConsulting\SafeFetch\Http\GuardedClient;
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
     * @throws UnsafeRequestOptions for a request that is already guarded, sent through a client of its own or given a `handler` option
     */
    public function execute(PendingRequest $request, DestinationPolicy $policy): PendingRequest
    {
        if (! CurlSupport::available()) {
            throw DestinationRejected::because(Reason::CannotPin, '');
        }

        $property = new ReflectionProperty(PendingRequest::class, 'client');
        $client = $property->getValue($request);
        $current = (new ReflectionProperty(PendingRequest::class, 'handler'))->getValue($request);

        if ($client instanceof GuardedClient || $current instanceof PinnedHandler) {
            throw UnsafeRequestOptions::option('safe', 'the request is already guarded.');
        }

        // A client set on the request replaces the whole handler stack, guard included.
        if ($client !== null) {
            throw UnsafeRequestOptions::option('client', 'a client set with setClient() replaces the handler stack the guard runs in.');
        }

        // So does a `handler` option on Guzzle 7 (`Http::globalOptions()`, `withOptions()`); one
        // added after this call is refused by the guarded client when the request is sent.
        GuardedClient::refuseHandler($request->getOptions());

        $request
            ->setHandler($this->handlers->for($current))
            ->withoutRedirecting()
            ->withMiddleware(new GuardMiddleware($this->manager, $policy, $this->clock, $this->events));

        // Not through setClient(): the guarded client is no Guzzle Client, which Guzzle keeps final.
        $property->setValue($request, new GuardedClient($request));

        return $request;
    }
}
