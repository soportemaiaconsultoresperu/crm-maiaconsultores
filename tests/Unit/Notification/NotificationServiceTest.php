<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Jobs\V2\SendOutboundDelivery;
use App\Models\Notification\NotificationPreference;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * B17 Pasada B — NotificationService unit tests.
 *
 * Covers the canonical high-level pipeline:
 *  - dispatch() persists an OutboundDelivery row in DB::transaction and queues
 *    the SendOutboundDelivery job;
 *  - dispatch() is idempotent on (channel, recipient, related_entity, payload,
 *    bucket) → second call returns the existing row without re-dispatching;
 *  - isEnabled() is default-true when no preference row exists and honours
 *    enabled=false rows when they do;
 *  - markFailed() increments attempts and persists the error envelope.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_persists_row_and_dispatches_job(): void
    {
        Bus::fake();

        $service = app(NotificationService::class);

        $delivery = $service->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => ['subject' => 'Integration failed', 'body' => 'Details'],
            'bucket' => 'D-21a',
        ]);

        $this->assertNotNull($delivery->id);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame(0, (int) $delivery->attempts);
        $this->assertSame(OutboundDelivery::CHANNEL_MAIL, $delivery->channel);
        $this->assertSame('admin@example.com', $delivery->recipient_ref);
        $this->assertSame('IntegrationAccount', $delivery->related_entity_type);
        $this->assertSame(42, (int) $delivery->related_entity_id);
        $this->assertNotEmpty($delivery->idempotency_key);

        $this->assertDatabaseHas('outbound_deliveries', [
            'id' => $delivery->id,
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'status' => OutboundDelivery::STATUS_QUEUED,
        ]);

        Bus::assertDispatched(SendOutboundDelivery::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_dispatch_idempotency_returns_existing_row(): void
    {
        Bus::fake();

        $service = app(NotificationService::class);

        $first = $service->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => ['subject' => 'Integration failed', 'body' => 'Details'],
            'bucket' => 'D-21a',
        ]);

        $second = $service->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => ['subject' => 'Integration failed', 'body' => 'Details'],
            'bucket' => 'D-21a',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OutboundDelivery::query()->count());

        Bus::assertDispatchedTimes(SendOutboundDelivery::class, 1);
    }

    public function test_is_enabled_returns_true_when_no_preference_row_exists(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $service = app(NotificationService::class);

        $this->assertTrue($service->isEnabled($user, 'IntegrationAccount', 'mail'));
    }

    public function test_is_enabled_returns_row_value_when_preference_exists(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        NotificationPreference::create([
            'user_id' => $user->id,
            'subject_type' => 'IntegrationAccount',
            'channel' => 'mail',
            'enabled' => false,
            'scope' => NotificationPreference::SCOPE_ADMINISTRATIVE,
        ]);

        $service = app(NotificationService::class);

        $this->assertFalse($service->isEnabled($user, 'IntegrationAccount', 'mail'));
    }

    public function test_mark_failed_increments_attempts(): void
    {
        Bus::fake();

        $service = app(NotificationService::class);

        $delivery = $service->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => ['subject' => 'Integration failed', 'body' => 'Details'],
            'bucket' => 'D-21a',
        ]);

        $service->markFailed($delivery->id, 'SmtpError', 'connect timed out');
        $delivery->refresh();
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('SmtpError', $delivery->last_error);

        $service->markFailed($delivery->id, 'SmtpError', 'connect timed out again');
        $delivery->refresh();
        $this->assertSame(2, (int) $delivery->attempts);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
    }

    public function test_mark_failed_finalises_status_when_attempts_exceed_max(): void
    {
        Bus::fake();

        $service = app(NotificationService::class);

        $delivery = $service->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => ['subject' => 'Integration failed', 'body' => 'Details'],
            'bucket' => 'D-21a',
        ]);

        $service->markFailed($delivery->id, 'SmtpError', 'first');
        $service->markFailed($delivery->id, 'SmtpError', 'second');
        $service->markFailed($delivery->id, 'SmtpError', 'third');
        $service->markFailed($delivery->id, 'SmtpError', 'fourth');

        $delivery->refresh();
        $this->assertSame(4, (int) $delivery->attempts);
        $this->assertSame(OutboundDelivery::STATUS_FAILED, $delivery->status);
    }
}
