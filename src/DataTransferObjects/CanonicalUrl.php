<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

/**
 * A URL the guard parsed itself and rebuilt from its parts — `SafeFetch::urls()->canonicalise()`.
 *
 * Scheme and host are lowercase, user info and fragment are gone, a trailing dot and the scheme's
 * default port are dropped, an IPv6 address is compressed and bracketed; path and query are kept
 * as written (an empty path is `/`). No parser can read another host into {@see self::toString()}.
 */
final readonly class CanonicalUrl
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public bool $ipLiteral,
        public string $pathAndQuery,
    ) {}

    /** The URL a request is sent to: no user info, no fragment, no default port. */
    public function toString(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme.'://'.$this->bracketedHost().($this->port === $default ? '' : ':'.$this->port).$this->pathAndQuery;
    }

    /** `scheme://host:port`, the port always written, an IPv6 host bracketed. */
    public function origin(): string
    {
        return $this->scheme.'://'.$this->bracketedHost().':'.$this->port;
    }

    private function bracketedHost(): string
    {
        return str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
    }
}
