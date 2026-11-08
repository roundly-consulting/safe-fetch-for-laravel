<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;

/**
 * Reads `config/safe-fetch.php`, every key strictly: a wrong type or a value out of range throws
 * naming the key; blank means not set.
 *
 * @internal
 */
final class SafeFetchConfig
{
    /** The configured default policy. */
    public static function policy(): DestinationPolicy
    {
        return new DestinationPolicy(
            schemes: self::allowHttp() ? ['http', 'https'] : ['https'],
            ports: self::integers('safe-fetch.ports', config('safe-fetch.ports'), 1, 65535),
            allowedHosts: Config::list('safe-fetch.hosts.allowed', [], HostPattern::isValid(...)),
            blockedHosts: Config::list('safe-fetch.hosts.blocked', [], HostPattern::isValid(...)),
            privateHosts: Config::list('safe-fetch.hosts.private', [], HostPattern::isValid(...)),
            allowedRanges: self::notEverything('safe-fetch.ranges.allowed', Config::list('safe-fetch.ranges.allowed', [], AddressRanges::isValid(...))),
            blockedRanges: Config::list('safe-fetch.ranges.blocked', [], AddressRanges::isValid(...)),
            blockAppHosts: Config::boolean('safe-fetch.app_hosts.block', true),
            appHosts: [...self::appUrlHost(), ...Config::list('safe-fetch.app_hosts.extra', [], HostPattern::isValid(...))],
            redirects: self::redirects(),
        );
    }

    public static function allowHttp(): bool
    {
        return Config::boolean('safe-fetch.allow_http', false);
    }

    public static function redirects(): RedirectPolicy
    {
        $statuses = self::integers('safe-fetch.redirects.statuses', config('safe-fetch.redirects.statuses'), 100, 599);

        foreach ($statuses ?? [] as $status) {
            if (! in_array($status, RedirectLocation::STATUSES, true)) {
                throw InvalidConfigurationException::notAValidListItem('safe-fetch.redirects.statuses', (string) $status);
            }
        }

        $headers = Config::list(
            'safe-fetch.redirects.forward_headers',
            [],
            static fn (string $header): bool => preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $header) === 1,
        );

        return new RedirectPolicy(
            enabled: Config::boolean('safe-fetch.redirects.enabled', false),
            max: Config::integer('safe-fetch.redirects.max', 3, min: 0, max: 10),
            statuses: $statuses,
            allowDowngrade: Config::boolean('safe-fetch.redirects.allow_downgrade', false),
            forwardHeaders: $headers,
        );
    }

    public static function fetchMaxBytes(): int
    {
        return Config::integer('safe-fetch.fetch.max_bytes', 5_242_880, min: 1);
    }

    public static function fetchTimeout(): int
    {
        return Config::integer('safe-fetch.fetch.timeout', 30, min: 1, max: 600);
    }

    /**
     * The host of `app.url` — the app's own name — or nothing when it has none the guard can read.
     *
     * @return list<string>
     */
    private static function appUrlHost(): array
    {
        $url = config('app.url');

        if (! is_string($url) || trim($url) === '') {
            return [];
        }

        try {
            $canonical = (new UrlCanonicaliser)->canonicalise(trim($url));
        } catch (DestinationRejected) {
            return [];
        }

        return $canonical->ipLiteral ? [] : [$canonical->host];
    }

    /**
     * `$ranges`, unless one of them is every address: everything allowed is no guard at all, so
     * say so rather than run one that blocks nothing.
     *
     * @param  list<string>  $ranges
     * @return list<string>
     */
    private static function notEverything(string $key, array $ranges): array
    {
        foreach ($ranges as $range) {
            if (AddressRanges::prefixLength($range) === 0) {
                throw InvalidConfigurationException::notAValidListItem($key, $range);
            }
        }

        return $ranges;
    }

    /**
     * A list of integers between `$min` and `$max` — an array or an env comma list — or null when
     * not set. An explicit empty list throws: it reads as "none", which is no setting at all.
     *
     * @return list<int>|null
     */
    private static function integers(string $key, mixed $value, int $min, int $max): ?array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value) || $value === []) {
            throw InvalidConfigurationException::notAList($key, $value);
        }

        $integers = [];

        foreach ($value as $item) {
            $item = is_string($item) ? trim($item) : $item;

            if (is_string($item) && preg_match('/^[0-9]{1,6}$/', $item) === 1) {
                $item = (int) $item;
            }

            if (! is_int($item)) {
                throw InvalidConfigurationException::notAValidListItem($key, is_scalar($item) ? (string) $item : get_debug_type($item));
            }

            if ($item < $min || $item > $max) {
                throw InvalidConfigurationException::outOfRange($key, $min, $max, $item);
            }

            $integers[] = $item;
        }

        return $integers;
    }
}
