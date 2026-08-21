<?php

declare(strict_types=1);

namespace App\Events\V2;

/**
 * B17 / D-21b — Mandatory trigger: an integration account transitioned to
 * inactive (expired credentials, manual disconnect, OAuth revocation). Emitted
 * by B11's `IntegrationAccount::markDisconnected()` and similar lifecycle
 * hooks.
 *
 * Listeners decide what to do (default: email all admin users; v1 does not
 * ship a UI listener, only the typed event contract).
 */
final class IntegrationAccountDisconnected
{
    public function __construct(
        public readonly int $accountId,
        public readonly ?string $reason = null,
    ) {
    }
}
