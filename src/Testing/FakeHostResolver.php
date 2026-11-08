<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Testing;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\UrlCanonicaliser;

/**
 * The DNS table {@see SafeFetch::fakeDns()} installs: the guard's {@see HostResolver} binding asks it
 * instead of DNS.
 *
 * A name it was given resolves to exactly the addresses pinned for it, and the guard judges them as
 * it judges real answers — a private one is refused. **A name it was not given does not resolve**
 * (under `SafeFetch::fake()` it resolves to the public `93.184.216.34` instead, unless
 * `strictDns()`), so a test never reaches real DNS. Address literals and names refused by spelling
 * (`localhost`, unless it is a trusted private host) are never looked up.
 *
 * Only DNS is faked. The requests themselves go through Laravel's HTTP client: answer them with
 * `Http::fake()`. The table lives in the application container, so it ends with the test.
 */
final class FakeHostResolver
{
    /** @var array<string, list<string>> host name => the addresses it resolves to */
    private array $answers = [];

    /** @var list<string> every name the guard looked up, in order */
    private array $lookups = [];

    /** @var list<string> what a name the table was not given resolves to (empty: nothing) */
    private array $fallback = [];

    public function __construct(
        private readonly UrlCanonicaliser $canonicaliser = new UrlCanonicaliser,
    ) {}

    /**
     * Install a table into `$container` — the one already there, if any, so pins add up — and pin
     * `$records`.
     *
     * @param  array<string, string|list<string>>  $records  host name => its address(es); `[]` = unresolvable
     *
     * @internal called by {@see SafeFetch::fakeDns()} and `SafeFetch::fake()`
     */
    public static function install(Container $container, array $records = []): self
    {
        $fake = $container->bound(self::class) ? $container->make(self::class) : new self;

        $container->instance(self::class, $fake);
        $container->instance(HostResolver::class, new FakeHostLookup($fake));

        foreach ($records as $host => $addresses) {
            // PHP turns an all-digit key into an int; the guard refuses such a name anyway.
            $host = (string) $host;
            $addresses === [] ? $fake->unresolvable($host) : $fake->resolve($host, $addresses);
        }

        return $fake;
    }

    /**
     * Make `$host` resolve to `$addresses` (replacing an earlier pin for it). The name is matched
     * the way the guard writes it: lower case, without a trailing dot, an international name in its
     * punycode form.
     *
     * @param  string|list<string>  $addresses  IPv4 or IPv6 addresses, IPv6 without brackets
     *
     * @throws InvalidArgumentException for a name the guard would never look up, or a non-address
     */
    public function resolve(string $host, string|array $addresses): self
    {
        $name = $this->name($host);
        $addresses = is_string($addresses) ? [$addresses] : $addresses;

        if ($addresses === []) {
            throw new InvalidArgumentException("Pin at least one address for [{$host}], or call unresolvable().");
        }

        $pinned = array_map(static fn (mixed $address): string => self::address($host, $address), $addresses);

        $this->answers[$name] = array_values(array_unique($pinned));

        return $this;
    }

    /**
     * Make `$host` not resolve — what every name the table was not given does already (outside
     * `SafeFetch::fake()`); use it to drop an earlier pin.
     *
     * @throws InvalidArgumentException for a name the guard would never look up
     */
    public function unresolvable(string $host): self
    {
        $this->answers[$this->name($host)] = [];

        return $this;
    }

    /**
     * What a name the table was not given resolves to — `[]` (the default) for nothing.
     *
     * @param  list<string>  $addresses
     *
     * @internal `SafeFetch::fake()` answers unknown names with a public address
     */
    public function answerUnknownNamesWith(array $addresses): self
    {
        $this->fallback = array_map(static fn (string $address): string => self::address('*', $address), $addresses);

        return $this;
    }

    /**
     * Every name the guard looked up, in order, as it asked for it (one entry per lookup).
     *
     * @return list<string>
     */
    public function lookups(): array
    {
        return $this->lookups;
    }

    /** `$host` was looked up — at least once, or exactly `$times` times. */
    public function assertLookedUp(string $host, ?int $times = null): void
    {
        $name = $this->name($host);
        $count = $this->countOf($name);

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Expected [{$name}] to be looked up, but it was not.");

            return;
        }

        Assert::assertSame($times, $count, "Expected [{$name}] to be looked up {$times} time(s), but it was looked up {$count} time(s).");
    }

    public function assertNotLookedUp(string $host): void
    {
        $name = $this->name($host);
        $count = $this->countOf($name);

        Assert::assertSame(0, $count, "Expected [{$name}] not to be looked up, but it was looked up {$count} time(s).");
    }

    public function assertNothingLookedUp(): void
    {
        $count = count($this->lookups);

        Assert::assertSame(0, $count, "Expected no DNS lookups, but {$count} were made: ".implode(', ', $this->lookups).'.');
    }

    /**
     * The guard's lookup: the pinned addresses, or the fallback for a name the table was not given.
     *
     * @return list<string>
     *
     * @internal called through the {@see HostResolver} binding
     */
    public function lookup(string $host): array
    {
        $this->lookups[] = $host;

        return $this->answers[$host] ?? $this->fallback;
    }

    private function countOf(string $name): int
    {
        return count(array_keys($this->lookups, $name, true));
    }

    /**
     * `$address` in its canonical spelling, as a real lookup reports it. Typed `mixed`: nothing
     * stops a caller handing in something that is not a string.
     */
    private static function address(string $host, mixed $address): string
    {
        $bytes = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? @inet_pton($address) : false;

        if ($bytes === false) {
            $shown = is_scalar($address) ? (string) $address : get_debug_type($address);

            throw new InvalidArgumentException("Cannot pin [{$host}] to [{$shown}]: not an IP address.");
        }

        return (string) inet_ntop($bytes);
    }

    /** `$host` as the guard asks for it, or a refusal when the guard would never ask for it. */
    private function name(string $host): string
    {
        // Anything that would make it more than a host in a URL: a port, a path, credentials, brackets.
        if (preg_match('#[/?\#@:\[\]]#', $host) === 1) {
            throw self::notAHostName($host);
        }

        try {
            $canonical = $this->canonicaliser->canonicalise("http://{$host}/");
        } catch (SafeFetchException) {
            throw self::notAHostName($host);
        }

        if ($canonical->ipLiteral) {
            throw new InvalidArgumentException("Cannot pin [{$host}]: it is an address, and the guard never looks an address up.");
        }

        return $canonical->host;
    }

    private static function notAHostName(string $host): InvalidArgumentException
    {
        return new InvalidArgumentException("Cannot pin [{$host}]: not a host name the guard would look up.");
    }
}
