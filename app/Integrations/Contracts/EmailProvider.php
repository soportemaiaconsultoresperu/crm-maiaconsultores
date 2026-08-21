<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Dto\EmailSendResult;

/**
 * Contract every email channel adapter must implement.
 *
 * Concrete adapters (added in B13):
 *   - App\Integrations\Adapters\SmtpAdapter       (MAIL_MAILER=smtp)
 *   - App\Integrations\Adapters\GmailAdapter      (Gmail API)
 *   - App\Integrations\Adapters\OutlookAdapter    (Microsoft Graph)
 *
 * Adapters are resolved through AdapterFactory keyed by the literal value
 * stored in integration_accounts.provider.
 */
interface EmailProvider
{
    /**
     * Send a single email message. Must be safe to retry; the caller supplies
     * an `idempotency_key` in $message so duplicate deliveries can be
     * deduped by the provider or by the outbound_deliveries table.
     *
     * @param  array{
     *     idempotency_key: string,
     *     from_email: string,
     *     from_name?: string|null,
     *     to: list<array{email: string, name?: string|null}>,
     *     cc?: list<array{email: string, name?: string|null}>,
     *     bcc?: list<array{email: string, name?: string|null}>,
     *     subject: string,
     *     body_html?: string|null,
     *     body_text?: string|null,
     *     reply_to?: string|null,
     *     attachments?: list<array{filename: string, mime: string, content: string}>,
     * }  $message
     */
    public function send(array $message): EmailSendResult;

    /**
     * Persist OAuth tokens / SMTP credentials into the bound integration
     * account row. Called by the OAuth callback handler (B13).
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): void;

    /**
     * Force a token refresh; returns silently on success and signals
     * a hard failure by throwing.
     */
    public function refresh(): void;

    /**
     * Revoke OAuth tokens (or no-op for SMTP) and clear the
     * credentials_encrypted column.
     */
    public function disconnect(): void;

    /**
     * Cheap health check used by the scheduler and by the admin panel.
     * Should NOT issue an outbound HTTP call.
     */
    public function isHealthy(): bool;
}