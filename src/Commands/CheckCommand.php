<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\AddressRanges;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use Throwable;

/**
 * `safe-fetch:check {url}` prints the guard's verdict on a URL; without one it checks the
 * environment the guard runs in (curl, intl, Guzzle, the configuration) and warns about settings
 * that weaken it. Exits 1 on a refusal or an error-level finding.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'safe-fetch:check
        {url? : The URL to judge}
        {--http : Allow http for this check only}';

    /** @var string */
    protected $description = 'Judge a URL the way the SSRF guard would, or check the environment it runs in';

    public function handle(SafeFetchManager $safeFetch): int
    {
        $url = $this->argument('url');

        return is_string($url) && $url !== '' ? $this->judge($safeFetch, $url) : $this->doctor($safeFetch);
    }

    private function judge(SafeFetchManager $safeFetch, string $url): int
    {
        try {
            $policy = $safeFetch->policy();
            $vetted = $safeFetch->vet($url, $this->option('http') === true ? $policy->allowHttp() : $policy);
        } catch (SafeFetchException $exception) {
            $this->components->error('Refused: '.$exception->reason()->value.'.');

            if ($exception instanceof DestinationRejected && $exception->blockedBy() !== null) {
                $this->components->twoColumnDetail('Blocked by', $exception->blockedBy());
            }

            return self::FAILURE;
        }

        $this->components->info('Allowed.');
        $this->components->twoColumnDetail('URL', $vetted->url);
        $this->components->twoColumnDetail('Addresses', implode(', ', $vetted->addresses));
        $this->components->twoColumnDetail('Pinned as', $vetted->resolveEntry() ?? 'address literal, nothing to resolve');

        return self::SUCCESS;
    }

    private function doctor(SafeFetchManager $safeFetch): int
    {
        $errors = 0;
        $curl = CurlSupport::curlVersion();

        if (CurlSupport::available()) {
            $this->components->twoColumnDetail('DNS pinning', '<fg=green>curl '.$curl.'</>');
        } else {
            $errors++;
            $this->components->twoColumnDetail('DNS pinning', '<fg=red>unavailable</>');
            $this->components->error('ext-curl with CURLOPT_RESOLVE is required to send guarded requests: Http::safe() and fetch() refuse with cannot_pin.');
        }

        $this->components->twoColumnDetail('Pre-send address check', CurlSupport::preSendCheck() ? '<fg=green>on</>' : '<fg=yellow>off</>');

        if (CurlSupport::available() && ! CurlSupport::preSendCheck()) {
            $this->components->warn('The connected address is checked after the request only (needs libcurl 7.80+, and not Guzzle 7.12.x).');
        }

        $this->components->twoColumnDetail('International host names', function_exists('idn_to_ascii') ? '<fg=green>ext-intl</>' : '<fg=yellow>refused (no ext-intl)</>');
        $this->components->twoColumnDetail('Guzzle', CurlSupport::guzzleVersion());

        try {
            $policy = $safeFetch->policy();
        } catch (Throwable $exception) {
            $this->components->error('The configuration is invalid: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Configuration', '<fg=green>valid</>');

        foreach ($this->warnings($policy) as $warning) {
            $this->components->warn($warning);
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function warnings(DestinationPolicy $policy): array
    {
        $warnings = [];

        if (in_array('http', $policy->schemes, true) && $this->laravel->environment('production')) {
            $warnings[] = 'http is allowed in production (safe-fetch.allow_http): requests can be read and changed on the way.';
        }

        $broad = array_filter(
            $policy->allowedRanges,
            static fn (string $range): bool => AddressRanges::prefixLength($range) < (AddressRanges::isIpv6($range) ? 32 : 8),
        );

        if ($broad !== []) {
            $warnings[] = count($broad).' allowed range(s) are broader than /8 (IPv4) or /32 (IPv6) (safe-fetch.ranges.allowed).';
        }

        if ($policy->redirects->enabled && $policy->redirects->allowDowngrade) {
            $warnings[] = 'Redirects may drop from https to http (safe-fetch.redirects.allow_downgrade).';
        }

        return $warnings;
    }
}
