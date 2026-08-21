<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\Email\EmailProvider;
use App\Mail\GenericEmail;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * B13 Pasada B — SMTP outbound provider (D-24 — default transport).
 *
 * Sends through Laravel's `Mail` facade with the configured transport from
 * `config('mail.default')` and `config('mail.mailers.smtp')`. Inbound is a
 * no-op (D-10c — SMTP is send-only). Webhook signature verification is a
 * no-op too (no signature model for outbound SMTP webhooks).
 *
 * The constructor accepts the bound `IntegrationAccount` row so future
 * outbound-account overrides (per-user SMTP credentials, custom from
 * address) can be applied. Today the SMTP transport reads the global
 * `mail.mailers.smtp.*` config; the account row is informational.
 */
class SmtpProvider implements EmailProvider
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
        try {
            /** @var \App\Mail\GenericEmail $mailable */
            $mailable = new GenericEmail($message);
            $recipients = $message->participants
                ->where('kind', EmailParticipant::KIND_TO)
                ->pluck('email')
                ->all();

            if ($recipients === []) {
                $recipients = array_filter([$message->from_email]);
            }

            if ($recipients === []) {
                return [
                    'ok' => false,
                    'error_class' => \InvalidArgumentException::class,
                    'error_message' => 'No recipients resolved for SMTP send.',
                ];
            }

            // Use the Mailable-based Mail::send() form so Mail::fake()
            // assertSentCount can pick the deliverable up cleanly. The
            // SMTP transport stays ergonomic for production while tests
            // get a stable seam.
            Mail::to($recipients)->send($mailable);

            $providerMessageId = (string) ($message->provider_message_id ?? 'smtp-'.$message->id);

            return [
                'ok' => true,
                'provider_message_id' => $providerMessageId,
            ];
        } catch (\Throwable $e) {
            Log::warning('SmtpProvider::send failed', [
                'message_id' => $message->id,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * SMTP is send-only — return empty list (decision D-10c).
     *
     * @param  string|null  $since
     * @return list<EmailMessage>
     */
    public function fetchInbound(?string $since = null): array
    {
        return [];
    }

    /**
     * Outbound SMTP has no inbound webhooks — return `true` so the
     * (future) endpoint would let SMTP origins through. No-op today.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }
}
