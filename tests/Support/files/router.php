<?php

// Router for the suite's local `php -S` server (see Tests\Support\LocalHttpServer). Logs every
// request it receives to SAFE_FETCH_SERVER_LOG.

declare(strict_types=1);

$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

file_put_contents(
    (string) getenv('SAFE_FETCH_SERVER_LOG'),
    $_SERVER['REQUEST_METHOD'].' '.$uri.' body='.$body.' host='.($_SERVER['HTTP_HOST'] ?? '').' auth='.($_SERVER['HTTP_AUTHORIZATION'] ?? '')."\n",
    FILE_APPEND | LOCK_EX,
);

if ($path === '/hello.txt' || $path === '/') {
    header('Content-Type: text/plain');
    header('Content-Length: 28');
    echo 'hello from the pinned server';

    return;
}

if ($path === '/echo') {
    header('Content-Type: text/plain');
    echo $_SERVER['REQUEST_METHOD'].' body='.$body;

    return;
}

if ($path === '/redirect') {
    header('Location: '.($_GET['to'] ?? '/hello.txt'), true, (int) ($_GET['status'] ?? 307));

    return;
}

if ($path === '/slow') {
    usleep(1_000_000);
    echo 'slow';

    return;
}

if ($path === '/big') {
    // 2 MiB, chunked (no Content-Length).
    header('Content-Type: application/octet-stream');
    $chunk = str_repeat('x', 65536);

    for ($i = 0; $i < 32; $i++) {
        echo $chunk;
        flush();
    }

    return;
}

if ($path === '/endless') {
    // No Content-Length: chunked, and it never ends on its own.
    header('Content-Type: application/octet-stream');
    $chunk = str_repeat('x', 8192);

    while (! connection_aborted()) {
        echo $chunk;
        flush();
    }

    return;
}

if ($path === '/gzip-bomb') {
    // 2 MiB of 'a', a few KiB on the wire: only a cap on DECODED bytes stops it.
    $encoded = (string) gzencode(str_repeat('a', 2 * 1048576), 9);
    header('Content-Type: text/plain');
    header('Content-Encoding: gzip');
    header('Content-Length: '.strlen($encoded));
    echo $encoded;

    return;
}

if ($path === '/unavailable') {
    header('Retry-After: 7');
    http_response_code(503);
    echo 'come back later';

    return;
}

http_response_code(404);
