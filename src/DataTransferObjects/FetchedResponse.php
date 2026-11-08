<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

/**
 * A successful (2xx) answer of `SafeFetch::fetch()`, its body read in full within the byte cap.
 */
final readonly class FetchedResponse
{
    /**
     * @param  string  $url  the URL the answer came from (the last hop, as the guard rebuilt it)
     * @param  array<string, list<string>>  $headers  by name, each a list of values
     */
    public function __construct(
        public int $status,
        public string $body,
        public ?string $contentType,
        public string $url,
        public array $headers,
    ) {}

    /** The first value of header `$name` (any case), or null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
