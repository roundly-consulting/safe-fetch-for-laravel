<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;

/**
 * Judges a URL under a policy before anything dials it. In order, first failure wins:
 *
 * 1. canonicalise ({@see UrlCanonicaliser});
 * 2. `scheme` — not one the policy allows (https only by default);
 * 3. `port` — not one the policy allows (when it lists any);
 * 4. name rules: a blocked host → `blocked` (`blocked_host`); one of the app's own hosts →
 *    `blocked` (`app_host`); not on a strict allowlist → `not_allowed` (an address literal never is
 *    on it); `localhost` / `*.localhost` not listed as a private host → `blocked` (`hostname`);
 * 5. resolve the name **once**; no address → `unresolved`;
 * 6. every address: in a blocked range → `blocked` (`blocked_range`); private and neither in an
 *    allowed range nor the name a private host → `blocked` (`private_address`).
 *
 * One private answer blocks the whole name.
 *
 * @internal
 */
final readonly class DestinationGuard
{
    public function __construct(
        private HostResolver $resolver,
        private UrlCanonicaliser $canonicaliser,
    ) {}

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable when the host name does not resolve
     */
    public function vet(string $url, DestinationPolicy $policy): VettedDestination
    {
        $canonical = $this->canonicaliser->canonicalise($url);
        $host = $canonical->host;

        if (! in_array($canonical->scheme, $policy->schemes, true)) {
            throw DestinationRejected::because(Reason::Scheme, $url, $host);
        }

        if ($policy->ports !== null && ! in_array($canonical->port, $policy->ports, true)) {
            throw DestinationRejected::because(Reason::Port, $url, $host);
        }

        $privateHost = false;

        if (! $canonical->ipLiteral) {
            if (HostPattern::matches($host, $policy->blockedHosts)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'blocked_host');
            }

            if ($policy->blockAppHosts && HostPattern::matches($host, $policy->appHosts)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'app_host');
            }
        }

        // A strict allowlist names hosts, so an address literal is never on it: "only these hosts"
        // must not let `https://203.0.113.9/` through.
        if ($policy->allowedHosts !== [] && ($canonical->ipLiteral || ! HostPattern::matches($host, $policy->allowedHosts))) {
            throw DestinationRejected::because(Reason::NotAllowed, $url, $host);
        }

        if (! $canonical->ipLiteral) {

            $privateHost = HostPattern::matches($host, $policy->privateHosts);

            if (! $privateHost && PrivateNetworks::isBlockedHostname($host)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'hostname');
            }
        }

        $addresses = $canonical->ipLiteral ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw DestinationUnavailable::unresolved($url, $host);
        }

        $vetted = [];

        foreach ($addresses as $address) {
            $bytes = AddressRanges::bytes($address);

            // A resolver answer that is no address is never dialled, whatever the policy trusts.
            if ($bytes === null) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'private_address');
            }

            $address = (string) inet_ntop($bytes);
            $vetted[$address] = $address;

            if (AddressRanges::contains($policy->blockedRanges, $address)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'blocked_range');
            }

            if (PrivateNetworks::isPrivate($address) && ! $privateHost && ! AddressRanges::contains($policy->allowedRanges, $address)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'private_address');
            }
        }

        return new VettedDestination(
            $canonical->toString(),
            $canonical->scheme,
            $host,
            $canonical->port,
            $canonical->ipLiteral,
            array_values($vetted),
        );
    }
}
