<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Models\Email\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B13 Pasada B — Dispatched job that fires the outbound send through the
 * configured {@see \App\Contracts\Email\EmailProvider}.
 *
 * D-24 requires ShouldQueue processing for outbound SMTP. The job uses
 *   tries=3
 *   backoff=[30, 120, 600]
 * mirroring the SendQueuedMail contract specified in
 * `docs/v2/01-roadmap.md` §10.1.
 *
 * Idempotency: the row's `provider_message_id` is captured on the first
 * successful send; retries find it already set and short-circuit (no
 * double-send).
 *
 * The actual provider is resolved through {@see \App\Contracts\Email\EmailProviderFactory}
 * inside the transaction so the unit-of-work stays self-contained.
 */
class SendEmailMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $messageId)
    {
    }

    public function handle(\App\Contracts\Email\EmailProviderFactory $factory): void
    {
        /** @var EmailMessage|null $message */
        $message = EmailMessage::query()->with(['account', 'participants'])->find($this->messageId);

        if ($message === null) {
            return;
        }

        if ($message->status === EmailMessage::STATUS_SENT
            || $message->status === EmailMessage::STATUS_DELIVERED) {
            // Idempotent short-circuit.
            return;
        }

        $account = $message->account;

        if ($account === null) {
            $this->markFailed($message, 'NoBoundAccount', 'EmailMessage has no IntegrationAccount.');

            return;
        }

        if (! $account->is_active) {
            $this->markFailed($message, 'AccountInactive', 'IntegrationAccount is inactive.');

            return;
        }

        $provider = $factory->for($account);
        $result = $provider->send($message);

        DB::transaction(function () use ($message, $result): void {
            if (($result['ok'] ?? false) === true) {
                $message->forceFill([
                    'status' => EmailMessage::STATUS_SENT,
                    'provider_message_id' => (string) ($result['provider_message_id'] ?? $message->provider_message_id),
                    'sent_at' => $message->sent_at ?? now(),
                    'error_class' => null,
                    'error_message' => null,
                ])->save();
            } else {
                $message->forceFill([
                    'status' => EmailMessage::STATUS_FAILED,
                    'error_class' => (string) ($result['error_class'] ?? 'UnknownError'),
                    'error_message' => (string) ($result['error_message'] ?? 'Unknown'),
                ])->save();
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        $message = EmailMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }
        $this->markFailed($message, $exception::class, $exception->getMessage());
        Log::warning('SendEmailMessage: exhausted retries', [
            'message_id' => $message->id,
            'error_class' => $exception::class,
        ]);
    }

    private function markFailed(EmailMessage $message, string $class, string $messageText): void
    {
        $message->forceFill([
            'status' => EmailMessage::STATUS_FAILED,
            'error_class' => $class,
            'error_message' => $messageText,
        ])->save();
    }
}
