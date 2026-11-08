<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\Actions\DownloadUrlAction;
use RoundlyConsulting\SafeFetch\Actions\FetchUrlAction;
use RoundlyConsulting\SafeFetch\Actions\GuardRequestAction;
use RoundlyConsulting\SafeFetch\Actions\PrecheckDestinationAction;
use RoundlyConsulting\SafeFetch\Actions\VetDestinationAction;
use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DownloadedFile;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Handles\Networks;
use RoundlyConsulting\SafeFetch\Handles\Urls;
use RoundlyConsulting\SafeFetch\Support\SafeFetchConfig;

/**
 * The package's public API: the root behind the {@see Facades\SafeFetch} facade, injectable by its
 * own class-string. Each method resolves one action through the container, so a host override and
 * `SafeFetch::fake()` both apply.
 *
 * Not final on purpose: {@see Testing\SafeFetchFake} extends it, so code that constructor-injects
 * the manager still type-checks under the fake.
 *
 * Security-critical callers use {@see self::guard()} / {@see self::http()} rather than the
 * `Http::safe()` macro: another package registering a `safe` macro after this one would replace it
 * silently.
 */
class SafeFetchManager
{
    /** The header a guarded response cut at `maxResponseBytes` carries, with the value `1`. */
    public const string TRUNCATED_HEADER = 'Safe-Fetch-Truncated';

    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * Judge `$url` under the policy (the configured one by default): its rebuilt URL and every
     * address it resolved to, or the reason it is refused.
     *
     * @throws DestinationRejected
     * @throws DestinationUnavailable when the host does not resolve (retryable)
     */
    public function vet(string $url, ?DestinationPolicy $policy = null): VettedDestination
    {
        return $this->container->make(VetDestinationAction::class)->execute($url, $policy ?? $this->policy());
    }

    /** Whether the policy lets `$url` through. Never throws a verdict. */
    public function allows(string $url, ?DestinationPolicy $policy = null): bool
    {
        try {
            $this->container->make(VetDestinationAction::class)->execute($url, $policy ?? $this->policy());
        } catch (SafeFetchException) {
            return false;
        }

        return true;
    }

    /**
     * Judge `$url` by every rule that needs no DNS lookup — shape, scheme, port, blocked and app
     * hosts, the allowlist, `localhost`, and an address literal in full — and return it rebuilt.
     * For save time and forms that should not wait on DNS: a name is not resolved, so it can still
     * resolve privately. {@see self::vet()} (or send through the guard) when you fetch it.
     *
     * @throws DestinationRejected
     */
    public function precheck(string $url, ?DestinationPolicy $policy = null): CanonicalUrl
    {
        return $this->container->make(PrecheckDestinationAction::class)->execute($url, $policy ?? $this->policy());
    }

    /** A new guarded request from Laravel's HTTP client — `Http::safe()` without the macro. */
    public function http(?DestinationPolicy $policy = null): PendingRequest
    {
        return $this->guard($this->container->make(Factory::class)->createPendingRequest(), $policy);
    }

    /** Guard a request you built (`Http::withToken($t)`): vetted, pinned and checked on every hop. */
    public function guard(PendingRequest $request, ?DestinationPolicy $policy = null): PendingRequest
    {
        return $this->container->make(GuardRequestAction::class)->execute($request, $policy ?? $this->policy());
    }

    /**
     * GET `$url` into memory, guarded, its body capped at `$maxBytes`.
     *
     * @param  array<string, string>  $headers
     *
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     */
    public function fetch(
        string $url,
        ?int $maxBytes = null,
        ?int $timeout = null,
        ?string $accept = null,
        array $headers = [],
        ?bool $followRedirects = null,
        ?DestinationPolicy $policy = null,
    ): FetchedResponse {
        return $this->container->make(FetchUrlAction::class)->execute(
            $url,
            new FetchOptions($maxBytes, $timeout, $accept, $headers, $followRedirects),
            $policy ?? $this->policy(),
        );
    }

    /**
     * GET `$url` into the file at `$path`, guarded, its body streamed to disk and capped at
     * `$maxBytes` — `fetch()` for files too large to hold in memory. The directory must exist; on
     * any failure the file is deleted.
     *
     * @param  array<string, string>  $headers
     *
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     */
    public function download(
        string $url,
        string $path,
        ?int $maxBytes = null,
        ?int $timeout = null,
        ?string $accept = null,
        array $headers = [],
        ?bool $followRedirects = null,
        ?DestinationPolicy $policy = null,
    ): DownloadedFile {
        return $this->container->make(DownloadUrlAction::class)->execute(
            $url,
            $path,
            new FetchOptions($maxBytes, $timeout, $accept, $headers, $followRedirects),
            $policy ?? $this->policy(),
        );
    }

    /**
     * Whether a guarded response was cut at the policy's `maxResponseBytes` — its body is only the
     * first that many bytes. Stateless: it reads the response's {@see self::TRUNCATED_HEADER}.
     */
    public function truncated(Response|ResponseInterface $response): bool
    {
        $value = $response instanceof Response
            ? $response->header(self::TRUNCATED_HEADER)
            : $response->getHeaderLine(self::TRUNCATED_HEADER);

        return $value === '1';
    }

    /** The configured default policy, to tighten or loosen per call. */
    public function policy(): DestinationPolicy
    {
        return SafeFetchConfig::policy();
    }

    /** The blocked-network table. */
    public function networks(): Networks
    {
        return $this->container->make(Networks::class);
    }

    /** The guard's URL parser and redirect resolver. */
    public function urls(): Urls
    {
        return $this->container->make(Urls::class);
    }

    /**
     * One hop of a guarded request, vetted.
     *
     * @internal called by the guard middleware, once per hop, so the fake sees every hop
     */
    public function vetHop(string $url, DestinationPolicy $policy): VettedDestination
    {
        return $this->container->make(VetDestinationAction::class)->execute($url, $policy);
    }
}
