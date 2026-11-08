<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Actions;

use InvalidArgumentException;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DownloadedFile;
use RoundlyConsulting\SafeFetch\DataTransferObjects\FetchOptions;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Support\CappedSink;
use RoundlyConsulting\SafeFetch\Support\CappedTransfer;
use Throwable;

/**
 * GET a URL into a file — `SafeFetch::download()`: guarded and capped exactly like `fetch()`, its
 * body streamed to disk instead of held in memory, so a large file costs no memory.
 *
 * Every hop writes to `$path` afresh (a redirect's body never lands there). On any failure the
 * file this download started is deleted: a partial or refused body is never left behind. Nothing
 * is written before the first hop passed the guard.
 */
final readonly class DownloadUrlAction
{
    public function __construct(
        private CappedTransfer $transfer,
    ) {}

    /**
     * @throws DestinationRejected
     * @throws DestinationUnavailable
     * @throws FetchFailed
     * @throws UnsafeRequestOptions for a Host header
     * @throws InvalidArgumentException for a path in no writable directory, a cap or timeout out of range
     */
    public function execute(string $url, string $path, FetchOptions $options, DestinationPolicy $policy): DownloadedFile
    {
        $directory = dirname($path);

        if ($path === '' || is_dir($path) || ! is_dir($directory) || ! is_writable($directory) || (file_exists($path) && ! is_writable($path))) {
            throw new InvalidArgumentException('A download needs a file path in an existing, writable directory.');
        }

        $started = false;

        try {
            $done = $this->transfer->run($url, $options, $policy, static function (int $maxBytes) use ($path, &$started): CappedSink {
                $started = true;

                return CappedSink::toFile($path, $maxBytes);
            });
        } catch (Throwable $exception) {
            if ($started && is_file($path)) {
                unlink($path);
            }

            throw $exception;
        }

        $done->sink->flush();

        return new DownloadedFile($done->status(), $path, $done->sink->written, $done->contentType(), $done->url, $done->headers());
    }
}
