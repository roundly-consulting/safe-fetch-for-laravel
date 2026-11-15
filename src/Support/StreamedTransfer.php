<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use Throwable;

/**
 * One hop of a guarded request sent with `stream => true`: the response comes back as soon as the
 * hop's final headers arrive, and its body ({@see StreamedBody}) reads while the transfer goes on.
 *
 * It is still Guzzle's curl — its own multi handler per transfer, driven only while the response
 * is waited for and while its body is read — so everything the guard set on the hop applies as it
 * does to a buffered request: the pin (`CURLOPT_RESOLVE`), the pre-send check, fresh connections,
 * no proxy, no redirects, the hop's share of the time limit, and the caller's `progress`,
 * `on_headers` and `on_stats`. What is new is only where the body goes: into a buffer the reader
 * drains, under the policy's byte cap, with an idle limit (`read_timeout`) checked between reads.
 *
 * Only where the pre-send check runs: the address curl connected to is checked before the request
 * is sent, so the headers handed out early come from a vetted address. Without it the request is
 * received whole and checked afterwards, as a buffered one.
 *
 * @internal
 */
final class StreamedTransfer
{
    /** The hop option the guard passes its byte cap in. */
    public const string MAX_BYTES = 'safe_fetch.stream_max_bytes';

    private ?CurlMultiHandler $multi = null;

    private ?PromiseInterface $promise = null;

    private ?ResponseInterface $head = null;

    private string $buffer = '';

    /** How much of the buffer was read already: reads move this, rather than copy the rest. */
    private int $offset = 0;

    private int $received = 0;

    private bool $exceeded = false;

    private bool $idle = false;

    /** Whether the transfer is over, its outcome recorded. */
    private bool $settled = false;

    private ?Throwable $failure = null;

    /** Whether a read has thrown how the transfer failed: only then is a failed body at its end. */
    private bool $failureThrown = false;

    private float $lastActivity;

    private function __construct(
        private readonly VettedDestination $hop,
        private readonly ?int $maxBytes,
        private readonly ?float $readTimeout,
    ) {
        $this->lastActivity = self::now();
    }

    /**
     * Whether a hop with `$options` is streamed: `stream => true`, where the pre-send check runs.
     *
     * @param  array<string, mixed>  $options
     */
    public static function wanted(array $options): bool
    {
        return ! empty($options['stream']) && CurlSupport::preSendCheck();
    }

    /**
     * Send the hop and wait for its final headers (or its end).
     *
     * @param  array<string, mixed>  $options
     */
    public static function send(RequestInterface $request, array $options, VettedDestination $hop): PromiseInterface
    {
        $maxBytes = $options[self::MAX_BYTES] ?? null;
        $readTimeout = $options['read_timeout'] ?? null;
        $transfer = new self(
            $hop,
            is_int($maxBytes) && $maxBytes > 0 ? $maxBytes : null,
            is_numeric($readTimeout) && (float) $readTimeout > 0 ? (float) $readTimeout : null,
        );

        unset($options[self::MAX_BYTES]);
        // The body goes to the reader, never to a sink of the caller's (Guzzle's stream handler, too, ignores it).
        $options['sink'] = new StreamedSink($transfer);
        $callerHeaders = $options['on_headers'] ?? null;
        $options['on_headers'] = static function (ResponseInterface $response, mixed ...$more) use ($transfer, $callerHeaders): void {
            // The guard's (and through it the caller's) first: a throw there ends the transfer here.
            if (is_callable($callerHeaders)) {
                $callerHeaders($response, ...$more);
            }

            $transfer->headers($response);
        };

        // A select timeout short enough that the idle limit is checked on time while nothing arrives.
        $multi = new CurlMultiHandler(['select_timeout' => $transfer->readTimeout === null ? 1.0 : min(1.0, max(0.01, $transfer->readTimeout / 4))]);
        $transfer->multi = $multi;

        try {
            $promise = $multi($request, $options);
            $transfer->promise = $promise;
            // Through then(): Guzzle can settle the transfer's promise with another one (a
            // rejection), so only its handlers see how it really ended.
            $promise->then(
                static function () use ($transfer): void {
                    $transfer->settled = true;
                },
                static function (mixed $reason) use ($transfer): void {
                    $transfer->settled = true;
                    $transfer->failure = Create::exceptionFor($reason);
                },
            );

            while ($transfer->head === null && $transfer->pending()) {
                $transfer->pump();
            }
        } catch (Throwable $exception) {
            $transfer->abort();

            return Create::rejectionFor($exception);
        }

        if ($transfer->idle) {
            return Create::rejectionFor(FetchFailed::because(Reason::Timeout, $hop->url, $hop->host));
        }

        if ($transfer->head === null) {
            // Over before a final answer: the transfer's own outcome (a refusal, a redirect the
            // guard follows, a connection error) is the guard's to judge.
            $transfer->release();

            return $promise;
        }

        return Create::promiseFor($transfer->head->withBody(new StreamedBody($transfer)));
    }

