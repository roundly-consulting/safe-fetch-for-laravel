<?php

// A raw HTTP server for the suite (see Tests\Support\RawHttpServer): every connection gets the
// same bytes back, verbatim.

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $errorCode, $errorMessage);
$answer = (string) base64_decode($argv[2], true);

if ($server === false) {
    exit(1);
}

while (true) {
    $client = @stream_socket_accept($server, 60);

    if ($client === false) {
        continue;
    }

    $head = '';

    while (! str_contains($head, "\r\n\r\n") && ($chunk = fread($client, 8192)) !== false && $chunk !== '') {
        $head .= $chunk;
    }

    fwrite($client, $answer);
    fclose($client);
}
