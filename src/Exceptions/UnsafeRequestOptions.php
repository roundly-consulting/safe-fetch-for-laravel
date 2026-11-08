<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Exceptions;

use LogicException;

/**
 * A guarded request carries an option that would take it around the guard — a proxy, Guzzle's own
 * redirects, a raw curl option that re-routes the connection. A programmer error, not a verdict on
 * a URL: fix the call. The message names the option, never a URL.
 */
final class UnsafeRequestOptions extends LogicException
{
    public static function option(string $option, string $why): self
    {
        return new self("A guarded request cannot use the [{$option}] option: {$why}");
    }
}
