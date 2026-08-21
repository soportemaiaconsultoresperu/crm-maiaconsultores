<?php

declare(strict_types=1);

namespace App\Exceptions\Integrations;

use RuntimeException;

/**
 * Thrown by {@see \App\Integrations\Services\AdapterFactory::make()} when a
 * caller asks for a provider whose feature flag in
 * `config('integrations.enabled.<channel>')` is false.
 *
 * Distinct from a missing integration_account row: this exception means
 * the channel is OFF for the whole installation, not "no credentials
 * configured for this user".
 */
class ProviderDisabledException extends RuntimeException
{
    public function __construct(string $channel, string $provider)
    {
        parent::__construct(
            sprintf(
                'Integration channel "%s" (provider "%s") is disabled by configuration.',
                $channel,
                $provider,
            ),
        );
    }
}