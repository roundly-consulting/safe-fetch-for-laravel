<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Events;

use RoundlyConsulting\SafeFetch\Enums\Reason;

/**
 * The guard refused a destination — at vetting, or when a request it sent connected somewhere it
 * had not vetted (`cannot_pin`). Worth alerting on: refusals are what an SSRF attempt looks like.
 *
 * Carries the reason, the host, (for `blocked`) the rule that refused it, and where the URL was
 * judged — never the URL, which can be a capability URL, and never a resolved address.
 *
 * `via` is `vet`, `allows` (the `SafeUrl` rule too), `precheck` (`SafeUrl::withoutLookup()` too),
 * `request` (a hop of a guarded request), `fetch` or `download` — so alerting can tell an attempt
 * on the network apart from a form that refused what someone typed.
 */
final readonly class DestinationRefused
{
    /**
     * @param  'vet'|'allows'|'precheck'|'request'|'fetch'|'download'  $via
     */
    public function __construct(
        public Reason $reason,
        public string $host,
        public ?string $blockedBy = null,
        public string $via = 'vet',
    ) {}
}
