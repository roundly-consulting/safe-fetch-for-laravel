<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Rules;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\SafeFetchManager;

/**
 * Validates a URL a user hands in for a server-side fetch: it passes when the guard lets it through
 * (`SafeFetch::allows()`) and otherwise fails with one translated message
 * (`safe-fetch::validation.safe_url`), whatever the reason. Telling "private" from "not found"
 * would tell a user which internal names exist; call `SafeFetch::vet()` for the exact reason.
 *
 * ```php
 * 'feed' => ['required', 'string', 'max:2048', new SafeUrl],   // or the string rule 'safe_url'
 * ```
 *
 * The URL is judged again when it is fetched — DNS can change in between.
 */
final readonly class SafeUrl implements ValidationRule
{
    public function __construct(
        private ?DestinationPolicy $policy = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Container::getInstance()->make(SafeFetchManager::class)->allows($value, $this->policy)) {
            $fail('safe-fetch::validation.safe_url')->translate();
        }
    }
}
