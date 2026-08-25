<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Jobs\V2\SendOutboundDelivery;
use App\Models\Notification\NotificationPreference;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\DemoData\DemoDataGuard;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * B17 Pasada B — High-level notification pipeline.
 *
 * `dispatch()`:
 *   - computes an idempotency key from (channel, recipient_ref, related_entity, payload, bucket);
 *   - if a row with that key already exists, returns the existing row (idempotency);
 *   - otherwise persists an OutboundDelivery row in a single transaction with
 *     status='queued' and attempts=0, and dispatches the async SendOutboundDelivery job.
 *
 * `isEnabled()`:
 *   - returns true when no NotificationPreference row exists (default opt-in);
 *   - returns the row's `enabled` value when a row exists.
 *
 * `markFailed()`:
 *   - increments attempts, sets last_error, last_response_code;
 *   - while attempts <= MAX_ATTEMPTS the row stays 'queued' (retryable);
 *   - once attempts > MAX_ATTEMPTS the row flips to 'failed' (terminal).
 *
 * Wrapped in `DB::transaction` so partial writes never leave the schema half-built.
 */
class NotificationService
{
    public function dispatch(array $attributes): OutboundDelivery
    {
        $idempotencyKey = $this->computeIdempotencyKey($attributes);

        $existing = OutboundDelivery::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $isDemo = isset($attributes['related_entity_type'], $attributes['related_entity_id'])
            && app(DemoDataGuard::class)->isDemo((string) $attributes['related_entity_type'], (int) $attributes['related_entity_id']);

        $delivery = DB::transaction(function () use ($attributes, $idempotencyKey, $isDemo): OutboundDelivery {
            $delivery = new OutboundDelivery();
            $delivery->fill([
                'channel' => $attributes['channel'],
                'recipient_ref' => (string) $attributes['recipient_ref'],
                'template_id' => $attributes['template_id'] ?? null,
                'related_entity_type' => $attributes['related_entity_type'] ?? null,
                'related_entity_id' => $attributes['related_entity_id'] ?? null,
                'account_id' => $attributes['account_id'] ?? null,
                'status' => $isDemo ? OutboundDelivery::STATUS_SKIPPED : OutboundDelivery::STATUS_QUEUED,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_error' => $isDemo ? 'skipped: demo data guard blocked outbound dispatch' : null,
                'last_response_code' => null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $delivery->save();

            return $delivery;
        });

        if (! $isDemo) {
            SendOutboundDelivery::dispatch($delivery->id);
        }

        return $delivery;
    }

    public function isEnabled(?User $user, string $subjectType, string $channel): bool
    {
        // Global channel gate: when the admin disables a channel globally
        // (e.g. notifications.mail.enabled = 0) we short-circuit before the
        // per-user preference lookup so no delivery is ever queued for it.
        // The `database` channel is always on (the in-app bell is core).
        if ($channel !== 'database' && ! $this->globalChannelEnabled($channel)) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        $row = NotificationPreference::query()
            ->forUser($user->id)
            ->forSubject($subjectType)
            ->forChannel($channel)
            ->first();

        if ($row === null) {
            return true;
        }

        return (bool) $row->enabled;
    }

    /**
     * Reads the global toggle for the given channel from the settings table.
     * Default true keeps the historical opt-in behaviour when the setting row
     * is missing (defensive — older databases may not have these rows yet).
     */
    private function globalChannelEnabled(string $channel): bool
    {
        $settingKey = match ($channel) {
            'mail' => 'notifications.mail.enabled',
            'whatsapp' => 'notifications.whatsapp.enabled',
            default => null,
        };

        if ($settingKey === null) {
            return true;
        }

        return (bool) app(SettingsService::class)->get($settingKey, true);
    }

    public function markSending(int $deliveryId): void
    {
        $delivery = OutboundDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => OutboundDelivery::STATUS_SENDING,
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();
    }

    public function markSent(int $deliveryId, ?int $responseCode = null): void
    {
        $delivery = OutboundDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => OutboundDelivery::STATUS_DELIVERED,
            'last_response_code' => $responseCode,
        ])->save();
    }

    public function markFailed(
        int $deliveryId,
        string $errorClass,
        string $errorMessage,
        ?int $responseCode = null,
        ?Carbon $nextAttemptAt = null,
    ): void {
        $delivery = OutboundDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            return;
        }

        $newAttempts = (int) $delivery->attempts + 1;
        $isFinal = $newAttempts > OutboundDelivery::MAX_ATTEMPTS;

        $delivery->forceFill([
            'attempts' => $newAttempts,
            'last_error' => $errorClass,
            'last_response_code' => $responseCode,
            'status' => $isFinal ? OutboundDelivery::STATUS_FAILED : OutboundDelivery::STATUS_QUEUED,
            'next_attempt_at' => $isFinal ? null : ($nextAttemptAt ?? now()->addSeconds(60 * (2 ** ($newAttempts - 1)))),
        ])->save();
    }

    public function markSkipped(int $deliveryId, string $reason): void
    {
        $delivery = OutboundDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => OutboundDelivery::STATUS_SKIPPED,
            'last_error' => 'skipped: '.$reason,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function computeIdempotencyKey(array $attributes): string
    {
        $parts = [
            (string) ($attributes['channel'] ?? ''),
            (string) ($attributes['recipient_ref'] ?? ''),
            (string) ($attributes['related_entity_type'] ?? ''),
            (string) ($attributes['related_entity_id'] ?? ''),
            (string) ($attributes['bucket'] ?? ''),
        ];

        $payload = $attributes['payload'] ?? [];
        if (is_array($payload)) {
            $parts[] = json_encode($payload, JSON_THROW_ON_ERROR);
        } else {
            $parts[] = (string) $payload;
        }

        return sha1(implode('|', $parts));
    }
}
