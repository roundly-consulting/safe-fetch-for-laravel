<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Support\CappedSink;
use RoundlyConsulting\SafeFetch\Support\FetchProgress;
use RoundlyConsulting\SafeFetch\Support\RedirectLocation;
use RoundlyConsulting\SafeFetch\Support\SafeFetchConfig;
use RoundlyConsulting\SafeFetch\Support\TransportFailure;
use RuntimeException;
use Throwable;

/**
 * GET a URL into memory — `SafeFetch::fetch()`, the twin of the Node package's `fetchUrl`: guarded
 * like `Http::safe()`, its body cut off at a byte cap however the server sends it (counted after
 * decoding, so a compressed bomb stops too), and every failure an exception that says whether
 * trying again can help.
 *
 * Only a 2xx body is read. Any other answer is judged by its status and headers alone: the transfer
 * stops before its body, so a 503 with a huge error page stays a retryable 503.
 */
final readonly class FetchUrlAction
{
    public function __construct(
        private SafeFetchManager $manager,
        private Factory $http,
    ) {}

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     * @throws UnsafeRequestOptions for a Host header
     */
    public function execute(string $url, FetchOptions $options, DestinationPolicy $policy): FetchedResponse
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
        $progress = new FetchProgress(CappedSink::open($maxBytes));

        $request = $this->manager->guard($this->http->createPendingRequest(), $policy)
            ->withHeaders($options->headers)
            ->timeout($timeout)
            ->withOptions([
                'sink' => $progress->sink->resource,
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
                // A fresh buffer per hop: Guzzle closes the sink of a transfer it aborted (a redirect
                // the guard follows without reading its body).
                'safe_fetch.before_hop' => static function (VettedDestination $hop) use ($progress, $maxBytes): mixed {
                    $progress->hop = $hop;
                    $progress->sink = CappedSink::open($maxBytes);

                    return $progress->sink->resource;
                },
            ]);

        if ($options->accept !== null) {
            $request->accept($options->accept);
        }

        try {
            $response = $request->get($url);
        } catch (SafeFetchException|UnsafeRequestOptions $exception) {
            throw $exception;
        } catch (Throwable $exception) {
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
        $body = $this->bodyOf($response, $sink);

        if ($sink->exceeded) {
            throw FetchFailed::because(Reason::TooLarge, $url, $host);
        }

        $contentType = $response->header('Content-Type');

        return new FetchedResponse($response->status(), $body, $contentType === '' ? null : $contentType, $finalUrl, self::headers($response));
    }

    /**
     * The body, through the cap. curl streamed it into the sink already; a stub's (`Http::fake()`,
     * which copies its body over without the cap seeing it) is copied through the cap here.
     */
    private function bodyOf(Response $response, CappedSink $sink): string
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->getMetadata('uri') === (stream_get_meta_data($sink->resource)['uri'] ?? null)) {
            return $sink->contents();
        }

        $sink->reset();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof() && ! $sink->exceeded && ($chunk = $body->read(1_048_576)) !== '') {
            $sink->write($chunk);
        }

        return $sink->contents();
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

    /**
     * @return array<string, list<string>>
     */
    private static function headers(Response $response): array
    {
        $headers = [];

        foreach ($response->toPsrResponse()->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), $values));
        }

        return $headers;
    }
}
