<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;

it('about renders counts not names', function (): void {
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.hosts.allowed', ['hooks.partner.example', '*.cdn.partner.example']);
    config()->set('safe-fetch.ranges.allowed', ['10.20.0.0/16']);
    config()->set('safe-fetch.ranges.blocked', ['10.99.0.0/16']);

    expect('safe-fetch')->toLeakNoSecrets(
        secrets: ['minio.internal', '10.20.0.0/16', 'hooks.partner.example', '10.99.0.0/16'],
        mustRender: ['Schemes', 'https', 'Allowed hosts', 'Private hosts', 'Redirects', 'off', 'DNS pinning', 'curl'],
    );
});

it('about renders the policy shape', function (): void {
    config()->set('safe-fetch.allow_http', true);
    config()->set('safe-fetch.ports', [443, 8443]);
    config()->set('safe-fetch.redirects.enabled', true);
    config()->set('safe-fetch.app_hosts.block', false);
    config()->set('safe-fetch.request.max_bytes', 1048576);
    config()->set('safe-fetch.request.timeout', 20);

    Artisan::call('about', ['--only' => 'safe-fetch']);
    $output = Artisan::output();

    expect($output)->toMatch('/Schemes\W+http, https/')
        ->toMatch('/Ports\W+443, 8443/')
        ->toMatch('/Allowed hosts\W+any/')
        ->toMatch('/App hosts\W+not blocked/')
        ->toMatch('/Redirects\W+307, 308 · max 3/')
        ->toMatch('/Request body cap\W+1048576 bytes/')
        ->toMatch('/Request time limit\W+20 s/');
});

it('about says when a guarded request has no caps of its own', function (): void {
    Artisan::call('about', ['--only' => 'safe-fetch']);

    expect(Artisan::output())->toMatch('/Request body cap\W+none/')
        ->toMatch('/Request time limit\W+none/');
});

it('about says when pinning is unavailable', function (): void {
    CurlSupport::assume(available: false);

    Artisan::call('about', ['--only' => 'safe-fetch']);

    expect(Artisan::output())->toMatch('/DNS pinning\W+unavailable/');
});
