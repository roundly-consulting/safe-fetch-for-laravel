<?php

declare(strict_types=1);

namespace RoundlyConsulting\SafeFetch\Tests\Support;

use RoundlyConsulting\SafeFetch\DataTransferObjects\DestinationPolicy;

/**
 * "The default guard" the contract fixture's URL vectors are written for: http and https, any
 * port, no lists, the app-host block off.
 */
final class ContractPolicy
{
    public static function make(): DestinationPolicy
    {
        return new DestinationPolicy(schemes: ['http', 'https'], blockAppHosts: false);
    }
}
