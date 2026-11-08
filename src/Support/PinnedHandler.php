<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Support;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RoundlyConsulting\SafeFetch\DataTransferObjects\VettedDestination;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\SafeFetch\Events\DestinationRefused;
use RoundlyConsulting\SafeFetch\Exceptions\DestinationRejected;
use RoundlyConsulting\SafeFetch\Http\GuardMiddleware;

/**
 * The handler a guarded request is sent through: curl, always (a stream handler would ignore the
 * pin), owned by the guard rather than the host, and the innermost code before the wire — so it
 * sees what middleware added after `safe()` and every `beforeSending()` callback did to the
 * request. Before handing a request to curl it checks that the request still goes to the vetted
 * scheme, host and port, that the pin is intact, no proxy is set and redirects are off; anything
 * else is refused with `cannot_pin` and nothing is dialled.
 *
 * Under `Http::fake()` the stub answers first and this never runs.
 *
 * @internal
 */
final class PinnedHandler
{
    /** @var callable(RequestInterface, array<string, mixed>): PromiseInterface|null */
    private $curl = null;

    public function __construct(
        private readonly PinnedHandlers $handlers,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $hop = $options['safe_fetch.hop'] ?? null;

        if (! $hop instanceof VettedDestination || ! self::intact($request, $options, $hop)) {
            $exception = DestinationRejected::because(
                Reason::CannotPin,
                (string) $request->getUri(),
                $hop instanceof VettedDestination ? $hop->host : '',
            );

            report($exception);
            $this->events->dispatch(new DestinationRefused(Reason::CannotPin, $exception->host(), via: GuardMiddleware::via($options)));

            return Create::rejectionFor($exception);
        }

        if ($this->handlers->refusesToDial()) {
            return Create::rejectionFor(new LogicException(
                'A guarded request reached the network under SafeFetch::fake(); answer it with Http::fake(), or call allowRealRequests().',
            ));
        }

        $this->curl ??= Proxy::wrapSync(new CurlMultiHandler, new CurlHandler);

        if (is_resource($options['sink'] ?? null)) {
            $options['sink'] = self::keptOpen($options['sink']);
        }

        return ($this->curl)($request, $options);
    }

    /**
     * A sink resource as a stream that lets go of it rather than close it. Guzzle 7 closes the sink
     * of a transfer it aborted — every redirect the guard follows — so the caller's own resource
     * would reach the next hop closed. (Guzzle 8 wraps a resource sink this way itself; a stub of
     * `Http::fake()` answers before this and writes to the resource as given.)
     *
     * @param  resource  $resource
     */
    private static function keptOpen(mixed $resource): StreamInterface
    {
        $stream = Utils::streamFor($resource);

        return FnStream::decorate($stream, ['close' => static function () use ($stream): void {
            $stream->detach();
        }]);
    }

    /**
     * Whether the request is still the vetted one, pinned the way the guard pinned it.
     *
     * @param  array<string, mixed>  $options
     */
    private static function intact(RequestInterface $request, array $options, VettedDestination $hop): bool
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower(trim($uri->getHost(), '[]'));
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $sameHost = $hop->ipLiteral ? AddressRanges::same($host, $hop->host) && AddressRanges::bytes($host) !== null : $host === $hop->host;

        if ($scheme !== $hop->scheme || ! $sameHost || $port !== $hop->port) {
            return false;
        }

        if (($options['proxy'] ?? null) !== '' || ($options['allow_redirects'] ?? null) !== false || array_key_exists('transport_sharing', $options)) {
            return false;
        }

        $curl = $options['curl'] ?? null;

        if (! is_array($curl) || ($curl[CURLOPT_FRESH_CONNECT] ?? null) !== true || ($curl[CURLOPT_FORBID_REUSE] ?? null) !== true) {
            return false;
        }

        $entry = $hop->resolveEntry();

        if (($entry === null && array_key_exists(CURLOPT_RESOLVE, $curl)) || ($entry !== null && ($curl[CURLOPT_RESOLVE] ?? null) !== [$entry])) {
            return false;
        }

        $check = PreSendCheck::in($curl);

        if (CurlSupport::preSendCheck() && (! $check instanceof PreSendCheck || $check->destination !== $hop)) {
            return false;
        }

        foreach (array_keys($curl) as $option) {
            $pin = $option === CURLOPT_RESOLVE || $option === CURLOPT_FRESH_CONNECT || $option === CURLOPT_FORBID_REUSE || PreSendCheck::isOption($option);

            if (! $pin && ! CurlOptions::callerMay($option)) {
                return false;
            }
        }

        return true;
    }
}
