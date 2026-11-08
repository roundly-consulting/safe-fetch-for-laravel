<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\DataTransferObjects;

use JsonException;

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

    /**
     * The body decoded as JSON (objects as arrays) — or the value at `$key` in dot notation, or
     * `$default` when it is not there. A body that is not valid JSON decodes to null. The body is
     * decoded on every call: keep the result when you read many keys of a large one.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        return $key === null ? $decoded : data_get($decoded, $key, $default);
    }

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
