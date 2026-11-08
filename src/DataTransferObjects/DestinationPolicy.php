<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

use InvalidArgumentException;
use RoundlyConsulting\SafeFetch\Support\AddressRanges;
use RoundlyConsulting\SafeFetch\Support\HostPattern;

/**
 * What the guard lets through, on top of the blocked-network table. `SafeFetch::policy()` is the
 * configured one; every wither returns a new policy, so a call can tighten or loosen it locally:
 *
 * ```php
 * Http::safe(SafeFetch::policy()->allowHosts(['hooks.example.com'])->followRedirects())->post($url, $payload);
 * ```
 *
 * Building a policy for another package's untrusted URLs (tenant-supplied webhook endpoints),
 * start from the configured one and drop the host's carve-outs, keeping its blocks:
 *
 * ```php
 * SafeFetch::policy()->withoutHostAllowlist()->withoutPrivateHosts()->withoutAllowedRanges();
 * ```
 *
 * Withers that tighten (block more, clear a carve-out) compose freely; anything that loosens is a
 * call that says so.
 *
 * Block beats allow: a blocked host or range is refused whatever else the policy says, and an
 * allowlisted host is still resolved, judged and pinned — it is only "may be asked for", never
 * "may be private". Host entries are exact names or `*.example.com` (subdomains only).
 */
