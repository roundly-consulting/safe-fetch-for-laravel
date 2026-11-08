<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;

beforeEach(function (): void {
    SafeFetch::fakeDns(['docs.example' => '93.184.216.34', 'intranet.example' => '10.0.0.5']);
});

it('the rule passes and fails', function (mixed $value, bool $passes): void {
    expect(Validator::make(['url' => $value], ['url' => [new SafeUrl]])->passes())->toBe($passes);
})->with([
    ['https://docs.example/a', true],
    ['https://intranet.example/a', false],
    ['https://unknown.example/a', false],
    ['http://docs.example/a', false],
    ['not a url', false],
    [42, false],
    [['https://docs.example/'], false],
]);

it('the rule takes a policy', function (): void {
    $policy = SafeFetch::policy()->allowHttp();

    expect(Validator::make(['url' => 'http://docs.example/'], ['url' => [new SafeUrl($policy)]])->passes())->toBeTrue();
});

it('the rule says one generic thing in english and slovak', function (string $locale, string $message): void {
    app()->setLocale($locale);

    $private = Validator::make(['feed' => 'https://intranet.example/'], ['feed' => [new SafeUrl]])->errors()->first('feed');
    $missing = Validator::make(['feed' => 'https://unknown.example/'], ['feed' => [new SafeUrl]])->errors()->first('feed');

    expect($private)->toBe($message)->and($missing)->toBe($message);
})->with([
    ['en', 'The feed must be a URL this application is allowed to fetch.'],
    ['sk', 'Pole feed musí byť URL adresa, ktorú táto aplikácia smie načítať.'],
]);

it('the string rule validates', function (): void {
    $validator = Validator::make(
        ['ok' => 'https://docs.example/', 'bad' => 'https://intranet.example/', 'nope' => 7],
        ['ok' => 'safe_url', 'bad' => 'safe_url', 'nope' => 'safe_url'],
    );

    expect($validator->errors()->keys())->toBe(['bad', 'nope'])
        ->and($validator->errors()->first('bad'))->toBe('The bad must be a URL this application is allowed to fetch.');

    app()->setLocale('sk');

    expect(Validator::make(['odkaz' => 'https://intranet.example/'], ['odkaz' => 'safe_url'])->errors()->first('odkaz'))
        ->toBe('Pole odkaz musí byť URL adresa, ktorú táto aplikácia smie načítať.')
        ->and(Validator::make(['odkaz' => 'https://intranet.example/'], ['odkaz' => 'safe_url'], ['safe_url' => 'Custom.'])->errors()->first('odkaz'))
        ->toBe('Custom.');
});

it('validates without a lookup', function (mixed $value, bool $passes): void {
    $dns = SafeFetch::fakeDns();

    expect(Validator::make(['url' => $value], ['url' => [SafeUrl::withoutLookup()]])->passes())->toBe($passes);
    $dns->assertNothingLookedUp();
})->with([
    'an unresolvable public name' => ['https://not-yet.example/hook', true],
    'localhost' => ['https://localhost/', false],
    'a private literal' => ['https://10.0.0.5/', false],
    'http' => ['http://docs.example/', false],
    'no url' => ['not a url', false],
    'not a string' => [42, false],
]);

it('validates without a lookup under a policy, with the one message', function (): void {
    SafeFetch::fakeDns();
    $rule = SafeUrl::withoutLookup(SafeFetch::policy()->allowHttp()->allowHosts(['hooks.example']));

    expect(Validator::make(['url' => 'http://hooks.example/'], ['url' => [$rule]])->passes())->toBeTrue()
        ->and(Validator::make(['feed' => 'https://other.example/'], ['feed' => [$rule]])->errors()->first('feed'))
        ->toBe('The feed must be a URL this application is allowed to fetch.');
});
