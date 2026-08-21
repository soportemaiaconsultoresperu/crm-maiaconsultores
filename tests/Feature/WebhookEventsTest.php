<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WebhookEvents — model factory + UNIQUE (provider, external_event_id)
 * constraint.
 *
 * The 200-OK smoke test lives in WebhookSignatureTest; here we exercise
 * the persistence layer directly with the UNIQUE dedupe guarantee.
 */
class WebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_a_row_with_status_received(): void
    {
        $event = WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'evt-001',
            'payload_hash' => str_repeat('a', 64),
            'signature' => 'sha256=abc',
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'provider' => 'meta',
            'external_event_id' => 'evt-001',
            'status' => 'received',
        ]);
    }

    public function test_double_post_with_same_external_event_id_fails_on_unique(): void
    {
        WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'evt-dup',
            'payload_hash' => str_repeat('b', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $this->expectException(QueryException::class);

        WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'evt-dup',
            'payload_hash' => str_repeat('c', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);
    }

    public function test_unprocessed_scope_returns_received_only(): void
    {
        WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'r-1',
            'payload_hash' => str_repeat('d', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);
        WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'p-1',
            'payload_hash' => str_repeat('e', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_PROCESSED,
        ]);

        $rows = WebhookEvent::query()->unprocessed()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('r-1', $rows->first()->external_event_id);
    }

    public function test_for_provider_scope_filters_by_provider(): void
    {
        WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'm-1',
            'payload_hash' => str_repeat('f', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);
        WebhookEvent::create([
            'provider' => 'google',
            'external_event_id' => 'g-1',
            'payload_hash' => str_repeat('0', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $meta = WebhookEvent::query()->forProvider('meta')->get();
        $google = WebhookEvent::query()->forProvider('google')->get();

        $this->assertCount(1, $meta);
        $this->assertCount(1, $google);
        $this->assertSame('m-1', $meta->first()->external_event_id);
        $this->assertSame('g-1', $google->first()->external_event_id);
    }

    public function test_mark_processed_updates_status_and_timestamp(): void
    {
        $event = WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'mp-1',
            'payload_hash' => str_repeat('1', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $event->markProcessed();
        $event->refresh();

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNotNull($event->processed_at);
    }

    public function test_mark_rejected_records_error_class(): void
    {
        $event = WebhookEvent::create([
            'provider' => 'meta',
            'external_event_id' => 'rj-1',
            'payload_hash' => str_repeat('2', 64),
            'received_at' => now(),
            'status' => WebhookEvent::STATUS_RECEIVED,
        ]);

        $event->markRejected('App\\Exceptions\\InvalidSignature', 'bad hmac');
        $event->refresh();

        $this->assertSame(WebhookEvent::STATUS_REJECTED, $event->status);
        $this->assertSame('App\\Exceptions\\InvalidSignature', $event->error_class);
        $this->assertSame('bad hmac', $event->error_message);
    }
}