<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Rules;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
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
    /**
     * @param  bool  $lookup  false: judge without DNS — {@see self::withoutLookup()}
     */
    public function __construct(
        private ?DestinationPolicy $policy = null,
        private bool $lookup = true,
    ) {}

    /**
     * Every rule except the DNS lookup (`SafeFetch::precheck()`): a form that should not wait on DNS
     * or fail while a name does not resolve yet. A name that passes can still resolve privately —
     * the guard judges it in full when it is fetched.
     */
    public static function withoutLookup(?DestinationPolicy $policy = null): self
    {
        return new self($policy, lookup: false);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->passes($value)) {
            $fail('safe-fetch::validation.safe_url')->translate();
        }
    }

    private function passes(string $url): bool
    {
        $manager = Container::getInstance()->make(SafeFetchManager::class);

        if ($this->lookup) {
            return $manager->allows($url, $this->policy);
        }

        try {
            $manager->precheck($url, $this->policy);
        } catch (SafeFetchException) {
            return false;
        }

        return true;
    }
}
