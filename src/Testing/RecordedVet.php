<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Testing;

use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;

/**
 * One URL the guard judged under `SafeFetch::fake()`: how it was asked (`vet`, `allows`, or `http`
 * for a hop of a guarded request) and its verdict — the vetted destination, or the reason it was
 * refused.
 */
final readonly class RecordedVet
{
    public function __construct(
        public string $url,
        public string $via,
        public ?VettedDestination $vetted,
        public ?Reason $reason,
    ) {}

    public function refused(): bool
    {
        return $this->reason !== null;
    }
}
