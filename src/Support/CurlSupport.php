<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Composer\InstalledVersions;
use GuzzleHttp\ClientInterface;

/**
 * What the installed curl and Guzzle can do for the guard: pin a connection at all
 * (`CURLOPT_RESOLVE`, curl functions not disabled), and check the connected address before a byte
 * is sent (`CURLOPT_PREREQFUNCTION`: PHP 8.4 with libcurl 7.80+, and not Guzzle 7.12.x, whose
 * raw-option check deprecates it — fixed in 7.13.0).
 *
 * @internal
 */
final class CurlSupport
{
    private static ?bool $assumedAvailable = null;

    private static ?string $assumedGuzzle = null;

    /** Whether a guarded request can be pinned. Without it, sending refuses with `cannot_pin`. */
    public static function available(): bool
    {
        if (self::$assumedAvailable !== null) {
            return self::$assumedAvailable;
        }

        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            return false;
        }

        $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));

        return ! in_array('curl_exec', $disabled, true) && ! in_array('curl_multi_exec', $disabled, true);
    }

    /** Whether each hop gets the pre-send address check. */
    public static function preSendCheck(): bool
    {
        if (! self::available() || ! defined('CURLOPT_PREREQFUNCTION') || ! defined('CURL_PREREQFUNC_OK')) {
            return false;
        }

        return ! str_starts_with(self::guzzleVersion(), '7.12.');
    }

    /** The installed Guzzle version (`8.3.1`), or its major (`8`) when Composer cannot say. */
    public static function guzzleVersion(): string
    {
        if (self::$assumedGuzzle !== null) {
            return self::$assumedGuzzle;
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('guzzlehttp/guzzle')) {
            return ltrim((string) InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'), 'v');
        }

        return (string) ClientInterface::MAJOR_VERSION;
    }

    /** The libcurl version (`8.23.0`), or null without ext-curl. */
    public static function curlVersion(): ?string
    {
        if (! function_exists('curl_version')) {
            return null;
        }

        $version = curl_version();

        return is_array($version) && is_string($version['version'] ?? null) ? $version['version'] : null;
    }

    /**
     * Testing seam: pretend curl is (not) there, or another Guzzle is installed. `forget()` undoes it.
     */
    public static function assume(?bool $available = null, ?string $guzzleVersion = null): void
    {
        self::$assumedAvailable = $available;
        self::$assumedGuzzle = $guzzleVersion;
    }

    public static function forget(): void
    {
        self::assume();
    }
}
