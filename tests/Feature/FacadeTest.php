<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\SafeFetch\Actions\DownloadUrlAction;
use RoundlyConsulting\SafeFetch\Actions\FetchUrlAction;
use RoundlyConsulting\SafeFetch\Actions\GuardRequestAction;
use RoundlyConsulting\SafeFetch\Actions\PrecheckDestinationAction;
use RoundlyConsulting\SafeFetch\Actions\VetDestinationAction;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Handles\Networks;
use RoundlyConsulting\SafeFetch\Handles\Urls;
use RoundlyConsulting\SafeFetch\SafeFetchManager;

/**
 * The public API contract — Actions → Manager → Facade (+ fake).
 */
it('pins the facade contract', function (): void {
    expect(SafeFetch::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

beforeEach(function (): void {
    SafeFetch::fakeDns(['hooks.example' => '93.184.216.34', 'inward.example' => '10.0.0.5']);
});

it('runs every facade method', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok', 200, ['Content-Type' => 'text/plain'])]);

    expect(SafeFetch::vet('https://hooks.example/a')->url)->toBe('https://hooks.example/a')
        ->and(SafeFetch::allows('https://inward.example/'))->toBeFalse()
        ->and(SafeFetch::allows('https://hooks.example/'))->toBeTrue()
        ->and(SafeFetch::precheck('https://unresolvable.example/x')->host)->toBe('unresolvable.example')
        ->and(SafeFetch::http())->toBeInstanceOf(PendingRequest::class)
        ->and(SafeFetch::http()->get('https://hooks.example/b')->body())->toBe('ok')
        ->and(SafeFetch::guard(Http::withToken('t'))->get('https://hooks.example/c')->body())->toBe('ok')
        ->and(SafeFetch::fetch('https://hooks.example/d')->body)->toBe('ok')
        ->and(SafeFetch::download('https://hooks.example/e', $path = tempnam(sys_get_temp_dir(), 'safe-fetch-facade-'))->bytes)->toBe(2)
        ->and(file_get_contents($path))->toBe('ok')
        ->and(SafeFetch::truncated(SafeFetch::http()->get('https://hooks.example/f')))->toBeFalse()
        ->and(SafeFetch::policy())->toBeInstanceOf(DestinationPolicy::class)
        ->and(SafeFetch::networks())->toBeInstanceOf(Networks::class)
        ->and(SafeFetch::urls())->toBeInstanceOf(Urls::class);

    Http::assertSent(static fn ($request): bool => $request->url() === 'https://hooks.example/c' && $request->hasHeader('Authorization', 'Bearer t'));
    unlink($path);
});

it('runs the same api through an injected manager', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok', 200)]);
    $manager = app(SafeFetchManager::class);

    expect($manager)->toBe(app(SafeFetchManager::class))
        ->and($manager->vet('https://hooks.example/')->addresses)->toBe(['93.184.216.34'])
        ->and($manager->precheck('https://hooks.example/z')->toString())->toBe('https://hooks.example/z')
        ->and($manager->http()->get('https://hooks.example/x')->body())->toBe('ok')
        ->and($manager->fetch('https://hooks.example/y')->status)->toBe(200)
        ->and($manager->download('https://hooks.example/w', $path = tempnam(sys_get_temp_dir(), 'safe-fetch-di-'))->status)->toBe(200);

    unlink($path);
});

it('runs the raw actions', function (): void {
    Http::fake(['hooks.example/*' => Http::response('ok', 200)]);

    expect(app(VetDestinationAction::class)->execute('https://hooks.example/', SafeFetch::policy())->host)->toBe('hooks.example')
        ->and(app(PrecheckDestinationAction::class)->execute('https://hooks.example/q', SafeFetch::policy())->pathAndQuery)->toBe('/q')
        ->and(app(GuardRequestAction::class)->execute(Http::withToken('t'), SafeFetch::policy())->post('https://hooks.example/p')->body())->toBe('ok')
        ->and(app(FetchUrlAction::class)->execute('https://hooks.example/f', new FetchOptions, SafeFetch::policy())->body)->toBe('ok')
        ->and(app(DownloadUrlAction::class)->execute('https://hooks.example/g', $path = tempnam(sys_get_temp_dir(), 'safe-fetch-action-'), new FetchOptions(maxBytes: 10), SafeFetch::policy())->bytes)->toBe(2);

    unlink($path);
});

it('resolves actions through the container so a host override applies', function (): void {
    app()->bind(VetDestinationAction::class, static fn (): never => throw new RuntimeException('overridden'));

    SafeFetch::vet('https://hooks.example/');
})->throws(RuntimeException::class, 'overridden');

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(SafeFetch::class) => SafeFetch::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
