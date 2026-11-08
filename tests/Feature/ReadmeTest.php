<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;

/*
 * The README's one example, as written, so it cannot drift into code that throws.
 */

beforeEach(function (): void {
    SafeFetch::fakeDns(['feeds.example' => '93.184.216.34', 'hooks.example' => '93.184.216.35', 'intranet.example' => '10.0.0.5']);
    Http::fake([
        'feeds.example/*' => Http::response('<rss/>', 200, ['Content-Type' => 'application/rss+xml']),
        'hooks.example/*' => Http::response('', 204),
    ]);
});

it('runs the readme example', function (): void {
    $request = Request::create('/', 'POST', ['feed' => 'https://feeds.example/rss']);
    $token = 'token';
    $hookUrl = 'https://hooks.example/in';
    $payload = ['event' => 'ping'];

    $request->validate(['feed' => ['required', 'string', 'max:2048', new SafeUrl]]);

    try {
        $feed = SafeFetch::fetch($request->input('feed'), maxBytes: 2_000_000);
        // $feed->body, $feed->contentType, $feed->url
    } catch (SafeFetchException $e) {
        $e->retryable(); // a 5xx or a timeout can heal; a blocked address never does
    }

    Http::safe()->withToken($token)->post($hookUrl, $payload);

    expect($feed->body)->toBe('<rss/>')
        ->and($feed->contentType)->toBe('application/rss+xml')
        ->and($feed->url)->toBe('https://feeds.example/rss');
    Http::assertSent(static fn ($sent): bool => $sent->url() === $hookUrl && $sent->hasHeader('Authorization', 'Bearer token'));
});

it('refuses a private feed in the readme example', function (): void {
    $request = Request::create('/', 'POST', ['feed' => 'https://intranet.example/rss']);

    expect(fn () => $request->validate(['feed' => ['required', 'string', 'max:2048', new SafeUrl]]))
        ->toThrow(ValidationException::class);
});

it('keeps the readme slim and true', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    preg_match_all('/^## (.+)$/m', $readme, $headings);

    expect($headings[1])->toBe(['Installation', 'Usage', 'Documentation', 'Support our work', 'License'])
        ->and($readme)->toContain('composer require roundly-consulting/safe-fetch-for-laravel')
        ->and($readme)->toContain('new SafeUrl')
        ->and($readme)->toContain('SafeFetch::fetch($request->input(\'feed\'), maxBytes: 2_000_000)')
        ->and($readme)->toContain('Http::safe()->withToken($token)->post($hookUrl, $payload)');
});
