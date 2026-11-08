<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

function blockedBy(Closure $call): ?string
{
    $refusal = Helpers::refusal($call);

    return $refusal instanceof DestinationRejected ? $refusal->blockedBy() : 'not rejected';
}

beforeEach(function (): void {
    SafeFetch::fakeDns(['public.example' => '93.184.216.34', 'inward.example' => '10.0.0.5']);
});

it('is https only by default', function (): void {
    expect(Helpers::refusal(fn () => SafeFetch::vet('http://public.example/'))->reason())->toBe(Reason::Scheme)
        ->and(SafeFetch::vet('https://public.example/')->url)->toBe('https://public.example/')
        ->and(SafeFetch::policy()->schemes)->toBe(['https']);
});

it('allows http by config', function (): void {
    config()->set('safe-fetch.allow_http', 'on');

    expect(SafeFetch::vet('http://public.example/')->scheme)->toBe('http')
        ->and(SafeFetch::policy()->schemes)->toBe(['http', 'https']);
});

it('limits ports', function (): void {
    config()->set('safe-fetch.ports', [443]);

    expect(Helpers::refusal(fn () => SafeFetch::vet('https://public.example:8443/'))->reason())->toBe(Reason::Port)
        ->and(SafeFetch::vet('https://public.example/')->port)->toBe(443)
        ->and(SafeFetch::vet('https://public.example:443/')->port)->toBe(443);
});

it('a strict allowlist refuses every other host', function (): void {
    $policy = SafeFetch::policy()->allowHosts(['hooks.example.com']);

    expect(Helpers::refusal(fn () => SafeFetch::vet('https://public.example/', $policy))->reason())->toBe(Reason::NotAllowed)
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://[2606:4700::1111]/', $policy))->reason())->toBe(Reason::NotAllowed)
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://93.184.216.34/', $policy))->reason())->toBe(Reason::NotAllowed);
});

it('a wildcard matches subdomains only', function (): void {
    SafeFetch::fakeDns(['a.example.com' => '93.184.216.34', 'example.com' => '93.184.216.34', 'badexample.com' => '93.184.216.34']);
    $policy = SafeFetch::policy()->allowHosts(['*.example.com']);

    expect(SafeFetch::allows('https://a.example.com/', $policy))->toBeTrue()
        ->and(SafeFetch::allows('https://example.com/', $policy))->toBeFalse()
        ->and(SafeFetch::allows('https://badexample.com/', $policy))->toBeFalse();
});

it('an allowed host is still resolved and pinned', function (): void {
    $dns = SafeFetch::fakeDns(['hooks.example.com' => '93.184.216.34']);

    $vetted = SafeFetch::vet('https://hooks.example.com/', SafeFetch::policy()->allowHosts(['hooks.example.com']));

    $dns->assertLookedUp('hooks.example.com', 1);
    expect($vetted->resolveEntry())->toBe('hooks.example.com:443:93.184.216.34');
});

it('an allowlist never exempts a private address', function (): void {
    SafeFetch::fakeDns(['hooks.example.com' => '10.0.0.5']);

    expect(blockedBy(fn () => SafeFetch::vet('https://hooks.example.com/', SafeFetch::policy()->allowHosts(['hooks.example.com']))))
        ->toBe('private_address');
});

it('a blocked host is refused before any lookup', function (): void {
    $dns = SafeFetch::fakeDns();
    config()->set('safe-fetch.hosts.blocked', ['public.example']);

    expect(blockedBy(fn () => SafeFetch::vet('https://PUBLIC.example./')))->toBe('blocked_host');
    $dns->assertNotLookedUp('public.example');
});

it('refuses the apps own host', function (): void {
    config()->set('app.url', 'https://myapp.test');
    SafeFetch::fakeDns(['myapp.test' => '93.184.216.34']);

    expect(blockedBy(fn () => SafeFetch::vet('https://MYAPP.test./x')))->toBe('app_host')
        ->and(SafeFetch::policy()->appHosts)->toBe(['myapp.test']);
});

