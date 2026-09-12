<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * B17 Pasada B — Async delivery job for outbound notifications.
 *
 * Wraps the actual channel-specific send logic in a `ShouldQueue` boundary so
 * the high-level pipeline (`NotificationService::dispatch`) stays async and
 * idempotent. The job itself is small: it loads the `OutboundDelivery` row,
 * delegates to the channel-specific sender, and reports back to the service.
 *
 * For v1 the channel senders are:
 *   - `database`  → Laravel built-in `DatabaseNotification` (via auth()->user()->notify()).
 *   - `mail`      → `Mail::raw()` to the recipient_ref.
 *   - `whatsapp`  → `MetaWhatsAppProvider::sendFreeFormMessage` (B14 wire; stub-mode returns NotImplementedException).
 *   - `webhook`   → `Http::post()` to the recipient_ref URL.
 *
 * `tries=3`, `backoff=[60, 300, 900]` mirrors the B13 / B14 async pattern.
 * `NotificationService::markFailed` is invoked on any exception; once
 * `attempts > MAX_ATTEMPTS` (3) the row flips to `status='failed'` (terminal).
 */
class SendOutboundDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $deliveryId)
    {
    }

    public function handle(NotificationService $service): void
    {
        $delivery = \App\Models\Notification\OutboundDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

            if (! in_array($delivery->status, [\App\Models\Notification\OutboundDelivery::STATUS_QUEUED, \App\Models\Notification\OutboundDelivery::STATUS_SENDING], true)) {
            return;
        }

        if (app(\App\Services\DemoData\DemoDataGuard::class)->isOutboundDeliveryDemo($delivery)) {
            $service->markSkipped($this->deliveryId, 'demo data guard blocked outbound job');
            return;
        }

        $service->markSending($this->deliveryId);

        try {
            match ($delivery->channel) {
                \App\Models\Notification\OutboundDelivery::CHANNEL_DATABASE => $this->sendDatabase($delivery, $service),
                \App\Models\Notification\OutboundDelivery::CHANNEL_MAIL => $this->sendMail($delivery, $service),
                \App\Models\Notification\OutboundDelivery::CHANNEL_WHATSAPP => $this->sendWhatsApp($delivery, $service),
                \App\Models\Notification\OutboundDelivery::CHANNEL_WEBHOOK => $this->sendWebhook($delivery, $service),
                default => throw new \RuntimeException('Unsupported channel: '.$delivery->channel),
            };
            $service->markSent($this->deliveryId, 200);
        } catch (\Throwable $e) {
            // A library exception with a zero/absent code means "no HTTP
            // response was received" — record that as null instead of 0, so a
            // rejected send is never mistaken for a response-coded delivery.
            $responseCode = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;

            $service->markFailed(
                $this->deliveryId,
                $e::class,
                $e->getMessage(),
                $responseCode > 0 ? $responseCode : null,
            );
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = \App\Models\Notification\OutboundDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        // Force the final state and emit the B17 mandatory trigger.
        \App\Services\Notification\NotificationService::class; // type-hint noop
        $delivery->forceFill([
            'attempts' => max((int) $delivery->attempts, \App\Models\Notification\OutboundDelivery::MAX_ATTEMPTS),
            'last_error' => $exception::class.': '.$exception->getMessage(),
            'status' => \App\Models\Notification\OutboundDelivery::STATUS_FAILED,
        ])->save();

        event(new \App\Events\V2\NotificationFailedPermanently($delivery->id));
    }

    private function sendDatabase(\App\Models\Notification\OutboundDelivery $delivery, NotificationService $service): void
    {
        $recipient = $delivery->recipient_ref;
        $payload = $this->payload($delivery);

        $notifiable = new class($recipient) {
            use \Illuminate\Notifications\Notifiable;

            public function __construct(public string $target) {}

            /** @return list<string> */
            public function routeNotificationsFor(\Illuminate\Notifications\Notifiable $notifiable, ?string $channel = null): array
            {
                return [$this->target];
            }
        };

        $notifiable->notify(new \App\Notifications\GenericNotification($payload));
    }

    private function sendMail(\App\Models\Notification\OutboundDelivery $delivery, NotificationService $service): void
    {
        $payload = $this->payload($delivery);
        $subject = (string) ($payload['subject'] ?? 'CRM notification');
        $body = (string) ($payload['body'] ?? '');

        \Illuminate\Support\Facades\Mail::raw('CRM notification: '.$subject."\n\n".$body, function ($msg) use ($delivery, $subject) {
            $msg->to($delivery->recipient_ref)->subject($subject);
        });
    }

    private function sendWhatsApp(\App\Models\Notification\OutboundDelivery $delivery, NotificationService $service): void
    {
        $provider = app(\App\Contracts\WhatsApp\WhatsAppProviderFactory::class);
        $account = \App\Models\WhatsApp\WhatsAppAccount::query()->find($delivery->account_id);
        if ($account === null) {
            throw new \RuntimeException('No active WhatsApp account for delivery '.$delivery->id);
        }
        $instance = $provider->for($account);

        // Build a transient free-form message; do not persist (the existing
        // WhatsAppMessage lifecycle is owned by the conversations pipeline).
        $msg = new \App\Models\WhatsApp\WhatsAppMessage();
        $msg->direction = \App\Models\WhatsApp\WhatsAppMessage::DIRECTION_OUTBOUND;
        $msg->type = 'text';
        $msg->body = (string) ($this->payload($delivery)['body'] ?? '');
        $msg->provider_message_id = 'local-'.bin2hex(random_bytes(8));

        $result = $instance->sendFreeFormMessage($msg, $delivery->recipient_ref);

        // E-4: the provider envelope used to be discarded and handle() then
        // marked the row delivered unconditionally — a rejected send landed in
        // the ledger as a delivery. The rejection must surface so markFailed()
        // records the real outcome.
        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException(sprintf(
                'WhatsApp provider rejected delivery #%d: %s — %s',
                $delivery->id,
                (string) ($result['error_class'] ?? 'UnknownError'),
                (string) ($result['error_message'] ?? 'Unknown'),
            ));
        }
    }

    private function sendWebhook(\App\Models\Notification\OutboundDelivery $delivery, NotificationService $service): void
    {
        $response = \Illuminate\Support\Facades\Http::post($delivery->recipient_ref, $this->payload($delivery));
        if ($response->failed()) {
            throw new \RuntimeException('Webhook delivery failed: HTTP '.$response->status());
        }
    }

    /**
     * The content that actually goes out.
     *
     * E-4: this used to fabricate `Delivery #N (channel, status=...)`, so the
     * mail/WhatsApp recipient never saw what the listener built. The content is
     * now persisted with the row by {@see NotificationService::dispatch()} and
     * survives the by-id re-dispatch performed by retries and by the admin
     * "Reintentar" button.
     *
     * Legacy rows (created before the `payload` column) keep no content, so
     * they say so explicitly instead of shipping a body that looks real.
     *
     * @return array<string, mixed>
     */
    private function payload(\App\Models\Notification\OutboundDelivery $delivery): array
    {
        $stored = $delivery->payload;
        $stored = is_array($stored) ? $stored : [];

        $subject = isset($stored['subject']) && is_string($stored['subject'])
            ? $stored['subject']
            : 'CRM notification';

        $body = isset($stored['body']) && is_string($stored['body'])
            ? $stored['body']
            : 'Delivery #'.$delivery->id.' has no stored content (legacy ledger row).';

        return [
            'subject' => $subject,
            'body' => $body,
            'related_entity_type' => $delivery->related_entity_type,
            'related_entity_id' => $delivery->related_entity_id,
        ];
    }
}