final readonly class DestinationPolicy
{
    /** @var list<string> */
    public array $schemes;

    /** @var list<int>|null */
    public ?array $ports;

    /** @var list<string> */
    public array $allowedHosts;

    /** @var list<string> */
    public array $blockedHosts;

    /** @var list<string> */
    public array $privateHosts;

    /** @var list<string> */
    public array $allowedRanges;

    /** @var list<string> */
    public array $blockedRanges;

    /** @var list<string> */
    public array $appHosts;

    /**
     * @param  list<string>  $schemes  `https`, and `http` when allowed
     * @param  list<int>|null  $ports  null: any port
     * @param  list<string>  $allowedHosts  non-empty: ONLY these hosts
     * @param  list<string>  $blockedHosts  refused by name, before any lookup
     * @param  list<string>  $privateHosts  names whose addresses may be private (still resolved and pinned)
     * @param  list<string>  $allowedRanges  addresses / CIDRs exempt from the blocked-network table
     * @param  list<string>  $blockedRanges  addresses / CIDRs always refused
     * @param  list<string>  $appHosts  the app's own host names
     * @param  int|null  $maxResponseBytes  `Http::safe()` only: cut a response body at this many bytes
     *
     * @throws InvalidArgumentException for any value the guard could not apply as written
     */
    public function __construct(
        array $schemes = ['https'],
        ?array $ports = null,
        array $allowedHosts = [],
        array $blockedHosts = [],
        array $privateHosts = [],
        array $allowedRanges = [],
        array $blockedRanges = [],
        public bool $blockAppHosts = true,
        array $appHosts = [],
        public RedirectPolicy $redirects = new RedirectPolicy,
        public ?int $maxResponseBytes = null,
    ) {
        $this->schemes = self::validSchemes($schemes);
        $this->ports = $ports === null ? null : self::validPorts($ports);
        $this->allowedHosts = self::validHosts($allowedHosts);
        $this->blockedHosts = self::validHosts($blockedHosts);
        $this->privateHosts = self::validHosts($privateHosts);
        $this->allowedRanges = self::validRanges($allowedRanges, allowed: true);
        $this->blockedRanges = self::validRanges($blockedRanges, allowed: false);
        $this->appHosts = self::validHosts($appHosts);

        if ($maxResponseBytes !== null && $maxResponseBytes < 1) {
            throw new InvalidArgumentException("A response cap must be at least 1 byte, [{$maxResponseBytes}] given.");
        }
    }

    /** http as well as https (local development, internal tools). */
    public function allowHttp(): self
    {
        return $this->with(schemes: ['http', 'https']);
    }

    public function httpsOnly(): self
    {
        return $this->with(schemes: ['https']);
    }

    /**
     * Only these ports (`[443]`); null for any.
     *
     * @param  list<int>|null  $ports
     */
    public function ports(?array $ports): self
    {
        return new self($this->schemes, $ports, $this->allowedHosts, $this->blockedHosts, $this->privateHosts, $this->allowedRanges, $this->blockedRanges, $this->blockAppHosts, $this->appHosts, $this->redirects, $this->maxResponseBytes);
    }

    /**
     * ONLY these hosts (exact or `*.example.com`) — replaces the allowlist, so it can only narrow.
     *
     * @param  list<string>  $hosts
     */
    public function allowHosts(array $hosts): self
    {
        return $this->with(allowedHosts: $hosts);
    }

    /** Lift a strict host allowlist: any host may be asked for again (still vetted and pinned). */
    public function withoutHostAllowlist(): self
    {
        return $this->with(allowedHosts: []);
    }

    /**
     * Refuse these hosts by name, before any lookup (added to the list).
     *
     * @param  list<string>  $hosts
     */
    public function blockHosts(array $hosts): self
    {
        return $this->with(blockedHosts: [...$this->blockedHosts, ...$hosts]);
    }

    /**
     * Let these names resolve to private addresses (`minio.internal`); they are still resolved and pinned.
     *
     * @param  list<string>  $hosts
     */
    public function trustPrivateHosts(array $hosts): self
    {
        return $this->with(privateHosts: [...$this->privateHosts, ...$hosts]);
    }

    /** Drop every private-host exemption: no name may resolve to a private address. */
    public function withoutPrivateHosts(): self
    {
        return $this->with(privateHosts: []);
    }

    /**
     * Exempt these addresses / CIDRs from the blocked-network table (added to the list).
     *
     * @param  list<string>  $ranges
     */
    public function allowRanges(array $ranges): self
    {
        return $this->with(allowedRanges: [...$this->allowedRanges, ...$ranges]);
    }

    /** Drop every allowed range: the whole blocked-network table applies again. */
    public function withoutAllowedRanges(): self
    {
        return $this->with(allowedRanges: []);
    }

    /**
     * Always refuse these addresses / CIDRs (added to the list); they beat every allow.
     *
     * @param  list<string>  $ranges
     */
    public function blockRanges(array $ranges): self
    {
        return $this->with(blockedRanges: [...$this->blockedRanges, ...$ranges]);
    }

    /**
     * More names that mean the app itself (added to the list).
     *
     * @param  list<string>  $hosts
     */
    public function appHosts(array $hosts): self
    {
        return $this->with(appHosts: [...$this->appHosts, ...$hosts]);
    }

    /**
     * Refuse the app's own host names (`true`) or let requests reach them — either way, whatever
     * the configuration said. The app hosts themselves are kept.
     */
    public function blockAppHosts(bool $block = true): self
    {
        return $this->with(blockAppHosts: $block);
    }

    /** Let requests reach the app's own host names: `blockAppHosts(false)`. */
    public function withoutAppHostBlock(): self
    {
        return $this->blockAppHosts(false);
    }

    public function redirects(RedirectPolicy $redirects): self
    {
        return $this->with(redirects: $redirects);
    }

    /** Follow redirects — 307 and 308 unless the policy names others — at most `$max` hops. */
    public function followRedirects(int $max = 3): self
    {
        return $this->with(redirects: $this->redirects->enabled(true, $max));
    }

    /**
     * `Http::safe()` only: cut every response body at `$bytes` — the transfer stops there, the
     * response keeps its status and headers and gains `Safe-Fetch-Truncated: 1`. Null for no cap.
     * (`SafeFetch::fetch()` has its own `maxBytes`, which fails with `too_large` instead.)
     */
    public function maxResponseBytes(?int $bytes): self
    {
        return new self($this->schemes, $this->ports, $this->allowedHosts, $this->blockedHosts, $this->privateHosts, $this->allowedRanges, $this->blockedRanges, $this->blockAppHosts, $this->appHosts, $this->redirects, $bytes);
    }

    /**
     * @param  list<string>|null  $schemes
     * @param  list<string>|null  $allowedHosts
     * @param  list<string>|null  $blockedHosts
     * @param  list<string>|null  $privateHosts
     * @param  list<string>|null  $allowedRanges
     * @param  list<string>|null  $blockedRanges
     * @param  list<string>|null  $appHosts
     */
    private function with(
        ?array $schemes = null,
        ?array $allowedHosts = null,
        ?array $blockedHosts = null,
        ?array $privateHosts = null,
        ?array $allowedRanges = null,
        ?array $blockedRanges = null,
        ?bool $blockAppHosts = null,
        ?array $appHosts = null,
        ?RedirectPolicy $redirects = null,
    ): self {
        return new self(
            $schemes ?? $this->schemes,
            $this->ports,
            $allowedHosts ?? $this->allowedHosts,
            $blockedHosts ?? $this->blockedHosts,
            $privateHosts ?? $this->privateHosts,
            $allowedRanges ?? $this->allowedRanges,
            $blockedRanges ?? $this->blockedRanges,
            $blockAppHosts ?? $this->blockAppHosts,
            $appHosts ?? $this->appHosts,
            $redirects ?? $this->redirects,
            $this->maxResponseBytes,
        );
    }

    /**
     * @param  array<mixed>  $schemes
     * @return list<string>
     */
    private static function validSchemes(array $schemes): array
    {
        $allowed = [];

        foreach ($schemes as $scheme) {
            if ($scheme !== 'http' && $scheme !== 'https') {
                $shown = is_scalar($scheme) ? (string) $scheme : get_debug_type($scheme);

                throw new InvalidArgumentException("A scheme must be http or https, [{$shown}] given.");
            }

            $allowed[$scheme] = $scheme;
        }

        if ($allowed === []) {
            throw new InvalidArgumentException('Allow at least one scheme.');
        }

        ksort($allowed);

        return array_values($allowed);
    }

    /**
     * @param  array<mixed>  $ports
     * @return list<int>
     */
    private static function validPorts(array $ports): array
    {
        $allowed = [];

        foreach ($ports as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535) {
                $shown = is_scalar($port) ? (string) $port : get_debug_type($port);

                throw new InvalidArgumentException("A port must be an integer between 1 and 65535, [{$shown}] given.");
            }

            $allowed[$port] = $port;
        }

        if ($allowed === []) {
            throw new InvalidArgumentException('Allow at least one port, or pass null for any port.');
        }

        ksort($allowed);

        return array_values($allowed);
    }

    /**
     * @param  array<mixed>  $hosts
     * @return list<string>
     */
    private static function validHosts(array $hosts): array
    {
        $patterns = [];

        foreach ($hosts as $host) {
            if (! is_string($host)) {
                throw new InvalidArgumentException('A host entry must be a string, ['.get_debug_type($host).'] given.');
            }

            $pattern = HostPattern::normalise($host);
            $patterns[$pattern] = $pattern;
        }

        return array_values($patterns);
    }

    /**
     * @param  array<mixed>  $ranges
     * @return list<string>
     */
    private static function validRanges(array $ranges, bool $allowed): array
    {
        $valid = [];

        foreach ($ranges as $range) {
            if (! is_string($range) || ! AddressRanges::isValid(trim($range))) {
                $shown = is_scalar($range) ? (string) $range : get_debug_type($range);

                throw new InvalidArgumentException("[{$shown}] is not an address or an address/prefix range.");
            }

            $range = trim($range);

            if ($allowed && AddressRanges::prefixLength($range) === 0) {
                throw new InvalidArgumentException("[{$range}] would allow every address and switch the guard off; do not guard the request instead.");
            }

            $valid[$range] = $range;
        }

        return array_values($valid);
    }
}