it('the app host block can be turned off', function (): void {
    config()->set('app.url', 'https://myapp.test');
    config()->set('safe-fetch.app_hosts.block', 'off');
    SafeFetch::fakeDns(['myapp.test' => '93.184.216.34']);

    expect(SafeFetch::vet('https://myapp.test/')->addresses)->toBe(['93.184.216.34'])
        ->and(SafeFetch::allows('https://myapp.test/', SafeFetch::policy()->withoutAppHostBlock()))->toBeTrue();
});

it('refuses extra app hosts', function (): void {
    config()->set('safe-fetch.app_hosts.extra', ['admin.myapp.test', '*.internal.myapp.test']);

    expect(blockedBy(fn () => SafeFetch::vet('https://admin.myapp.test/')))->toBe('app_host')
        ->and(blockedBy(fn () => SafeFetch::vet('https://x.internal.myapp.test/')))->toBe('app_host')
        ->and(blockedBy(fn () => SafeFetch::vet('https://y.example/', SafeFetch::policy()->appHosts(['y.example']))))->toBe('app_host');
});

it('ignores an app url it cannot read', function (mixed $url): void {
    config()->set('app.url', $url);

    expect(SafeFetch::policy()->appHosts)->toBe([]);
})->with([null, '', 'not a url', 'http://127.0.0.1:8000', 42]);

it('a private host may resolve privately', function (): void {
    config()->set('app.url', 'https://myapp.test');
    $dns = SafeFetch::fakeDns(['minio.internal' => '10.0.0.7', 'localhost' => '127.0.0.1']);
    $policy = SafeFetch::policy()->allowHttp()->trustPrivateHosts(['minio.internal']);

    expect(SafeFetch::vet('http://minio.internal:9000/bucket', $policy)->resolveEntry())->toBe('minio.internal:9000:10.0.0.7')
        ->and(blockedBy(fn () => SafeFetch::vet('http://localhost/', $policy)))->toBe('hostname')
        ->and(SafeFetch::vet('http://localhost/', $policy->trustPrivateHosts(['localhost']))->addresses)->toBe(['127.0.0.1']);
    $dns->assertLookedUp('minio.internal', 1);
    $dns->assertLookedUp('localhost', 1);
});

it('blocked ranges beat private hosts', function (): void {
    SafeFetch::fakeDns(['minio.internal' => '10.0.0.7']);
    $policy = SafeFetch::policy()->trustPrivateHosts(['minio.internal'])->blockRanges(['10.0.0.0/24']);

    expect(blockedBy(fn () => SafeFetch::vet('https://minio.internal/', $policy)))->toBe('blocked_range');
});

it('allowed ranges exempt addresses', function (): void {
    $policy = SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']);

    expect(SafeFetch::vet('http://127.0.0.1/', $policy)->addresses)->toBe(['127.0.0.1'])
        ->and(blockedBy(fn () => SafeFetch::vet('http://127.0.0.2/', $policy)))->toBe('private_address');
});

it('blocked ranges beat allowed ranges', function (): void {
    $policy = SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1'])->blockRanges(['127.0.0.1/32']);

    expect(blockedBy(fn () => SafeFetch::vet('http://127.0.0.1/', $policy)))->toBe('blocked_range')
        ->and(blockedBy(fn () => SafeFetch::vet('https://public.example/', $policy->blockRanges(['93.184.216.0/24']))))->toBe('blocked_range');
});

it('an allowed range covers the ipv4 an ipv6 address carries', function (): void {
    $policy = SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']);

    expect(SafeFetch::vet('http://[::ffff:127.0.0.1]/', $policy)->addresses)->toBe(['::ffff:127.0.0.1']);
});

it('one private answer blocks the whole name', function (): void {
    SafeFetch::fakeDns(['split.example' => ['93.184.216.34', '127.0.0.1']]);

    expect(blockedBy(fn () => SafeFetch::vet('https://split.example/')))->toBe('private_address');
});

