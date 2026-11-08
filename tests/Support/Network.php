<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The real-network suite's prerequisites. Locally a missing one skips the test visibly; with
 * `SAFE_FETCH_REQUIRE_NETWORK_TESTS=1` (every CI job sets it) it fails instead, so a runner that
 * lost curl, proc_open or IPv6 loopback can never turn the suite green by skipping it.
 */
final class Network
{
    /** What is missing for the real-network tests, or null when nothing is. */
    public static function missing(bool $ipv6 = false): ?string
    {
        return match (true) {
            ! extension_loaded('curl') => 'ext-curl',
            ! function_exists('proc_open') => 'proc_open',
            $ipv6 && ! self::hasIpv6Loopback() => 'IPv6 loopback (::1)',
            default => null,
        };
    }

    public static function required(): bool
    {
        return in_array(getenv('SAFE_FETCH_REQUIRE_NETWORK_TESTS'), ['1', 'true', 'on', 'yes'], true);
    }

    /** Skip (locally) or fail (CI) when a prerequisite is missing. */
    public static function ensure(bool $ipv6 = false): void
    {
        $missing = self::missing($ipv6);

        if ($missing === null) {
            return;
        }

        if (self::required()) {
            Assert::fail("The real-network tests need {$missing}, and SAFE_FETCH_REQUIRE_NETWORK_TESTS forbids skipping them.");
        }

        test()->markTestSkipped("Needs {$missing}; set SAFE_FETCH_REQUIRE_NETWORK_TESTS=1 to make this a failure.");
    }

    public static function hasIpv6Loopback(): bool
    {
        $socket = @stream_socket_server('tcp://[::1]:0', $code, $message);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
