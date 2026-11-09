<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\Support\SystemHostResolver;

/*
|--------------------------------------------------------------------------
| Safe Fetch
|--------------------------------------------------------------------------
|
| The default policy behind Http::safe(), SafeFetch::vet(), SafeFetch::fetch()
| and the safe_url rule. Private, loopback, link-local (cloud metadata),
| carrier-grade NAT, multicast, documentation and reserved addresses are always
| refused; the settings below only add to or carve out of that.
|
| Every value is read strictly: a typo or a value out of range throws naming
| the key instead of falling back to a default. Blank (a host's `KEY=`) means
| not set, so the default applies. Env booleans: true/false, 1/0, on/off, yes/no.
|
*/

return [

    // http too (local development, internal tools). https only by default.
    'allow_http' => env('SAFE_FETCH_ALLOW_HTTP', false),

    // null => any port 1–65535; otherwise the only ports a URL may use, e.g. [443].
    'ports' => null,

    'hosts' => [
        // Non-empty => ONLY these hosts (exact or *.example.com). Still resolved,
        // judged and pinned: an allowed host never makes a private address OK.
        'allowed' => [],

        // Refused by name, before any lookup.
        'blocked' => [],

        // Names whose addresses may be private (e.g. minio.internal). Still
        // resolved and pinned.
        'private' => [],
    ],

    'ranges' => [
        // Addresses / CIDRs exempt from the blocked-network table, e.g.
        // ['127.0.0.1/32', '10.20.0.0/16']. 0.0.0.0/0 and ::/0 are refused.
        'allowed' => [],

        // Extra addresses / CIDRs always refused; they beat every allow.
        'blocked' => [],
    ],

    // The app's own host names (app.url's host + extra) are refused by name, and
    // app.url's address when it is written as one: a request to yourself through
    // a public load balancer is SSRF too.
    'app_hosts' => [
        'block' => true,
        'extra' => [],
    ],

    'redirects' => [
        // Off by default: a redirect is returned to the caller unfollowed.
        'enabled' => env('SAFE_FETCH_REDIRECTS', false),

        // Hops followed at most, 0–10.
        'max' => env('SAFE_FETCH_MAX_REDIRECTS', 3),

        // null => 307 and 308 (method and body kept); SafeFetch::fetch() and
        // SafeFetch::download(), which only send GETs, follow 301, 302, 303, 307
        // and 308 then. Otherwise a subset of those five.
        'statuses' => null,

        // https -> http.
        'allow_downgrade' => false,

        // Header names also sent to another origin a redirect leads to
        // (Accept, Content-Type, User-Agent and the like always are).
        'forward_headers' => [],
    ],

    // Http::safe(), SafeFetch::http()/guard() and the guarded Guzzle stack
    // (SafeFetch::handlerStack()/guzzleClient()). Off (null) by default.
    'request' => [
        // Cut every response body at this many bytes (at least 1): the response
        // keeps its status and headers and gains Safe-Fetch-Truncated: 1. A
        // request's own `sink` is then refused. null => no cap.
        'max_bytes' => env('SAFE_FETCH_REQUEST_MAX_BYTES'),

        // The most seconds a request may take, every hop included, 1–600. A
        // shorter ->timeout() wins; a request without one gets this. null =>
        // only the request's own (Laravel's 30 s; none on a Guzzle client).
        'timeout' => env('SAFE_FETCH_REQUEST_TIMEOUT'),
    ],

    'fetch' => [
        // SafeFetch::fetch() and download() body cap in bytes, at least 1.
        'max_bytes' => env('SAFE_FETCH_MAX_BYTES', 5_242_880),

        // SafeFetch::fetch() and download() time budget in seconds, 1–600, every
        // hop included.
        'timeout' => env('SAFE_FETCH_TIMEOUT', 30),
    ],

    // The resolver behind every lookup: any class implementing
    // RoundlyConsulting\SafeFetch\Contracts\HostResolver.
    'resolver' => SystemHostResolver::class,

];
