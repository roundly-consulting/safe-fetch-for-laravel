<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Testing\FakeHostResolver;
use RoundlyConsulting\SafeFetch\Testing\SafeFetchFake;

/**
 * @method static \RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination vet(string $url, \RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy|null $policy = null)
 * @method static bool allows(string $url, \RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy|null $policy = null)
 * @method static \Illuminate\Http\Client\PendingRequest http(\RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy|null $policy = null)
 * @method static \Illuminate\Http\Client\PendingRequest guard(\Illuminate\Http\Client\PendingRequest $request, \RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy|null $policy = null)
 * @method static \RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse fetch(string $url, int|null $maxBytes = null, int|null $timeout = null, string|null $accept = null, array<string, string> $headers = [], bool|null $followRedirects = null, \RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy|null $policy = null)
 * @method static bool truncated(\Illuminate\Http\Client\Response|\Psr\Http\Message\ResponseInterface $response)
 * @method static \RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy policy()
 * @method static \RoundlyConsulting\SafeFetch\Handles\Networks networks()
 * @method static \RoundlyConsulting\SafeFetch\Handles\Urls urls()
 * @method static SafeFetchFake fake(array<string, string|list<string>> $dns = [])
 * @method static FakeHostResolver fakeDns(array<string, string|list<string>> $records = [])
 * @method static void assertVetted(string|\Closure $url)
 * @method static void assertNotVetted(string $url)
 * @method static void assertRefused(string $url, \RoundlyConsulting\SafeFetch\Enums\Reason|null $reason = null)
 * @method static void assertFetched(string|\Closure $url)
 * @method static void assertNothingFetched()
 * @method static void assertNothingVetted()
 * @method static void assertGuardedRequestSent(\Closure|null $callback = null)
 *
 * @see SafeFetchManager
 */
final class SafeFetch extends Facade
{
    /**
     * Swap the manager for a recording fake that keeps the real guard — behind the facade and in
     * the container, so an injected SafeFetchManager is faked too. Unknown host names resolve to a
     * public address (`strictDns()` turns that off); `$dns` pins names like {@see self::fakeDns()}.
     * Requests still go through Laravel's client: answer them with `Http::fake()`.
     *
     * @param  array<string, string|list<string>>  $dns
     */
    public static function fake(array $dns = []): SafeFetchFake
    {
        $fake = Container::getInstance()->make(SafeFetchFake::class);
        $fake->dns()->answerUnknownNamesWith(['93.184.216.34']);

        foreach ($dns as $host => $addresses) {
            $addresses === [] ? $fake->dns()->unresolvable((string) $host) : $fake->dns()->resolve((string) $host, $addresses);
        }

        self::swap($fake);

        return $fake;
    }

    /**
     * Answer the guard's DNS lookups from a table: a name not given does not resolve, so a test
     * never reaches real DNS. Pins add up across calls.
     *
     * @param  array<string, string|list<string>>  $records  host name => its address(es); `[]` = unresolvable
     */
    public static function fakeDns(array $records = []): FakeHostResolver
    {
        return FakeHostResolver::install(Container::getInstance(), $records);
    }

    protected static function getFacadeAccessor(): string
    {
        return SafeFetchManager::class;
    }
}
