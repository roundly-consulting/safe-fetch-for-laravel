# Changelog

All notable changes to `safe-fetch-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- `Http::safe()`: any Laravel HTTP request, guarded — the URL rebuilt from a strict parse, every
  address its host resolves to judged, the connection pinned to those addresses through curl
  (`CURLOPT_RESOLVE`), no proxy, no reused connection, and the address curl connected to checked
  before a byte is sent (libcurl 7.80+) and again afterwards. Works with `withToken()`, `retry()`,
  `pool()` (guarded members stay concurrent) and other middleware.
- The blocked-network table: private, loopback, link-local and cloud metadata (169.254.169.254,
  `fd00:ec2::254`, 100.100.100.200, 192.0.0.192, 168.63.129.16), carrier-grade NAT, multicast,
  documentation, benchmarking and reserved ranges; IPv6 that carries IPv4 (mapped, compatible,
  NAT64, translated, 6to4) judged by the IPv4 it carries, Teredo blocked; `localhost` and
  `*.localhost` by name. Shared with the Node twin through one contract fixture.
- A policy (`DestinationPolicy`, `config/safe-fetch.php`): https only by default, ports, a strict
  host allowlist (`allowHosts()` replaces it and refuses an empty list, so a list that happens to
  be empty never opens the guard), blocked hosts, the app's own hosts, private-host exemptions (still resolved and
  pinned), allowed and blocked address ranges, and a response cap (`maxResponseBytes`) that cuts
  a body and marks it `Safe-Fetch-Truncated: 1`. `blockAppHosts()` switches the app-host block
  on or off over whatever the configuration says. `withoutHostAllowlist()`,
  `withoutPrivateHosts()` and `withoutAllowedRanges()` drop the host's carve-outs while keeping
  its blocks — the start of a policy for another package's untrusted URLs.
- Redirects, off by default: 307/308 at most three hops when on, every hop vetted and pinned
  again, no https to http, headers and credentials kept to the first origin, one time budget.
  `RedirectPolicy::methodPreserving($max, forwardHeaders: [...])` builds a followed policy that
  also sends named headers (a webhook signature) to another origin in one call; the
  `forwardHeaders()`, `allowingDowngrade()` and `max()` withers change one setting each.
- `SafeFetch::fetch()`: a GET into memory with a byte cap (decoded bytes, so a compressed bomb
  stops too) and failures that say whether a retry can help (`retryable()`, `retryAfter()`).
- `SafeFetch::vet()` / `allows()`, `SafeFetch::networks()`, `SafeFetch::urls()`.
- The `SafeUrl` rule and the `safe_url` string rule, with one message whatever the reason.
- `php artisan safe-fetch:check {url?}`: a URL's verdict, or a check of the environment.
- `DestinationRefused` event for every refusal (reason, host, rule; never the URL).
- `SafeFetch::fake()` and `SafeFetch::fakeDns()` for host tests.
- English and Slovak messages that never name the URL or an address; `blocked` and `unresolved`
  read the same, so a message shown to whoever sent the URL cannot map the internal network.
