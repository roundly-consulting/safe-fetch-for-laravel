<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Handles;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Support\RedirectLocation;
use RoundlyConsulting\SafeFetch\Support\UrlCanonicaliser;

/**
 * The guard's URL parser and redirect resolver — `SafeFetch::urls()`. Pure: no policy, no lookup.
 */
final readonly class Urls
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * Parse `$url` the one way the guard accepts and rebuild it, or refuse it.
     *
     * @throws DestinationRejected with the reason (`invalid_url`, `scheme`, `credentials`, `non_canonical_host`, `port`)
     */
    public function canonicalise(string $url): CanonicalUrl
    {
        return $this->container->make(UrlCanonicaliser::class)->canonicalise($url);
    }

    /**
     * Where a redirect's `Location` leads from `$base` (RFC 3986 §5.2); an absolute one comes back
     * exactly as written, for the guard to judge.
     *
     * @throws DestinationRejected (`invalid_url`) for a relative reference no parser agrees on
     */
    public function resolveRedirect(string $base, string $location): string
    {
        return RedirectLocation::resolve($base, $location);
    }
}
