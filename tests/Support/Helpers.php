<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Support;

use Closure;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RuntimeException;
use Throwable;

final class Helpers
{
    /** The SafeFetchException a call throws. */
    public static function refusal(Closure $call): SafeFetchException
    {
        try {
            $call();
        } catch (SafeFetchException $exception) {
            return $exception;
        }

        throw new RuntimeException('Nothing was thrown.');
    }

    /**
     * Run `$call` with every regular file this process writes capped at `$bytes` — a full disk for
     * the duration: a write past it fails (`EFBIG`) instead of killing the process (`SIGXFSZ`).
     */
    public static function withFullDisk(int $bytes, Closure $call): mixed
    {
        $limits = posix_getrlimit();
        $limit = static fn (mixed $value): int => $value === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $value;
        $soft = $limit($limits['soft filesize'] ?? 'unlimited');
        $hard = $limit($limits['hard filesize'] ?? 'unlimited');
        $handler = pcntl_signal_get_handler(SIGXFSZ);

        pcntl_signal(SIGXFSZ, SIG_IGN);
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $bytes, $hard);

        try {
            return $call();
        } finally {
            posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
            pcntl_signal(SIGXFSZ, $handler);
        }
    }

    /** Whatever a call throws. */
    public static function thrown(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        throw new RuntimeException('Nothing was thrown.');
    }
}
