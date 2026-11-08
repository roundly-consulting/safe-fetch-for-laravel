<?php

declare(strict_types=1);

use RoundlyConsulting\SafeFetch\SafeFetchManager;
use RoundlyConsulting\SafeFetch\Tests\Arch\SourceTokens;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/*
 * The arch presets this package's shape qualifies for. Not called, because the package has no
 * models, model traits, factories or migrations — each would fail its own vacuity check by design:
 * modelsGoThroughTheFacade, modelsResolveThroughSeam, factoriesResolveThroughSeam,
 * morphColumnsUseTheSeam, swappableModelsAreNotFinal. The intent of the first is pinned by the
 * bespoke "goes through the manager" rule below instead.
 */
ArchPresets::strictTypes('RoundlyConsulting\SafeFetch');
// The manager is the one deliberate non-final class: SafeFetchFake extends it.
ArchPresets::finalByDefault('RoundlyConsulting\SafeFetch', [SafeFetchManager::class]);
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\SafeFetch');
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');
ArchPresets::noDebuggingLeftovers();

/*
 * Bespoke bans — each proved red by a throwaway mutation (recorded in the plan's §18).
 */

it('the rule, the middleware, the command and the handles go through the manager, never an action', function (): void {
    $actions = array_map(static fn (string $file): string => basename($file, '.php'), glob(__DIR__.'/../../src/Actions/*.php') ?: []);
    $callers = array_filter(
        safeFetchSources(),
        static fn (string $path): bool => (bool) preg_match('#^(Rules|Http|Commands|Handles)/#', $path),
        ARRAY_FILTER_USE_KEY,
    );
    $offenders = [];

    foreach ($actions as $action) {
        $offenders += SourceTokens::uses($callers, $action);
    }

    expect($actions)->toHaveCount(4)
        ->and(array_keys($callers))->toContain('Rules/SafeUrl.php', 'Http/GuardMiddleware.php', 'Commands/CheckCommand.php', 'Handles/Urls.php')
        ->and($offenders)->toBe([]);
});

/** @return array<string, string> */
function safeFetchSources(): array
{
    return SourceTokens::files(__DIR__.'/../../src');
}

it('looks host names up in the system resolver only', function (string $function): void {
    expect(array_keys(SourceTokens::uses(safeFetchSources(), $function)))
        ->toBeIn([[], ['Support/SystemHostResolver.php']]);
})->with(['gethostbynamel', 'gethostbyname', 'dns_get_record']);

it('names no curl option guzzle rejects or that re-routes a connection', function (string $constant): void {
    expect(SourceTokens::uses(safeFetchSources(), $constant))->toBe([]);
})->with([
    'CURLOPT_PROTOCOLS', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS_STR',
    'CURLOPT_PROXY', 'CURLOPT_NOPROXY', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_MAXREDIRS', 'CURLOPT_CONNECT_TO',
    'CURLOPT_IPRESOLVE', 'CURLOPT_UNIX_SOCKET_PATH',
]);

it('keeps the pre-send check in its own class', function (): void {
    expect(array_keys(SourceTokens::uses(safeFetchSources(), 'CURLOPT_PREREQFUNCTION')))
        ->toBe(['Support/PreSendCheck.php']);
});

it('keeps curl credentials in the curl option list', function (string $constant): void {
    expect(array_keys(SourceTokens::uses(safeFetchSources(), $constant)))->toBe(['Support/CurlOptions.php']);
})->with(['CURLOPT_HTTPAUTH', 'CURLOPT_USERPWD']);

it('parses urls itself where it vets them', function (): void {
    $sources = array_intersect_key(safeFetchSources(), array_flip(['Support/UrlCanonicaliser.php', 'Support/DestinationGuard.php']));

    expect($sources)->toHaveCount(2)
        ->and(SourceTokens::uses($sources, 'parse_url'))->toBe([]);
});

it('never sends through a stream handler or fetches a url outside the client', function (): void {
    $sources = safeFetchSources();

    expect(SourceTokens::uses($sources, 'StreamHandler'))->toBe([])
        ->and(SourceTokens::uses($sources, 'curl_init'))->toBe([])
        ->and(SourceTokens::uses($sources, 'curl_exec'))->toBe([])
        ->and(SourceTokens::callsWithLiteral($sources, 'file_get_contents', 'http'))->toBe([])
        ->and(SourceTokens::callsWithLiteral($sources, 'fopen', 'http'))->toBe([])
        ->and(SourceTokens::callsWithLiteral($sources, 'fopen', 'php://temp'))->toBe(['Support/CappedSink.php']);
});
