<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B14 Pasada B-1 — Dispatched job that fires the outbound send through the
 * configured {@see \App\Contracts\WhatsApp\WhatsAppProvider}.
 *
 * Decision D-12a requires Meta Cloud API direct; the job uses
 *   tries=3
 *   backoff=[30, 120, 600]
 * mirroring the B13 + B12 engine pattern (docs/v2/01-roadmap.md §10.1).
 *
 * Idempotency: a row whose `status` is already `sent`/`delivered`/`read`
 * short-circuits so retries never double-send.
 */
class SendWhatsAppMessage implements ShouldQueue
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

    public function handle(WhatsAppProviderFactory $factory): void
    {
        /** @var WhatsAppMessage|null $message */
        $message = WhatsAppMessage::query()
            ->with(['template', 'conversation', 'conversation.account'])
            ->find($this->messageId);

        if ($message === null) {
            return;
        }

        if (in_array($message->status, [
            WhatsAppMessage::STATUS_SENT,
            WhatsAppMessage::STATUS_DELIVERED,
            WhatsAppMessage::STATUS_READ,
        ], true)) {
            // Idempotent short-circuit.
            return;
        }

        $conversation = $message->conversation;
        $account = $conversation?->account;

        if ($account === null) {
            $this->markFailed($message, 'NoBoundAccount', 'WhatsAppMessage has no WhatsAppAccount.');

            return;
        }

        if ($message->template === null) {
            $this->markFailed($message, 'NoTemplate', 'WhatsAppMessage has no template.');

            return;
        }

        if (! $this->isAccountUsable($account)) {
            $this->markFailed($message, 'AccountDisabled', 'WhatsAppAccount is disabled.');

            return;
        }

        $provider = $factory->for($account);
        $result = $provider->sendTemplateMessage(
            $message,
            $message->template,
            (string) $conversation->phone_number,
            [],
        );

        DB::transaction(function () use ($message, $result): void {
            if (($result['ok'] ?? false) === true) {
                $message->forceFill([
                    'status' => WhatsAppMessage::STATUS_SENT,
                    'wamid' => $result['wamid'] ?? $message->wamid,
                    'sent_at' => $message->sent_at ?? now(),
                    'error_class' => null,
                    'error_message' => null,
                ])->save();
            } else {
                $message->forceFill([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'error_class' => (string) ($result['error_class'] ?? 'UnknownError'),
                    'error_message' => (string) ($result['error_message'] ?? 'Unknown'),
                ])->save();
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }
        $this->markFailed($message, $exception::class, $exception->getMessage());
        Log::warning('SendWhatsAppMessage: exhausted retries', [
            'message_id' => $message->id,
            'error_class' => $exception::class,
        ]);
    }

    private function markFailed(WhatsAppMessage $message, string $class, string $messageText): void
    {
        $message->forceFill([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'error_class' => $class,
            'error_message' => $messageText,
        ])->save();
    }

    private function isAccountUsable(\App\Models\WhatsApp\WhatsAppAccount $account): bool
    {
        return $account->status !== \App\Models\WhatsApp\WhatsAppAccount::STATUS_DISABLED;
    }
}