<?php

declare(strict_types=1);

namespace App\Contracts\WhatsApp;

use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use Illuminate\Http\Request;

/**
 * B14 Pasada B-1 — WhatsApp provider contract for the OUTBOUND send path
 * + INBOUND webhook verification + template catalogue sync.
 *
 * One concrete adapter implements this contract:
 *   - {@see \App\Services\WhatsApp\MetaWhatsAppProvider} — Meta WhatsApp
 *     Cloud API (decision 12a).
 *
 * In v1 the adapter is wired through {@see WhatsAppProviderFactory} which
 * returns Meta today and may return a BSP adapter (Twilio, MessageBird, ...)
 * in a future release without changing call sites (decision 12b — contract
 * swap-ready).
 *
 * This contract intentionally lives under `App\Contracts\WhatsApp` (B14
 * layer) and is separate from `App\Integrations\Contracts\WhatsAppProvider`
 * which is the B11 OAuth-credential-management layer.
 *
 * Spec   : docs/v2/01-roadmap.md §2.4 (schema), §7 decisions 12-15.
 */
interface WhatsAppProvider
{
    /**
     * Send a pre-approved template message to a phone number.
     *
     * MUST return one of the following envelopes:
     *  - Success: `['ok' => true, 'wamid' => '...']`
     *  - Failure: `['ok' => false, 'error_class' => ..., 'error_message' => ...]`
     *
     * `wamid` is the canonical Meta WhatsApp message id assigned by the
     * Cloud API and persisted in `whatsapp_messages.wamid`.
     *
     * @param  array<string, string|int>  $vars  Variable substitution values.
     * @return array{ok: bool, wamid?: string, error_class?: string, error_message?: string}
     */
    public function sendTemplateMessage(
        WhatsAppMessage $message,
        WhatsAppTemplate $template,
        string $phoneNumber,
        array $vars,
    ): array;

    /**
     * Send a freeform text message — only allowed inside the 24-hour
     * customer-service window. Adapters MUST return the canonical error
     * envelope when invoked outside the window with no template context.
     *
     * @return array{ok: bool, wamid?: string, error_class?: string, error_message?: string}
     */
    public function sendFreeFormMessage(WhatsAppMessage $message, string $phoneNumber): array;

    /**
     * Verify a webhook request's signature.
     *
     * Meta signs deliveries with HMAC-SHA256 over the raw body using the
     * App Secret / per-account webhook secret as the key. The header value
     * is `sha256=<hex>`.
     *
     * Implementations return `false` when the webhook secret is not
     * configured — refusing every inbound webhook is the safe default for
     * v1 (per docs/v2/01-roadmap.md §7 decision 14 + B14 Pasada B-1).
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Fetch the templates currently approved for the account from Meta.
     *
     * Returns a list of normalized payload arrays; the caller
     * ({@see \App\Services\WhatsApp\WhatsAppService::syncTemplates})
     * upserts into the local `whatsapp_templates` table.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchTemplates(WhatsAppAccount $account): array;
}