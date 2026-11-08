<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DownloadedFile;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Exceptions\FetchFailed;
use RoundlyConsulting\SafeFetch\Exceptions\UnsafeRequestOptions;
use RoundlyConsulting\SafeFetch\Facades\SafeFetch;
use RoundlyConsulting\SafeFetch\Tests\Support\Helpers;

beforeEach(function (): void {
    SafeFetch::fakeDns([
        'files.example' => '93.184.216.34',
        'cdn.example' => '151.101.1.1',
        'inward.example' => '10.0.0.5',
    ]);
    $this->dir = sys_get_temp_dir().'/safe-fetch-download-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
    $this->path = $this->dir.'/file.bin';
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('downloads a body to the path', function (): void {
    Http::fake(['files.example/*' => Http::response('%PDF-1.7 body', 200, ['Content-Type' => 'application/pdf', 'X-Extra' => ['a', 'b']])]);

    $file = SafeFetch::download('https://FILES.example/doc.pdf#p2', $this->path, accept: 'application/pdf', headers: ['X-Trace' => '1']);

    expect($file)->toBeInstanceOf(DownloadedFile::class)
        ->and($file->status)->toBe(200)
        ->and($file->path)->toBe($this->path)
        ->and($file->bytes)->toBe(13)
        ->and($file->contentType)->toBe('application/pdf')
        ->and($file->url)->toBe('https://files.example/doc.pdf')
        ->and($file->headers['X-Extra'])->toBe(['a', 'b'])
        ->and($file->header('x-extra'))->toBe('a')
        ->and($file->header('missing'))->toBeNull()
        ->and(file_get_contents($this->path))->toBe('%PDF-1.7 body');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->header('Accept') === ['application/pdf']
        && $request->header('X-Trace') === ['1']);
});

it('reports no content type when there is none', function (): void {
    Http::fake(['files.example/*' => Http::response('raw', 200)]);

    expect(SafeFetch::download('https://files.example/', $this->path)->contentType)->toBeNull();
});

it('copies a faked body through the cap', function (): void {
    Http::fake([
        'files.example/exact' => Http::response(str_repeat('y', 1024)),
        'files.example/empty' => Http::response(''),
    ]);

    $exact = SafeFetch::download('https://files.example/exact', $this->path, maxBytes: 1024);

    expect($exact->bytes)->toBe(1024)
        ->and(file_get_contents($this->path))->toBe(str_repeat('y', 1024))
        ->and(SafeFetch::download('https://files.example/empty', $this->dir.'/empty.bin')->bytes)->toBe(0)
        ->and(file_get_contents($this->dir.'/empty.bin'))->toBe('');
});

it('fails too large and removes the partial file', function (string $path): void {
    Http::fake([
        'files.example/declared' => Http::response('small', 200, ['Content-Length' => '999999']),
        'files.example/streamed' => Http::response(str_repeat('x', 2048)),
    ]);

    $refusal = Helpers::refusal(fn () => SafeFetch::download("https://files.example/{$path}", $this->path, maxBytes: 1024));

    expect($refusal)->toBeInstanceOf(FetchFailed::class)
        ->and($refusal->reason())->toBe(Reason::TooLarge)
        ->and(file_exists($this->path))->toBeFalse();
})->with(['declared', 'streamed']);

it('fails a non success status without writing a body', function (): void {
    Http::fake(['files.example/*' => Http::response('a huge error page', 503, ['Retry-After' => '30'])]);

    $refusal = Helpers::refusal(fn () => SafeFetch::download('https://files.example/', $this->path));

    expect($refusal)->toBeInstanceOf(FetchFailed::class)
        ->and($refusal instanceof FetchFailed ? $refusal->status() : null)->toBe(503)
        ->and($refusal instanceof FetchFailed ? $refusal->retryAfter() : null)->toBe(30)
        ->and($refusal->retryable())->toBeTrue()
        ->and(file_exists($this->path))->toBeFalse();
});

it('removes the file when the transfer fails', function (): void {
    Http::fake(['files.example/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    $refusal = Helpers::refusal(fn () => SafeFetch::download('https://files.example/', $this->path));

    expect($refusal->reason())->toBe(Reason::Timeout)
        ->and(file_exists($this->path))->toBeFalse();
});

it('starts a fresh file on every hop', function (): void {
    Http::fake([
        'files.example/start' => Http::response('redirect body', 302, ['Location' => 'https://cdn.example/final']),
        'cdn.example/final' => Http::response('final body'),
    ]);

    $file = SafeFetch::download('https://files.example/start', $this->path, followRedirects: true);

    expect($file->url)->toBe('https://cdn.example/final')
        ->and($file->bytes)->toBe(10)
        ->and(file_get_contents($this->path))->toBe('final body');
});

it('keeps the previous file whole until a download succeeds', function (): void {
    file_put_contents($this->path, 'old');
    chmod($this->path, 0640);
    $during = [];
    Http::fake([
        'files.example/broken' => Http::response('error page', 503),
        'files.example/*' => function () use (&$during) {
            $during[] = file_get_contents($this->path);

            return Http::response('new body');
        },
    ]);

    $refusal = Helpers::refusal(fn () => SafeFetch::download('https://files.example/broken', $this->path));
    $kept = file_get_contents($this->path);
    $file = SafeFetch::download('https://files.example/fresh', $this->path);
    clearstatcache();

    expect($refusal)->toBeInstanceOf(FetchFailed::class)
        ->and($kept)->toBe('old')
        ->and($during)->not->toBeEmpty()->each->toBe('old')
        ->and($file->bytes)->toBe(8)
        ->and(file_get_contents($this->path))->toBe('new body')
        ->and(fileperms($this->path) & 0777)->toBe(0640)
        ->and(array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])))->toBe(['file.bin']);
});

