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
use RoundlyConsulting\SafeFetch\Support\SafeFetchConfig;
use Throwable;

/**
 * `safe-fetch:check {url}` prints the guard's verdict on a URL; without one it checks the
 * environment the guard runs in (curl, intl, Guzzle, the configuration) and warns about settings
 * that weaken it — `--json` for either as JSON, never naming a trusted host or range. Exits 1 on a
 * refusal or an error-level finding.
 */
final class CheckCommand extends Command
{
    private const string NO_CURL = 'ext-curl with CURLOPT_RESOLVE is required to send guarded requests: Http::safe(), fetch() and download() refuse with cannot_pin.';

    /** @var string */
    protected $signature = 'safe-fetch:check
        {url? : The URL to judge}
        {--http : Allow http for this check only}
        {--json : Print the verdict or the environment check as JSON}';

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
            $blockedBy = $exception instanceof DestinationRejected ? $exception->blockedBy() : null;

            if ($this->json()) {
                $this->printJson(['allowed' => false, 'reason' => $exception->reason()->value, 'retryable' => $exception->retryable(), 'blocked_by' => $blockedBy]);

                return self::FAILURE;
            }

            $this->components->error('Refused: '.$exception->reason()->value.'.');

            if ($blockedBy !== null) {
                $this->components->twoColumnDetail('Blocked by', $blockedBy);
            }

            return self::FAILURE;
        }

        if ($this->json()) {
            $this->printJson(['allowed' => true, 'url' => $vetted->url, 'addresses' => $vetted->addresses, 'pinned_as' => $vetted->resolveEntry()]);

            return self::SUCCESS;
        }

        $this->components->info('Allowed.');
        $this->components->twoColumnDetail('URL', $vetted->url);
        $this->components->twoColumnDetail('Addresses', implode(', ', $vetted->addresses));
        $this->components->twoColumnDetail('Pinned as', $vetted->resolveEntry() ?? 'address literal, nothing to resolve');

        return self::SUCCESS;
    }

    private function doctor(SafeFetchManager $safeFetch): int
    {
        $curl = CurlSupport::available();
        $preSend = CurlSupport::preSendCheck();
        $intl = function_exists('idn_to_ascii');
        $errors = [];
        $warnings = [];

        if (! $curl) {
            $errors[] = self::NO_CURL;
        }

        if ($curl && ! $preSend) {
            $warnings[] = 'The connected address is checked after the request only (needs libcurl 7.80+, and not Guzzle 7.12.x).';
        }

        try {
            $warnings = [...$warnings, ...$this->warnings($safeFetch->policy())];
            $configuration = null;
        } catch (Throwable $exception) {
            $configuration = 'The configuration is invalid: '.$exception->getMessage();
            $errors[] = $configuration;
        }

        if ($this->json()) {
            $this->printJson([
                'dns_pinning' => $curl,
                'curl' => CurlSupport::curlVersion(),
                'pre_send_check' => $preSend,
                'international_host_names' => $intl,
                'guzzle' => CurlSupport::guzzleVersion(),
                'configuration_valid' => $configuration === null,
                'errors' => $errors,
                'warnings' => $configuration === null ? $warnings : [],
            ]);

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($curl) {
            $this->components->twoColumnDetail('DNS pinning', '<fg=green>curl '.CurlSupport::curlVersion().'</>');
        } else {
            $this->components->twoColumnDetail('DNS pinning', '<fg=red>unavailable</>');
            $this->components->error(self::NO_CURL);
        }

        $this->components->twoColumnDetail('Pre-send address check', $preSend ? '<fg=green>on</>' : '<fg=yellow>off</>');
        $this->components->twoColumnDetail('International host names', $intl ? '<fg=green>ext-intl</>' : '<fg=yellow>refused (no ext-intl)</>');
        $this->components->twoColumnDetail('Guzzle', CurlSupport::guzzleVersion());

        if ($configuration !== null) {
            $this->components->error($configuration);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Configuration', '<fg=green>valid</>');

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function json(): bool
    {
        return $this->option('json') === true;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printJson(array $report): void
    {
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
            static fn (string $range): bool => (AddressRanges::ipv4PrefixLength($range) ?? 8) < 8
                || (AddressRanges::isIpv6($range) && AddressRanges::prefixLength($range) < 32),
        );

        if ($broad !== []) {
            $warnings[] = count($broad).' allowed range(s) are broader than /8 (IPv4) or /32 (IPv6) (safe-fetch.ranges.allowed).';
        }

        if ($policy->blockAppHosts && SafeFetchConfig::appUrlUnreadable()) {
            $warnings[] = 'app.url names no host the guard can read, so the app\'s own host is not refused (safe-fetch.app_hosts.block).';
        }

        if ($policy->redirects->enabled && $policy->redirects->allowDowngrade) {
            $warnings[] = 'Redirects may drop from https to http (safe-fetch.redirects.allow_downgrade).';
        }

        return $warnings;
    }
}
