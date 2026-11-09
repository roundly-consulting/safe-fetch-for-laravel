<?php

// CI gate: the security core must be covered line for line (plan OQ-8: 95 % overall, 100 % here).
//
//   php bin/core-coverage.php build/clover.xml

declare(strict_types=1);

$core = [
    'src/Support/PrivateNetworks.php',
    'src/Support/UrlCanonicaliser.php',
    'src/Support/DestinationGuard.php',
    'src/Support/RedirectLocation.php',
    'src/Support/HostPattern.php',
    'src/Support/AddressRanges.php',
    'src/Http/GuardMiddleware.php',
    'src/Http/GuardedClient.php',
    'src/Http/GuzzleClientOptions.php',
    'src/Actions/BuildHandlerStackAction.php',
    'src/Actions/CreateGuzzleClientAction.php',
    'src/Support/CappedTransfer.php',
];

$clover = simplexml_load_file($argv[1] ?? 'build/clover.xml');

if ($clover === false) {
    fwrite(STDERR, "No clover report.\n");
    exit(1);
}

$root = realpath(__DIR__.'/..').'/';
$failed = false;
$seen = [];

foreach ($clover->xpath('//file') ?: [] as $file) {
    $path = str_replace($root, '', (string) $file['name']);

    if (! in_array($path, $core, true)) {
        continue;
    }

    $seen[] = $path;
    $missed = [];

    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missed[] = (int) $line['num'];
        }
    }

    if ($missed !== []) {
        $failed = true;
        fwrite(STDERR, "{$path}: lines ".implode(', ', $missed)." not covered\n");
    } else {
        echo "{$path}: 100 %\n";
    }
}

foreach (array_diff($core, $seen) as $path) {
    $failed = true;
    fwrite(STDERR, "{$path}: not in the coverage report\n");
}

exit($failed ? 1 : 0);
