<?php

// A "proxy" for the suite: it answers anything and logs it, so a test can prove nothing went through it.

declare(strict_types=1);

file_put_contents((string) getenv('SAFE_FETCH_SERVER_LOG'), 'PROXIED '.$_SERVER['REQUEST_URI']."\n", FILE_APPEND | LOCK_EX);

echo 'via-proxy';