it('refuses a resolver answer that is no address', function (): void {
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['not-an-address'];
        }
    });

    expect(blockedBy(fn () => SafeFetch::vet('https://weird.example/', SafeFetch::policy()->trustPrivateHosts(['weird.example']))))->toBe('private_address');
});

it('an unresolvable host is retryable', function (): void {
    $refusal = Helpers::refusal(fn () => SafeFetch::vet('https://nowhere.example/'));

    expect($refusal)->toBeInstanceOf(DestinationUnavailable::class)
        ->and($refusal->reason())->toBe(Reason::Unresolved)
        ->and($refusal->retryable())->toBeTrue()
        ->and($refusal->host())->toBe('nowhere.example')
        ->and($refusal->url())->toBe('https://nowhere.example/');
});

it('applies name rules in order', function (): void {
    config()->set('app.url', 'https://both.example');
    $policy = SafeFetch::policy()->blockHosts(['both.example'])->allowHosts(['other.example']);

    expect(blockedBy(fn () => SafeFetch::vet('https://both.example/', $policy)))->toBe('blocked_host')
        ->and(blockedBy(fn () => SafeFetch::vet('https://both.example/', SafeFetch::policy()->allowHosts(['other.example']))))->toBe('app_host')
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://localhost/', SafeFetch::policy()->allowHosts(['other.example'])))->reason())->toBe(Reason::NotAllowed)
        ->and(blockedBy(fn () => SafeFetch::vet('https://localhost/')))->toBe('hostname');
});

it('announces every refusal', function (): void {
    Event::fake([DestinationRefused::class]);

    SafeFetch::allows('https://inward.example/secret?token=abc');
    SafeFetch::allows('https://nowhere.example/');
    SafeFetch::allows('not a url');
    SafeFetch::allows('https://public.example/');

    Event::assertDispatchedTimes(DestinationRefused::class, 3);
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->reason === Reason::Blocked
        && $event->host === 'inward.example'
        && $event->blockedBy === 'private_address');
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->reason === Reason::Unresolved && $event->blockedBy === null);
    Event::assertDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => $event->reason === Reason::InvalidUrl && $event->host === '');
    Event::assertNotDispatched(DestinationRefused::class, static fn (DestinationRefused $event): bool => str_contains(serialize($event), 'token=abc') || str_contains(serialize($event), '10.0.0.5'));
});

it('policies are immutable and validated', function (): void {
    $base = new DestinationPolicy;
    $loose = $base->allowHttp()->ports([443, 80, 443])->allowHosts(['a.example'])->allowHosts(['b.example'])
        ->blockHosts(['c.example'])->blockHosts(['d.example'])->trustPrivateHosts(['e.internal'])
        ->allowRanges(['10.0.0.0/8'])->allowRanges(['10.0.0.0/8', '192.168.1.1'])->blockRanges(['10.1.0.0/16'])
        ->appHosts(['me.example'])->withoutAppHostBlock()->followRedirects(5)->maxResponseBytes(4096);

    expect($base->schemes)->toBe(['https'])
        ->and($base->ports)->toBeNull()
        ->and($base->redirects->enabled)->toBeFalse()
        ->and($loose->schemes)->toBe(['http', 'https'])
        ->and($loose->httpsOnly()->schemes)->toBe(['https'])
        ->and($loose->ports)->toBe([80, 443])
        ->and($loose->ports(null)->ports)->toBeNull()
        ->and($loose->allowedHosts)->toBe(['b.example'])
        ->and($loose->blockedHosts)->toBe(['c.example', 'd.example'])
        ->and($loose->privateHosts)->toBe(['e.internal'])
        ->and($loose->allowedRanges)->toBe(['10.0.0.0/8', '192.168.1.1'])
        ->and($loose->blockedRanges)->toBe(['10.1.0.0/16'])
        ->and($loose->appHosts)->toBe(['me.example'])
        ->and($loose->blockAppHosts)->toBeFalse()
        ->and($loose->redirects->enabled)->toBeTrue()
        ->and($loose->redirects->max)->toBe(5)
        ->and($loose->redirects->statuses)->toBe([307, 308])
        ->and($loose->maxResponseBytes)->toBe(4096)
        ->and($loose->maxResponseBytes(null)->maxResponseBytes)->toBeNull()
        ->and($loose->redirects(RedirectPolicy::none())->redirects->enabled)->toBeFalse();
});

