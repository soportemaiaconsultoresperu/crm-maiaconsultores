<?php

namespace Tests\Feature;

use App\Jobs\ReconcileGoogleCalendarChannel;
use App\Models\Activity;
use App\Models\ActivityCalendarLink;
use App\Models\GoogleCalendarChannel;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\User;
use App\Services\GoogleCalendarReconciliationService;
use App\Services\GoogleCalendarWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://crm.test',
            'app.timezone' => 'America/Lima',
            'integrations.google_calendar_watch.webhook_url' => 'https://crm.test/webhooks/google/calendar',
            'integrations.google_calendar_watch.ttl_minutes' => 120,
        ]);

        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'asesor@example.com']);
    }

    public function test_watch_registration_stores_channel_and_calls_google_watch_endpoint(): void
    {
        $account = $this->googleCalendarAccount();
        $expiration = (string) (now()->addMinutes(120)->getTimestamp() * 1000);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/watch' => Http::response([
                'id' => 'google-channel-1',
                'resourceId' => 'resource-1',
                'resourceUri' => 'https://googleapis.test/calendar/v3/calendars/primary/events',
                'expiration' => $expiration,
            ], 200),
        ]);

        $channel = app(GoogleCalendarWatchService::class)->startForAccount($account);

        $this->assertSame(GoogleCalendarChannel::STATUS_ACTIVE, $channel->status);
        $this->assertSame('resource-1', $channel->resource_id);
        $this->assertNotNull($channel->channel_token_hash);
        $this->assertSame(64, strlen((string) $channel->channel_token_hash));
        $this->assertDatabaseHas('google_calendar_channels', [
            'integration_account_id' => $account->id,
            'provider' => 'google',
            'external_calendar_id' => 'primary',
            'channel_id' => 'google-channel-1',
            'resource_id' => 'resource-1',
            'status' => GoogleCalendarChannel::STATUS_ACTIVE,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && (string) $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/watch'
                && $payload['type'] === 'web_hook'
                && $payload['address'] === 'https://crm.test/webhooks/google/calendar'
                && isset($payload['id'], $payload['token'], $payload['expiration']);
        });
    }

    public function test_valid_webhook_validates_headers_dedupes_and_queues_reconciliation(): void
    {
        Queue::fake();
        $channel = $this->channel(token: 'secret-token');

        $headers = [
            'X-Goog-Channel-ID' => $channel->channel_id,
            'X-Goog-Resource-ID' => $channel->resource_id,
            'X-Goog-Message-Number' => '10',
            'X-Goog-Channel-Token' => 'secret-token',
        ];

        $this->postJson(route('webhooks.google-calendar'), [], $headers)
            ->assertAccepted();

        $channel->refresh();
        $this->assertSame('10', $channel->last_message_number);
        $this->assertNotNull($channel->last_received_at);
        Queue::assertPushed(ReconcileGoogleCalendarChannel::class, fn (ReconcileGoogleCalendarChannel $job): bool => $job->channelId === $channel->id);

        $this->postJson(route('webhooks.google-calendar'), [], $headers)
            ->assertNoContent();

        Queue::assertPushed(ReconcileGoogleCalendarChannel::class, 1);
    }

    public function test_invalid_channel_resource_or_token_are_rejected(): void
    {
        Queue::fake();
        $channel = $this->channel(token: 'secret-token');

        $this->postJson(route('webhooks.google-calendar'), [], [
            'X-Goog-Channel-ID' => 'missing-channel',
            'X-Goog-Resource-ID' => $channel->resource_id,
            'X-Goog-Message-Number' => '11',
            'X-Goog-Channel-Token' => 'secret-token',
        ])->assertNotFound();

        $this->postJson(route('webhooks.google-calendar'), [], [
            'X-Goog-Channel-ID' => $channel->channel_id,
            'X-Goog-Resource-ID' => 'wrong-resource',
            'X-Goog-Message-Number' => '11',
            'X-Goog-Channel-Token' => 'secret-token',
        ])->assertForbidden();

        $this->postJson(route('webhooks.google-calendar'), [], [
            'X-Goog-Channel-ID' => $channel->channel_id,
            'X-Goog-Resource-ID' => $channel->resource_id,
            'X-Goog-Message-Number' => '11',
            'X-Goog-Channel-Token' => 'wrong-token',
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_reconciliation_ignores_non_crm_metadata_and_does_not_create_activity(): void
    {
        $account = $this->googleCalendarAccount();
        $activity = $this->activity();
        $this->linkedEvent($activity, $account, 'google-event-ignored');
        $activityCount = Activity::query()->count();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-ignored' => Http::response([
                'id' => 'google-event-ignored',
                'extendedProperties' => ['private' => [
                    'crm_instance_id' => 'https://other-crm.test',
                    'crm_activity_id' => (string) $activity->id,
                ]],
            ], 200),
        ]);

        $result = app(GoogleCalendarReconciliationService::class)->reconcile($this->channel(account: $account));

        $this->assertSame(['checked' => 1, 'ignored' => 1, 'overwritten' => 0, 'missing' => 0], $result);
        $this->assertSame($activityCount, Activity::query()->count());
        $this->assertSame(ActivityCalendarLink::STATUS_SYNCED, ActivityCalendarLink::query()->firstOrFail()->sync_status);
        Http::assertSentCount(1);
    }

    public function test_remote_edit_for_crm_origin_link_is_overwritten_by_crm_projection(): void
    {
        $account = $this->googleCalendarAccount();
        $activity = $this->activity(['title' => 'CRM title wins', 'description' => 'CRM description wins']);
        $this->linkedEvent($activity, $account, 'google-event-1');
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-1' => Http::sequence()
                ->push([
                    'id' => 'google-event-1',
                    'summary' => 'Remote edited title',
                    'extendedProperties' => ['private' => [
                        'crm_instance_id' => 'https://crm.test',
                        'crm_activity_id' => (string) $activity->id,
                    ]],
                ], 200)
                ->push(['id' => 'google-event-1', 'etag' => 'etag-updated'], 200),
        ]);

        $result = app(GoogleCalendarReconciliationService::class)->reconcile($this->channel(account: $account));

        $this->assertSame(['checked' => 1, 'ignored' => 0, 'overwritten' => 1, 'missing' => 0], $result);
        $this->assertSame(ActivityCalendarLink::STATUS_SYNCED, ActivityCalendarLink::query()->firstOrFail()->sync_status);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $payload['summary'] === 'CRM title wins'
                && $payload['description'] === 'CRM description wins'
                && $payload['extendedProperties']['private']['crm_instance_id'] === 'https://crm.test';
        });
    }

    public function test_remote_deletion_marks_link_external_event_missing(): void
    {
        $account = $this->googleCalendarAccount();
        $activity = $this->activity();
        $link = $this->linkedEvent($activity, $account, 'google-missing');
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-missing' => Http::response(['error' => ['message' => 'Not found']], 404),
        ]);

        $result = app(GoogleCalendarReconciliationService::class)->reconcile($this->channel(account: $account));

        $this->assertSame(['checked' => 1, 'ignored' => 0, 'overwritten' => 0, 'missing' => 1], $result);
        $this->assertSame(ActivityCalendarLink::STATUS_EXTERNAL_EVENT_MISSING, $link->fresh()->sync_status);
    }

    public function test_watch_service_stop_calls_google_and_marks_channel_stopped(): void
    {
        $account = $this->googleCalendarAccount();
        $channel = $this->channel(account: $account);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/channels/stop' => Http::response([], 200),
        ]);

        $stopped = app(GoogleCalendarWatchService::class)->stopActiveForAccount($account);

        $this->assertSame(1, $stopped);
        $this->assertSame(GoogleCalendarChannel::STATUS_STOPPED, $channel->fresh()->status);
        Http::assertSent(function ($request) use ($channel): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && (string) $request->url() === 'https://www.googleapis.com/calendar/v3/channels/stop'
                && $payload['id'] === $channel->channel_id
                && $payload['resourceId'] === $channel->resource_id;
        });
    }

    private function googleCalendarAccount(): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Google Workspace — asesor@example.com',
            'owner_id' => $this->actor->id,
            'is_active' => true,
            'test_mode' => false,
            'config_json' => [
                'google_account_email' => 'asesor@example.com',
                'services' => ['gmail' => false, 'calendar' => true],
                'calendar' => ['default_calendar_id' => 'primary'],
                'status' => 'connected',
            ],
            'credentials_encrypted' => [
                'access_token' => 'calendar-token',
                'refresh_token' => 'refresh-token',
                'token_type' => 'Bearer',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.events'],
            'expires_at' => now()->addHour(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function activity(array $overrides = []): Activity
    {
        return Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(array_merge([
                'title' => 'Follow-up from CRM',
                'description' => 'CRM description',
                'scheduled_at' => '2099-01-15 10:00:00',
                'status' => 'pending',
            ], $overrides));
    }

    private function linkedEvent(Activity $activity, IntegrationAccount $account, string $eventId): ActivityCalendarLink
    {
        return ActivityCalendarLink::query()->create([
            'activity_id' => $activity->id,
            'integration_account_id' => $account->id,
            'provider' => 'google',
            'external_calendar_id' => 'primary',
            'external_event_id' => $eventId,
            'sync_hash' => 'old-hash',
            'sync_status' => ActivityCalendarLink::STATUS_SYNCED,
            'last_synced_at' => now(),
        ]);
    }

    private function channel(?IntegrationAccount $account = null, string $token = 'secret-token'): GoogleCalendarChannel
    {
        $account ??= $this->googleCalendarAccount();

        return GoogleCalendarChannel::query()->create([
            'integration_account_id' => $account->id,
            'provider' => 'google',
            'external_calendar_id' => 'primary',
            'channel_id' => 'channel-'.bin2hex(random_bytes(4)),
            'resource_id' => 'resource-'.bin2hex(random_bytes(4)),
            'resource_uri' => 'https://googleapis.test/calendar/v3/calendars/primary/events',
            'channel_token_hash' => GoogleCalendarChannel::tokenHash($token),
            'status' => GoogleCalendarChannel::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
        ]);
    }
}