it('cleans up when the finished download cannot be moved into place', function (): void {
    Http::fake(['files.example/*' => function () {
        mkdir($this->path);

        return Http::response('body');
    }]);

    expect(fn () => SafeFetch::download('https://files.example/', $this->path))
        ->toThrow(RuntimeException::class, 'could not be moved into place')
        ->and(array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])))->toBe(['file.bin'])
        ->and(is_dir($this->path))->toBeTrue();
});

it('leaves a file it never started alone', function (): void {
    file_put_contents($this->path, 'keep me');
    Http::fake();

    expect(Helpers::refusal(fn () => SafeFetch::download('https://inward.example/', $this->path))->reason())->toBe(Reason::Blocked)
        ->and(file_get_contents($this->path))->toBe('keep me');
    Http::assertNothingSent();
});

it('removes a file a refused redirect started', function (): void {
    Http::fake(['files.example/*' => Http::response('', 302, ['Location' => 'https://inward.example/'])]);

    $refusal = Helpers::refusal(fn () => SafeFetch::download('https://files.example/', $this->path, followRedirects: true));

    expect($refusal)->toBeInstanceOf(DestinationRejected::class)
        ->and(file_exists($this->path))->toBeFalse();
    Http::assertSentCount(1);
});

it('refuses a host header', function (): void {
    Http::fake();

    expect(fn () => SafeFetch::download('https://files.example/', $this->path, headers: ['Host' => 'inward.example']))
        ->toThrow(UnsafeRequestOptions::class, '[headers.Host]')
        ->and(file_exists($this->path))->toBeFalse();
    Http::assertNothingSent();
});

it('refuses a missing directory', function (string $path): void {
    Http::fake();

    expect(fn () => SafeFetch::download('https://files.example/', $path))
        ->toThrow(InvalidArgumentException::class, 'an existing, writable directory');
    Http::assertNothingSent();
})->with([
    'missing' => fn (): string => $this->dir.'/missing/file.bin',
    'a directory' => fn (): string => $this->dir,
    'empty' => fn (): string => '',
]);

it('refuses a path it cannot write to', function (): void {
    Http::fake();
    file_put_contents($this->path, 'read only');
    chmod($this->path, 0400);
    $readOnlyFile = Helpers::thrown(fn () => SafeFetch::download('https://files.example/', $this->path));
    chmod($this->path, 0600);

    chmod($this->dir, 0500);
    $readOnlyDirectory = Helpers::thrown(fn () => SafeFetch::download('https://files.example/', $this->dir.'/new.bin'));
    chmod($this->dir, 0700);

    expect($readOnlyFile)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($readOnlyDirectory)->toBeInstanceOf(InvalidArgumentException::class)
        ->and(file_get_contents($this->path))->toBe('read only');
    Http::assertNothingSent();
});

it('refuses a download it could not run as asked', function (Closure $download, string $message): void {
    expect($download)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no bytes' => [fn () => SafeFetch::download('https://files.example/', sys_get_temp_dir().'/never.bin', maxBytes: 0), 'at least 1'],
    'no time' => [fn () => SafeFetch::download('https://files.example/', sys_get_temp_dir().'/never.bin', timeout: 0), 'between 1 and 600'],
]);

it('records downloads under the fake', function (): void {
    $fake = SafeFetch::fake();
    Http::fake(['*' => Http::response('ok')]);

    $fake->assertNothingDownloaded();
    SafeFetch::download('https://files.example/a.bin', $this->path);

    $fake->assertDownloaded('https://files.example/a.bin');
    $fake->assertDownloaded('https://FILES.example./a.bin#x');
    $fake->assertDownloaded(static fn (string $url): bool => str_ends_with($url, '.bin'));
    $fake->assertVetted('https://files.example/a.bin');
    $fake->assertNothingFetched();
    $fake->assertGuardedRequestSent();

    expect(fn () => $fake->assertDownloaded('https://files.example/b.bin'))->toThrow(ExpectationFailedException::class, 'to be downloaded')
        ->and(fn () => $fake->assertDownloaded(static fn (): bool => false))->toThrow(ExpectationFailedException::class, 'matching the callback')
        ->and(fn () => $fake->assertNothingDownloaded())->toThrow(ExpectationFailedException::class, '1 URL(s) were: https://files.example/a.bin');
});
