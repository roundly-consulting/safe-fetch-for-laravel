<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Testing;

use RoundlyConsulting\SafeFetch\Contracts\HostResolver;

/**
 * The {@see HostResolver} binding behind `SafeFetch::fakeDns()`: every lookup goes to the
 * {@see FakeHostResolver} table, which records it.
 *
 * @internal
 */
final readonly class FakeHostLookup implements HostResolver
{
    public function __construct(
        public FakeHostResolver $table,
    ) {}

    public function resolve(string $host): array
    {
        return $this->table->lookup($host);
    }
}
