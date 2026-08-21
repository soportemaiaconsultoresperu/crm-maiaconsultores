<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * RF-ACT-007: `activities:notify-upcoming` emits one ActivityUpcoming
 * notification per pending activity scheduled within (now, now + 24h],
 * respecting reminder_at, and is idempotent across repeated runs.
 *
 * The dedupe test queries the notifications table directly because the
 * command relies on DatabaseNotification rows, not the Notification
 * fake.
 */
class NotifyUpcomingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_emits_one_notification_per_upcoming_activity(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $inOneHour = $this->make($lead, $owner, now()->addHour(), 'Llamada 1h');
        $inTwentyHours = $this->make($lead, $owner, now()->addHours(20), 'Llamada 20h');
        $this->make($lead, $owner, now()->subHour(), 'Pasada');
        $this->make($lead, $owner, now()->addHours(48), 'Futura lejana');

        Artisan::call('activities:notify-upcoming');

        $rows = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->get();

        $titles = $rows->pluck('data')->map(fn ($data) => $data['title'] ?? null)->all();

        $this->assertCount(2, $rows);
        $this->assertContains($inOneHour->title, $titles);
        $this->assertContains($inTwentyHours->title, $titles);
    }

    public function test_respects_future_reminder_at(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $activity = $this->make($lead, $owner, now()->addHours(2), 'Con recordatorio');
        $activity->update(['reminder_at' => now()->addHours(10)]);

        Artisan::call('activities:notify-upcoming');

        $rows = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->get();

        $this->assertCount(0, $rows);
    }

    public function test_idempotent_on_second_run_when_no_new_rows(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $this->make($lead, $owner, now()->addHours(2), 'Próxima');

        Artisan::call('activities:notify-upcoming');
        $firstCount = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->count();
        $this->assertSame(1, $firstCount);

        Artisan::call('activities:notify-upcoming');
        $secondCount = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->count();
        $this->assertSame(1, $secondCount, 'No duplicate notification expected.');
    }

    public function test_inactive_owner_is_skipped(): void
    {
        $active = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);

        $lead = Lead::factory()->forOwner($active)->create();

        $this->make($lead, $active, now()->addHours(2), 'Para activo');
        $this->make($lead, $inactive, now()->addHours(2), 'Para inactivo');

        Artisan::call('activities:notify-upcoming');

        $activeCount = DatabaseNotification::query()
            ->where('notifiable_id', $active->id)
            ->count();
        $inactiveCount = DatabaseNotification::query()
            ->where('notifiable_id', $inactive->id)
            ->count();

        $this->assertSame(1, $activeCount);
        $this->assertSame(0, $inactiveCount);
    }

    public function test_persists_a_database_notification_row_with_correct_payload(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $this->make($lead, $owner, now()->addHours(2), 'Llamada específica');

        Artisan::call('activities:notify-upcoming');

        $row = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-upcoming')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Llamada específica', $row->data['title']);
        $this->assertNotEmpty($row->data['scheduled_at_iso']);
        $this->assertStringContainsString('Llamada específica', $row->data['message']);
    }

    private function make(Lead $lead, User $owner, $when, string $title): Activity
    {
        return Activity::create([
            'type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => $owner->id,
            'title' => $title,
            'scheduled_at' => $when,
            'status' => 'pending',
            'priority' => 'media',
        ]);
    }
}