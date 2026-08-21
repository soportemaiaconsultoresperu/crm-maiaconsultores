<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\Email\EmailProvider;
use App\Models\Email\EmailMessage;
use App\Models\IntegrationAccount;
use App\Services\Email\Exceptions\NotImplementedException;
use Illuminate\Http\Request;

/**
 * B13 Pasada B — Gmail provider stub.
 *
 * The real implementation lands in B13 Pasada C when
 * `INTEGRATIONS_GMAIL_CLIENT_ID` and related credentials are issued (A6
 * pending — D-23+ B13 alignment). Until then every method other than
 * `verifyWebhookSignature` returns the documented
 * `NotImplementedException` envelope so the rest of the application can
 * rely on it uniformly.
 *
 * Inbound (decision 10b): real implementation would call
 * `https://gmail.googleapis.com/gmail/v1/users/me/messages` with the OAuth
 * token. Today this returns `[]`.
 *
 * Webhook verification: HMAC-SHA256 over the raw body, keyed by
 * `INTEGRATIONS_GMAIL_WEBHOOK_SECRET`, compared to the `X-Goog-Signature`
 * header value.
 */
class GmailProvider implements EmailProvider
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
        //   POST https://gmail.googleapis.com/gmail/v1/users/me/messages/send
        // with `Authorization: Bearer <oauth_token>` and a base64url-encoded
        // raw RFC 2822 message body. Deferred to B13 Pasada C.

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
     * Compare the X-Goog-Signature header against HMAC-SHA256(raw_body,
     * INTEGRATIONS_GMAIL_WEBHOOK_SECRET). Constant-time compare.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) (env('INTEGRATIONS_GMAIL_WEBHOOK_SECRET') ?? '');

        if ($secret === '') {
            // No secret configured → fail closed (reject the webhook).
            return false;
        }

        $provided = (string) $request->header('X-Goog-Signature', '');
        if ($provided === '') {
            return false;
        }

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    private function isConfigured(): bool
    {
        $clientId = (string) (env('INTEGRATIONS_GMAIL_CLIENT_ID') ?? '');

        return $clientId !== '';
    }

    /**
     * @return array{ok: false, error_class: string, error_message: string}
     */
    private function notConfiguredEnvelope(): array
    {
        return [
            'ok' => false,
            'error_class' => NotImplementedException::class,
            'error_message' => 'Gmail OAuth credentials not configured (A6 pending). Configure INTEGRATIONS_GMAIL_* to enable.',
        ];
    }
}
