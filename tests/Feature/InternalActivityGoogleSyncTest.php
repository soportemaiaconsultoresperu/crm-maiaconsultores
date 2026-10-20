<?php

namespace Tests\Feature;

use App\Models\IntegrationAccount;
use App\Models\InternalActivity;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Internal activity Google Calendar sync (odd/tasks/internal-activities.md, T4: S7).
 * The creator and every participant with a connected Google Calendar account
 * get the event in their own calendar; recurring activities are one RRULE event.
 */
class InternalActivityGoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $connected;

    private User $unconnected;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'America/Lima', 'app.url' => 'https://crm.test']);

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->creator = User::factory()->create(['is_active' => true]);
        $this->creator->assignRole('vendedor');
        $this->connected = User::factory()->create(['is_active' => true]);
        $this->unconnected = User::factory()->create(['is_active' => true]);

        $this->fakeGoogle();
    }

    public function test_create_syncs_to_connected_creator_and_participant_only(): void
    {
        $this->googleAccountFor($this->creator);
        $this->googleAccountFor($this->connected);

        $activity = $this->store();

        $posts = $this->requests('POST');
        $this->assertCount(2, $posts);
        $this->assertEqualsCanonicalizing(
            ['Bearer token-'.$this->creator->id, 'Bearer token-'.$this->connected->id],
            array_map(fn (Request $r): string => $r->header('Authorization')[0], $posts),
        );
        $this->assertSame(2, $activity->calendarLinks()->count());
        $this->assertDatabaseHas('internal_activity_calendar_links', [
            'internal_activity_id' => $activity->id,
            'user_id' => $this->connected->id,
            'external_event_id' => 'evt-'.$this->connected->id,
            'sync_status' => 'synced',
        ]);
        $this->assertDatabaseMissing('internal_activity_calendar_links', ['user_id' => $this->unconnected->id]);

        $payload = $posts[0]->data();
        $this->assertSame('Reunión de equipo', $payload['summary']);
        $this->assertSame('2026-11-05T09:00:00-05:00', $payload['start']['dateTime']);
        $this->assertSame((string) $activity->id, $payload['extendedProperties']['private']['crm_internal_activity_id']);
    }

    public function test_recurring_activity_sends_rrule_and_single_does_not(): void
    {
        $this->googleAccountFor($this->creator);

        $this->store(['participant_ids' => [], 'recurrence_frequency' => 'weekly', 'recurrence_until' => '2026-12-03']);
        $this->store(['participant_ids' => [], 'title' => 'Única']);

        $posts = $this->requests('POST');
        $this->assertCount(2, $posts);
        $this->assertSame(['RRULE:FREQ=WEEKLY;UNTIL=20261203T235959Z'], $posts[0]->data()['recurrence']);
        $this->assertArrayNotHasKey('recurrence', $posts[1]->data());
    }

    public function test_update_patches_existing_events_without_duplicate_create(): void
    {
        $this->googleAccountFor($this->creator);
        $this->googleAccountFor($this->connected);
        $activity = $this->store();

        $this->update($activity, ['title' => 'Reunión movida', 'starts_at' => '2026-11-06 10:00']);

        $this->assertCount(2, $this->requests('POST'));
        $patches = $this->requests('PATCH');
        $this->assertCount(2, $patches);
        $this->assertSame('Reunión movida', $patches[0]->data()['summary']);
        $this->assertSame(2, $activity->calendarLinks()->count());
    }

    public function test_removing_a_participant_deletes_their_event(): void
    {
        $this->googleAccountFor($this->creator);
        $this->googleAccountFor($this->connected);
        $activity = $this->store();

        $this->update($activity, ['participant_ids' => [$this->unconnected->id]]);

        $deletes = $this->requests('DELETE');
        $this->assertCount(1, $deletes);
        $this->assertStringEndsWith('/events/evt-'.$this->connected->id, $deletes[0]->url());
        $this->assertSame('Bearer token-'.$this->connected->id, $deletes[0]->header('Authorization')[0]);
        $this->assertDatabaseMissing('internal_activity_calendar_links', ['user_id' => $this->connected->id]);
        $this->assertDatabaseHas('internal_activity_calendar_links', ['user_id' => $this->creator->id]);
    }

    public function test_destroy_deletes_all_events(): void
    {
        $this->googleAccountFor($this->creator);
        $this->googleAccountFor($this->connected);
        $activity = $this->store();

        $this->actingAs($this->creator)->delete(route('internal-activities.destroy', $activity))->assertRedirect();

        $this->assertCount(2, $this->requests('DELETE'));
        $this->assertDatabaseCount('internal_activity_calendar_links', 0);
    }

    public function test_users_without_account_produce_no_request(): void
    {
        $activity = $this->store();
        $this->update($activity, ['title' => 'Sin Google']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('internal_activity_calendar_links', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function store(array $overrides = []): InternalActivity
    {
        $this->actingAs($this->creator)->post(route('internal-activities.store'), array_merge([
            'title' => 'Reunión de equipo',
            'starts_at' => '2026-11-05 09:00',
            'audience_type' => 'all',
            'participant_ids' => [$this->connected->id, $this->unconnected->id],
        ], $overrides))->assertRedirect()->assertSessionHasNoErrors();

        return InternalActivity::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function update(InternalActivity $activity, array $overrides): void
    {
        $this->actingAs($this->creator)->put(route('internal-activities.update', $activity), array_merge([
            'title' => $activity->title,
            'starts_at' => $activity->starts_at->format('Y-m-d H:i'),
            'audience_type' => 'all',
            'participant_ids' => [$this->connected->id, $this->unconnected->id],
        ], $overrides))->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * @return list<Request>
     */
    private function requests(string $method): array
    {
        return Http::recorded()
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => $request->method() === $method)
            ->values()
            ->all();
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $token = substr((string) ($request->header('Authorization')[0] ?? ''), strlen('Bearer token-'));

            return match ($request->method()) {
                'POST' => Http::response(['id' => 'evt-'.$token], 201),
                'PATCH' => Http::response(['id' => basename(parse_url($request->url(), PHP_URL_PATH))], 200),
                default => Http::response(null, 204),
            };
        });
    }

    private function googleAccountFor(User $user): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Google Workspace — '.$user->email,
            'owner_id' => $user->id,
            'is_active' => true,
            'test_mode' => false,
            'config_json' => [
                'google_account_email' => $user->email,
                'services' => ['gmail' => false, 'calendar' => true],
                'calendar' => ['default_calendar_id' => 'primary'],
                'status' => 'connected',
            ],
            'credentials_encrypted' => [
                'access_token' => 'token-'.$user->id,
                'refresh_token' => 'refresh-token',
                'token_type' => 'Bearer',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.events'],
            'expires_at' => now()->addHour(),
        ]);
    }
}
