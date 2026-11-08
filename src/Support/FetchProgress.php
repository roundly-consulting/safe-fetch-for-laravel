<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;

/**
 * What one `SafeFetch::fetch()` learned while its transfer ran: the hop it is on, the buffer that
 * hop's body goes to, and why it stopped a transfer early (a non-2xx head, a declared length over
 * the cap).
 *
 * @internal
 */
final class FetchProgress
{
    public ?VettedDestination $hop = null;

    public ?ResponseInterface $headersOnly = null;

    public bool $declaredTooLarge = false;

    public function __construct(
        public CappedSink $sink,
    ) {}
}
