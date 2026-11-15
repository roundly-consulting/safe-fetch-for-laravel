<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PreSendCheck;
use RoundlyConsulting\SafeFetch\Support\StreamedBody;
use RoundlyConsulting\SafeFetch\Support\StreamedTransfer;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;
use RoundlyConsulting\SafeFetch\Tests\Support\LocalHttpServer;
use RoundlyConsulting\SafeFetch\Tests\Support\Network;

/*
 * Streamed responses (`stream => true`) over real curl to `php -S` on loopback: the answer comes
 * back at its headers and its body reads as the server sends it, with every guard still on.
 */

function streamPolicy(): DestinationPolicy
{
    return SafeFetch::policy()->allowHttp()->allowRanges(['127.0.0.1/32']);
}

/**
 * Every entry point a streamed request goes through, each returning the PSR-7 response.
 *
 * @return array<string, array{Closure(string, DestinationPolicy, array<string, mixed>): ResponseInterface}>
 */
function streamSenders(): array
{
    return [
        'Http::safe()' => [static fn (string $url, DestinationPolicy $policy, array $options = []): ResponseInterface => Http::withOptions(['stream' => true, ...$options])->safe($policy)->get($url)->toPsrResponse()],
        'SafeFetch::handlerStack()' => [static fn (string $url, DestinationPolicy $policy, array $options = []): ResponseInterface => (new Client(['handler' => SafeFetch::handlerStack($policy)]))->get($url, ['stream' => true, ...$options])],
        'SafeFetch::guzzleClient()' => [static fn (string $url, DestinationPolicy $policy, array $options = []): ResponseInterface => SafeFetch::guzzleClient(['stream' => true], $policy)->get($url, $options)],
    ];
}

/** Read `$body` to its end in small pieces. */
function drain(StreamInterface $body): string
{
    $contents = '';

    while (! $body->eof()) {
        $contents .= $body->read(64);
    }

    return $contents;
}

beforeEach(function (): void {
    Network::ensure();

    if (! CurlSupport::preSendCheck()) {
        $this->markTestSkipped('streams only with the pre-send check (libcurl 7.80+)');
    }

    SafeFetch::fakeDns(['pin.invalid' => '127.0.0.1']);
    $this->server = LocalHttpServer::start();
});

afterEach(function (): void {
    $this->server?->stop();
});

it('hands out the first event before the server has finished', function (Closure $send): void {
    $started = microtime(true);
    $response = $send($this->server->url('pin.invalid', '/sse?chunks=4&delay=400'), streamPolicy(), []);
    $first = $response->getBody()->read(1024);
    $firstAfter = microtime(true) - $started;
    $rest = drain($response->getBody());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->getBody())->toBeInstanceOf(StreamedBody::class)
        ->and($first)->toBe("data: 1\n\n")
        // The other three take 1.2 s more: the first came long before them.
        ->and($firstAfter)->toBeLessThan(0.8)
        ->and($first.$rest)->toBe("data: 1\n\ndata: 2\n\ndata: 3\n\ndata: 4\n\n")
        ->and(microtime(true) - $started)->toBeGreaterThan(1.1)
        ->and($this->server->requests())->toHaveCount(1);
})->with(streamSenders());

it('streams a post body to a caller reading it in a loop', function (): void {
    config()->set('safe-fetch.allow_http', true);
    config()->set('safe-fetch.ranges.allowed', ['127.0.0.1/32']);
    $llmUrl = $this->server->url('pin.invalid', '/sse?chunks=3&delay=50');
    $payload = ['prompt' => 'Hello'];

    ob_start();

    $body = Http::withOptions(['stream' => true])
        ->safe(SafeFetch::policy()->readTimeout(30)) // end a stream silent for 30 s
        ->post($llmUrl, $payload)
        ->toPsrResponse()->getBody();

    while (! $body->eof()) {
        echo $body->read(1024);
    }

    expect(ob_get_clean())->toBe("data: 1\n\ndata: 2\n\ndata: 3\n\n")
        ->and($this->server->requests()[0])->toStartWith('POST /sse');
});

it('reads a whole streamed body as a string too', function (): void {
    $response = Http::withOptions(['stream' => true])->safe(streamPolicy())->get($this->server->url('pin.invalid', '/sse?chunks=3&delay=50'));

    expect($response->body())->toBe("data: 1\n\ndata: 2\n\ndata: 3\n\n")
        ->and($response->toPsrResponse()->getBody()->eof())->toBeTrue();
});

it('refuses a private or loopback address in stream mode', function (Closure $send): void {
    $loopback = Helpers::refusal(fn () => $send($this->server->url('pin.invalid', '/sse'), SafeFetch::policy()->allowHttp(), []));

    expect($loopback)->toBeInstanceOf(DestinationRejected::class)
        ->and($loopback->reason())->toBe(Reason::Blocked)
        ->and($this->server->requests())->toBe([]);
})->with(streamSenders());

