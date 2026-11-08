<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\SafeFetchConfig;
use RoundlyConsulting\SafeFetch\Support\SystemHostResolver;

it('reads every setting strictly', function (string $key, mixed $value, Closure $read, string $message): void {
    config()->set("safe-fetch.{$key}", $value);

    expect($read)->toThrow(InvalidConfigurationException::class, "[safe-fetch.{$key}] {$message}");
})->with([
    'allow_http word' => ['allow_http', 'sometimes', fn () => SafeFetch::policy(), 'must be a boolean'],
    'allow_http number' => ['allow_http', 2, fn () => SafeFetch::policy(), 'must be a boolean'],
    'ports word' => ['ports', 'https', fn () => SafeFetch::policy(), 'must contain only valid items'],
    'ports zero' => ['ports', [0], fn () => SafeFetch::policy(), 'must be between 1 and 65535'],
    'ports too big' => ['ports', [70000], fn () => SafeFetch::policy(), 'must be between 1 and 65535'],
    'ports float' => ['ports', [44.3], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'ports empty' => ['ports', [], fn () => SafeFetch::policy(), 'must be a list'],
    'ports scalar' => ['ports', 443.0, fn () => SafeFetch::policy(), 'must be a list'],
    'hosts.allowed bare star' => ['hosts.allowed', ['*'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'hosts.allowed scheme' => ['hosts.allowed', ['https://a.example'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'hosts.blocked port' => ['hosts.blocked', ['a.example:443'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'hosts.blocked type' => ['hosts.blocked', [42], fn () => SafeFetch::policy(), 'must contain only string items'],
    'hosts.private address' => ['hosts.private', ['10.0.0.1'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'hosts.private type' => ['hosts.private', 42, fn () => SafeFetch::policy(), 'must be a list'],
    'ranges.allowed junk' => ['ranges.allowed', ['10.0.0.0/99'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'ranges.blocked name' => ['ranges.blocked', ['example.com'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'app_hosts.block word' => ['app_hosts.block', 'maybe', fn () => SafeFetch::policy(), 'must be a boolean'],
    'app_hosts.extra bad' => ['app_hosts.extra', ['*'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'redirects.enabled word' => ['redirects.enabled', 'always', fn () => SafeFetch::policy(), 'must be a boolean'],
    'redirects.max negative' => ['redirects.max', -1, fn () => SafeFetch::policy(), 'must be between 0 and 10'],
    'redirects.max too many' => ['redirects.max', 11, fn () => SafeFetch::policy(), 'must be between 0 and 10'],
    'redirects.max word' => ['redirects.max', 'three', fn () => SafeFetch::policy(), 'must be an integer'],
    'redirects.statuses 304' => ['redirects.statuses', [304], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'redirects.statuses 99' => ['redirects.statuses', [99], fn () => SafeFetch::policy(), 'must be between 100 and 599'],
    'redirects.statuses word' => ['redirects.statuses', ['moved'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'redirects.allow_downgrade word' => ['redirects.allow_downgrade', 'sure', fn () => SafeFetch::policy(), 'must be a boolean'],
    'redirects.forward_headers bad' => ['redirects.forward_headers', ['bad header'], fn () => SafeFetch::policy(), 'must contain only valid items'],
    'fetch.max_bytes zero' => ['fetch.max_bytes', 0, fn () => SafeFetchConfig::fetchMaxBytes(), 'must be at least 1'],
    'fetch.max_bytes word' => ['fetch.max_bytes', '5MB', fn () => SafeFetchConfig::fetchMaxBytes(), 'must be an integer'],
    'fetch.timeout zero' => ['fetch.timeout', 0, fn () => SafeFetchConfig::fetchTimeout(), 'must be between 1 and 600'],
    'fetch.timeout too long' => ['fetch.timeout', 601, fn () => SafeFetchConfig::fetchTimeout(), 'must be between 1 and 600'],
    'resolver not a resolver' => ['resolver', stdClass::class, fn () => app(HostResolver::class), 'must be a class-string'],
    'resolver missing class' => ['resolver', 'App\\Nope', fn () => app(HostResolver::class), 'must be a class-string'],
]);

it('reads every setting from env strings and uses the default when blank', function (): void {
    config()->set('safe-fetch.allow_http', 'yes');
    config()->set('safe-fetch.ports', '443, 8443');
    config()->set('safe-fetch.hosts.allowed', 'a.example, *.b.example');
    config()->set('safe-fetch.ranges.allowed', '10.20.0.0/16');
    config()->set('safe-fetch.redirects.enabled', '1');
    config()->set('safe-fetch.redirects.max', '5');
    config()->set('safe-fetch.redirects.statuses', '308,301');
    config()->set('safe-fetch.redirects.allow_downgrade', 'off');
    config()->set('safe-fetch.redirects.forward_headers', 'Webhook-Signature');
    config()->set('safe-fetch.fetch.max_bytes', '');
    config()->set('safe-fetch.fetch.timeout', '  ');
    config()->set('safe-fetch.resolver', '');

    $policy = SafeFetch::policy();

    expect($policy->schemes)->toBe(['http', 'https'])
        ->and($policy->ports)->toBe([443, 8443])
        ->and($policy->allowedHosts)->toBe(['a.example', '*.b.example'])
        ->and($policy->allowedRanges)->toBe(['10.20.0.0/16'])
        ->and($policy->redirects->enabled)->toBeTrue()
        ->and($policy->redirects->max)->toBe(5)
        ->and($policy->redirects->statuses)->toBe([301, 308])
        ->and($policy->redirects->allowDowngrade)->toBeFalse()
        ->and($policy->redirects->forwardHeaders)->toBe(['webhook-signature'])
        ->and(SafeFetchConfig::fetchMaxBytes())->toBe(5_242_880)
        ->and(SafeFetchConfig::fetchTimeout())->toBe(30)
        ->and(app(HostResolver::class))->toBeInstanceOf(SystemHostResolver::class);
});

it('ships the defaults it documents', function (): void {
    $policy = SafeFetch::policy();

    expect($policy->schemes)->toBe(['https'])
        ->and($policy->ports)->toBeNull()
        ->and($policy->allowedHosts)->toBe([])
        ->and($policy->blockAppHosts)->toBeTrue()
        ->and($policy->redirects->enabled)->toBeFalse()
        ->and($policy->redirects->max)->toBe(3)
        ->and($policy->redirects->statuses)->toBe([307, 308])
        ->and($policy->redirects->statusesGiven)->toBeFalse()
        ->and($policy->maxResponseBytes)->toBeNull()
        ->and(app(HostResolver::class))->toBeInstanceOf(SystemHostResolver::class);
});

it('refuses settings that switch the guard off', function (string $key, mixed $value): void {
    config()->set("safe-fetch.{$key}", $value);

    expect(fn () => SafeFetch::policy())->toThrow(InvalidConfigurationException::class, "[safe-fetch.{$key}]");
})->with([
    'every ipv4 address' => ['ranges.allowed', ['0.0.0.0/0']],
    'every ipv6 address' => ['ranges.allowed', ['::/0']],
    'every ipv4 address, mapped' => ['ranges.allowed', ['::ffff:0:0/96']],
    'every ipv4 address, nat64' => ['ranges.allowed', ['64:ff9b::/96']],
    'every ipv4 address, 6to4' => ['ranges.allowed', ['2002::/16']],
    'any host' => ['hosts.allowed', ['*']],
    'any private host' => ['hosts.private', ['*']],
]);

it('fails loudly on a bad setting when a request is guarded', function (): void {
    config()->set('safe-fetch.allow_http', 'sometimes');
    Http::fake();

    expect(fn () => Http::safe()->get('https://example.com/'))->toThrow(InvalidConfigurationException::class);
    Http::assertNothingSent();
});
