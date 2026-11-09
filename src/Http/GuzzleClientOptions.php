<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Http;

use Closure;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use RoundlyConsulting\SafeFetch\SafeFetchManager;

/**
 * The outermost layer of the guarded Guzzle stack ({@see SafeFetchManager::handlerStack()}).
 * A Guzzle client a library builds itself sends Guzzle's defaults — redirects on, a cookie jar of
 * its own — which the guard would refuse or which would sit outside it. This turns them into what
 * the guard runs on, the way {@see GuardedClient} does for `Http::safe()`:
 *
 * - redirects off: the guard follows them under the policy, vetting every hop (a library that
 *   expected Guzzle to follow them gets the redirect back unless the policy follows it);
 * - the client's cookie jar handed to the guard, which applies it per hop.
 *
 * Everything else — a proxy, raw curl options, connection sharing, a `sink` under a body cap — is
 * the guard's to refuse, as on `Http::safe()`.
 *
 * @internal
 */
final readonly class GuzzleClientOptions
{
    /**
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $jar = $options['cookies'] ?? null;

            if ($jar instanceof CookieJarInterface) {
                $options[GuardMiddleware::COOKIES] = $jar;
            }

            // Laravel's own bookkeeping inside the stack reads the key; the jar now travels with the guard.
            $options['cookies'] = false;
            $options['allow_redirects'] = false;

            return $handler($request, $options);
        };
    }
}
