<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\SafeFetch\DataTransferObjects\CanonicalUrl;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Testing\RecordedVet;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

it('prechecks every rule except the lookup', function (string $url, Closure $policy, Reason $reason, ?string $blockedBy): void {
    config()->set('app.url', 'https://myapp.test');
    config()->set('safe-fetch.hosts.blocked', ['evil.example']);
    config()->set('safe-fetch.ports', [443]);
    $dns = SafeFetch::fakeDns(['public.example' => '93.184.216.34']);

    $refusal = Helpers::refusal(fn () => SafeFetch::precheck($url, $policy()));

    expect($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and($refusal->reason())->toBe($reason)
        ->and($refusal instanceof DestinationRejected ? $refusal->blockedBy() : 'none')->toBe($blockedBy);
    $dns->assertNothingLookedUp();
})->with([
    'localhost' => ['https://localhost/', fn () => SafeFetch::policy(), Reason::Blocked, 'hostname'],
    'a localhost subdomain' => ['https://app.localhost/', fn () => SafeFetch::policy(), Reason::Blocked, 'hostname'],
    'the app host' => ['https://MYAPP.test./hook', fn () => SafeFetch::policy(), Reason::Blocked, 'app_host'],
    'a blocked host' => ['https://evil.example/', fn () => SafeFetch::policy(), Reason::Blocked, 'blocked_host'],
    'not on the allowlist' => ['https://public.example/', fn () => SafeFetch::policy()->allowHosts(['hooks.example']), Reason::NotAllowed, null],
    'an address literal under an allowlist' => ['https://93.184.216.34/', fn () => SafeFetch::policy()->allowHosts(['hooks.example']), Reason::NotAllowed, null],
    'a private address literal' => ['https://10.0.0.1/', fn () => SafeFetch::policy(), Reason::Blocked, 'private_address'],
    'a metadata literal' => ['https://169.254.169.254/latest/', fn () => SafeFetch::policy(), Reason::Blocked, 'private_address'],
    'a mapped ipv6 literal' => ['https://[::ffff:127.0.0.1]/', fn () => SafeFetch::policy(), Reason::Blocked, 'private_address'],
    'a blocked range literal' => ['https://93.184.216.34/', fn () => SafeFetch::policy()->blockRanges(['93.184.216.0/24']), Reason::Blocked, 'blocked_range'],
    'http under https only' => ['http://public.example/', fn () => SafeFetch::policy(), Reason::Scheme, null],
    'a bad port' => ['https://public.example:8443/', fn () => SafeFetch::policy(), Reason::Port, null],
    'no url' => ['not a url', fn () => SafeFetch::policy(), Reason::InvalidUrl, null],
]);

it('lets an unresolvable public name through a precheck', function (): void {
    $dns = SafeFetch::fakeDns();

    $canonical = SafeFetch::precheck('https://Not-Yet.Example./hook?x=1#frag');

    expect($canonical)->toBeInstanceOf(CanonicalUrl::class)
        ->and($canonical->toString())->toBe('https://not-yet.example/hook?x=1')
        ->and($canonical->host)->toBe('not-yet.example')
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://not-yet.example/hook'))->reason())->toBe(Reason::Unresolved);
    $dns->assertLookedUp('not-yet.example', 1);
});

it('passes what the policy carves out without a lookup', function (): void {
    config()->set('app.url', 'https://myapp.test');
    $dns = SafeFetch::fakeDns();
    $policy = SafeFetch::policy()->allowHttp()->trustPrivateHosts(['localhost'])->allowRanges(['127.0.0.1/32']);

    expect(SafeFetch::precheck('http://localhost:8000/', $policy)->port)->toBe(8000)
        ->and(SafeFetch::precheck('http://127.0.0.1/', $policy)->ipLiteral)->toBeTrue()
        ->and(SafeFetch::precheck('https://[2606:4700::1111]/')->host)->toBe('2606:4700::1111')
        ->and(SafeFetch::precheck('https://hooks.example/', (new DestinationPolicy)->allowHosts(['*.example']))->host)->toBe('hooks.example');
    $dns->assertNothingLookedUp();
});

it('announces a precheck refusal', function (): void {
    config()->set('app.url', 'https://myapp.test');
    SafeFetch::fakeDns();
    Event::fake([DestinationRefused::class]);

    SafeFetch::precheck('https://public.example/');
    Helpers::refusal(fn () => SafeFetch::precheck('https://10.0.0.1/secret?token=abc'));
    Helpers::refusal(fn () => SafeFetch::precheck('https://localhost/'));

    Event::assertDispatchedTimes(DestinationRefused::class, 2);
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->reason === Reason::Blocked
        && $event->host === '10.0.0.1'
        && $event->blockedBy === 'private_address');
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->blockedBy === 'hostname');
    Event::assertNotDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => str_contains(serialize($event), 'token=abc'));
});

it('records a precheck under the fake', function (): void {
    $fake = SafeFetch::fake();

    SafeFetch::precheck('https://hooks.example/a');
    Helpers::refusal(fn () => SafeFetch::precheck('https://127.0.0.1/'));

    expect(array_map(static fn (RecordedVet $vet): string => $vet->via.' '.$vet->url.' '.($vet->reason->value ?? 'ok'), $fake->recorded()))
        ->toBe(['precheck https://hooks.example/a ok', 'precheck https://127.0.0.1/ blocked'])
        ->and($fake->recorded()[0]->vetted)->toBeNull();
    $fake->assertVetted('https://hooks.example/a');
    $fake->assertRefused('https://127.0.0.1/', Reason::Blocked);
    $fake->dns()->assertNothingLookedUp();
});
