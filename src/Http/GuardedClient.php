<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;

/**
 * What a guarded request sends through in place of a Guzzle client. Guzzle 7 lets a request-level
 * `handler` option replace the whole handler stack — the guard and the pin with it — and an option
 * added after `safe()` never reaches anything inside that stack. This sees every request's final
 * options first: it refuses a `handler`, then sends through a fresh Guzzle client on the request's
 * own handler stack, exactly as Laravel would have built it.
 *
 * Laravel only ever calls `request()` and `requestAsync()` on its client, so that is all this
 * offers; it is not a {@see Client}, which Guzzle marks final.
 *
 * @internal
 */
final readonly class GuardedClient
{
    public function __construct(
        private PendingRequest $request,
    ) {}

    /**
     * @param  string|UriInterface  $uri
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        self::refuseHandler($options);

        return $this->client()->request($method, $uri, $options);
    }

    /**
     * @param  string|UriInterface  $uri
     * @param  array<string, mixed>  $options
     */
    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        self::refuseHandler($options);

        return $this->client()->requestAsync($method, $uri, $options);
    }

    /**
     * Refuse a request-level `handler`: it would replace the stack the guard runs in.
     *
     * @param  array<array-key, mixed>  $options
     */
    public static function refuseHandler(array $options): void
    {
        if (isset($options['handler'])) {
            throw UnsafeRequestOptions::option('handler', 'a handler replaces the handler stack the guard runs in.');
        }
    }

    /** A Guzzle client on the request's own stack, built per send — Laravel's own way. */
    private function client(): Client
    {
        return $this->request->createClient($this->request->buildHandlerStack());
    }
}
