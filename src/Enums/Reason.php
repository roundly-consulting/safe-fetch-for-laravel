<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Why a URL was not fetched. The first seven are the vetting verdicts the contract fixture
 * (`tests/Fixtures/contract/blocked-networks.json`) pins; the rest come from the policy, from
 * sending and from `SafeFetch::fetch()`.
 */
enum Reason: string
{
    use Helpers;

    /** Not a URL the guard can parse one way only (a control character, no authority, a `\`). */
    case InvalidUrl = 'invalid_url';

    /** Not http(s), or a scheme the policy does not allow (https only by default). */
    case Scheme = 'scheme';

    /** User info (`user:pw@`) in the authority. */
    case Credentials = 'credentials';

    /** A host spelled in a form parsers disagree on (numeric IPv4, `%`, a zone id, whitespace). */
    case NonCanonicalHost = 'non_canonical_host';

    /** A port outside 1–65535, or one the policy does not allow. */
    case Port = 'port';

    /** A private, loopback, metadata or blocked destination; see `blockedBy()` on the exception. */
    case Blocked = 'blocked';

    /** The host name does not resolve. DNS can heal, so it is retryable. */
    case Unresolved = 'unresolved';

    /** The policy has a strict host allowlist and the host is not on it. */
    case NotAllowed = 'not_allowed';

    /** The connection could not be pinned to the vetted addresses (no curl, or it went elsewhere). */
    case CannotPin = 'cannot_pin';

    /** More redirects than the policy allows. */
    case TooManyRedirects = 'too_many_redirects';

    /** A redirect from https to http. */
    case RedirectDowngrade = 'redirect_downgrade';

    /** The time budget ran out. */
    case Timeout = 'timeout';

    /** `fetch()` got a non-2xx answer; retryable for 408, 429 and 5xx. */
    case Status = 'status';

    /** `fetch()` got a body larger than its byte cap. */
    case TooLarge = 'too_large';

    /** The connection failed or broke off. */
    case Connection = 'connection';

    /** The server's TLS certificate could not be verified. */
    case Tls = 'tls';

    /** The answer was not readable HTTP (a status outside 100–599, a broken head). */
    case Protocol = 'protocol';

    /**
     * Whether trying again later can help. For `status` the exception decides (408, 429 and 5xx
     * can heal, a 404 cannot), so this returns false for it.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::Unresolved, self::Timeout, self::Connection => true,
            default => false,
        };
    }
}
