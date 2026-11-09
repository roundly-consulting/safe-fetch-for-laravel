<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use GuzzleHttp\Client;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\SafeFetchManager;

/**
 * A Guzzle client on the guarded stack — `SafeFetch::guzzleClient()`, for a library that takes a
 * ready Guzzle client (or a PSR-18 one: Guzzle's client is both). Every request it sends is vetted,
 * pinned and checked on every hop, exactly as through {@see BuildHandlerStackAction}.
 */
final readonly class CreateGuzzleClientAction
{
    public function __construct(
        private SafeFetchManager $manager,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Guzzle client options (`base_uri`, `headers`, `timeout`, …)
     *
     * @throws DestinationRejected (`cannot_pin`) without ext-curl
     * @throws UnsafeRequestOptions for a `handler` (it would replace the guarded stack) or Guzzle's own redirects
     */
    public function execute(array $config, DestinationPolicy $policy): Client
    {
        if (array_key_exists('handler', $config)) {
            throw UnsafeRequestOptions::option('handler', 'the guarded client sends through the guarded stack; push middleware onto SafeFetch::handlerStack() instead.');
        }

        $redirects = $config['allow_redirects'] ?? false;

        if ($redirects !== false) {
            throw UnsafeRequestOptions::option('allow_redirects', 'redirects are followed by the guard, which vets every hop; enable them on the policy (followRedirects()).');
        }

        return new Client(['allow_redirects' => false, ...$config, 'handler' => $this->manager->handlerStack($policy)]);
    }
}
