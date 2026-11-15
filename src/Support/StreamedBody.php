<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Psr\Http\Message\StreamInterface;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationUnavailable;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RuntimeException;

/**
 * The body of a streamed guarded response: each read() returns what has arrived, driving the
 * transfer until something does. Read-only and not seekable. Closing it — or letting go of it —
 * stops the transfer and frees its curl handles.
 *
 * A read throws a `FetchFailed` when the body passes the policy's byte cap (`too_large`), the
 * server goes silent for longer than the read timeout or the time limit runs out (`timeout`), or
 * the connection breaks (`connection`, `tls`, …).
 *
 * @internal
 */
final class StreamedBody implements StreamInterface
{
    private ?StreamedTransfer $transfer;

    private int $position = 0;

    public function __construct(StreamedTransfer $transfer)
    {
        $this->transfer = $transfer;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws FetchFailed|DestinationUnavailable
     */
    public function read(int $length): string
    {
        if ($this->transfer === null) {
            throw new RuntimeException('The streamed body is closed.');
        }

        $chunk = $this->transfer->read($length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * Everything left, read to the end.
     *
     * @throws FetchFailed|DestinationUnavailable
     */
    public function getContents(): string
    {
        $contents = '';

        while (! $this->eof()) {
            $contents .= $this->read(1_048_576);
        }

        return $contents;
    }

    /**
     * @throws FetchFailed|DestinationUnavailable
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->transfer === null || $this->transfer->eof();
    }

    public function close(): void
    {
        $transfer = $this->transfer;
        $this->transfer = null;
        $transfer?->abort();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A streamed body cannot seek.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('A streamed body cannot seek.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A streamed body is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->transfer !== null;
    }

    public function getMetadata(?string $key = null)
    {
        $metadata = ['safe_fetch_streamed' => true];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
