<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\PinnedHandlers;
use RoundlyConsulting\SafeFetch\Support\UrlCanonicaliser;

/**
 * The manager `SafeFetch::fake()` installs. It keeps the **real** guard — a private address is
 * still refused — answers DNS from a table (unknown names resolve to the public `93.184.216.34`
 * unless {@see self::strictDns()}), and records every verdict: `vet()`, `allows()`, each hop of a
 * guarded request (`via: http`) and every `fetch()`.
 *
 * Requests still go through Laravel's HTTP client: answer them with `Http::fake()`. One that
 * reaches the network is refused before dialling (a test that forgot `Http::fake()` would
 * otherwise hit a real server); {@see self::allowRealRequests()} lets it through.
 */
final class SafeFetchFake extends SafeFetchManager
{
    /** @var list<RecordedVet> */
    private array $vets = [];

    /** @var list<string> */
    private array $fetches = [];

    private FakeHostResolver $dns;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->dns = FakeHostResolver::install($container);
        $container->make(PinnedHandlers::class)->refuseToDial();
    }

    /** The fake DNS table: pin names, inspect lookups. */
    public function dns(): FakeHostResolver
    {
        return $this->dns;
    }

    /** Names nobody pinned do not resolve (`unresolved`), as under `SafeFetch::fakeDns()`. */
    public function strictDns(): self
    {
        $this->dns->answerUnknownNamesWith([]);

        return $this;
    }

    /** Let a guarded request no `Http::fake()` stub answered go out to the network. */
    public function allowRealRequests(): self
    {
        $this->container->make(PinnedHandlers::class)->refuseToDial(false);

        return $this;
    }

    public function vet(string $url, ?DestinationPolicy $policy = null): VettedDestination
    {
        return $this->recording($url, 'vet', fn (): VettedDestination => parent::vet($url, $policy));
    }

    public function allows(string $url, ?DestinationPolicy $policy = null): bool
    {
        try {
            $this->recording($url, 'allows', fn (): VettedDestination => parent::vet($url, $policy));
        } catch (SafeFetchException) {
            return false;
        }

        return true;
    }

    public function vetHop(string $url, DestinationPolicy $policy): VettedDestination
    {
        return $this->recording($url, 'http', fn (): VettedDestination => parent::vetHop($url, $policy));
    }

    public function fetch(
        string $url,
        ?int $maxBytes = null,
        ?int $timeout = null,
        ?string $accept = null,
        array $headers = [],
        ?bool $followRedirects = null,
        ?DestinationPolicy $policy = null,
    ): FetchedResponse {
        $this->fetches[] = $url;

        return parent::fetch($url, $maxBytes, $timeout, $accept, $headers, $followRedirects, $policy);
    }

    /**
     * Every verdict so far, in order.
     *
     * @return list<RecordedVet>
     */
    public function recorded(): array
    {
        return $this->vets;
    }

    /**
     * `$url` was judged (and let through or not) — or a verdict matched `$url` as a callback.
     *
     * @param  string|(Closure(RecordedVet): bool)  $url
     */
    public function assertVetted(string|Closure $url): void
    {
        $matches = array_filter($this->vets, $url instanceof Closure ? $url : fn (RecordedVet $vet): bool => $this->sameUrl($vet->url, $url));

        PHPUnit::assertNotEmpty($matches, $url instanceof Closure
            ? 'Expected a URL matching the callback to be vetted, but none was.'
            : "Expected [{$url}] to be vetted, but it was not.");
    }

    public function assertNotVetted(string $url): void
    {
        $count = count(array_filter($this->vets, fn (RecordedVet $vet): bool => $this->sameUrl($vet->url, $url)));

        PHPUnit::assertSame(0, $count, "Expected [{$url}] not to be vetted, but it was vetted {$count} time(s).");
    }

    /** `$url` was refused — for `$reason`, when given. */
    public function assertRefused(string $url, ?Reason $reason = null): void
    {
        $verdicts = array_values(array_filter($this->vets, fn (RecordedVet $vet): bool => $this->sameUrl($vet->url, $url)));
        $refused = array_filter($verdicts, static fn (RecordedVet $vet): bool => $vet->refused() && ($reason === null || $vet->reason === $reason));

        $seen = implode(', ', array_map(static fn (RecordedVet $vet): string => $vet->reason->value ?? 'ok', $verdicts));

        PHPUnit::assertNotEmpty($refused, $verdicts === []
            ? "Expected [{$url}] to be refused, but it was never vetted."
            : "Expected [{$url}] to be refused".($reason === null ? '' : " ({$reason->value})").", but its verdicts were: {$seen}.");
    }

    /**
     * `fetch()` was called for `$url` — or with a URL the callback accepts.
     *
     * @param  string|(Closure(string): bool)  $url
     */
    public function assertFetched(string|Closure $url): void
    {
        $matches = array_filter($this->fetches, $url instanceof Closure ? $url : fn (string $fetched): bool => $this->sameUrl($fetched, $url));

        PHPUnit::assertNotEmpty($matches, $url instanceof Closure
            ? 'Expected a URL matching the callback to be fetched, but none was.'
            : "Expected [{$url}] to be fetched, but it was not.");
    }

    public function assertNothingFetched(): void
    {
        $count = count($this->fetches);

        PHPUnit::assertSame(0, $count, "Expected nothing to be fetched, but {$count} URL(s) were: ".implode(', ', $this->fetches).'.');
    }

    /** No verdict so far was a refusal — every URL vetted, allowed, prechecked or sent passed. */
    public function assertNothingRefused(): void
    {
        $refused = array_values(array_filter($this->vets, static fn (RecordedVet $vet): bool => $vet->refused()));
        $count = count($refused);

        PHPUnit::assertSame(0, $count, "Expected nothing to be refused, but {$count} URL(s) were: "
            .implode(', ', array_map(static fn (RecordedVet $vet): string => "{$vet->url} ({$vet->reason?->value})", $refused)).'.');
    }

    public function assertNothingVetted(): void
    {
        $count = count($this->vets);

        PHPUnit::assertSame(0, $count, "Expected nothing to be vetted, but {$count} URL(s) were: ".implode(', ', array_map(static fn (RecordedVet $vet): string => $vet->url, $this->vets)).'.');
    }

    /**
     * A guarded request (`Http::safe()`, `guard()`, `http()`, `fetch()`) passed the guard on at
     * least one hop — one whose vetted destination the callback accepts, when given.
     *
     * @param  (Closure(VettedDestination): bool)|null  $callback
     */
    public function assertGuardedRequestSent(?Closure $callback = null): void
    {
        $hops = array_filter(
            $this->vets,
            static fn (RecordedVet $vet): bool => $vet->via === 'http' && $vet->vetted !== null && ($callback === null || $callback($vet->vetted)),
        );

        PHPUnit::assertNotEmpty($hops, $callback === null
            ? 'Expected a guarded request to pass the guard, but none did.'
            : 'Expected a guarded request matching the callback to pass the guard, but none did.');
    }

    /**
     * @param  Closure(): VettedDestination  $vet
     */
    private function recording(string $url, string $via, Closure $vet): VettedDestination
    {
        try {
            $vetted = $vet();
        } catch (SafeFetchException $exception) {
            $this->vets[] = new RecordedVet($url, $via, null, $exception->reason());

            throw $exception;
        }

        $this->vets[] = new RecordedVet($url, $via, $vetted, null);

        return $vetted;
    }

    /** The same URL as written, or once both are rebuilt the guard's way. */
    private function sameUrl(string $recorded, string $expected): bool
    {
        if ($recorded === $expected) {
            return true;
        }

        $canonicaliser = new UrlCanonicaliser;

        try {
            return $canonicaliser->canonicalise($recorded)->toString() === $canonicaliser->canonicalise($expected)->toString();
        } catch (SafeFetchException) {
            return false;
        }
    }
}
