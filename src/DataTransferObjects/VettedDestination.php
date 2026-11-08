<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Support\AddressRanges;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;

/**
 * A destination the guard accepted — `SafeFetch::vet($url)`.
 *
 * `url` is what a request is sent to: rebuilt from the vetted parts, so no parser can read another
 * host into it. `addresses` are every address the host resolved to (the address itself for a
 * literal), each judged allowed; a connection must use one of them. `Http::safe()` does all of
 * this for you; to send a request of your own, use {@see self::pinningOptions()}.
 */
final readonly class VettedDestination
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public bool $ipLiteral,
        public array $addresses,
    ) {}

    /** `scheme://host:port`, the port always written, an IPv6 host bracketed. */
    public function origin(): string
    {
        return $this->scheme.'://'.(str_contains($this->host, ':') ? '['.$this->host.']' : $this->host).':'.$this->port;
    }

    /**
     * The `CURLOPT_RESOLVE` entry pinning `host:port` to every vetted address, IPv6 bracketed, in one
     * entry so curl fails over only between vetted addresses (curl 7.59+); null for an address
     * literal, which has nothing to resolve.
     */
    public function resolveEntry(): ?string
    {
        if ($this->ipLiteral || $this->addresses === []) {
            return null;
        }

        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
            $this->addresses,
        );

        return $this->host.':'.$this->port.':'.implode(',', $addresses);
    }

    /**
     * The Laravel HTTP client (Guzzle) options that send a request of your own the way
     * `Http::safe()` sends one: pinned to the vetted addresses, never through a proxy (which would
     * resolve the name itself), no redirects (a redirect is a new URL to vet), no reused connection.
     *
     * ```php
     * Http::withOptions($vetted->pinningOptions())->get($vetted->url);
     * ```
     *
     * On its own this trusts the handler to honour curl options; `Http::safe()` also forces curl and
     * checks the address it connected to.
     *
     * @return array<string, mixed>
     *
     * @throws DestinationRejected (`cannot_pin`) without ext-curl
     */
    public function pinningOptions(): array
    {
        if (! CurlSupport::available()) {
            throw DestinationRejected::because(Reason::CannotPin, $this->url, $this->host);
        }

        $curl = [];
        $entry = $this->resolveEntry();

        if ($entry !== null) {
            $curl[CURLOPT_RESOLVE] = [$entry];
        }

        $curl[CURLOPT_FRESH_CONNECT] = true;
        $curl[CURLOPT_FORBID_REUSE] = true;

        return [
            'proxy' => '',
            'allow_redirects' => false,
            'protocols' => [$this->scheme],
            'curl' => $curl,
        ];
    }

    /** Whether a connection to `$address` stays inside the vetted set (`::ffff:a.b.c.d` is `a.b.c.d`). */
    public function connectsTo(string $address): bool
    {
        foreach ($this->addresses as $vetted) {
            if (AddressRanges::same($vetted, $address)) {
                return true;
            }
        }

        return false;
    }
}
