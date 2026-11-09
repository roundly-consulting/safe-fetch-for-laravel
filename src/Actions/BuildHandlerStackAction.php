<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use GuzzleHttp\HandlerStack;
use Illuminate\Http\Client\Factory;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Http\GuzzleClientOptions;
use RoundlyConsulting\SafeFetch\SafeFetchManager;

/**
 * A Guzzle handler stack that guards every request sent through it — `SafeFetch::handlerStack()`,
 * for a library that builds its own Guzzle client (`new Client(['handler' => $stack])`). It is the
 * stack a guarded Laravel request sends through: the guard's own curl handler, the guard middleware
 * that vets, pins and checks every hop (redirects included), and Laravel's stub and recorder, so
 * `Http::fake()` answers and records these requests too. On top sits {@see GuzzleClientOptions},
 * which turns a Guzzle client's defaults (its own redirects, its cookie jar) into what the guard
 * runs on.
 *
 * The stack is only as safe as its handler: code that calls `setHandler()` on it, or (Guzzle 7) a
 * per-request `handler` option, replaces the guard's curl handler — the pin and the pre-send check
 * then hold only where that handler is curl.
 */
final readonly class BuildHandlerStackAction
{
    public function __construct(
        private SafeFetchManager $manager,
        private Factory $http,
    ) {}

    /**
     * @return HandlerStack<callable>
     *
     * @throws DestinationRejected (`cannot_pin`) without ext-curl
     * @throws UnsafeRequestOptions when `Http::globalOptions()` sets a `handler`
     */
    public function execute(DestinationPolicy $policy): HandlerStack
    {
        $stack = $this->manager->guard($this->http->createPendingRequest(), $policy)->buildHandlerStack();
        $stack->unshift(new GuzzleClientOptions, 'safe_fetch.client_options');

        return $stack;
    }
}
