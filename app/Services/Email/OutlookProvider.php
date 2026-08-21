<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\Email\EmailProvider;
use App\Models\Email\EmailMessage;
use App\Models\IntegrationAccount;
use App\Services\Email\Exceptions\NotImplementedException;
use Illuminate\Http\Request;

/**
 * B13 Pasada B — Outlook / MS Graph provider stub.
 *
 * Real implementation lands in B13 Pasada C when the Azure AD app credentials
 * are issued (A7 pending). Until then every method other than
 * `verifyWebhookSignature` returns the documented
 * `NotImplementedException` envelope so the rest of the application can
 * rely on it uniformly.
 *
 * Inbound (decision 10b): real implementation would call
 * `https://graph.microsoft.com/v1.0/me/messages` with the OAuth token.
 * Today this returns `[]`.
 *
 * Webhook verification: HMAC-SHA256 over the raw body, keyed by
 * `INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET`, compared to the
 * `X-Office-Signature` header value.
 */
class OutlookProvider implements EmailProvider
{
    public function __construct(
        public readonly ?IntegrationAccount $account = null,
    ) {
    }

    /**
     * @return array{ok: bool, provider_message_id?: string, error_class?: string, error_message?: string}
     */
    public function send(EmailMessage $message): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfiguredEnvelope();
        }

        // Real path would call:
        //   POST https://graph.microsoft.com/v1.0/me/sendMail
        // with `Authorization: Bearer <oauth_token>` and a JSON envelope
        // { message: {...}, saveToSentItems: true }. Deferred to B13 Pasada C.

        return $this->notConfiguredEnvelope();
    }

    /**
     * @param  string|null  $since
     * @return list<EmailMessage>
     */
    public function fetchInbound(?string $since = null): array
    {
        return [];
    }

    /**
     * Compare the X-Office-Signature header against HMAC-SHA256(raw_body,
     * INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET). Constant-time compare.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) (env('INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET') ?? '');

        if ($secret === '') {
            return false;
        }

        $provided = (string) $request->header('X-Office-Signature', '');
        if ($provided === '') {
            return false;
        }

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    private function isConfigured(): bool
    {
        $tenantId = (string) (env('INTEGRATIONS_OUTLOOK_TENANT_ID') ?? '');

        return $tenantId !== '';
    }

    /**
     * @return array{ok: false, error_class: string, error_message: string}
     */
    private function notConfiguredEnvelope(): array
    {
        return [
            'ok' => false,
            'error_class' => NotImplementedException::class,
            'error_message' => 'Outlook OAuth credentials not configured (A7 pending). Configure INTEGRATIONS_OUTLOOK_* to enable.',
        ];
    }
}
