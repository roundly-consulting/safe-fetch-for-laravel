<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
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
 * 4. name rules: a blocked host → `blocked` (`blocked_host`); one of the app's own hosts, or an
 *    address literal that is the app's own address (`app.url` written as one) →
 *    `blocked` (`app_host`); not on a strict allowlist → `not_allowed` (an address literal never is
 *    on it); `localhost` / `*.localhost` not listed as a private host → `blocked` (`hostname`);
 * 5. resolve the name **once**; no address → `unresolved`;
 * 6. every address: in a blocked range → `blocked` (`blocked_range`); private and neither in an
 *    allowed range nor the name a private host → `blocked` (`private_address`). A private host
 *    never exempts a cloud-metadata address ({@see PrivateNetworks::METADATA}): only an allowed
 *    range does.
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
     * Every rule that needs no lookup — steps 1–4, and step 6 for an address literal — so a URL
     * can be judged at save time without DNS. A name passes when no name rule refuses it; it is
     * **not** resolved, so it can still resolve privately: {@see self::vet()} it again at send time.
     *
     * @throws DestinationRejected
     */
    public function precheck(string $url, DestinationPolicy $policy): CanonicalUrl
    {
        $canonical = $this->canonicaliser->canonicalise($url);
        $host = $canonical->host;

        if (! in_array($canonical->scheme, $policy->schemes, true)) {
            throw DestinationRejected::because(Reason::Scheme, $url, $host);
        }

        if ($policy->ports !== null && ! in_array($canonical->port, $policy->ports, true)) {
            throw DestinationRejected::because(Reason::Port, $url, $host);
        }

        if (! $canonical->ipLiteral) {
            if (HostPattern::matches($host, $policy->blockedHosts)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'blocked_host');
            }

            if ($policy->blockAppHosts && HostPattern::matches($host, $policy->appHosts)) {
                throw DestinationRejected::because(Reason::Blocked, $url, $host, 'app_host');
            }
        } elseif ($policy->blockAppHosts && self::isAppAddress($host, $policy)) {
            throw DestinationRejected::because(Reason::Blocked, $url, $host, 'app_host');
        }

        // A strict allowlist names hosts, so an address literal is never on it: "only these hosts"
        // must not let `https://203.0.113.9/` through.
        if ($policy->allowedHosts !== [] && ($canonical->ipLiteral || ! HostPattern::matches($host, $policy->allowedHosts))) {
            throw DestinationRejected::because(Reason::NotAllowed, $url, $host);
        }

        if ($canonical->ipLiteral) {
            $this->judge($host, $url, $host, $policy, privateHost: false);
        } elseif (! self::isPrivateHost($host, $policy) && PrivateNetworks::isBlockedHostname($host)) {
            throw DestinationRejected::because(Reason::Blocked, $url, $host, 'hostname');
        }

        return $canonical;
    }

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable when the host name does not resolve
     */
    public function vet(string $url, DestinationPolicy $policy): VettedDestination
    {
        $canonical = $this->precheck($url, $policy);
        $host = $canonical->host;
        $privateHost = ! $canonical->ipLiteral && self::isPrivateHost($host, $policy);

        $addresses = $canonical->ipLiteral ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw DestinationUnavailable::unresolved($url, $host);
        }

        $vetted = [];

        foreach ($addresses as $address) {
            $address = $this->judge($address, $url, $host, $policy, $privateHost);
            $vetted[$address] = $address;
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

    /**
     * Step 6 for one address: its normal form, or the refusal.
     *
     * @throws DestinationRejected
     */
    private function judge(string $address, string $url, string $host, DestinationPolicy $policy, bool $privateHost): string
    {
        $bytes = AddressRanges::bytes($address);

        // A resolver answer that is no address is never dialled, whatever the policy trusts.
        if ($bytes === null) {
            throw DestinationRejected::because(Reason::Blocked, $url, $host, 'private_address');
        }

        $address = (string) inet_ntop($bytes);

        if (AddressRanges::contains($policy->blockedRanges, $address)) {
            throw DestinationRejected::because(Reason::Blocked, $url, $host, 'blocked_range');
        }

        $exempt = AddressRanges::contains($policy->allowedRanges, $address)
            || ($privateHost && ! PrivateNetworks::isMetadata($address));

        if (PrivateNetworks::isPrivate($address) && ! $exempt) {
            throw DestinationRejected::because(Reason::Blocked, $url, $host, 'private_address');
        }

        return $address;
    }

    /** Whether an address literal is one of the app's own addresses, however it is written (`::ffff:` included). */
    private static function isAppAddress(string $address, DestinationPolicy $policy): bool
    {
        foreach ($policy->appAddresses as $appAddress) {
            if (AddressRanges::same($address, $appAddress)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateHost(string $host, DestinationPolicy $policy): bool
    {
        return HostPattern::matches($host, $policy->privateHosts);
    }
}
