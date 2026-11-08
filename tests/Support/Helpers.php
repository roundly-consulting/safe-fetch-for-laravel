<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Support;

use Closure;
use RoundlyConsulting\SafeFetch\Exceptions\SafeFetchException;
use RuntimeException;

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
}
