<?php

declare(strict_types=1);

namespace App\Events\V2;

use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * B14 Pasada B-1 — Inbound WhatsApp message received.
 *
 * Dispatched by {@see \App\Services\WhatsApp\WhatsAppService::handleInbound()}
 * after the inbound row is persisted. B14.x wires the listeners (lead
 * creation, assignment, conversation tagging); Pasada B-1 ships the event
 * class only so the public surface is stable.
 *
 * Spec: docs/v2/01-roadmap.md §2.4 (schema) + §7 decisions 13a-e (lead
 * creation rules).
 */
class WhatsAppInboundEvent
{
    use Dispatchable;

    public function __construct(
        public readonly WhatsAppMessage $message,
    ) {
    }
}