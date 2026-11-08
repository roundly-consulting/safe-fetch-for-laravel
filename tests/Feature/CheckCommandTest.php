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
    config()->set('safe-fetch.ranges.allowed', ['10.0.0.0/7', 'fd00::/16', '::ffff:0:0/100', '64:ff9b::/97', '::ffff:10.0.0.0/104']);
    config()->set('safe-fetch.redirects.enabled', true);
    config()->set('safe-fetch.redirects.allow_downgrade', true);

    $this->artisan('safe-fetch:check')
        ->expectsOutputToContain('http is allowed in production')
        ->expectsOutputToContain('4 allowed range(s) are broader')
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

/**
 * @return array<string, mixed>
 */
function checkJson(array $arguments, int $exitCode): array
{
    expect(Artisan::call('safe-fetch:check', [...$arguments, '--json' => true]))->toBe($exitCode);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('prints the verdict as json', function (): void {
    expect(checkJson(['url' => 'https://docs.example/a'], 0))->toBe([
        'allowed' => true,
        'url' => 'https://docs.example/a',
        'addresses' => ['93.184.216.34', '2606:4700::1111'],
        'pinned_as' => 'docs.example:443:93.184.216.34,[2606:4700::1111]',
    ])
        ->and(checkJson(['url' => 'https://intranet.example/'], 1))->toBe(['allowed' => false, 'reason' => 'blocked', 'retryable' => false, 'blocked_by' => 'private_address'])
        ->and(checkJson(['url' => 'https://unknown.example/'], 1))->toBe(['allowed' => false, 'reason' => 'unresolved', 'retryable' => true, 'blocked_by' => null])
        ->and(checkJson(['url' => 'http://93.184.216.34/', '--http' => true], 0)['pinned_as'])->toBeNull();
});

it('prints the doctor as json without trusted names', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config()->set('safe-fetch.allow_http', true);
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.ranges.allowed', ['10.0.0.0/7']);

    $report = checkJson([], 0);

    expect(array_keys($report))->toBe(['dns_pinning', 'curl', 'pre_send_check', 'international_host_names', 'guzzle', 'configuration_valid', 'errors', 'warnings'])
        ->and($report['dns_pinning'])->toBeTrue()
        ->and($report['curl'])->toBe(CurlSupport::curlVersion())
        ->and($report['configuration_valid'])->toBeTrue()
        ->and($report['errors'])->toBe([])
        ->and($report['warnings'])->toHaveCount(2)
        ->and(json_encode($report))->not->toContain('minio.internal')
        ->not->toContain('10.0.0.0');
});

it('prints a failing doctor as json', function (): void {
    CurlSupport::assume(available: false);
    config()->set('safe-fetch.allow_http', 'sometimes');

    $report = checkJson([], 1);

    expect($report['dns_pinning'])->toBeFalse()
        ->and($report['configuration_valid'])->toBeFalse()
        ->and($report['errors'])->toHaveCount(2)
        ->and($report['errors'][1])->toContain('The configuration is invalid')
        ->and($report['warnings'])->toBe([]);
});
