<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\RedirectPolicy;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;
use RoundlyConsulting\SafeFetch\Support\PrivateNetworks;
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

it('clears the private hosts a host trusts', function (): void {
    config()->set('safe-fetch.hosts.private', ['minio.internal', '*.svc.internal']);
    SafeFetch::fakeDns(['minio.internal' => '10.0.0.7', 'a.svc.internal' => '10.0.0.8']);
    $policy = SafeFetch::policy()->withoutPrivateHosts();

    expect(SafeFetch::allows('https://minio.internal/'))->toBeTrue()
        ->and($policy->privateHosts)->toBe([])
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://minio.internal/', $policy))->reason())->toBe(Reason::Blocked)
        ->and(blockedBy(fn () => SafeFetch::vet('https://minio.internal/', $policy)))->toBe('private_address')
        ->and(blockedBy(fn () => SafeFetch::vet('https://a.svc.internal/', $policy)))->toBe('private_address');
});

it('clears the allowed ranges a host trusts', function (): void {
    config()->set('safe-fetch.ranges.allowed', ['127.0.0.1/32', '10.20.0.0/16']);
    SafeFetch::fakeDns(['dev.example' => '127.0.0.1', 'svc.example' => '10.20.1.1']);
    $policy = SafeFetch::policy()->withoutAllowedRanges();

    expect(SafeFetch::allows('https://dev.example/'))->toBeTrue()
        ->and(SafeFetch::allows('https://svc.example/'))->toBeTrue()
        ->and($policy->allowedRanges)->toBe([])
        ->and(blockedBy(fn () => SafeFetch::vet('https://dev.example/', $policy)))->toBe('private_address')
        ->and(blockedBy(fn () => SafeFetch::vet('https://svc.example/', $policy)))->toBe('private_address')
        ->and(blockedBy(fn () => SafeFetch::vet('https://127.0.0.1/', $policy)))->toBe('private_address');
});

it('lifts a host allowlist only when asked', function (): void {
    config()->set('safe-fetch.hosts.allowed', ['media.example']);
    SafeFetch::fakeDns(['media.example' => '93.184.216.34']);

    expect(Helpers::refusal(fn () => SafeFetch::vet('https://public.example/'))->reason())->toBe(Reason::NotAllowed)
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://public.example/', SafeFetch::policy()->blockHosts(['x.example'])))->reason())->toBe(Reason::NotAllowed)
        ->and(SafeFetch::policy()->withoutHostAllowlist()->allowedHosts)->toBe([])
        ->and(SafeFetch::vet('https://public.example/', SafeFetch::policy()->withoutHostAllowlist())->host)->toBe('public.example')
        ->and(blockedBy(fn () => SafeFetch::vet('https://inward.example/', SafeFetch::policy()->withoutHostAllowlist())))->toBe('private_address');
});

it('keeps inherited blocks when carve outs are cleared', function (): void {
    config()->set('app.url', 'https://myapp.test');
    config()->set('safe-fetch.hosts.blocked', ['evil.example']);
    config()->set('safe-fetch.ranges.blocked', ['93.184.216.0/24']);
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.ranges.allowed', ['127.0.0.1/32']);
    SafeFetch::fakeDns(['evil.example' => '151.101.1.1', 'myapp.test' => '151.101.1.2']);
    $policy = SafeFetch::policy()->withoutHostAllowlist()->withoutPrivateHosts()->withoutAllowedRanges();

    expect($policy->blockedHosts)->toBe(['evil.example'])
        ->and($policy->blockedRanges)->toBe(['93.184.216.0/24'])
        ->and($policy->appHosts)->toBe(['myapp.test'])
        ->and($policy->blockAppHosts)->toBeTrue()
        ->and(blockedBy(fn () => SafeFetch::vet('https://evil.example/', $policy)))->toBe('blocked_host')
        ->and(blockedBy(fn () => SafeFetch::vet('https://public.example/', $policy)))->toBe('blocked_range')
        ->and(blockedBy(fn () => SafeFetch::vet('https://myapp.test/', $policy)))->toBe('app_host');
});

it('refuses an empty allowlist instead of lifting it', function (): void {
    config()->set('safe-fetch.hosts.allowed', ['media.example']);

    expect(fn () => SafeFetch::policy()->allowHosts([]))
        ->toThrow(InvalidArgumentException::class, 'An empty allowlist would allow every host; call withoutHostAllowlist() to lift it.')
        ->and(fn () => (new DestinationPolicy)->allowHosts([]))
        ->toThrow(InvalidArgumentException::class, 'withoutHostAllowlist()')
        ->and(SafeFetch::policy()->allowedHosts)->toBe(['media.example']);
});

