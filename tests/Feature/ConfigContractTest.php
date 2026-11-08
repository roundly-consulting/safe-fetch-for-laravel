<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

it('ships every setting it reads', function (): void {
    expect(__DIR__.'/../../config/safe-fetch.php')->toSatisfyConfigContract(__DIR__.'/../../src');
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/safe-fetch.php')->not->toBeFile();
});
