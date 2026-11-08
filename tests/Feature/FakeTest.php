<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Testing\RecordedVet;
use RoundlyConsulting\SafeFetch\Testing\SafeFetchFake;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

it('the fake replaces the injected manager', function (): void {
    $fake = SafeFetch::fake();

    expect(app(SafeFetchManager::class))->toBe($fake)
        ->and($fake)->toBeInstanceOf(SafeFetchFake::class)
        ->and(SafeFetch::getFacadeRoot())->toBe($fake);
});

it('the fake sees guarded requests', function (): void {
    $fake = SafeFetch::fake();
    Http::fake(['*' => Http::response('ok')]);

    Http::safe()->get('https://hooks.example/a');
    SafeFetch::http()->get('https://hooks.example/b');

    expect(array_map(static fn (RecordedVet $vet): string => $vet->via.' '.$vet->url, $fake->recorded()))
        ->toBe(['http https://hooks.example/a', 'http https://hooks.example/b']);
    $fake->assertGuardedRequestSent();
    $fake->assertGuardedRequestSent(static fn (VettedDestination $hop): bool => $hop->url === 'https://hooks.example/b');
});

it('the fake answers unknown names publicly unless strict', function (): void {
    $fake = SafeFetch::fake(['pinned.example' => '151.101.1.1', 'gone.example' => []]);

    expect(SafeFetch::vet('https://anything.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(SafeFetch::vet('https://pinned.example/')->addresses)->toBe(['151.101.1.1'])
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://gone.example/'))->reason())->toBe(Reason::Unresolved)
        ->and($fake->strictDns())->toBe($fake)
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://anything.example/'))->reason())->toBe(Reason::Unresolved);
    $fake->dns()->assertLookedUp('anything.example', 2);
});

it('the fake keeps the real guard', function (): void {
    $fake = SafeFetch::fake();

    expect(Helpers::refusal(fn () => SafeFetch::vet('https://127.0.0.1/'))->reason())->toBe(Reason::Blocked)
        ->and(SafeFetch::allows('https://169.254.169.254/'))->toBeFalse();
    $fake->assertRefused('https://127.0.0.1/', Reason::Blocked);
    $fake->assertRefused('https://169.254.169.254/');
});

it('the fake never dials unless allowed', function (): void {
    SafeFetch::fake();

    expect(fn () => Http::safe()->get('https://hooks.example/'))->toThrow(LogicException::class, 'Http::fake()');
});

it('the fake records fetches', function (): void {
    $fake = SafeFetch::fake();
    Http::fake(['*' => Http::response('ok')]);

    SafeFetch::fetch('https://feed.example/rss');

    $fake->assertFetched('https://feed.example/rss');
    $fake->assertFetched('https://FEED.example./rss#x');
    $fake->assertFetched(static fn (string $url): bool => str_contains($url, 'rss'));
    $fake->assertVetted('https://feed.example/rss');
});

it('assert vetted passes', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::vet('https://a.example/');

    $fake->assertVetted('https://a.example/');
    $fake->assertVetted('HTTPS://A.EXAMPLE.:443/');
    $fake->assertVetted(static fn (RecordedVet $vet): bool => $vet->via === 'vet' && ! $vet->refused());
});

it('assert vetted fails', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::vet('https://a.example/');

    expect(fn () => $fake->assertVetted('https://b.example/'))->toThrow(ExpectationFailedException::class, 'Expected [https://b.example/] to be vetted')
        ->and(fn () => $fake->assertVetted(static fn (RecordedVet $vet): bool => $vet->refused()))->toThrow(ExpectationFailedException::class, 'matching the callback');
});

it('assert not vetted passes', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::vet('https://a.example/');

    $fake->assertNotVetted('https://b.example/');
    $fake->assertNotVetted('not a url');
});

it('assert not vetted fails', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::allows('https://a.example/');

    expect(fn () => $fake->assertNotVetted('https://a.example/'))->toThrow(ExpectationFailedException::class, 'vetted 1 time(s)');
});

it('assert refused passes', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::allows('https://10.0.0.1/');

    $fake->assertRefused('https://10.0.0.1/');
    $fake->assertRefused('https://10.0.0.1/', Reason::Blocked);
});

it('assert refused fails', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::allows('https://a.example/');
    SafeFetch::allows('https://10.0.0.1/');

    expect(fn () => $fake->assertRefused('https://a.example/'))->toThrow(ExpectationFailedException::class, 'its verdicts were: ok')
        ->and(fn () => $fake->assertRefused('https://10.0.0.1/', Reason::Scheme))->toThrow(ExpectationFailedException::class, '(scheme), but its verdicts were: blocked')
        ->and(fn () => $fake->assertRefused('https://never.example/'))->toThrow(ExpectationFailedException::class, 'never vetted');
});

it('assert fetched passes', function (): void {
    $fake = SafeFetch::fake();
    Http::fake(['*' => Http::response('ok')]);
    SafeFetch::fetch('https://a.example/');

    $fake->assertFetched('https://a.example/');
});

it('assert fetched fails', function (): void {
    $fake = SafeFetch::fake();

    expect(fn () => $fake->assertFetched('https://a.example/'))->toThrow(ExpectationFailedException::class, 'to be fetched')
        ->and(fn () => $fake->assertFetched(static fn (): bool => true))->toThrow(ExpectationFailedException::class, 'matching the callback');
});

it('assert nothing fetched passes', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::vet('https://a.example/');

    $fake->assertNothingFetched();
});

it('assert nothing fetched fails', function (): void {
    $fake = SafeFetch::fake();
    Http::fake(['*' => Http::response('ok')]);
    SafeFetch::fetch('https://a.example/');

    expect(fn () => $fake->assertNothingFetched())->toThrow(ExpectationFailedException::class, '1 URL(s) were: https://a.example/');
});

it('assert nothing vetted passes', function (): void {
    SafeFetch::fake()->assertNothingVetted();
});

it('assert nothing vetted fails', function (): void {
    $fake = SafeFetch::fake();
    SafeFetch::allows('https://a.example/');

    expect(fn () => $fake->assertNothingVetted())->toThrow(ExpectationFailedException::class, '1 URL(s) were: https://a.example/');
});

it('assert guarded request sent passes', function (): void {
    $fake = SafeFetch::fake();
    Http::fake();
    Http::safe()->get('https://a.example/');

    $fake->assertGuardedRequestSent();
});

it('assert guarded request sent fails', function (): void {
    $fake = SafeFetch::fake();
    Http::fake();
    SafeFetch::vet('https://a.example/');
    Helpers::refusal(fn () => Http::safe()->get('https://10.0.0.1/'));

    expect(fn () => $fake->assertGuardedRequestSent())->toThrow(ExpectationFailedException::class, 'none did')
        ->and(fn () => $fake->assertGuardedRequestSent(static fn (): bool => true))->toThrow(ExpectationFailedException::class, 'matching the callback');
});

it('records refusals through the facade too', function (): void {
    SafeFetch::fake();

    expect(fn () => SafeFetch::vet('https://10.0.0.1/'))->toThrow(DestinationRejected::class);
    SafeFetch::assertRefused('https://10.0.0.1/', Reason::Blocked);
});
