<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The PHP stream wrapper behind {@see CappedSink}: a `fopen()`-able handle whose writes go through
 * the sink's budget and whose reads and seeks go to its memory.
 *
 * @internal
 */
final class CappedSinkStream
{
    private const string PROTOCOL = 'safe-fetch-capped';

    /** @var array<string, CappedSink> sinks waiting to be opened, by id */
    private static array $pending = [];

    /** @var resource|null set by PHP for every stream wrapper */
    public $context;

    private ?CappedSink $sink = null;

    /**
     * @return resource
     */
    public static function open(CappedSink $sink): mixed
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $id = Str::random(32);
        self::$pending[$id] = $sink;

        try {
            $handle = fopen(self::PROTOCOL.'://'.$id, 'w+b');
        } finally {
            unset(self::$pending[$id]);
        }

        if ($handle === false) {
            throw new RuntimeException('A response buffer could not be opened.');
        }

        return $handle;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->sink = self::$pending[substr($path, strlen(self::PROTOCOL) + 3)] ?? null;

        return $this->sink !== null;
    }

    public function stream_write(string $data): int
    {
        return $this->sink?->write($data) ?? 0;
    }

    public function stream_read(int $count): string|false
    {
        return $this->sink === null ? false : fread($this->sink->memory, max(1, $count));
    }

    public function stream_eof(): bool
    {
        return $this->sink === null || feof($this->sink->memory);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->sink !== null && fseek($this->sink->memory, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return $this->sink === null ? 0 : (int) ftell($this->sink->memory);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->sink === null ? false : fstat($this->sink->memory);
    }

    public function stream_flush(): bool
    {
        return $this->sink !== null && fflush($this->sink->memory);
    }

    public function stream_truncate(int $size): bool
    {
        return $this->sink !== null && $size >= 0 && ftruncate($this->sink->memory, $size);
    }

    public function stream_close(): void
    {
        $this->sink = null;
    }
}
