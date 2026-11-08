<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Every verdict and failure the package reports: a refused destination, one that cannot be
 * reached right now, or a fetch that failed.
 *
 * The message is fixed per reason (`safe-fetch::messages.<reason>`), never names the URL or an
 * address, and reads the same for `blocked` and `unresolved` — so it is safe to show an end user.
 * Telling "private" from "not found" would map the host's internal network for whoever controls
 * the URL: an internal name resolves (privately) and is refused, a made-up one does not resolve.
 *
 * {@see self::reason()}, {@see self::retryable()} and `DestinationRejected::blockedBy()` do tell
 * them apart. They are for your code and your operators: never let their difference reach the
 * person who submitted the URL (a "try again later" shown only for `unresolved` is that oracle
 * again). The URL is kept for the caller in {@see self::url()}.
 */
abstract class SafeFetchException extends RuntimeException
{
    final public function __construct(
        private readonly Reason $reason,
        #[SensitiveParameter] private readonly string $url = '',
        private readonly string $host = '',
        ?Throwable $previous = null,
        string $message = '',
    ) {
        parent::__construct($message === '' ? self::translate($reason) : $message, 0, $previous);
    }

    public function reason(): Reason
    {
        return $this->reason;
    }

    /** Whether trying again later can help (DNS, a timeout, a dropped connection, 408/429/5xx). */
    public function retryable(): bool
    {
        return $this->reason->retryable();
    }

    /** The URL that was refused or failed, as the caller handed it in. Never part of the message. */
    public function url(): string
    {
        return $this->url;
    }

    /** The host the verdict is about, canonicalised; empty when the URL had none the guard could read. */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * The fixed message for `$reason`, translated when a translator is bound.
     *
     * @param  array<string, string|int>  $replace
     */
    protected static function translate(Reason $reason, array $replace = []): string
    {
        $key = 'safe-fetch::messages.'.$reason->value;
        $container = Container::getInstance();

        if ($container->bound('translator')) {
            $translated = $container->make(Translator::class)->get($key, $replace);

            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        return 'The request was refused ('.$reason->value.').';
    }
}
