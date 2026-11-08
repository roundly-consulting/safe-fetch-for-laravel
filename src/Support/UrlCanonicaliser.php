<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;

/**
 * Parses a URL the one way the guard accepts, or refuses it.
 *
 * The URL is parsed here, strictly, rather than by PHP's own URL parser: that parser and curl's
 * read some hosts differently (`http://a.com\@127.0.0.1/`, `http://127.0.0.1#@a.com/`), so a guard
 * would vet one host while curl dials another. Anything a parser could read two ways is refused,
 * and the request URL is rebuilt from the parts that were vetted. In order, first failure wins:
 *
 * 1. `invalid_url` — a control character (C0 or DEL) anywhere in the URL: WHATWG parsers strip a
 *    tab or newline silently, so it is refused rather than guessed at;
 * 2. `invalid_url` — no `scheme:`; `scheme` — not http(s);
 * 3. `invalid_url` — no `//` authority, or an empty one;
 * 4. `non_canonical_host` — a backslash, whitespace (Unicode whitespace and U+FEFF too) or `%` in
 *    the authority (a percent-encoded host, an IPv6 zone id);
 * 5. `credentials` — any user info (`@`);
 * 6. `invalid_url` — a port that is not digits; `port` — a port outside 1–65535;
 * 7. `non_canonical_host` — anything but a canonical dotted-quad IPv4 address, a bracketed IPv6
 *    address or a host name of `[a-z0-9_-]` labels (1–63 characters, 253 in total). Numeric IPv4
 *    spellings (`2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`) are refused, never normalised. One
 *    trailing dot is dropped. A host with a non-ASCII character or a punycode (`xn--`) label goes
 *    through UTS #46 (nontransitional, with the hyphen, bidi and joiner rules) with ext-intl, and
 *    is refused without it; an address it maps to (`８.８.８.８`, `127。0。0。1`) is refused too:
 *    an address is written in ASCII;
 * 8. `invalid_url` — a backslash in the path, query or fragment (WHATWG parsers read it as `/`).
 *
 * The fragment is dropped. The scheme *policy* (https only by default) is the guard's, applied
 * after this. The contract fixture pins these verdicts.
 *
 * @internal
 */
final readonly class UrlCanonicaliser
{
    /**
     * @param  bool|null  $intl  whether ext-intl is there (testing seam); null detects it
     */
    public function __construct(
        private ?bool $intl = null,
    ) {}

    /**
     * @throws DestinationRejected
     */
    public function canonicalise(string $url): CanonicalUrl
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $match) !== 1) {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        $scheme = strtolower($match[1]);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw self::refuse(Reason::Scheme, $url);
        }

        $rest = substr($url, strlen($match[0]));

        if (! str_starts_with($rest, '//')) {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        $rest = substr($rest, 2);
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $remainder = substr($rest, strlen($authority));

        if ($authority === '') {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        // A space, a backslash or `%` — or any other whitespace a WHATWG parser knows, U+FEFF
        // included, which IDNA would drop without a trace.
        if (preg_match('/[\x20\\\\%]/', $authority) === 1
            || preg_match('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', $authority) === 1) {
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        if (str_contains($authority, '@')) {
            throw self::refuse(Reason::Credentials, $url);
        }

        [$host, $port] = $this->splitAuthority($authority, $url);
        $port = $this->port($port, $scheme, $url);
        [$host, $ipLiteral] = $this->host($host, $url);

        if (str_contains($remainder, '\\')) {
            throw self::refuse(Reason::InvalidUrl, $url, $host);
        }

        $pathAndQuery = substr($remainder, 0, strcspn($remainder, '#'));

        return new CanonicalUrl(
            $scheme,
            $host,
            $port,
            $ipLiteral,
            str_starts_with($pathAndQuery, '/') ? $pathAndQuery : '/'.$pathAndQuery,
        );
    }

    /**
     * The host part (brackets kept) and the port part (null when there is none).
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitAuthority(string $authority, string $url): array
    {
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            $after = $close === false ? '' : substr($authority, $close + 1);

            if ($close === false || ($after !== '' && ! str_starts_with($after, ':'))) {
                throw self::refuse(Reason::NonCanonicalHost, $url);
            }

            return [substr($authority, 0, $close + 1), $after === '' ? null : substr($after, 1)];
        }

        if (substr_count($authority, ':') > 1) {
            // An IPv6 address without brackets: where it ends and a port begins is a guess.
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        $parts = explode(':', $authority, 2);

        if ($parts[0] === '') {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        return [$parts[0], $parts[1] ?? null];
    }

    /** The port, the scheme's default when none (or an empty one) is given. */
    private function port(?string $port, string $scheme, string $url): int
    {
        if ($port === null || $port === '') {
            return $scheme === 'https' ? 443 : 80;
        }

        if (! ctype_digit($port)) {
            throw self::refuse(Reason::InvalidUrl, $url);
        }

        $digits = ltrim($port, '0');

        if ($digits === '' || strlen($digits) > 5 || (int) $digits > 65535) {
            throw self::refuse(Reason::Port, $url);
        }

        return (int) $digits;
    }

    /**
     * The canonical host, and whether it is an address literal.
     *
     * @return array{0: string, 1: bool}
     */
    private function host(string $host, string $url): array
    {
        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);
            $bytes = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : @inet_pton($address);

            if ($bytes === false || strlen($bytes) !== 16) {
                throw self::refuse(Reason::NonCanonicalHost, $url);
            }

            return [(string) inet_ntop($bytes), true];
        }

        if (str_contains($host, '[') || str_contains($host, ']')) {
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        $converted = preg_match('/[^\x00-\x7F]/', $host) === 1 || preg_match('/(^|\.)xn--/i', $host) === 1;

        if ($converted) {
            $host = $this->toAscii($host, $url);
        }

        $host = strtolower($host);
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $labels = explode('.', $host);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $labels[count($labels) - 1]) === 1) {
            // Ends in a number, so every parser reads an IPv4 address — accepted only as the one
            // spelling all of them agree on, and only when it was typed as one: IDNA maps
            // `８.８.８.８` to it, and so normalises an address the guard refuses to normalise.
            if ($converted || count($labels) !== 4) {
                throw self::refuse(Reason::NonCanonicalHost, $url);
            }

            foreach ($labels as $label) {
                if (preg_match('/^(0|[1-9][0-9]{0,2})$/', $label) !== 1 || (int) $label > 255) {
                    throw self::refuse(Reason::NonCanonicalHost, $url);
                }
            }

            return [$host, true];
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9_-]{1,63}$/', $label) !== 1) {
                throw self::refuse(Reason::NonCanonicalHost, $url);
            }
        }

        if (strlen($host) > 253) {
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        return [$host, false];
    }

    /**
     * An internationalised host name in its ASCII (punycode) form: UTS #46, nontransitional, with
     * the bidi, joiner and hyphen rules. A punycode label is decoded and checked the same way, so
     * `xn--localhost` (not valid punycode) and `xn----eha` (`-ü`, a leading hyphen) are refused.
     */
    private function toAscii(string $host, string $url): string
    {
        if (! ($this->intl ?? function_exists('idn_to_ascii'))) {
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        $ascii = idn_to_ascii(
            $host,
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
            INTL_IDNA_VARIANT_UTS46,
        );

        if ($ascii === false || preg_match('/[^\x00-\x7F]/', $ascii) === 1) {
            throw self::refuse(Reason::NonCanonicalHost, $url);
        }

        return $ascii;
    }

    private static function refuse(Reason $reason, string $url, string $host = ''): DestinationRejected
    {
        return DestinationRejected::because($reason, $url, $host);
    }
}