it('refuses a redirect to a private address in stream mode', function (Closure $send): void {
    $target = "http://127.0.0.2:{$this->server->port}/sse";

    $refusal = Helpers::refusal(fn () => $send($this->server->url('pin.invalid', '/redirect?to='.urlencode($target)), streamPolicy()->followRedirects(), []));

    expect($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and($refusal->reason())->toBe(Reason::Blocked)
        ->and($this->server->requests())->toHaveCount(1);
})->with(streamSenders());

it('follows an allowed redirect and streams only the final hop', function (): void {
    $response = Http::withOptions(['stream' => true])->safe(streamPolicy()->followRedirects())
        ->get($this->server->url('pin.invalid', '/redirect?body=junk&to='.urlencode('/sse?chunks=2&delay=50')));

    expect($response->status())->toBe(200)
        ->and($response->body())->toBe("data: 1\n\ndata: 2\n\n")
        ->and($this->server->requests())->toHaveCount(2);
});

it('dials the address it vetted when dns rebinds between vet and dial', function (): void {
    $resolver = new class implements HostResolver
    {
        public int $lookups = 0;

        public function resolve(string $host): array
        {
            return [$this->lookups++ === 0 ? '127.0.0.1' : '169.254.169.254'];
        }
    };
    app()->instance(HostResolver::class, $resolver);
    $url = $this->server->url('rebind.invalid', '/sse?chunks=2&delay=50');

    $first = Http::withOptions(['stream' => true])->safe(streamPolicy())->get($url);
    $second = Helpers::refusal(fn () => Http::withOptions(['stream' => true])->safe(streamPolicy())->get($url));

    expect($first->body())->toBe("data: 1\n\ndata: 2\n\n")
        ->and($second->reason())->toBe(Reason::Blocked)
        ->and($resolver->lookups)->toBe(2)
        ->and($this->server->requests())->toHaveCount(1);
});

it('checks the connected address before sending, so a connection that lands elsewhere sends nothing', function (): void {
    // Vetted as 127.0.0.2, but the connection lands on 127.0.0.1: what a name answering
    // differently between vet and dial would do without the pin.
    $vetted = new VettedDestination($this->server->url('pin.invalid', '/sse'), 'http', 'pin.invalid', $this->server->port, false, ['127.0.0.2']);
    $check = new PreSendCheck($vetted);
    $options = [
        'stream' => true,
        'allow_redirects' => false,
        'curl' => $check->attachTo([CURLOPT_RESOLVE => ["pin.invalid:{$this->server->port}:127.0.0.1"], CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]),
    ];

    $result = Helpers::thrown(fn () => StreamedTransfer::send(new PsrRequest('GET', $vetted->url), $options, $vetted)->wait());

    expect($check->refused)->toBeTrue()
        ->and($result)->toBeInstanceOf(TransferException::class)
        ->and($this->server->requests())->toBe([]);
});

it('fails a stream past the byte cap mid-stream', function (Closure $send): void {
    $response = $send($this->server->url('pin.invalid', '/sse?chunks=40&delay=30'), streamPolicy()->maxResponseBytes(30), []);
    $body = $response->getBody();
    $read = '';

    $failure = Helpers::refusal(function () use ($body, &$read): void {
        while (true) {
            $read .= $body->read(8);
        }
    });

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::TooLarge)
        ->and($read)->toBe(substr(implode('', array_map(static fn (int $i): string => "data: {$i}\n\n", range(1, 10))), 0, 30));
})->with(streamSenders());

it('fails a stream that declares a body over the cap before reading it', function (): void {
    $failure = Helpers::refusal(fn () => Http::withOptions(['stream' => true])->safe(streamPolicy()->maxResponseBytes(10))->get($this->server->url('pin.invalid', '/hello.txt')));

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::TooLarge);
});

it('ends a stream that goes silent for the read timeout', function (DestinationPolicy $policy, array $options): void {
    $response = Http::withOptions(['stream' => true, ...$options])->safe($policy)->get($this->server->url('pin.invalid', '/sse?chunks=2&pause=5000'));
    $body = $response->toPsrResponse()->getBody();
    $first = $body->read(1024);
    $started = microtime(true);

    $failure = Helpers::refusal(fn () => $body->read(1024));

    expect($first)->toBe("data: 1\n\n")
        ->and($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::Timeout)
        ->and(microtime(true) - $started)->toBeGreaterThan(0.9)->toBeLessThan(2.5);
})->with([
    'the policy' => [fn () => streamPolicy()->readTimeout(1), []],
    'the request' => [fn () => streamPolicy(), ['read_timeout' => 1]],
    'the shorter of both' => [fn () => streamPolicy()->readTimeout(30), ['read_timeout' => 1]],
]);

it('ends a stream whose headers do not come within the read timeout', function (): void {
    $started = microtime(true);

    $failure = Helpers::refusal(fn () => Http::withOptions(['stream' => true, 'read_timeout' => 0.3])->safe(streamPolicy())->get($this->server->url('pin.invalid', '/slow')));

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::Timeout)
        ->and(microtime(true) - $started)->toBeLessThan(0.9);
});

