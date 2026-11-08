<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Events;

use RoundlyConsulting\SafeFetch\Enums\Reason;

/**
 * The guard refused a destination — at vetting, or when a request it sent connected somewhere it
 * had not vetted (`cannot_pin`). Worth alerting on: refusals are what an SSRF attempt looks like.
 *
 * Carries the reason, the host and (for `blocked`) the rule that refused it — never the URL, which
 * can be a capability URL, and never a resolved address.
 */
final readonly class DestinationRefused
{
    public function __construct(
        public Reason $reason,
        public string $host,
        public ?string $blockedBy = null,
    ) {}
}
