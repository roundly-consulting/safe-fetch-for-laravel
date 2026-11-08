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
use RuntimeException;
use Throwable;

/**
 * GET a URL into a file — `SafeFetch::download()`: guarded and capped exactly like `fetch()`, its
 * body streamed to disk instead of held in memory, so a large file costs no memory.
 *
 * The body goes to a temporary file next to `$path` — every hop starts it afresh, so a redirect's
 * body never lands there — and replaces `$path` only once the download succeeded. A file already
 * at `$path` stays whole until then (readers never see it truncated or half written) and is left
 * alone on any failure; the temporary file is deleted. Nothing is written before the first hop
 * passed the guard.
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

        $temporary = null;

        try {
            $done = $this->transfer->run($url, $options, $policy, static function (int $maxBytes) use ($directory, $path, &$temporary): CappedSink {
                // Created on the first hop, never before the guard passed it; by tempnam(), which
                // makes a new file of its own (a name planted in the directory is never followed).
                $temporary ??= self::temporaryFile($directory, $path);

                return CappedSink::toFile($temporary, $maxBytes);
            }, 'download');

            $done->sink->flush();
            // The file keeps the mode the one it replaces had; a new one gets the umask's.
            chmod($temporary, file_exists($path) ? fileperms($path) & 0777 : 0666 & ~umask());

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('The download could not be moved into place.');
            }
        } catch (Throwable $exception) {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception;
        }

        return new DownloadedFile($done->status(), $path, $done->sink->written, $done->contentType(), $done->url, $done->headers());
    }

    private static function temporaryFile(string $directory, string $path): string
    {
        $temporary = @tempnam($directory, '.'.basename($path).'.');

        if ($temporary === false) {
            throw new RuntimeException('The download file could not be created.');
        }

        return $temporary;
    }
}
