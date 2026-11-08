<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch;

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Validation\Validator;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\SafeFetch\Commands\CheckCommand;
use RoundlyConsulting\SafeFetch\Contracts\HostResolver;
use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;
use RoundlyConsulting\SafeFetch\Support\Clock;
use RoundlyConsulting\SafeFetch\Support\CurlSupport;
use RoundlyConsulting\SafeFetch\Support\PinnedHandlers;
use RoundlyConsulting\SafeFetch\Support\SafeFetchConfig;
use RoundlyConsulting\SafeFetch\Support\SystemHostResolver;

final class SafeFetchServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('safe-fetch')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommands([CheckCommand::class])
            // Counts only: the internal host names and ranges a host trusts never render.
            ->contributesToAbout(static function (): array {
                $policy = SafeFetchConfig::policy();
                $redirects = $policy->redirects;

                return [
                    'Schemes' => implode(', ', $policy->schemes),
                    'Ports' => $policy->ports === null ? 'any' : implode(', ', $policy->ports),
                    'Allowed hosts' => $policy->allowedHosts === [] ? 'any' : (string) count($policy->allowedHosts),
                    'Private hosts' => (string) count($policy->privateHosts),
                    'Allowed ranges' => (string) count($policy->allowedRanges),
                    'Blocked ranges' => (string) count($policy->blockedRanges),
                    'App hosts' => $policy->blockAppHosts ? 'blocked' : 'not blocked',
                    'Redirects' => $redirects->enabled ? implode(', ', $redirects->statuses).' · max '.$redirects->max : 'off',
                    'DNS pinning' => CurlSupport::available() ? 'curl '.CurlSupport::curlVersion() : 'unavailable',
                ];
            });
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(SafeFetchManager::class);
        $this->app->singleton(PinnedHandlers::class);
        $this->app->singleton(Clock::class);
        $this->bindFromConfig(HostResolver::class, 'safe-fetch.resolver', SystemHostResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        // One macro gives both Http::safe() and Http::withToken($t)->safe(): the factory forwards
        // unknown calls to a new PendingRequest.
        PendingRequest::macro('safe', function (?DestinationPolicy $policy = null): PendingRequest {
            /** @var PendingRequest $this */
            return Container::getInstance()->make(SafeFetchManager::class)->guard($this, $policy);
        });

        $this->callAfterResolving(ValidationFactory::class, static function (ValidationFactory $validation): void {
            $validation->extend(
                'safe_url',
                static fn (string $attribute, mixed $value): bool => is_string($value)
                    && Container::getInstance()->make(SafeFetchManager::class)->allows($value),
                'safe-fetch::validation.safe_url',
            );

            // Translated when the message is built, in the locale of that moment — unless the host
            // gave the rule a message of its own.
            $validation->replacer('safe_url', static fn (string $message, string $attribute, string $rule, array $parameters, Validator $validator): string => $message === 'safe-fetch::validation.safe_url'
                ? (string) __('safe-fetch::validation.safe_url', ['attribute' => $validator->getDisplayableAttribute($attribute)])
                : $message);
        });
    }
}
