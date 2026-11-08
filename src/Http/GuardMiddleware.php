<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Http;

use Closure;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\CappedSink;
use RoundlyConsulting\SafeFetch\Support\Clock;
use RoundlyConsulting\SafeFetch\Support\CurlOptions;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PreSendCheck;
use RoundlyConsulting\SafeFetch\Support\RedirectLocation;
use RuntimeException;
use Throwable;

/**
 * The Guzzle middleware `Http::safe()` adds: every hop of a request is vetted (through the manager,
 * so `SafeFetch::fake()` sees it), sent to the rebuilt URL, pinned to the vetted addresses, kept
 * off any proxy, checked against the address curl actually connected to — before a byte is sent
 * where curl can, and from the transfer stats afterwards — and redirects are followed by hand under
 * the policy, each hop vetted and pinned again.
 *
 * It never wraps, swallows or reorders a throwable from code inside it: a refusal of its own is a
 * {@see DestinationRejected}/{@see DestinationUnavailable}; anything else reaches the caller as thrown.
 *
 * @internal
 */
final readonly class GuardMiddleware
{
    /** Headers a redirect to another origin still carries; everything else (credentials, cookies) stays behind. */
    private const array CROSS_ORIGIN_HEADERS = [
        'accept', 'accept-encoding', 'accept-language', 'content-type', 'content-length', 'content-encoding', 'user-agent',
    ];

    public const string TRUNCATED_HEADER = SafeFetchManager::TRUNCATED_HEADER;

    /** The option the guarded client hands the request's cookie jar over in. */
    public const string COOKIES = 'safe_fetch.cookies';

    public function __construct(
        private SafeFetchManager $manager,
        private DestinationPolicy $policy,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $this->refuseUnsafeOptions($options);

            $timeout = is_numeric($options['timeout'] ?? null) ? (float) $options['timeout'] : 0.0;
            $deadline = $timeout > 0 ? $this->clock->now() + $timeout : null;

            return $this->hop($handler, (string) $request->getUri(), $request, $options, 0, $deadline, null);
        };
    }

    /**
     * Refuse the options that would take the request around the guard. A programmer error, so it
     * throws rather than rejecting.
     *
     * @param  array<string, mixed>  $options
     */
    private function refuseUnsafeOptions(array $options): void
    {
        if (($options['allow_redirects'] ?? null) !== false) {
            throw UnsafeRequestOptions::option('allow_redirects', 'redirects are followed by the guard, which vets every hop; enable them on the policy (followRedirects()).');
        }

        $proxy = $options['proxy'] ?? null;

        if ($proxy !== null && $proxy !== '' && $proxy !== [] && ! self::fromEnvironment($proxy)) {
            throw UnsafeRequestOptions::option('proxy', 'a proxy resolves the host name itself, so the vetted addresses would not be the ones it connects to.');
        }

        if (array_key_exists('transport_sharing', $options)) {
            throw UnsafeRequestOptions::option('transport_sharing', 'a guarded request never shares a connection.');
        }

        $curl = $options['curl'] ?? [];

        foreach (is_array($curl) ? array_keys($curl) : [] as $option) {
            if (! CurlOptions::callerMay($option)) {
                throw UnsafeRequestOptions::option('curl.'.CurlOptions::nameOf($option), 'raw curl options that could route the connection elsewhere are not allowed on a guarded request.');
            }
        }

        if ($this->policy->maxResponseBytes !== null && isset($options['sink'])) {
            throw UnsafeRequestOptions::option('sink', 'with maxResponseBytes the guard owns the response body.');
        }
    }

    /**
     * Where the request was sent from, for {@see DestinationRefused::$via}: `fetch()` and
     * `download()` say so in `safe_fetch.via`; anything else is a guarded `request`.
     *
     * @param  array<string, mixed>  $options
     * @return 'request'|'fetch'|'download'
     */
    public static function via(array $options): string
    {
        $via = $options['safe_fetch.via'] ?? null;

        return $via === 'fetch' || $via === 'download' ? $via : 'request';
    }

    /**
     * Whether a proxy option only repeats the environment's proxies — Guzzle turns `HTTP_PROXY`,
     * `HTTPS_PROXY` and `NO_PROXY` into every client's default. Those the guard bypasses (it sends
     * `proxy: ''`); a proxy the caller chose is refused instead.
     */
    private static function fromEnvironment(mixed $proxy): bool
    {
        if (! is_array($proxy)) {
            return false;
        }

        $environment = [];

        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'] as $name) {
            $value = $_SERVER[$name] ?? getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[] = $value;
            }
        }

        foreach ($proxy as $scheme => $value) {
            if (! in_array($scheme, ['http', 'https', 'no'], true)) {
                return false;
            }

            if ($scheme !== 'no' && $value !== null && ! in_array($value, $environment, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One hop: vet `$url`, send the rebuilt request pinned, and follow a redirect if the policy says so.
     *
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @param  array<string, mixed>  $options  the caller's options, credentials stripped once a hop left the first origin
     */
    private function hop(callable $handler, string $url, RequestInterface $request, array $options, int $n, ?float $deadline, ?string $firstOrigin): PromiseInterface
    {
        try {
            $vetted = $this->manager->vetHop($url, $this->policy, self::via($options));
            $pins = $vetted->pinningOptions();
        } catch (Throwable $exception) {
            return Create::rejectionFor($exception);
        }

        $firstOrigin ??= $vetted->origin();
        $request = $request->withUri(new Uri($vetted->url));

        if ($vetted->origin() !== $firstOrigin) {
            [$request, $options] = $this->withoutCredentials($request, $options);
        }

        // Cookies per hop, as Guzzle's redirects send them: the jar's for this URL, stored from
        // this hop's own answer.
        $jar = $options[self::COOKIES] ?? null;
        $jar = $jar instanceof CookieJarInterface ? $jar : null;
        $request = $jar?->withCookieHeader($request) ?? $request;

        $remaining = $deadline === null ? null : $deadline - $this->clock->now();

        // Under a millisecond is spent: curl reads its timeout in whole milliseconds, and 0 as none.
        if ($remaining !== null && $remaining < 0.001) {
            return Create::rejectionFor(DestinationUnavailable::timedOut($vetted->url, $vetted->host));
        }

        $check = CurlSupport::preSendCheck() ? new PreSendCheck($vetted) : null;
        $sink = $this->policy->maxResponseBytes === null ? null : CappedSink::open($this->policy->maxResponseBytes);
        $stats = null;
        $redirect = null;
        $head = null;

        $hopOptions = $options;
        unset($hopOptions['safe_fetch.before_hop'], $hopOptions[self::COOKIES]);

        if ($jar !== null) {
            // Laravel's own bookkeeping reads the jar here (`Response::cookies()`).
            $hopOptions['cookies'] = $jar;
        }
        $hopOptions['safe_fetch.via'] = self::via($options);

        if ($n > 0) {
            // The caller's delay applies once, before the first request — as Guzzle's own redirects do.
            unset($hopOptions['delay']);
        }

        $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        $pinned = is_array($pins['curl'] ?? null) ? $pins['curl'] : [];

        foreach ($pinned as $option => $value) {
            $curl[$option] = $value;
        }

        $hopOptions['proxy'] = '';
        $hopOptions['allow_redirects'] = false;
        $hopOptions['protocols'] = [$vetted->scheme];
        $hopOptions['curl'] = $check === null ? $curl : $check->attachTo($curl);
        $hopOptions['safe_fetch.hop'] = $vetted;

        if ($remaining !== null) {
            $hopOptions['timeout'] = $remaining;
        }

        if ($sink !== null) {
            $hopOptions['sink'] = $sink->resource;
        }

        $callerStats = $options['on_stats'] ?? null;
        $hopOptions['on_stats'] = static function (TransferStats $transfer) use (&$stats, $callerStats): void {
            $stats = $transfer;

            if (is_callable($callerStats)) {
                $callerStats($transfer);
            }
        };

        $callerHeaders = $options['on_headers'] ?? null;
        $hopOptions['on_headers'] = function (ResponseInterface $response) use (&$redirect, &$head, $callerHeaders, $request): void {
            if (is_callable($callerHeaders)) {
                $callerHeaders($response);
            }

            $status = $response->getStatusCode();

            if ($status < 200) {
                return;
            }

            $head = $response;

            // A redirect the guard follows is judged by its status and Location alone: stop the
            // transfer before its body, which can be anything (and never end).
            if ($this->follows($response, $request)) {
                $redirect = $response;

                throw new RuntimeException('A followed redirect: its body is not read.');
            }
        };

        // `fetch()` hears of every hop, and may hand in a fresh sink for it.
        $before = $options['safe_fetch.before_hop'] ?? null;
        $replacement = is_callable($before) ? $before($vetted) : null;

        if (is_resource($replacement)) {
            $hopOptions['sink'] = $replacement;
        }

        return $handler($request, $hopOptions)->then(
            function (ResponseInterface $response) use (&$stats, $handler, $request, $options, $n, $deadline, $firstOrigin, $vetted, $sink, $jar): PromiseInterface {
                if ($stats instanceof TransferStats && ! self::stubbed($stats) && ! self::connectedInside($stats, $vetted)) {
                    $response->getBody()->close();

                    return $this->refusePin($vetted, $options);
                }

                $jar?->extractCookies($request, $response);

                if ($this->follows($response, $request)) {
                    return $this->follow($handler, $response, $request, $options, $n, $deadline, $firstOrigin, $vetted);
                }

                return Create::promiseFor($this->capped($response, $sink));
            },
            function (mixed $reason) use (&$stats, &$redirect, &$head, $check, $handler, $request, $options, $n, $deadline, $firstOrigin, $vetted, $sink, $jar): PromiseInterface {
                if ($check?->refused === true || ($stats instanceof TransferStats && self::connectedOutside($stats, $vetted))) {
                    return $this->refusePin($vetted, $options);
                }

                if ($head instanceof ResponseInterface) {
                    $jar?->extractCookies($request, $head);
                }

                if ($redirect instanceof ResponseInterface) {
                    return $this->follow($handler, $redirect, $request, $options, $n, $deadline, $firstOrigin, $vetted);
                }

                if ($sink !== null && $sink->exceeded && $head instanceof ResponseInterface) {
                    return Create::promiseFor(self::truncated($head, $sink));
                }

                return Create::rejectionFor($reason);
            },
        );
    }

    /**
     * Follow a redirect the policy follows: method and body per its status, credentials dropped on
     * another origin, the next hop vetted and pinned like the first.
     *
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @param  array<string, mixed>  $options
     */
    private function follow(callable $handler, ResponseInterface $response, RequestInterface $request, array $options, int $n, ?float $deadline, string $firstOrigin, VettedDestination $vetted): PromiseInterface
    {
        $response->getBody()->close();

        if ($n >= $this->policy->redirects->max) {
            return Create::rejectionFor(DestinationRejected::because(Reason::TooManyRedirects, $vetted->url, $vetted->host));
        }

        try {
            $next = RedirectLocation::resolve($vetted->url, trim($response->getHeaderLine('Location')));
        } catch (DestinationRejected $exception) {
            return Create::rejectionFor($exception);
        }

        if ($vetted->scheme === 'https' && stripos($next, 'http:') === 0 && ! $this->policy->redirects->allowDowngrade) {
            return Create::rejectionFor(DestinationRejected::because(Reason::RedirectDowngrade, $vetted->url, $vetted->host));
        }

        $status = $response->getStatusCode();
        $method = strtoupper($request->getMethod());

        if (($status === 307 || $status === 308) && $request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        } elseif (($status === 303 && $method !== 'HEAD') || (($status === 301 || $status === 302) && $method === 'POST')) {
            $request = $request->withMethod('GET')->withBody(Utils::streamFor(''));

            foreach (array_keys($request->getHeaders()) as $name) {
                if (str_starts_with(strtolower($name), 'content-')) {
                    $request = $request->withoutHeader($name);
                }
            }
        }

        return $this->hop($handler, $next, $request, $options, $n + 1, $deadline, $firstOrigin);
    }

    /**
     * Whether the guard follows this answer: redirects on, a followed status, a `Location` — and,
     * for 307/308, a body it can send again (one it cannot rewind is returned unfollowed).
     */
    private function follows(ResponseInterface $response, RequestInterface $request): bool
    {
        $status = $response->getStatusCode();

        if (! $this->policy->redirects->follows($status) || trim($response->getHeaderLine('Location')) === '') {
            return false;
        }

        return ($status !== 307 && $status !== 308) || $request->getBody()->isSeekable();
    }

    /**
     * The request and options a hop on another origin carries: the basic headers and the policy's
     * forward list, no credentials of any kind (CVE-2022-31090 — what Guzzle's redirect middleware
     * does, which this hop loop replaces).
     *
     * @param  array<string, mixed>  $options
     * @return array{0: RequestInterface, 1: array<string, mixed>}
     */
    private function withoutCredentials(RequestInterface $request, array $options): array
    {
        $kept = [...self::CROSS_ORIGIN_HEADERS, ...$this->policy->redirects->forwardHeaders];

        foreach (array_keys($request->getHeaders()) as $name) {
            if (strtolower($name) !== 'host' && ! in_array(strtolower($name), $kept, true)) {
                $request = $request->withoutHeader($name);
            }
        }

        unset($options['auth'], $options['__guzzle_digest_retries']);

        if (is_array($options['curl'] ?? null)) {
            $options['curl'] = CurlOptions::withoutCredentials($options['curl']);
        }

        return [$request, $options];
    }

    /** Whether the transfer connected to a vetted address. No address at all is no pin either. */
    private static function connectedInside(TransferStats $stats, VettedDestination $vetted): bool
    {
        $address = $stats->getHandlerStat('primary_ip');

        return is_string($address) && $address !== '' && $vetted->connectsTo($address);
    }

    /**
     * Whether the stats come from `Http::fake()`, which reports a transfer it never made: no time,
     * no handler stats. A real handler always reports its time — even one that is not curl, whose
     * missing address then fails the check.
     */
    private static function stubbed(TransferStats $stats): bool
    {
        return $stats->getTransferTime() === null && $stats->getHandlerStats() === [];
    }

    /** Whether a failed transfer had connected to an address the guard did not vet. */
    private static function connectedOutside(TransferStats $stats, VettedDestination $vetted): bool
    {
        $address = $stats->getHandlerStat('primary_ip');

        return is_string($address) && $address !== '' && ! $vetted->connectsTo($address);
    }

    /** A response whose body passed `maxResponseBytes`, cut there and marked. */
    private static function truncated(ResponseInterface $head, CappedSink $sink): ResponseInterface
    {
        return $head->withHeader(self::TRUNCATED_HEADER, '1')->withBody(Utils::streamFor($sink->contents()));
    }

    /**
     * The response, its body cut at `maxResponseBytes` when it is longer — one curl wrote into the
     * sink is already; a stub's (`Http::fake()`) is cut here.
     */
    private function capped(ResponseInterface $response, ?CappedSink $sink): ResponseInterface
    {
        $max = $this->policy->maxResponseBytes;

        if ($max === null || $sink === null) {
            return $response;
        }

        if ($sink->exceeded) {
            return self::truncated($response, $sink);
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $data = Utils::copyToString($body, $max + 1);

        if (strlen($data) <= $max) {
            if ($body->isSeekable()) {
                $body->rewind();

                return $response;
            }

            return $response->withBody(Utils::streamFor($data));
        }

        return $response->withHeader(self::TRUNCATED_HEADER, '1')->withBody(Utils::streamFor(substr($data, 0, $max)));
    }

    /**
     * The connection went somewhere the guard did not vet: report it, announce it, refuse it.
     *
     * @param  array<string, mixed>  $options
     */
    private function refusePin(VettedDestination $vetted, array $options): PromiseInterface
    {
        $exception = DestinationRejected::because(Reason::CannotPin, $vetted->url, $vetted->host);

        report($exception);
        $this->events->dispatch(new DestinationRefused(Reason::CannotPin, $vetted->host, via: self::via($options)));

        return Create::rejectionFor($exception);
    }
}
