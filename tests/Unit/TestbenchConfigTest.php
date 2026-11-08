<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Config;
use RoundlyConsulting\PackageTemplate\PackageTemplateServiceProvider;

/**
 * Larastan boots its app from `testbench.yaml(.dist)` with exactly this call. On a fresh CI
 * checkout it rebuilds Testbench's discovery manifest outside the testbench CLI, which drops
 * the root package, so the provider is registered only if the yaml names it. Without it the
 * package's views look missing to the view-string check (`View::make('pkg::view')`: expects
 * view-string, string given) and the phpstan workflow goes red on the first run.
 */
it('registers the package provider in the app Larastan boots', function (): void {
    $config = Config::loadFromYaml(dirname(__DIR__, 2));

    expect($config->getExtraAttributes()['providers'])->toBe([PackageTemplateServiceProvider::class]);
});
