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
