<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Dto\ProviderMessageRef;

/**
 * Contract every WhatsApp provider must implement.
 *
 * B11 ships the contract only. The Meta Cloud API implementation lives in
 * {@see App\Integrations\Adapters\MetaWhatsAppAdapter} (B14).
 *
 * `verifyWebhook()` MUST be constant-time (use `hash_equals`) and MUST NOT
 * mutate the body. The middleware calls it before any handler touches
 * the payload.
 */
interface WhatsAppProvider
{
    /**
     * Send a pre-approved template message. `vars` keys must match the
     * template's variable list (B14 will mirror `whatsapp_templates.variables_json`).
     *
     * @param  array<string, string|int>  $vars
     */
    public function sendTemplate(string $phone, string $templateName, string $language, array $vars): ProviderMessageRef;

    /**
     * Send a freeform text message — only allowed inside the 24-hour
     * customer-service window. Adapters MUST raise if invoked outside
     * the window with no template context.
     */
    public function sendFreeform(string $phone, string $body): ProviderMessageRef;

    /**
     * Fetch the list of templates currently approved for the account.
     * Returned array must contain at minimum the fields:
     *   name, language, status, category, body, variables
     *
     * @return list<array<string, mixed>>
     */
    public function fetchTemplates(): array;

    /**
     * Verify the HMAC signature of an inbound webhook payload.
     *
     * @param  string  $signature  raw header value (e.g. "sha256=...")
     * @param  string  $body  raw request body (bytes-as-string)
     * @param  int|null  $timestamp  Unix timestamp from the X-Hub-Timestamp
     *                              header when present, otherwise null
     */
    public function verifyWebhook(string $signature, string $body, ?int $timestamp): bool;

    /**
     * Subscribe the adapter's webhook endpoint to a list of event types
     * (messages, message_template_status_update, etc.). Idempotent.
     *
     * @param  list<string>  $events
     */
    public function subscribeWebhook(string $url, array $events): void;
}