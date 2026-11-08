<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RuntimeException;

/**
 * One guarded GET whose 2xx body goes through a byte cap into a sink — what `SafeFetch::fetch()`
 * (into memory) and `SafeFetch::download()` (into a file) share. The body is cut off at the cap
 * however the server sends it (counted after decoding, so a compressed bomb stops too), and every
 * failure is an exception that says whether trying again can help.
 *
 * Only a 2xx body is read. Any other answer is judged by its status and headers alone: the transfer
 * stops before its body, so a 503 with a huge error page stays a retryable 503.
 *
 * @internal
 */
final readonly class CappedTransfer
{
    public function __construct(
        private SafeFetchManager $manager,
        private Factory $http,
    ) {}

    /**
     * @param  Closure(int): CappedSink  $sinkForHop  a fresh sink, under the given cap, for every hop
     * @param  'fetch'|'download'  $via  who asked, for `DestinationRefused::$via`
     *
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     * @throws UnsafeRequestOptions for a Host header
     * @throws InvalidArgumentException for a cap or timeout out of range
     */
    public function run(string $url, FetchOptions $options, DestinationPolicy $policy, Closure $sinkForHop, string $via): CompletedTransfer
    {
        $maxBytes = $options->maxBytes ?? SafeFetchConfig::fetchMaxBytes();
        $timeout = $options->timeout ?? SafeFetchConfig::fetchTimeout();

        if ($maxBytes < 1) {
            throw new InvalidArgumentException("A fetch byte cap must be at least 1, [{$maxBytes}] given.");
        }

        if ($timeout < 1 || $timeout > 600) {
            throw new InvalidArgumentException("A fetch timeout must be between 1 and 600 seconds, [{$timeout}] given.");
        }

        foreach (array_keys($options->headers) as $name) {
            if (strcasecmp(trim((string) $name), 'host') === 0) {
                throw UnsafeRequestOptions::option('headers.Host', 'the Host header is the vetted host, always.');
            }
        }

        $redirects = $policy->redirects;

        if ($options->followRedirects !== null) {
            $redirects = $redirects->enabled($options->followRedirects);
        }

        // Only GETs here, so every redirect status keeps the method: all five, unless the policy names some.
        if ($redirects->enabled && ! $redirects->statusesGiven) {
            $redirects = $redirects->withStatuses(RedirectLocation::STATUSES);
        }

        $policy = $policy->redirects($redirects)->maxResponseBytes(null);
        // Never written to: every hop the guard sends gets its own sink from `safe_fetch.before_hop`.
        $progress = new FetchProgress(CappedSink::open($maxBytes));

        $request = $this->manager->guard($this->http->createPendingRequest(), $policy)
            ->withHeaders($options->headers)
            ->timeout($timeout)
            ->withOptions([
                'sink' => $progress->sink->resource,
                'safe_fetch.via' => $via,
                // Called for every header block: an informational 1xx (`103 Early Hints`) is not the
                // answer, and a redirect the guard follows is the guard's to read.
                'on_headers' => static function (ResponseInterface $answer) use ($progress, $maxBytes, $redirects): void {
                    $status = $answer->getStatusCode();

                    if ($status < 200 || ($redirects->follows($status) && trim($answer->getHeaderLine('Location')) !== '')) {
                        return;
                    }

                    if ($status > 299) {
                        $progress->headersOnly = $answer;

                        throw new RuntimeException('Not a success: its body is not read.');
                    }

                    $declared = $answer->getHeaderLine('Content-Length');

                    if (ctype_digit($declared) && (int) $declared > $maxBytes) {
                        $progress->declaredTooLarge = true;

                        throw new RuntimeException('Declared larger than the cap: its body is not read.');
                    }
                },
                // A fresh sink per hop: Guzzle closes the sink of a transfer it aborted (a redirect
                // the guard follows without reading its body).
                'safe_fetch.before_hop' => static function (VettedDestination $hop) use ($progress, $maxBytes, $sinkForHop): mixed {
                    $progress->hop = $hop;
                    $progress->sink = $sinkForHop($maxBytes);

                    return $progress->sink->resource;
                },
            ]);

        if ($options->accept !== null) {
            $request->accept($options->accept);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException|RequestException|TransferException $exception) {
            // Only a transport failure is mapped; anything else — a refusal of the guard, the
            // fake's or a stray request's exception, a programmer error — reaches the caller as thrown.
            $host = $progress->hop->host ?? '';
            $response = $progress->headersOnly instanceof ResponseInterface ? new Response($progress->headersOnly) : null;

            if ($response === null) {
                throw $progress->declaredTooLarge || $progress->sink->exceeded
                    ? FetchFailed::because(Reason::TooLarge, $url, $host)
                    : TransportFailure::from($exception, $url, $host);
            }
        }

        $finalUrl = $progress->hop->url ?? $url;
        $host = $progress->hop->host ?? '';

        if (! $response->successful()) {
            throw self::failedStatus($url, $host, $response);
        }

        $declared = $response->header('Content-Length');

        if ($progress->declaredTooLarge || (ctype_digit($declared) && (int) $declared > $maxBytes)) {
            throw FetchFailed::because(Reason::TooLarge, $url, $host);
        }

        $sink = $progress->sink;
        self::throughTheCap($response, $sink);

        if ($sink->exceeded) {
            throw FetchFailed::because(Reason::TooLarge, $url, $host);
        }

        return new CompletedTransfer($response, $sink, $finalUrl);
    }

    /**
     * Make sure the body went through the cap. curl streamed it into the sink already; a stub's
     * (`Http::fake()`, which copies its body over without the cap seeing it) is copied in here.
     */
    private static function throughTheCap(Response $response, CappedSink $sink): void
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->getMetadata('uri') === (stream_get_meta_data($sink->resource)['uri'] ?? null)) {
            return;
        }

        $sink->reset();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof() && ! $sink->exceeded && ($chunk = $body->read(1_048_576)) !== '') {
            $sink->write($chunk);
        }
    }

    /** A non-2xx answer: retryable for 408, 429 and 5xx, terminal otherwise. */
    private static function failedStatus(string $url, string $host, Response $response): FetchFailed
    {
        $status = $response->status();
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        return FetchFailed::forStatus($url, $host, $status, $retryable ? self::retryAfter($response->header('Retry-After')) : null);
    }

    /** `Retry-After` in seconds — given as seconds or as an HTTP date, capped at a day — or null. */
    private static function retryAfter(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min(86_400, (int) $value);
        }

        try {
            $at = CarbonImmutable::createFromFormat(DATE_RFC7231, $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        return $at === null ? null : min(86_400, max(0, $at->getTimestamp() - CarbonImmutable::now()->getTimestamp()));
    }
}
