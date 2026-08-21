<?php

declare(strict_types=1);

namespace App\Contracts\Email;

use App\Models\Email\EmailMessage;
use Illuminate\Http\Request;

/**
 * B13 Pasada B — Email provider contract for the OUTBOUND send path.
 *
 * Three concrete adapters implement this contract:
 *   - {@see \App\Services\Email\SmtpProvider}      — Laravel Mail transport (D-24).
 *   - {@see \App\Services\Email\GmailProvider}     — stub for Google OAuth (A6 pending).
 *   - {@see \App\Services\Email\OutlookProvider}   — stub for MS Graph (A7 pending).
 *
 * The two stubs return a uniform error envelope `['ok' => false, 'error_class' => ..., 'error_message' => ...]`
 * when their respective OAuth credentials are not configured, so the rest of
 * the application can rely on the envelope uniformly.
 *
 * This contract intentionally lives under `App\Contracts\Email` (B13 layer)
 * and is separate from `App\Integrations\Contracts\EmailProvider` which is
 * the B11 OAuth-credential-management layer.
 *
 * Spec   : docs/v2/01-roadmap.md §2.3 (schema), §6 decisions 09-11, §10.1 D-24.
 * Design : openspec/changes/b12-ui/design.md §1 (architecture).
 */
interface EmailProvider
{
    /**
     * Send a single outbound email.
     *
     * MUST return one of the following envelopes:
     *  - Success: `['ok' => true, 'provider_message_id' => '...']`
     *  - Failure: `['ok' => false, 'error_class' => ..., 'error_message' => ...]`
     *
     * `provider_message_id` is the upstream-side identifier (SMTP message-id
     * header, Gmail API resource id, Outlook API message id) — persisted in
     * `email_messages.provider_message_id` for dedup / thread correlation.
     *
     * @return array{ok: bool, provider_message_id?: string, error_class?: string, error_message?: string}
     */
    public function send(EmailMessage $message): array;

    /**
     * Fetch inbound messages since `$since` (ISO-8601 timestamp).
     *
     * SMTP is send-only (decision D-10c) — the SMTP adapter returns `[]`.
     * Gmail + Outlook adapters in stub mode also return `[]`.
     *
     * Implementations return a list of DRAFT EmailMessage models (no DB
     * persistence). The caller (EmailWebhookController / EmailService) is
     * responsible for persisting them.
     *
     * @param  string|null  $since  ISO-8601 timestamp, or null for "all".
     * @return list<EmailMessage>
     */
    public function fetchInbound(?string $since = null): array;

    /**
     * Verify a webhook request's signature.
     *
     * SMPT adapter returns `true` (no signature model for outbound SMTP).
     * Gmail adapter uses HMAC-SHA256 over the raw body, keyed by
     * `INTEGRATIONS_GMAIL_WEBHOOK_SECRET` and compared to the
     * `X-Goog-Signature` header.
     * Outlook adapter uses HMAC-SHA256 with the `X-Office-Signature`
     * header and `INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET`.
     */
    public function verifyWebhookSignature(Request $request): bool;
}
