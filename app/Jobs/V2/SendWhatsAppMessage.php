<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
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
 *
 * E-3: an inbox reply is created as `freeform` with no `template_id`, and the
 * job used to fail every one of them with `NoTemplate` without ever calling
 * the provider. Template-less messages now go through the provider's free-form
 * path and the provider's envelope decides the terminal state: `sent` + wamid
 * on acceptance, `failed` + the provider's reason otherwise. There is no path
 * that reports `sent` for a message the provider did not accept.
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

        if (app(\App\Services\DemoData\DemoDataGuard::class)->isWhatsAppMessageDemo($message)) {
            $this->markFailed($message, 'DemoDataGuardBlocked', 'Demo data guard blocked outbound WhatsApp job.');
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

        if (! $this->isAccountUsable($account)) {
            $this->markFailed($message, 'AccountDisabled', 'WhatsAppAccount is disabled.');

            return;
        }

        $provider = $factory->for($account);

        if ($message->template === null) {
            $blocked = $this->freeFormBlockedReason($conversation);

            if ($blocked !== null) {
                $this->markFailed($message, $blocked[0], $blocked[1]);

                return;
            }

            $result = $provider->sendFreeFormMessage($message, (string) $conversation->phone_number);
        } else {
            $result = $provider->sendTemplateMessage(
                $message,
                $message->template,
                (string) $conversation->phone_number,
                [],
            );
        }

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

    /**
     * Preconditions for a free-form (template-less) send.
     *
     * Meta only accepts free-form text inside the customer-service window and
     * never after an opt-out. Two of those are checkable here:
     *
     *  - opt-out: a hard block (the UI blocks it at creation too, but a message
     *    queued before the opt-out would otherwise still go out);
     *  - `window_closes_at`: blocked only when the window is explicitly tracked
     *    AND elapsed. It is tracked via the conversation columns the B14 schema
     *    reserved for it, which nothing populates yet — so today this guard is
     *    inert and the provider remains the authority. It is deliberately NOT
     *    derived from message history: a Meta window can also be opened by
     *    interactions we do not persist (e.g. click-to-WhatsApp), and blocking
     *    a send Meta would accept is itself a defect. When the window is
     *    unknown we attempt the send and record the provider's answer verbatim.
     *
     * @return array{0: string, 1: string}|null [error_class, error_message]
     */
    private function freeFormBlockedReason(WhatsAppConversation $conversation): ?array
    {
        if ($conversation->opt_out_at !== null) {
            return [
                'ConversationOptedOut',
                'WhatsAppConversation opted out at '.$conversation->opt_out_at->toDateTimeString().'; free-form messages are not permitted.',
            ];
        }

        $closesAt = $conversation->window_closes_at;
        if ($closesAt !== null && $closesAt->isPast()) {
            return [
                'FreeFormWindowClosed',
                'The 24-hour customer-service window closed at '.$closesAt->toDateTimeString().'; send an approved template instead.',
            ];
        }

        return null;
    }

    private function isAccountUsable(WhatsAppAccount $account): bool
    {
        return $account->status !== WhatsAppAccount::STATUS_DISABLED;
    }
}