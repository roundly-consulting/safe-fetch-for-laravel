<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

/**
 * How `SafeFetch::fetch()` fetches; null takes the configured default (`safe-fetch.fetch.*`, the
 * policy's redirects).
 */
final readonly class FetchOptions
{
    /**
     * @param  int|null  $maxBytes  the body cap; a larger body fails with `too_large`
     * @param  int|null  $timeout  seconds for every hop together, 1–600
     * @param  string|null  $accept  the Accept header
     * @param  array<string, string>  $headers  more request headers (never Host); sent to the first origin only
     * @param  bool|null  $followRedirects  follow redirects (all five statuses, unless the policy names some)
     */
    public function __construct(
        public ?int $maxBytes = null,
        public ?int $timeout = null,
        public ?string $accept = null,
        public array $headers = [],
        public ?bool $followRedirects = null,
    ) {}
}
