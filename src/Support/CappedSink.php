<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use RuntimeException;

/**
 * A response body kept in memory (`php://temp`) — or in a file, {@see self::toFile()} — through a
 * byte budget: a write past `maxBytes` stores what still fits and reports a short write count,
 * never an error.
 *
 * That count is the point. As a transfer's sink, a short write is what makes curl abort the
 * transfer at once — however the server sends the body (no Content-Length, chunked, lying about
 * its length, compressed: the count is of decoded bytes) — whereas a failed write only throws
 * inside curl's callback. `Http::fake()` writes a stub's body through the same handle.
 *
 * @internal
 */
final class CappedSink
{
    public int $written = 0;

    public bool $exceeded = false;

    /** @var resource the handle Guzzle writes to (a {@see CappedSinkStream}) */
    public readonly mixed $resource;

    /** @var resource the memory (or file) the body lands in */
    public readonly mixed $memory;

    /**
     * @param  resource  $memory
     */
    private function __construct(
        public readonly int $maxBytes,
        mixed $memory,
    ) {
        $this->memory = $memory;
        $this->resource = CappedSinkStream::open($this);
    }

    public static function open(int $maxBytes): self
    {
        $memory = fopen('php://temp', 'w+b');

        if ($memory === false) {
            throw new RuntimeException('A response buffer could not be opened.');
        }

        return new self($maxBytes, $memory);
    }

    /**
     * A sink writing to the file at `$path`, which it creates or empties. Opened by path every time —
     * never a reused handle: Guzzle closes the sink of a transfer it aborted.
     */
    public static function toFile(string $path, int $maxBytes): self
    {
        $file = @fopen($path, 'w+b');

        if ($file === false) {
            throw new RuntimeException('The download file could not be opened.');
        }

        return new self($maxBytes, $file);
    }

    /** Write `$data` through the budget; returns how many bytes were kept. */
    public function write(string $data): int
    {
        $room = $this->maxBytes - $this->written;

        if (strlen($data) > $room) {
            $this->exceeded = true;
            $data = substr($data, 0, max(0, $room));
        }

        if ($data === '') {
            return 0;
        }

        $written = (int) fwrite($this->memory, $data);
        $this->written += $written;

        return $written;
    }

    /** Empty the buffer for the next hop. */
    public function reset(): void
    {
        ftruncate($this->memory, 0);
        rewind($this->memory);
        $this->written = 0;
        $this->exceeded = false;
    }

    /** Push what was kept to the file, so a reader by path sees all of it. */
    public function flush(): void
    {
        fflush($this->memory);
    }

    /** Everything kept so far. */
    public function contents(): string
    {
        rewind($this->memory);

        return (string) stream_get_contents($this->memory);
    }
}
