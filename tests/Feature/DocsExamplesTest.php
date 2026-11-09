<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DownloadedFile;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Testing\RecordedVet;
use RoundlyConsulting\SafeFetch\Testing\SafeFetchFake;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

/*
 * The technical docs' examples, run. Each block between `// docs: <page> #n` and `// end docs` is a
 * verbatim copy of the n-th php block of docs/packages/safe-fetch-for-laravel/technical/<page> —
 * keep them identical (an example that throws is a bug). `use` lines are hoisted to the imports;
 * blocks that show no call (the pinning option array, the event's shape) are not copied, nor are
 * third-party-clients.md #2 and #3, which need laravel/mcp and laravel/ai (proved in a throwaway
 * project; the package does not install them).
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/safe-fetch-docs-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('runs the guarded-requests examples', function (): void {
    SafeFetch::fakeDns(['hooks.example' => '93.184.216.34']);
    Http::fake(['hooks.example/*' => Http::response(str_repeat('x', 5000), 200)]);
    $url = 'https://hooks.example/in';
    $payload = ['event' => 'ping'];
    $token = 'token';
    $policy = SafeFetch::policy();

    // docs: guarded-requests.md #0
    Http::safe()->post($url, $payload);                                          // the configured policy
    Http::safe(SafeFetch::policy()->followRedirects())->post($url, $payload);    // a policy for this call
    Http::withToken($token)->safe()->post($url, $payload);                       // chains either way
    SafeFetch::guard(Http::withToken($token)->timeout(10), $policy)->post($url); // the manager, no macro
    SafeFetch::http($policy)->get($url);                                         // a new guarded request
    // end docs

    // docs: guarded-requests.md #2
    Http::safe()->retry(3, 100, fn (Throwable $e) => ! $e instanceof SafeFetchException)->post($url, $payload);
    // end docs

    // docs: guarded-requests.md #3
    $response = Http::safe(SafeFetch::policy()->maxResponseBytes(4096))->post($url, $payload);

    $response->status();                       // the real status
    $response->body();                         // at most 4 096 bytes
    SafeFetch::truncated($response);           // true when the body was cut
    $response->handlerStats()['primary_ip'] ?? null; // the address it connected to, truncated or not
    // end docs

    expect($response->status())->toBe(200)
        ->and(strlen($response->body()))->toBe(4096)
        ->and(SafeFetch::truncated($response))->toBeTrue();

    // docs: guarded-requests.md #4
    $vetted = SafeFetch::vet($url);
    Http::withOptions($vetted->pinningOptions())->get($vetted->url);
    // end docs

    Http::assertSentCount(8);
    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token'));
});

it('runs the redirects examples', function (): void {
    SafeFetch::fakeDns(['hooks.example' => '93.184.216.34', 'other.example' => '93.184.216.35']);
    Http::fake([
        'hooks.example/*' => Http::response('', 307, ['Location' => 'https://other.example/moved']),
        'other.example/*' => Http::response('ok'),
    ]);
    $url = 'https://hooks.example/in';
    $payload = ['event' => 'ping'];

    // docs: redirects.md #0
    Http::safe(SafeFetch::policy()->followRedirects())->post($url, $payload);            // 307/308, ≤ 3 hops
    Http::safe(SafeFetch::policy()->redirects(RedirectPolicy::methodPreserving(5)))->post($url, $payload);
    Http::safe(SafeFetch::policy()->redirects(RedirectPolicy::methodPreserving(3, forwardHeaders: ['webhook-signature'])))
        ->post($url, $payload);
    // end docs

    Http::assertSentCount(6);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://other.example/moved' && $request->method() === 'POST');
});

it('runs the policy-and-configuration examples', function (): void {
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.ranges.allowed', ['127.0.0.1/32']);
    config()->set('safe-fetch.hosts.blocked', ['evil.example']);
    SafeFetch::fakeDns(['hooks.example.com' => '93.184.216.34', 'minio.internal' => '10.0.0.7']);
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $url = 'https://hooks.example.com/in';
    $payload = ['event' => 'ping'];
    $appHosts = ['admin.example.com'];
    $blockedHosts = ['spam.example'];
    $blockedRanges = ['198.51.100.0/24'];
    $allowedRanges = [];
    $ports = [443];
    $max = 3;
    $allowedHosts = [];

    // docs: policy-and-configuration.md #0
    Http::safe(SafeFetch::policy()->allowHosts(['hooks.example.com'])->followRedirects())->post($url, $payload);
    // end docs

    // docs: policy-and-configuration.md #1
    $policy = SafeFetch::policy()
        ->withoutHostAllowlist()->withoutPrivateHosts()->withoutAllowedRanges()
        ->blockAppHosts()->appHosts($appHosts)
        ->blockHosts($blockedHosts)->blockRanges($blockedRanges)->allowRanges($allowedRanges)
        ->ports($ports)->maxResponseBytes(4096)
        ->redirects(RedirectPolicy::methodPreserving($max, forwardHeaders: ['webhook-id', 'webhook-timestamp', 'webhook-signature']));
    $policy = $allowedHosts === [] ? $policy : $policy->allowHosts($allowedHosts);
    // end docs

    expect($policy->privateHosts)->toBe([])
        ->and($policy->allowedRanges)->toBe([])
        ->and($policy->blockedHosts)->toBe(['evil.example', 'spam.example'])
        ->and($policy->redirects->forwardHeaders)->toBe(['webhook-id', 'webhook-timestamp', 'webhook-signature'])
        ->and(SafeFetch::allows('https://minio.internal/', $policy))->toBeFalse()
        ->and(SafeFetch::allows('https://admin.example.com/', $policy))->toBeFalse()
        ->and(SafeFetch::allows($url, $policy))->toBeTrue();
    Http::assertSentCount(1);
});

it('runs the fetch examples', function (): void {
    SafeFetch::fakeDns(['docs.example' => '93.184.216.34']);
    Http::fake(['docs.example/*' => Http::response(['data' => ['items' => [1, 2]]], 200, ['ETag' => '"v1"'])]);
    $url = 'https://docs.example/feed';
    $id = 'trace-1';
    $path = $this->dir.'/feed.xml';

    // docs: fetch.md #0
    $page = SafeFetch::fetch(
        $url,
        maxBytes: 1_000_000,          // default safe-fetch.fetch.max_bytes (5 MiB)
        timeout: 20,                  // default safe-fetch.fetch.timeout (30 s), every hop together, 1–600
        accept: 'text/html',
        headers: ['X-Trace' => $id],  // never Host; sent to the first origin only
        followRedirects: true,        // default: the policy's redirects
        policy: SafeFetch::policy(),  // default: the configured policy
    );

    $page->status;       // 2xx
    $page->body;         // the whole body, within the cap
    $page->contentType;  // or null
    $page->url;          // the URL the answer came from (the last hop, rebuilt)
    $page->headers;      // array<string, list<string>>
    $page->header('etag');
    $page->json('data.items', []);  // the body decoded as JSON (null when it is not), a dot-notation key
    // end docs

    // docs: fetch.md #1
    $file = SafeFetch::download($url, $path, maxBytes: 50_000_000);   // $path: e.g. storage_path('app/imports/feed.xml')

    $file->path;         // where the body is — the path you gave
    $file->bytes;        // its size, as written (decoded)
    $file->contentType;  // or null
    $file->url;          // the URL the answer came from (the last hop, rebuilt)
    $file->headers;      // array<string, list<string>>
    $file->header('etag');
    // end docs

    expect($page->json('data.items', []))->toBe([1, 2])
        ->and($page->header('etag'))->toBe('"v1"')
        ->and($file)->toBeInstanceOf(DownloadedFile::class)
        ->and($file->bytes)->toBe(filesize($path))
        ->and(json_decode((string) file_get_contents($path), true))->toBe(['data' => ['items' => [1, 2]]]);
});

it('runs the rule and event examples', function (): void {
    config()->set('app.url', 'https://myapp.test');
    SafeFetch::fakeDns(['feeds.example' => '93.184.216.34', 'api.partner.example' => '93.184.216.35', 'dev.example' => '93.184.216.36', 'inward.example' => '10.0.0.5']);
    $request = HttpRequest::create('/', 'POST', ['feed' => 'https://feeds.example/rss', 'hook' => 'https://api.partner.example/in', 'dev_hook' => 'http://dev.example/in']);
    Log::spy();

    // docs: rule-command-events.md #0
    $request->validate(['feed' => ['required', 'string', 'max:2048', new SafeUrl]]);
    $request->validate(['feed' => ['required', 'string', 'max:2048', 'safe_url']]);
    $request->validate(['hook' => ['required', new SafeUrl(SafeFetch::policy()->allowHosts(['*.partner.example']))]]);
    $request->validate(['hook' => ['required', SafeUrl::withoutLookup()]]);             // no DNS lookup
    $request->validate(['dev_hook' => ['required', 'safe_url:http']]);                   // http as well
    // end docs

    // docs: rule-command-events.md #2
    Event::listen(function (DestinationRefused $event): void {
        if (! in_array($event->via, ['allows', 'precheck'], true)) {
            Log::warning('Outbound request refused', ['reason' => $event->reason->value, 'host' => $event->host, 'rule' => $event->blockedBy, 'via' => $event->via]);
        }
    });
    // end docs

    SafeFetch::allows('https://inward.example/form');
    Helpers::refusal(fn () => SafeFetch::vet('https://inward.example/job'));

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context['via'] === 'vet' && $context['rule'] === 'private_address');
});

it('runs the testing examples', function (): void {
    // docs: testing.md #0
    $dns = SafeFetch::fakeDns([
        'hooks.example' => '93.184.216.34',
        'dual.example' => ['93.184.216.34', '2606:4700::1111'],
        'gone.example' => [],                       // does not resolve
    ]);
    $dns->resolve('cdn.example', '151.101.1.1');    // pins add up across calls
    $dns->unresolvable('hooks.example');

    Http::fake(['hooks.example/*' => Http::response('ok')]);
    // end docs

    expect(SafeFetch::allows('https://dual.example/'))->toBeTrue()
        ->and(SafeFetch::allows('https://cdn.example/'))->toBeTrue()
        ->and(SafeFetch::allows('https://hooks.example/'))->toBeFalse()
        ->and(SafeFetch::allows('https://gone.example/'))->toBeFalse();
    $dns->assertLookedUp('dual.example', 1);
});

it('runs the testing examples of the fake', function (): void {
    // docs: testing.md #1
    $fake = SafeFetch::fake(['pinned.example' => '151.101.1.1']);   // optional DNS pins
    Http::fake(['*' => Http::response('ok')]);

    // The code under test:
    SafeFetch::fetch('https://feed.example/rss');
    Http::safe()->post('https://hooks.example/a', ['event' => 'ping']);
    SafeFetch::allows('https://10.0.0.1/');

    $fake->assertFetched('https://feed.example/rss');                // or a Closure(string $url): bool
    $fake->assertVetted('https://hooks.example/a');                  // or a Closure(RecordedVet): bool
    $fake->assertNotVetted('https://other.example/');
    $fake->assertRefused('https://10.0.0.1/', Reason::Blocked);      // reason optional
    $fake->assertGuardedRequestSent(fn (VettedDestination $hop) => $hop->host === 'hooks.example');
    $fake->assertNothingDownloaded();
    $fake->recorded();                                               // list<RecordedVet>
    // end docs

    expect($fake)->toBeInstanceOf(SafeFetchFake::class)
        ->and(array_map(static fn (RecordedVet $vet): string => $vet->via, $fake->recorded()))->toBe(['http', 'http', 'allows']);
});

it('runs the consumer-packages examples', function (): void {
    config()->set('app.url', 'https://myapp.test');
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    SafeFetch::fakeDns(['hooks.example.com' => '93.184.216.34', 'minio.internal' => '10.0.0.7', 'gone.example.com' => []]);
    Http::fake(['hooks.example.com/*' => Http::response(str_repeat('y', 6000), 200)]);
    $safeFetch = app(SafeFetchManager::class);
    $appHosts = [];
    $blockedHosts = [];
    $blockedRanges = [];
    $allowedRanges = [];
    $ports = null;
    $max = 3;
    $allowedHosts = ['hooks.example.com', 'minio.internal', 'gone.example.com'];
    $url = 'https://hooks.example.com/in';
    $payload = ['event' => 'ping'];
    $headers = ['X-Custom' => '1'];
    $body = '{"event":"ping"}';
    $throttled = 0;
    $throttle = static function (callable $handler) use (&$throttled): Closure {
        return static function ($request, array $options) use ($handler, &$throttled) {
            $throttled++;

            return $handler($request, $options);
        };
    };
    $request = HttpRequest::create('/', 'POST', ['endpoint' => 'https://hooks.example.com/hook']);

    // docs: consumer-packages.md #0
    $policy = SafeFetch::policy()
        ->withoutHostAllowlist()->withoutPrivateHosts()->withoutAllowedRanges()
        ->blockAppHosts()->appHosts($appHosts)
        ->blockHosts($blockedHosts)->blockRanges($blockedRanges)->allowRanges($allowedRanges)
        ->ports($ports)->maxResponseBytes(4096)
        ->redirects(RedirectPolicy::methodPreserving($max, forwardHeaders: ['webhook-id', 'webhook-timestamp', 'webhook-signature']));
    $policy = $allowedHosts === [] ? $policy : $policy->allowHosts($allowedHosts);
    // end docs

    // docs: consumer-packages.md #1
    $response = $safeFetch->guard(
        Http::withMiddleware($throttle)->connectTimeout(5)->timeout(10)->withHeaders($headers),
        $policy,
    )->withBody($body, 'application/json')->post($url);
    // end docs

    // docs: consumer-packages.md #2
    $response = $safeFetch->guard(Http::retry(3, 100, fn (Throwable $e) => ! $e instanceof SafeFetchException), $policy)
        ->post($url, $payload);
    // end docs

    // docs: consumer-packages.md #4
    $response = $safeFetch->guard(Http::timeout(10), $policy->maxResponseBytes(4096))->post($url, $payload);

    $excerpt = $response->body();                                    // at most 4 096 bytes
    $cut = $safeFetch->truncated($response);                         // true when the body was cut
    $remoteAddress = $response->handlerStats()['primary_ip'] ?? null; // the vetted address it connected to
    // end docs

    // docs: consumer-packages.md #5
    $request->validate(['endpoint' => ['required', 'string', SafeUrl::withoutLookup($policy)]]);
    $stored = $safeFetch->precheck($request->input('endpoint'), $policy)->toString();
    // end docs

    expect($throttled)->toBe(1)
        ->and($excerpt)->toBe(str_repeat('y', 4096))
        ->and($cut)->toBeTrue()
        ->and($remoteAddress)->toBeNull()
        ->and($stored)->toBe('https://hooks.example.com/hook')
        ->and($policy->allowedHosts)->toBe(['hooks.example.com', 'minio.internal', 'gone.example.com']);

    $outcomes = [];

    foreach (['https://minio.internal/in', 'https://gone.example.com/in'] as $url) {
        // docs: consumer-packages.md #3
        try {
            $response = $safeFetch->guard(Http::timeout(10), $policy)->post($url, $payload);
        } catch (DestinationRejected $e) {
            $outcome = ['retry' => false, 'reason' => $e->reason()->value, 'rule' => $e->blockedBy()];   // never retry
        } catch (DestinationUnavailable $e) {
            $outcome = ['retry' => true, 'reason' => $e->reason()->value];                              // unresolved, out of time
        }
        // end docs

        $outcomes[] = $outcome;
    }

    expect($outcomes)->toBe([
        ['retry' => false, 'reason' => 'blocked', 'rule' => 'private_address'],
        ['retry' => true, 'reason' => 'unresolved'],
    ]);
});

it('runs the consumer-packages testing example', function (): void {
    $safeFetch = app(SafeFetchManager::class);
    $policy = SafeFetch::policy();

    // docs: consumer-packages.md #6
    $dns = SafeFetch::fakeDns(['hooks.example.com' => '93.184.216.34', 'internal.example.com' => '10.0.0.5']);
    Http::fake(fn (Request $request, array $options) => Http::response($options['curl'][CURLOPT_RESOLVE][0]));

    $pinned = $safeFetch->guard(Http::timeout(10), $policy)->post('https://hooks.example.com/in', ['event' => 'ping'])->body();

    $dns->assertLookedUp('hooks.example.com', 1);                    // $pinned === 'hooks.example.com:443:93.184.216.34'
    // end docs

    expect($pinned)->toBe('hooks.example.com:443:93.184.216.34');
});

it('runs the third-party-clients examples', function (): void {
    SafeFetch::fakeDns(['api.partner.example' => '93.184.216.34', 'tenant.example' => '151.101.1.1']);
    Http::fake(['api.partner.example/*' => Http::response('ok')]);
    $tenantUrl = 'https://tenant.example/hook';

    // docs: third-party-clients.md #0
    $client = SafeFetch::guzzleClient(['base_uri' => 'https://api.partner.example/', 'timeout' => 10]);

    $response = $client->get('v1/items');
    $client->post('v1/hooks', ['json' => ['url' => $tenantUrl]]);
    // end docs

    expect((string) $response->getBody())->toBe('ok');

    // docs: third-party-clients.md #1
    $stack = SafeFetch::handlerStack(SafeFetch::policy()->allowHosts(['*.partner.example']));

    $client = new Client(['handler' => $stack, 'base_uri' => 'https://api.partner.example/']);
    // end docs

    expect((string) $client->get('v1/items')->getBody())->toBe('ok')
        ->and(fn () => $client->get('https://tenant.example/'))->toThrow(DestinationRejected::class);
    Http::assertSentCount(3);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.partner.example/v1/hooks'
        && $request->data() === ['url' => $tenantUrl]);
});