it('still ends a stream at the total time limit', function (): void {
    $response = Http::withOptions(['stream' => true])->safe(streamPolicy()->timeout(1))->timeout(0)->get($this->server->url('pin.invalid', '/sse?chunks=20&delay=200'));
    $body = $response->toPsrResponse()->getBody();

    $failure = Helpers::refusal(fn () => drain($body));

    expect($failure)->toBeInstanceOf(FetchFailed::class)
        ->and($failure->reason())->toBe(Reason::Timeout);
});

it('keeps the callers progress, on headers and on stats in stream mode', function (): void {
    $progress = [];
    $heads = [];
    $stats = [];
    $response = Http::withOptions([
        'stream' => true,
        'progress' => static function (int $total, int $downloaded) use (&$progress): void {
            $progress[] = $downloaded;
        },
        'on_headers' => static function (ResponseInterface $head) use (&$heads): void {
            $heads[] = $head->getStatusCode();
        },
        'on_stats' => static function (TransferStats $transfer) use (&$stats): void {
            $stats[] = $transfer->getHandlerStat('primary_ip');
        },
    ])->safe(streamPolicy())->get($this->server->url('pin.invalid', '/sse?chunks=3&delay=600'));
    $body = $response->toPsrResponse()->getBody();

    $body->read(1024);
    $body->read(1024);
    $midway = $progress;
    drain($body);

    // Progress was reported while the stream was still running — before its last event.
    expect($heads)->toBe([200])
        ->and(max($midway))->toBeGreaterThan(0)
        ->and(max($midway))->toBeLessThan(strlen("data: 1\n\ndata: 2\n\ndata: 3\n\n"))
        ->and(max($progress))->toBe(strlen("data: 1\n\ndata: 2\n\ndata: 3\n\n"))
        ->and($stats)->toBe(['127.0.0.1']);
});

it('hands a callback of the callers that threw mid-stream back from the read', function (): void {
    $response = Http::withOptions([
        'stream' => true,
        'progress' => static function (int $total, int $downloaded): void {
            if ($downloaded > 9) {
                throw new LogicException('enough');
            }
        },
    ])->safe(streamPolicy())->get($this->server->url('pin.invalid', '/sse?chunks=4&delay=300'));
    $body = $response->toPsrResponse()->getBody();

    $failure = Helpers::thrown(fn () => drain($body));

    expect($failure)->not->toBeInstanceOf(FetchFailed::class)
        ->and($failure->getPrevious() ?? $failure)->toBeInstanceOf(LogicException::class)
        ->and(($failure->getPrevious() ?? $failure)->getMessage())->toBe('enough');
});

it('stops the transfer when the body is closed early', function (Closure $let): void {
    $response = Http::withOptions(['stream' => true])->safe(streamPolicy())->get($this->server->url('pin.invalid', '/sse?chunks=40&delay=50'));
    $body = $response->toPsrResponse()->getBody();

    expect($body->read(1024))->toBe("data: 1\n\n");

    $transfer = (new ReflectionProperty(StreamedBody::class, 'transfer'))->getValue($body);
    $multi = WeakReference::create((new ReflectionProperty(StreamedTransfer::class, 'multi'))->getValue($transfer));

    expect($multi->get())->toBeInstanceOf(CurlMultiHandler::class);

    $let($response, $body);
    unset($response, $body, $transfer);

    // Nothing holds the multi handler any more: its curl handles are closed with it.
    expect($multi->get())->toBeNull();

    // The server notices on its next write; give it a few.
    usleep(800_000);

    $events = $this->server->events();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatch('/^sse sent=(\d+) of=40 aborted=1$/');

    preg_match('/sent=(\d+)/', $events[0], $match);

    expect((int) $match[1])->toBeLessThan(40);
})->with([
    'closed' => [static function (mixed $response, StreamInterface $body): void {
        $body->close();

        expect($body->eof())->toBeTrue()
            ->and($body->isReadable())->toBeFalse()
            ->and(fn () => $body->read(1))->toThrow(RuntimeException::class, 'The streamed body is closed.');
    }],
    'let go of' => [static function (): void {}],
]);

it('sends without stream mode exactly as before', function (): void {
    $response = Http::safe(streamPolicy())->get($this->server->url('pin.invalid', '/sse?chunks=2&delay=50'));

    expect($response->toPsrResponse()->getBody())->not->toBeInstanceOf(StreamedBody::class)
        ->and($response->body())->toBe("data: 1\n\ndata: 2\n\n");
});

it('receives a streamed request whole where the pre-send check cannot run', function (): void {
    CurlSupport::assume(guzzleVersion: '7.12.3');

    try {
        $response = Http::withOptions(['stream' => true])->safe(streamPolicy()->maxResponseBytes(12))->get($this->server->url('pin.invalid', '/sse?chunks=2&delay=50'));
    } finally {
        CurlSupport::forget();
    }

    expect($response->toPsrResponse()->getBody())->not->toBeInstanceOf(StreamedBody::class)
        ->and($response->body())->toBe("data: 1\n\ndat")
        ->and($response->header('Safe-Fetch-Truncated'))->toBe('1');
});
