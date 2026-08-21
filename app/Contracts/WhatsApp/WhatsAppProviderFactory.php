<?php

declare(strict_types=1);

namespace App\Contracts\WhatsApp;

use App\Models\WhatsApp\WhatsAppAccount;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use InvalidArgumentException;

/**
 * B14 Pasada B-1 — Selects the concrete {@see WhatsAppProvider}
 * implementation for a given {@see WhatsAppAccount}.
 *
 * Selection rules (decision 12a — Meta Cloud API direct; decision 12b —
 * contract swap-ready so a future BSP drops in without changing call sites):
 *
 *   - `meta` → MetaWhatsAppProvider (default; only supported in v1)
 *   - any other value → {@see InvalidArgumentException}
 *
 * Anything else raises {@see InvalidArgumentException}. Per-account overrides
 * of the implementation class (e.g. an operator chooses a custom BSP) are
 * deferred to a future release.
 */
class WhatsAppProviderFactory
{
    /**
     * @param  WhatsAppAccount  $account
     */
    public function for(WhatsAppAccount $account): WhatsAppProvider
    {
        return $this->make('meta', $account);
    }

    /**
     * @param  string  $provider  Currently only `meta` is supported.
     */
    public function make(string $provider, ?WhatsAppAccount $account = null): WhatsAppProvider
    {
        return match ($provider) {
            'meta' => new MetaWhatsAppProvider(
                $account ?? new WhatsAppAccount(['phone_number' => 'unspecified']),
            ),
            default => throw new InvalidArgumentException(
                sprintf('Unknown WhatsApp provider "%s".', $provider),
            ),
        };
    }
}