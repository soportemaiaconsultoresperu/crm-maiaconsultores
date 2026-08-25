<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Models\Email\EmailMessage;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendEmailMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $messageId)
    {
    }

    public function handle(\App\Contracts\Email\EmailProviderFactory $factory, QuotationService $quotations): void
    {
        /** @var EmailMessage|null $message */
        $message = EmailMessage::query()->with(['account', 'participants', 'attachments'])->find($this->messageId);

        if ($message === null) {
            return;
        }

        if (app(\App\Services\DemoData\DemoDataGuard::class)->isEmailMessageDemo($message)) {
            $this->markFailed($message, 'DemoDataGuardBlocked', 'Demo data guard blocked outbound email job.');
            return;
        }

        if (in_array($message->status, [EmailMessage::STATUS_SENT, EmailMessage::STATUS_DELIVERED], true)) {
            return;
        }

        if ($message->status === EmailMessage::STATUS_SEND_UNCONFIRMED) {
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

        $message->forceFill([
            'status' => EmailMessage::STATUS_PROCESSING,
            'error_class' => null,
            'error_message' => null,
        ])->save();

        $provider = $factory->for($account);
        $result = $provider->send($message);

        if (($result['retryable'] ?? false) === true && $this->attempts() < $this->tries) {
            $message->forceFill([
                'status' => EmailMessage::STATUS_PENDING,
                'error_class' => (string) ($result['error_class'] ?? 'RetryableEmailProviderError'),
                'error_message' => (string) ($result['error_message'] ?? 'Email provider returned a retryable error.'),
            ])->save();

            throw new RuntimeException($message->error_message ?? 'Email provider returned a retryable error.');
        }

        DB::transaction(function () use ($message, $result, $quotations): void {
            $message->refresh();

            if (($result['ok'] ?? false) === true) {
                $message->forceFill([
                    'status' => EmailMessage::STATUS_SENT,
                    'provider_message_id' => (string) ($result['provider_message_id'] ?? $message->provider_message_id),
                    'thread_id' => $result['thread_id'] ?? $message->thread_id,
                    'sent_at' => $message->sent_at ?? now(),
                    'error_class' => null,
                    'error_message' => null,
                ])->save();

                if ($message->related_quotation_id !== null) {
                    /** @var Quotation|null $quotation */
                    $quotation = Quotation::query()->find($message->related_quotation_id);
                    if ($quotation !== null && $message->creator !== null) {
                        $quotations->markAsSentFromGmail($quotation, $message->creator, $message);
                    }
                }

                return;
            }

            if (($result['indeterminate'] ?? false) === true) {
                $message->forceFill([
                    'status' => EmailMessage::STATUS_SEND_UNCONFIRMED,
                    'error_class' => (string) ($result['error_class'] ?? 'GmailSendUnconfirmed'),
                    'error_message' => (string) ($result['error_message'] ?? 'No se pudo confirmar si Gmail aceptó el mensaje.'),
                ])->save();

                return;
            }

            $message->forceFill([
                'status' => EmailMessage::STATUS_FAILED,
                'error_class' => (string) ($result['error_class'] ?? 'UnknownError'),
                'error_message' => (string) ($result['error_message'] ?? 'Unknown'),
            ])->save();
        });
    }

    public function failed(\Throwable $exception): void
    {
        $message = EmailMessage::query()->find($this->messageId);
        if ($message === null || $message->status === EmailMessage::STATUS_SEND_UNCONFIRMED) {
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