it('refuses a policy it could not apply as written', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'scheme' => [fn () => new DestinationPolicy(schemes: ['ftp']), 'http or https'],
    'no scheme' => [fn () => new DestinationPolicy(schemes: []), 'at least one scheme'],
    'port' => [fn () => new DestinationPolicy(ports: [0]), 'between 1 and 65535'],
    'port string' => [fn () => new DestinationPolicy(ports: ['443']), 'between 1 and 65535'],
    'no port' => [fn () => new DestinationPolicy(ports: []), 'at least one port'],
    'host' => [fn () => new DestinationPolicy(allowedHosts: ['*']), 'not a host name'],
    'host type' => [fn () => new DestinationPolicy(blockedHosts: [42]), 'must be a string'],
    'address as host' => [fn () => new DestinationPolicy(privateHosts: ['10.0.0.1']), 'is an address'],
    'range' => [fn () => new DestinationPolicy(allowedRanges: ['10.0.0.0/40']), 'not an address'],
    'range type' => [fn () => new DestinationPolicy(blockedRanges: [false]), 'not an address'],
    'everything v4' => [fn () => new DestinationPolicy(allowedRanges: ['0.0.0.0/0']), 'switch the guard off'],
    'everything v6' => [fn () => (new DestinationPolicy)->allowRanges(['::/0']), 'switch the guard off'],
    'cap' => [fn () => (new DestinationPolicy)->maxResponseBytes(0), 'at least 1 byte'],
    'redirect max' => [fn () => new RedirectPolicy(max: 11), 'between 0 and 10'],
    'redirect statuses' => [fn () => new RedirectPolicy(statuses: [302, 304]), 'one of 301'],
    'redirect no statuses' => [fn () => new RedirectPolicy(statuses: []), 'at least one'],
    'forward header' => [fn () => new RedirectPolicy(forwardHeaders: ['bad header']), 'not a header name'],
]);

it('shapes a redirect policy', function (): void {
    $policy = new RedirectPolicy(enabled: true, statuses: [308, 301, 308], forwardHeaders: ['X-Signature', 'x-signature']);

    expect($policy->statuses)->toBe([301, 308])
        ->and($policy->statusesGiven)->toBeTrue()
        ->and($policy->forwardHeaders)->toBe(['x-signature'])
        ->and($policy->follows(301))->toBeTrue()
        ->and($policy->follows(302))->toBeFalse()
        ->and($policy->enabled(false)->follows(301))->toBeFalse()
        ->and(RedirectPolicy::none()->enabled)->toBeFalse()
        ->and(RedirectPolicy::methodPreserving(2)->max)->toBe(2)
        ->and(RedirectPolicy::methodPreserving()->statusesGiven)->toBeTrue()
        ->and((new RedirectPolicy)->statusesGiven)->toBeFalse()
        ->and((new RedirectPolicy)->enabled()->statusesGiven)->toBeFalse()
        ->and((new RedirectPolicy)->withStatuses([303])->statuses)->toBe([303]);
});

it('builds a method preserving policy that forwards headers in one call', function (): void {
    $policy = RedirectPolicy::methodPreserving(4, forwardHeaders: ['Webhook-Id', 'webhook-timestamp', 'Webhook-Signature']);

    expect($policy->enabled)->toBeTrue()
        ->and($policy->max)->toBe(4)
        ->and($policy->statuses)->toBe([307, 308])
        ->and($policy->statusesGiven)->toBeTrue()
        ->and($policy->allowDowngrade)->toBeFalse()
        ->and($policy->forwardHeaders)->toBe(['webhook-id', 'webhook-timestamp', 'webhook-signature'])
        ->and(RedirectPolicy::methodPreserving()->forwardHeaders)->toBe([]);
});

