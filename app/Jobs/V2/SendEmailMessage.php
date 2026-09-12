<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Email\EmailMessage;
use App\Models\Notification\OutboundDelivery;
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
    private const FAILURE_MESSAGE = 'No fue posible enviar el correo.';

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
            $this->markFailed($message, 'DemoDataGuardBlocked');
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
            $this->markFailed($message, 'NoBoundAccount');

            return;
        }

        if (! $account->is_active) {
            $this->markFailed($message, 'AccountInactive');

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
                'error_message' => self::FAILURE_MESSAGE,
            ])->save();
            $this->syncCourseDelivery($message->fresh());

            throw new RuntimeException(self::FAILURE_MESSAGE);
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
                    'error_message' => 'No se pudo confirmar el envío del correo.',
                ])->save();

                return;
            }

            $message->forceFill([
                'status' => EmailMessage::STATUS_FAILED,
                'error_class' => (string) ($result['error_class'] ?? 'UnknownError'),
                'error_message' => self::FAILURE_MESSAGE,
            ])->save();
        });

        $this->syncCourseDelivery($message->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        $message = EmailMessage::query()->find($this->messageId);
        if ($message === null || $message->status === EmailMessage::STATUS_SEND_UNCONFIRMED) {
            return;
        }
        $this->markFailed($message, $exception::class);
        Log::warning('SendEmailMessage: exhausted retries', [
            'message_id' => $message->id,
            'error_class' => $exception::class,
        ]);
    }

    private function markFailed(EmailMessage $message, string $class): void
    {
        $message->forceFill([
            'status' => EmailMessage::STATUS_FAILED,
            'error_class' => $class,
            'error_message' => self::FAILURE_MESSAGE,
        ])->save();
        $this->syncCourseDelivery($message->fresh());
    }

    /**
     * The only writer of the terminal ledger state and of the correlated course
     * document's delivery snapshot. Both course document types share this job:
     * the message's state maps to a ledger status — and, when terminal, to a
     * snapshot status — through one decision table, so the academic and
     * commercial channels cannot drift. Any other correlated entity (a quotation,
     * a plain notification) keeps the historical no-op behavior.
     */
    private function syncCourseDelivery(EmailMessage $message): void
    {
        $deliveries = OutboundDelivery::query()->where('email_message_id', $message->id)->get();
        if ($deliveries->count() !== 1) {
            return;
        }

        $delivery = $deliveries->first();
        $documentClass = $this->correlatedCourseDocumentClass($delivery->related_entity_type);
        if ($documentClass === null) {
            return;
        }

        [$status, $lastError, $snapshotStatus] = $this->courseDeliveryOutcome($message);

        DB::transaction(function () use ($delivery, $message, $documentClass, $status, $lastError, $snapshotStatus): void {
            $delivery->forceFill(['status' => $status, 'last_error' => $lastError])->save();

            if ($snapshotStatus === null) {
                // Unconfirmed: the ledger carries the unresolved attempt and the
                // document snapshot deliberately stays pending for follow-up.
                return;
            }

            $snapshot = ['delivery_status' => $snapshotStatus];
            if ($snapshotStatus === DeliveryStatus::Sent) {
                $snapshot['last_sent_at'] = $message->sent_at ?? now();
            }

            $documentClass::query()
                ->find($delivery->related_entity_id)
                ?->forceFill($snapshot)
                ->save();
        });
    }

    /**
     * The course document types whose delivery snapshot this shared job owns.
     * Every other correlated entity keeps the previous no-op behavior.
     */
    private function correlatedCourseDocumentClass(string $relatedEntityType): ?string
    {
        return match ($relatedEntityType) {
            CourseAcademicDocument::class, CourseCommercialDocument::class => $relatedEntityType,
            default => null,
        };
    }

    /**
     * One decision table for both course document types: the message's state
     * decides the ledger status, its sanitized error text and — for the two
     * terminal outcomes — the document snapshot status. A `null` snapshot status
     * means the snapshot is intentionally left untouched.
     *
     * @return array{0: string, 1: string|null, 2: DeliveryStatus|null}
     */
    private function courseDeliveryOutcome(EmailMessage $message): array
    {
        if ($message->status === EmailMessage::STATUS_SENT) {
            return [OutboundDelivery::STATUS_SENT, null, DeliveryStatus::Sent];
        }

        if ($message->status === EmailMessage::STATUS_FAILED) {
            return [OutboundDelivery::STATUS_FAILED, self::FAILURE_MESSAGE, DeliveryStatus::Failed];
        }

        return [OutboundDelivery::STATUS_QUEUED, 'No fue posible confirmar el envío del correo.', null];
    }
}
