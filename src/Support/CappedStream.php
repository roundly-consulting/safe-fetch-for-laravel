<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Psr\Http\Message\StreamInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RuntimeException;

/**
 * A streamed response body that did not come off the wire (an `Http::fake()` stub's), held to the
 * policy's byte cap the way a real one is: reads hand out up to the cap, and the read after it
 * throws `FetchFailed` (`too_large`) when more was there.
 *
 * @internal
 */
final class CappedStream implements StreamInterface
{
    private int $read = 0;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $maxBytes,
        private readonly VettedDestination $hop,
    ) {}

    /**
     * @throws FetchFailed when the body is longer than the cap
     */
    public function read(int $length): string
    {
        $room = $this->maxBytes - $this->read;

        if ($room <= 0) {
            if ($this->stream->read(1) === '') {
                return '';
            }

            throw FetchFailed::because(Reason::TooLarge, $this->hop->url, $this->hop->host);
        }

        $chunk = $this->stream->read(min(max(1, $length), $room));
        $this->read += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';

        while (($chunk = $this->read(1_048_576)) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function tell(): int
    {
        return $this->read;
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
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

    public function getSize(): ?int
    {
        return null;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A streamed body is read-only.');
    }
}
