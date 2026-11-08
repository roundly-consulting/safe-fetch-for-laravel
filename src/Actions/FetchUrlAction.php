<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use InvalidArgumentException;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchedResponse;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Support\CappedSink;
use RoundlyConsulting\SafeFetch\Support\CappedTransfer;

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
        private CappedTransfer $transfer,
    ) {}

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     * @throws UnsafeRequestOptions for a Host header
     * @throws InvalidArgumentException for a cap or timeout out of range
     */
    public function execute(string $url, FetchOptions $options, DestinationPolicy $policy): FetchedResponse
    {
        $done = $this->transfer->run($url, $options, $policy, CappedSink::open(...));

        return new FetchedResponse($done->status(), $done->sink->contents(), $done->contentType(), $done->url, $done->headers());
    }
}
