<?php

declare(strict_types=1);

namespace App\Events\V2;

/**
 * B17 / D-21d — Mandatory trigger: a `OutboundDelivery` row hit
 * `MAX_ATTEMPTS` (3) and was finalised with `status='failed'`. Emitted by
 * `SendOutboundDelivery::failed()` in `App\Jobs\V2`.
 *
 * Listeners decide what to do (default: email all admin users; v1 does not
 * ship a UI listener, only the typed event contract).
 */
final class NotificationFailedPermanently
{
    public function __construct(
        public readonly int $deliveryId,
    ) {
    }
}