it('replaces the allowlist as documented', function (): void {
    config()->set('safe-fetch.hosts.allowed', ['a.example']);
    SafeFetch::fakeDns(['a.example' => '93.184.216.34', 'b.example' => '93.184.216.35']);
    $policy = SafeFetch::policy()->allowHosts(['b.example']);

    expect($policy->allowedHosts)->toBe(['b.example'])
        ->and(SafeFetch::vet('https://b.example/', $policy)->host)->toBe('b.example')
        ->and(Helpers::refusal(fn () => SafeFetch::vet('https://a.example/', $policy))->reason())->toBe(Reason::NotAllowed);
});

it('never exempts a metadata address for a private host', function (string $address): void {
    SafeFetch::fakeDns(['meta.internal' => $address, 'minio.internal' => '10.0.0.7']);
    $policy = SafeFetch::policy()->trustPrivateHosts(['meta.internal', 'minio.internal']);

    expect(blockedBy(fn () => SafeFetch::vet('https://meta.internal/latest/meta-data/', $policy)))->toBe('private_address')
        ->and(SafeFetch::vet('https://minio.internal/', $policy)->addresses)->toBe(['10.0.0.7'])
        ->and(PrivateNetworks::isMetadata($address))->toBeTrue();
})->with([
    'aws, gcp, azure, openstack' => '169.254.169.254',
    'aws ipv6' => 'fd00:ec2::254',
    'alibaba' => '100.100.100.200',
    'oracle' => '192.0.0.192',
    'azure wireserver' => '168.63.129.16',
    'ipv4-mapped' => '::ffff:169.254.169.254',
    'nat64' => '64:ff9b::a9fe:a9fe',
]);

it('exempts a metadata address only through an allowed range', function (): void {
    SafeFetch::fakeDns(['meta.internal' => '169.254.169.254']);

    expect(SafeFetch::vet('https://meta.internal/', SafeFetch::policy()->allowRanges(['169.254.169.254/32']))->addresses)->toBe(['169.254.169.254'])
        ->and(SafeFetch::vet('https://meta.internal/', SafeFetch::policy()->trustPrivateHosts(['meta.internal'])->allowRanges(['169.254.0.0/16']))->addresses)->toBe(['169.254.169.254'])
        ->and(blockedBy(fn () => SafeFetch::vet('https://meta.internal/', SafeFetch::policy()->trustPrivateHosts(['meta.internal'])->allowRanges(['10.0.0.0/8']))))->toBe('private_address')
        ->and(PrivateNetworks::isMetadata('169.254.169.253'))->toBeFalse()
        ->and(PrivateNetworks::isMetadata('10.0.0.1'))->toBeFalse()
        ->and(PrivateNetworks::isMetadata('not an address'))->toBeFalse();
});

it('says where a refusal came from', function (): void {
    Event::fake([DestinationRefused::class]);
    Http::fake(['public.example/*' => Http::response('', 307, ['Location' => 'https://inward.example/'])]);
    $redirects = SafeFetch::policy()->followRedirects();
    $path = sys_get_temp_dir().'/safe-fetch-via-'.bin2hex(random_bytes(4));

    Helpers::refusal(fn () => SafeFetch::vet('https://inward.example/vet'));
    SafeFetch::allows('https://inward.example/allows');
    Validator::make(['url' => 'https://inward.example/rule'], ['url' => [new SafeUrl]])->passes();
    Helpers::refusal(fn () => SafeFetch::precheck('https://localhost/'));
    Validator::make(['url' => 'https://10.0.0.1/'], ['url' => [SafeUrl::withoutLookup()]])->passes();
    Helpers::refusal(fn () => Http::safe($redirects)->get('https://public.example/request'));
    Helpers::refusal(fn () => SafeFetch::fetch('https://public.example/fetch', followRedirects: true));
    Helpers::refusal(fn () => SafeFetch::download('https://public.example/download', $path, followRedirects: true));

    $vias = [];
    Event::assertDispatched(DestinationRefused::class, static function (DestinationRefused $event) use (&$vias): bool {
        $vias[] = $event->via;

        return true;
    });

    expect($vias)->toBe(['vet', 'allows', 'allows', 'precheck', 'precheck', 'request', 'fetch', 'download'])
        ->and(file_exists($path))->toBeFalse()
        ->and((new DestinationRefused(Reason::Blocked, 'x.example'))->via)->toBe('vet');
});

it('says where a refusal came from under the fake', function (): void {
    SafeFetch::fake(['inward.example' => '10.0.0.5']);
    Event::fake([DestinationRefused::class]);

    SafeFetch::allows('https://inward.example/');
    Helpers::refusal(fn () => SafeFetch::vet('https://inward.example/'));

    $vias = [];
    Event::assertDispatched(DestinationRefused::class, static function (DestinationRefused $event) use (&$vias): bool {
        $vias[] = $event->via;

        return true;
    });

    expect($vias)->toBe(['allows', 'vet']);
});
