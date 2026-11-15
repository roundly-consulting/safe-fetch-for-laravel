<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/safe-fetch-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=safe-fetch-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/safe-fetch-for-laravel/main/art/hero.png" alt="Safe Fetch for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/safe-fetch-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/safe-fetch-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/safe-fetch-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/safe-fetch-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/safe-fetch-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/safe-fetch-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=safe-fetch-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Safe Fetch for Laravel

Make server-side requests to URLs you do not control — webhook targets, link previews, avatar,
feed and import URLs — without reaching your own network. Every URL is parsed strictly, every
address it resolves to must be public, and the connection is pinned to exactly those addresses,
redirects included.

## Installation

Requires PHP 8.4 and Laravel 13; `ext-curl` to send requests (`ext-intl` for international
host names).

```bash
composer require roundly-consulting/safe-fetch-for-laravel
```

## Usage

Validate a URL a user hands in, then fetch it — capped, with errors that say whether a retry can
help:

```php
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Rules\SafeUrl;

$request->validate(['feed' => ['required', 'string', 'max:2048', new SafeUrl]]);

try {
    $feed = SafeFetch::fetch($request->input('feed'), maxBytes: 2_000_000);
    // $feed->body, $feed->contentType, $feed->url
} catch (SafeFetchException $e) {
    $e->retryable(); // a 5xx or a timeout can heal; a blocked address never does
}
```

Or guard any Laravel HTTP call:

```php
Http::safe()->withToken($token)->post($hookUrl, $payload);
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/safe-fetch-for-laravel](https://roundly-consulting.com/open-source/docs/safe-fetch-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=safe-fetch-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=safe-fetch-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=safe-fetch-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
