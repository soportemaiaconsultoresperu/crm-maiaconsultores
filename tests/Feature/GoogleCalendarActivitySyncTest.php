<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleCalendarActivity;
use App\Models\Activity;
use App\Models\ActivityCalendarLink;
use App\Models\ActivityType;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\GoogleCalendarActivitySyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarActivitySyncTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private ActivityService $activities;

    private GoogleCalendarActivitySyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'America/Lima', 'app.url' => 'https://crm.test']);

        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'asesor@example.com']);
        $this->activities = app(ActivityService::class);
        $this->sync = app(GoogleCalendarActivitySyncService::class);
    }

    public function test_create_activity_queues_and_syncs_google_calendar_event(): void
    {
        Queue::fake();
        $this->googleCalendarAccount();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'google-event-1', 'etag' => 'etag-1'], 201),
        ]);

        $activity = $this->activities->create($this->validActivityData([
            'title' => 'Discovery call',
            'description' => 'CRM activity description',
        ]), $this->actor);

        Queue::assertPushed(SyncGoogleCalendarActivity::class, fn (SyncGoogleCalendarActivity $job): bool => $job->activityId === $activity->id);

        $link = $this->sync->syncActivity($activity->id);

        $this->assertInstanceOf(ActivityCalendarLink::class, $link);
        $this->assertSame('google-event-1', $link->fresh()->external_event_id);
        $this->assertSame(ActivityCalendarLink::STATUS_SYNCED, $link->fresh()->sync_status);
        $this->assertDatabaseHas('activity_calendar_links', [
            'activity_id' => $activity->id,
            'integration_account_id' => $this->googleAccount()->id,
            'external_calendar_id' => 'primary',
            'external_event_id' => 'google-event-1',
        ]);

        Http::assertSent(function ($request) use ($activity): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $payload['description'] === "CRM activity description\n\nCRM activity: https://crm.test/activities/{$activity->id}"
                && $payload['extendedProperties']['private']['crm_activity_id'] === (string) $activity->id
                && $payload['extendedProperties']['private']['crm_activity_url'] === 'https://crm.test/activities/'.$activity->id;
        });
    }

    public function test_google_payload_preserves_wall_clock_datetime_in_resolved_timezone(): void
    {
        config(['app.timezone' => 'UTC', 'company.timezone' => 'America/Lima']);
        $this->googleCalendarAccount();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'google-event-1'], 201),
        ]);
        Queue::fake();
        $activity = $this->activities->create($this->validActivityData([
            'scheduled_at' => '2099-01-15 10:00:00',
        ]), $this->actor);

        $this->sync->syncActivity($activity->id);

        $recorded = Http::recorded();
        $payload = json_decode($recorded[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('2099-01-15T10:00:00-05:00', $payload['start']['dateTime']);
        $this->assertSame('2099-01-15T11:00:00-05:00', $payload['end']['dateTime']);
        $this->assertSame('America/Lima', $payload['start']['timeZone']);
        $this->assertSame('CRM activity: https://crm.test/activities/'.$activity->id, $payload['description']);
    }

    public function test_calendar_not_connected_does_not_block_activity_creation(): void
    {
        Queue::fake();

        $activity = $this->activities->create($this->validActivityData(['title' => 'Local only activity']), $this->actor);

        $this->assertNotNull($activity->id);
        $this->assertSame('Local only activity', $activity->title);
        $this->assertSame(0, ActivityCalendarLink::query()->count());
        Queue::assertNotPushed(SyncGoogleCalendarActivity::class);
    }

    public function test_update_reschedule_updates_same_event_without_duplicate_create(): void
    {
        $this->googleCalendarAccount();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'google-event-1', 'etag' => 'etag-1'], 201),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-1' => Http::response(['id' => 'google-event-1', 'etag' => 'etag-2'], 200),
        ]);
        Queue::fake();
        $activity = $this->activities->create($this->validActivityData(['scheduled_at' => '2099-01-15 10:00:00']), $this->actor);
        $this->sync->syncActivity($activity->id);

        $this->activities->update($activity, ['scheduled_at' => '2099-01-16 11:30:00', 'title' => 'Rescheduled call'], $this->actor);
        $this->sync->syncActivity($activity->id);

        $this->assertSame(1, ActivityCalendarLink::query()->count());
        $this->assertSame('google-event-1', ActivityCalendarLink::query()->firstOrFail()->external_event_id);

        $methods = Http::recorded()->map(fn (array $record): string => $record[0]->method())->all();
        $this->assertSame(['POST', 'PATCH'], $methods);
    }

    public function test_missing_duration_uses_sixty_minutes(): void
    {
        $this->googleCalendarAccount();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'google-event-1'], 201),
        ]);
        Queue::fake();
        $activity = $this->activities->create($this->validActivityData(['scheduled_at' => '2099-01-15 10:00:00']), $this->actor);

        $this->sync->syncActivity($activity->id);

        $recorded = Http::recorded();
        $payload = json_decode($recorded[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);
        $startsAt = Carbon::parse($payload['start']['dateTime']);
        $endsAt = Carbon::parse($payload['end']['dateTime']);

        $this->assertEquals(60, $startsAt->diffInMinutes($endsAt));
        $this->assertSame('America/Lima', $payload['start']['timeZone']);
        $this->assertSame('America/Lima', $payload['end']['timeZone']);
    }

    public function test_cancel_and_soft_delete_mark_event_cancelled_without_deleting_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('admin');
        $account = $this->googleCalendarAccount();
        Queue::fake();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-1' => Http::response(['id' => 'google-event-1'], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-2' => Http::response(['id' => 'google-event-2'], 200),
        ]);
        $activity = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => '2099-01-15 10:00:00']);
        $this->linkedEvent($activity, $account, 'google-event-1');

        $this->activities->cancel($activity, $this->actor, 'Customer cancelled');
        $cancelledLink = $this->sync->syncActivity($activity->id)->fresh();

        $this->assertSame(ActivityCalendarLink::STATUS_CANCELLED, $cancelledLink->sync_status);

        $deletedActivity = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => '2099-01-16 10:00:00']);
        $this->linkedEvent($deletedActivity, $account, 'google-event-2');

        $this->actingAs($this->actor)
            ->post(route('activities.destroy', $deletedActivity), ['reason' => 'Duplicate'])
            ->assertRedirect(route('activities.index'));
        Queue::assertPushed(SyncGoogleCalendarActivity::class, fn (SyncGoogleCalendarActivity $job): bool => $job->activityId === $deletedActivity->id);
        $deletedLink = $this->sync->syncActivity($deletedActivity->id)->fresh();

        $this->assertSoftDeleted('activities', ['id' => $deletedActivity->id]);
        $this->assertSame(ActivityCalendarLink::STATUS_CANCELLED, $deletedLink->sync_status);

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'PATCH'
                && ($payload['status'] ?? null) === 'cancelled'
                && str_starts_with($payload['summary'], '[Cancelled]');
        });
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    public function test_google_404_on_linked_update_marks_external_event_missing(): void
    {
        $account = $this->googleCalendarAccount();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-missing' => Http::response(['error' => ['message' => 'Not found']], 404),
        ]);
        $activity = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => '2099-01-15 10:00:00']);
        $link = $this->linkedEvent($activity, $account, 'google-missing');

        $this->sync->syncActivity($activity->id);

        $this->assertSame(ActivityCalendarLink::STATUS_EXTERNAL_EVENT_MISSING, $link->fresh()->sync_status);
        Http::assertSentCount(1);
    }

    public function test_temporary_failure_leaves_activity_saved_and_link_temporary_error(): void
    {
        $this->googleCalendarAccount();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $activity = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => '2099-01-15 10:00:00']);

        $link = $this->sync->syncActivity($activity->id);

        $this->assertNotNull($activity->fresh());
        $this->assertSame(ActivityCalendarLink::STATUS_TEMPORARY_ERROR, $link->fresh()->sync_status);
        $this->assertSame('ConnectionException', $link->fresh()->error_class);
    }

    public function test_initial_sync_counts_only_future_pending_own_unlinked_activities_and_queueing_is_explicit(): void
    {
        Queue::fake();
        $account = $this->googleCalendarAccount();
        $mineFuture = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => now()->addDay(), 'status' => 'pending']);
        Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => now()->subDay(), 'status' => 'pending']);
        Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->completed()
            ->create(['scheduled_at' => now()->addDay()]);
        Activity::factory()
            ->forLead(Lead::factory()->forOwner(User::factory()->create(['is_active' => true]))->create())
            ->forOwner(User::factory()->create(['is_active' => true]))
            ->create(['scheduled_at' => now()->addDay(), 'status' => 'pending']);
        $linked = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => now()->addDays(2), 'status' => 'pending']);
        $this->linkedEvent($linked, $account, 'already-linked');

        $this->assertSame(1, $this->sync->countInitialSyncCandidates($this->actor));
        Queue::assertNothingPushed();

        $queued = $this->sync->queueInitialSyncCandidates($this->actor);

        $this->assertSame(1, $queued);
        Queue::assertPushed(SyncGoogleCalendarActivity::class, fn (SyncGoogleCalendarActivity $job): bool => $job->activityId === $mineFuture->id);
    }

    public function test_initial_sync_confirmation_ui_requires_explicit_post_before_queueing(): void
    {
        Queue::fake();
        $this->googleCalendarAccount();
        $mineFuture = Activity::factory()
            ->forLead(Lead::factory()->forOwner($this->actor)->create())
            ->forOwner($this->actor)
            ->create(['scheduled_at' => now()->addDay(), 'status' => 'pending']);

        $this->actingAs($this->actor)
            ->get(route('account.integrations.index'))
            ->assertOk()
            ->assertSee('Se crearán 1 eventos futuros en Google Calendar')
            ->assertSee('Sincronizar 1 actividades');
        Queue::assertNothingPushed();

        $this->actingAs($this->actor)
            ->post(route('account.integrations.google.calendar.initial-sync'))
            ->assertRedirect(route('account.integrations.index'))
            ->assertSessionHas('status', 'Se encolaron 1 actividades futuras para sincronizar con Google Calendar.');

        Queue::assertPushed(SyncGoogleCalendarActivity::class, fn (SyncGoogleCalendarActivity $job): bool => $job->activityId === $mineFuture->id);
    }

    /** @param array<string, mixed> $overrides */
    private function validActivityData(array $overrides = []): array
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        return array_merge([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => ActivityType::query()->firstOrCreate(
                ['slug' => 'llamada'],
                ['name' => 'Llamada', 'sort' => 1, 'is_active' => true],
            )->id,
            'title' => 'Follow-up call',
            'scheduled_at' => '2099-01-15 10:00:00',
            'priority' => 'media',
        ], $overrides);
    }

    private function googleCalendarAccount(): IntegrationAccount
    {
        return $this->googleAccount() ?? IntegrationAccount::query()->create([
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

    private function googleAccount(): ?IntegrationAccount
    {
        return IntegrationAccount::query()
            ->where('provider', 'google')
            ->where('owner_id', $this->actor->id)
            ->first();
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
}
