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
  `pool()` (guarded members stay concurrent) and other middleware. A Guzzle `handler` option, which
  on Guzzle 7 would replace the whole stack, is refused wherever it is set (`Http::globalOptions()`,
  `withOptions()` before or after `safe()`).
- The blocked-network table: private, loopback, link-local and cloud metadata (169.254.169.254,
  `fd00:ec2::254`, 100.100.100.200, 192.0.0.192, 168.63.129.16), carrier-grade NAT, multicast,
  documentation, benchmarking and reserved ranges; IPv6 that carries IPv4 (mapped, compatible,
  NAT64, translated, 6to4) judged by the IPv4 it carries, Teredo blocked; `localhost` and
  `*.localhost` by name. Shared with the Node twin through one contract fixture.
- A policy (`DestinationPolicy`, `config/safe-fetch.php`): https only by default, ports, a strict
  host allowlist (`allowHosts()` replaces it and refuses an empty list, so a list that happens to
  be empty never opens the guard), blocked hosts, the app's own hosts (`blockAppHosts()` turns the
  block on or off over the configuration), private-host exemptions (still resolved and pinned,
  never for a link-local or other cloud-metadata address — ECS and EKS credential endpoints
  included — only an allowed range names one back in), allowed and
  blocked address ranges (an allowed range covering every address — `0.0.0.0/0`, `::/0`, or an
  IPv6 prefix carrying all of IPv4 such as `::ffff:0:0/96` or `2002::/16` — is refused). `withoutHostAllowlist()`, `withoutPrivateHosts()` and
  `withoutAllowedRanges()` drop the host's carve-outs while keeping its blocks — the start of a
  policy for another package's untrusted URLs.
- A response cap for guarded requests (`maxResponseBytes`) that cuts a body and marks it
  `Safe-Fetch-Truncated: 1`; `SafeFetch::truncated($response)` reads the mark
  (`SafeFetchManager::TRUNCATED_HEADER` names it).
- Redirects, off by default: 307/308 at most three hops when on, every hop vetted and pinned
  again, no https to http, headers and credentials kept to the first origin, cookies sent and
  stored per hop (a redirected-to site cannot set cookies for the first one), one time budget.
  `RedirectPolicy::methodPreserving($max, forwardHeaders: [...])` builds a followed policy that
  also sends named headers (a webhook signature) to another origin in one call; the
  `forwardHeaders()`, `allowingDowngrade()` and `max()` withers change one setting each.
- `SafeFetch::fetch()`: a GET into memory with a byte cap (decoded bytes, so a compressed bomb
  stops too) and transport failures that say whether a retry can help (`retryable()`,
  `retryAfter()`), with a cause naming the curl code but never the URL; anything else (the fake's
  `LogicException`, a stray request, a programmer error) is thrown as is.
  `FetchedResponse::json()` decodes the body (null when it is not JSON).
- `SafeFetch::download()`: the same capped, guarded GET streamed to a file instead of memory
  (`DownloadedFile`: path, bytes, content type, final URL, headers); the body lands in a temporary
  file next to the path and replaces it only on success, so an existing file stays whole while the
  download runs and is left as it was when it fails.
- `SafeFetch::vet()` / `allows()`, `SafeFetch::networks()`, `SafeFetch::urls()`.
- `SafeFetch::precheck()`: every rule except the DNS lookup (shape, scheme, port, blocked and app
  hosts, the allowlist, `localhost`, an address literal in full) for save time and forms that
  should not wait on DNS; the URL is judged in full again when it is fetched.
- The `SafeUrl` rule and the `safe_url` string rule (`safe_url:http` takes http too), with one
  message whatever the reason; `SafeUrl::withoutLookup()` validates through `precheck()`.
- `php artisan safe-fetch:check {url?}`: a URL's verdict, or a check of the environment; `--json`
  prints either as JSON for scripts and monitoring.
- `DestinationRefused` event for every refusal (reason, host, rule, and `via` — `vet`, `allows`,
  `precheck`, `request`, `fetch` or `download` — so alerting can ignore form validation; never
  the URL).
- `SafeFetch::fake()` and `SafeFetch::fakeDns()` for host tests, with assertions for what was
  vetted, refused, fetched, downloaded and sent (`assertNothingRefused()`, `assertDownloaded()`
  among them).
- English and Slovak messages that never name the URL or an address; `blocked` and `unresolved`
  read the same, so a message shown to whoever sent the URL cannot map the internal network.
