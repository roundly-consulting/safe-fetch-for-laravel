<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;

beforeEach(function (): void {
    SafeFetch::fakeDns(['docs.example' => ['93.184.216.34', '2606:4700::1111'], 'intranet.example' => '10.0.0.5']);
});

it('check prints the verdict on an allowed url', function (): void {
    $this->artisan('safe-fetch:check', ['url' => 'https://docs.example/a'])
        ->expectsOutputToContain('Allowed.')
        ->expectsOutputToContain('93.184.216.34, 2606:4700::1111')
        ->expectsOutputToContain('docs.example:443:93.184.216.34,[2606:4700::1111]')
        ->assertExitCode(0);
});

it('check prints the reason and the rule for a refused url', function (): void {
    $this->artisan('safe-fetch:check', ['url' => 'https://intranet.example/'])
        ->expectsOutputToContain('Refused: blocked.')
        ->expectsOutputToContain('private_address')
        ->assertExitCode(1);

    $this->artisan('safe-fetch:check', ['url' => 'https://unknown.example/'])
        ->expectsOutputToContain('Refused: unresolved.')
        ->assertExitCode(1);
});

it('check allows http for this check only', function (): void {
    $this->artisan('safe-fetch:check', ['url' => 'http://docs.example/'])->expectsOutputToContain('Refused: scheme.')->assertExitCode(1);
    $this->artisan('safe-fetch:check', ['url' => 'http://docs.example/', '--http' => true])->expectsOutputToContain('Allowed.')->assertExitCode(0);
    $this->artisan('safe-fetch:check', ['url' => 'http://93.184.216.34/', '--http' => true])->expectsOutputToContain('address literal')->assertExitCode(0);
});

it('check without a url reports the environment', function (): void {
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.ranges.allowed', ['10.20.0.0/16']);

    Artisan::call('safe-fetch:check');
    $output = Artisan::output();

    expect(Artisan::call('safe-fetch:check'))->toBe(0)
        ->and($output)->toContain('DNS pinning')
        ->and($output)->toContain('curl '.CurlSupport::curlVersion())
        ->and($output)->toContain('Pre-send address check')
        ->and($output)->toContain('International host names')
        ->and($output)->toContain('Guzzle')
        ->and($output)->toContain('Configuration')
        ->and($output)->not->toContain('minio.internal')
        ->and($output)->not->toContain('10.20.0.0');
});

it('check warns about settings that weaken the guard', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config()->set('safe-fetch.allow_http', true);
    config()->set('safe-fetch.ranges.allowed', ['10.0.0.0/7', 'fd00::/16']);
    config()->set('safe-fetch.redirects.enabled', true);
    config()->set('safe-fetch.redirects.allow_downgrade', true);

    $this->artisan('safe-fetch:check')
        ->expectsOutputToContain('http is allowed in production')
        ->expectsOutputToContain('2 allowed range(s) are broader')
        ->expectsOutputToContain('drop from https to http')
        ->assertExitCode(0);
});

it('check fails without curl or with a broken configuration', function (): void {
    CurlSupport::assume(available: false);

    $this->artisan('safe-fetch:check')->expectsOutputToContain('unavailable')->assertExitCode(1);

    CurlSupport::forget();
    config()->set('safe-fetch.allow_http', 'sometimes');

    $this->artisan('safe-fetch:check')->expectsOutputToContain('The configuration is invalid')->assertExitCode(1);
});

it('check reports the pre send check off where guzzle deprecates it', function (): void {
    CurlSupport::assume(guzzleVersion: '7.12.1');

    $this->artisan('safe-fetch:check')
        ->expectsOutputToContain('checked after the request only')
        ->assertExitCode(0);
});