    /** curl's headers: the final answer's are the response. */
    public function headers(ResponseInterface $response): void
    {
        $this->lastActivity = self::now();

        if ($response->getStatusCode() >= 200) {
            $this->head = $response;
        }
    }

    /**
     * curl's body bytes, through the byte cap. A short count is what makes curl abort the transfer
     * at the cap.
     */
    public function receive(string $data): int
    {
        $this->lastActivity = self::now();

        if ($this->maxBytes !== null && strlen($data) > $this->maxBytes - $this->received) {
            $this->exceeded = true;
            $data = substr($data, 0, $this->maxBytes - $this->received);
        }

        $this->buffer .= $data;
        $this->received += strlen($data);

        return strlen($data);
    }

    /**
     * Up to `$length` bytes of the body, driving the transfer until some arrive or it ends; '' at
     * the end.
     *
     * @throws FetchFailed|DestinationUnavailable when the body passed the cap, went idle or broke off
     */
    public function read(int $length): string
    {
        while ($this->buffer === '' && $this->pending()) {
            $this->pump();
        }

        if ($this->buffer !== '') {
            $chunk = substr($this->buffer, $this->offset, max(1, $length));
            $this->offset += strlen($chunk);

            if ($this->offset >= strlen($this->buffer)) {
                $this->buffer = '';
                $this->offset = 0;
            }

            return $chunk;
        }

        $this->release();

        if ($this->failed()) {
            $this->failureThrown = true;
        }

        if ($this->exceeded) {
            throw FetchFailed::because(Reason::TooLarge, $this->hop->url, $this->hop->host);
        }

        if ($this->idle) {
            throw FetchFailed::because(Reason::Timeout, $this->hop->url, $this->hop->host);
        }

        if ($this->failure !== null) {
            // A curl failure as a typed one; anything else (a callback of the caller's that threw) as thrown.
            throw TransportFailure::curlCode($this->failure) === null
                ? $this->failure
                : TransportFailure::from($this->failure, $this->hop->url, $this->hop->host);
        }

        return '';
    }

    /**
     * Whether nothing is left to read: the buffer is empty and the transfer is over — and, when it
     * failed, a read has thrown why, so a `while (! eof()) read()` loop never ends quietly short.
     */
    public function eof(): bool
    {
        return $this->buffer === '' && ! $this->pending() && (! $this->failed() || $this->failureThrown);
    }

    private function failed(): bool
    {
        return $this->exceeded || $this->idle || $this->failure !== null;
    }

    /** Stop the transfer, if it is still running, and let go of its curl handles. */
    public function abort(): void
    {
        if ($this->promise !== null && $this->pending()) {
            $this->promise->cancel();
        }

        $this->release();
    }

    /** Whether the transfer is still running. */
    private function pending(): bool
    {
        return ! $this->settled && $this->promise !== null && $this->multi !== null;
    }

    /** One turn of curl, unless the server has been silent for longer than the read timeout. */
    private function pump(): void
    {
        if ($this->readTimeout !== null && self::now() - $this->lastActivity >= $this->readTimeout) {
            $this->idle = true;
            $this->abort();

            return;
        }

        try {
            $this->multi?->tick();
            // What the turn settled reaches the handlers above now, not on the next turn.
            PromiseUtils::queue()->run();
        } catch (Throwable $exception) {
            // A callback that threw out of curl (Guzzle 7 lets it): the transfer cannot go on.
            $this->abort();

            throw $exception;
        }
    }

    /** Let go of the multi handler — closing its curl handles — once the transfer is over. */
    private function release(): void
    {
        $this->multi = null;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
