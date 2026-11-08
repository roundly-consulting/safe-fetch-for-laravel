<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\SafeFetch\SafeFetchServiceProvider;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    // No migrationSources(): the package has no database.

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [SafeFetchServiceProvider::class];
    }

    protected function tearDown(): void
    {
        CurlSupport::forget();

        parent::tearDown();
    }
}
