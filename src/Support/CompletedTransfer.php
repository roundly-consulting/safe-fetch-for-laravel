<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use Illuminate\Http\Client\Response;

/**
 * A {@see CappedTransfer} that ended in a 2xx answer whose body fit the cap: the answer, the sink
 * its body is in, and the URL it came from (the last hop, as the guard rebuilt it).
 *
 * @internal
 */
final readonly class CompletedTransfer
{
    public function __construct(
        public Response $response,
        public CappedSink $sink,
        public string $url,
    ) {}

    public function status(): int
    {
        return $this->response->status();
    }

    public function contentType(): ?string
    {
        $contentType = $this->response->header('Content-Type');

        return $contentType === '' ? null : $contentType;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->response->toPsrResponse()->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), $values));
        }

        return $headers;
    }
}
