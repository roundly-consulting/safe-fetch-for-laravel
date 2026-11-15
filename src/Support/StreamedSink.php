<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The sink curl writes a streamed hop's body into: every write goes to the {@see StreamedTransfer}
 * buffer its reader drains. Write-only; nothing but curl ever holds it.
 *
 * @internal
 */
final class StreamedSink implements StreamInterface
{
    public function __construct(
        private readonly StreamedTransfer $transfer,
    ) {}

    public function write(string $string): int
    {
        return $this->transfer->receive($string);
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('A streamed body has no position.');
    }

    public function eof(): bool
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

    public function read(int $length): string
    {
        throw new RuntimeException('A streamed body is read from its response.');
    }

    public function getContents(): string
    {
        throw new RuntimeException('A streamed body is read from its response.');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
