<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Support;

use RuntimeException;

/**
 * A real HTTP server on loopback (PHP's built-in one, IPv4 or IPv6), so guarded requests run
 * through Laravel's real HTTP client, Guzzle's real curl handler and real curl rather than
 * `Http::fake()`. Every request it receives is logged, so a test can prove what did — and did not —
 * arrive. A random port per start keeps parallel test processes apart.
 */
final class LocalHttpServer
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly string $host,
        public readonly int $port,
        private readonly string $log,
    ) {}

    /**
     * @param  string  $host  `127.0.0.1` or `::1`
     * @param  array<string, string>  $env  more environment for the server (e.g. PHP_CLI_SERVER_WORKERS)
     */
    public static function start(string $host = '127.0.0.1', string $router = 'router.php', array $env = []): self
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'safe-fetch-server-');
        $listen = str_contains($host, ':') ? "[{$host}]" : $host;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', "{$listen}:{$port}", __DIR__.'/files/'.$router],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                [...getenv(), 'SAFE_FETCH_SERVER_LOG' => $log, ...$env],
            );

            if (! is_resource($process)) {
                continue;
            }

            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen($listen, $port, $errorCode, $errorMessage, 0.1);

                if (is_resource($socket)) {
                    fclose($socket);

                    return new self($process, $host, $port, $log);
                }

                if (! proc_get_status($process)['running']) {
                    break;
                }

                usleep(50_000);
            }

            proc_terminate($process);
        }

        throw new RuntimeException("The local HTTP server on [{$host}] did not start.");
    }

    /**
     * Every request the server received: `METHOD /path?query body=<body> host=<Host header>`.
     *
     * @return list<string>
     */
    public function requests(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, 'PROBE')));
    }

    /** `http://<name>:<port><path>` for this server. */
    public function url(string $name, string $path = '/'): string
    {
        return "http://{$name}:{$this->port}{$path}";
    }

    public function stop(): void
    {
        // With PHP_CLI_SERVER_WORKERS the server forks workers, which outlive a terminated parent.
        $pid = proc_get_status($this->process)['pid'];
        @exec('pkill -TERM -P '.(int) $pid.' 2>/dev/null');

        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
    }
}