it('replaces forward headers and keeps every other redirect setting', function (): void {
    $base = new RedirectPolicy(enabled: true, max: 5, statuses: [301, 308], allowDowngrade: true, forwardHeaders: ['x-old']);
    $policy = $base->forwardHeaders(['X-New', 'x-other']);

    expect($policy->forwardHeaders)->toBe(['x-new', 'x-other'])
        ->and($policy->enabled)->toBeTrue()
        ->and($policy->max)->toBe(5)
        ->and($policy->statuses)->toBe([301, 308])
        ->and($policy->statusesGiven)->toBeTrue()
        ->and($policy->allowDowngrade)->toBeTrue()
        ->and($base->forwardHeaders)->toBe(['x-old'])
        ->and($policy->forwardHeaders([])->forwardHeaders)->toBe([])
        ->and((new RedirectPolicy)->forwardHeaders(['x-a'])->statusesGiven)->toBeFalse();
});

it('allows a downgrade only when asked', function (): void {
    $policy = RedirectPolicy::methodPreserving(2, forwardHeaders: ['x-sig']);

    expect($policy->allowDowngrade)->toBeFalse()
        ->and($policy->allowingDowngrade()->allowDowngrade)->toBeTrue()
        ->and($policy->allowingDowngrade()->allowingDowngrade(false)->allowDowngrade)->toBeFalse()
        ->and($policy->allowingDowngrade()->max)->toBe(2)
        ->and($policy->allowingDowngrade()->forwardHeaders)->toBe(['x-sig'])
        ->and($policy->allowingDowngrade()->statuses)->toBe([307, 308]);
});

it('validates a forwarded header name in the wither', function (): void {
    expect(fn () => RedirectPolicy::methodPreserving()->forwardHeaders(['bad header']))
        ->toThrow(InvalidArgumentException::class, 'not a header name')
        ->and(fn () => RedirectPolicy::methodPreserving(forwardHeaders: ['x:y']))
        ->toThrow(InvalidArgumentException::class, 'not a header name');
});

it('limits max in the wither', function (): void {
    $policy = RedirectPolicy::methodPreserving(forwardHeaders: ['x-sig'])->allowingDowngrade();

    expect($policy->max(0)->max)->toBe(0)
        ->and($policy->max(10)->max)->toBe(10)
        ->and($policy->max(7)->forwardHeaders)->toBe(['x-sig'])
        ->and($policy->max(7)->allowDowngrade)->toBeTrue()
        ->and($policy->max(7)->enabled)->toBeTrue()
        ->and(fn () => $policy->max(-1))->toThrow(InvalidArgumentException::class, 'between 0 and 10')
        ->and(fn () => $policy->max(11))->toThrow(InvalidArgumentException::class, 'between 0 and 10');
});

it('turns the app host block back on', function (): void {
    config()->set('app.url', 'https://myapp.test');
    config()->set('safe-fetch.app_hosts.block', 'off');
    SafeFetch::fakeDns(['myapp.test' => '93.184.216.34']);

    expect(SafeFetch::allows('https://myapp.test/'))->toBeTrue()
        ->and(blockedBy(fn () => SafeFetch::vet('https://myapp.test/', SafeFetch::policy()->blockAppHosts())))->toBe('app_host');
});

it('switches the app host block either way without touching the app hosts', function (): void {
    $policy = (new DestinationPolicy)->appHosts(['me.example', '*.me.example']);

    expect($policy->blockAppHosts)->toBeTrue()
        ->and($policy->blockAppHosts(false)->blockAppHosts)->toBeFalse()
        ->and($policy->blockAppHosts(false)->blockAppHosts()->blockAppHosts)->toBeTrue()
        ->and($policy->withoutAppHostBlock()->blockAppHosts)->toBeFalse()
        ->and($policy->blockAppHosts(false)->appHosts)->toBe(['me.example', '*.me.example'])
        ->and($policy->withoutAppHostBlock()->blockAppHosts()->appHosts)->toBe(['me.example', '*.me.example']);
});
