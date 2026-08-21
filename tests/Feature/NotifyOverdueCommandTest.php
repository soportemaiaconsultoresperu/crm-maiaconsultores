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
 * RF-ACT-003 (notification side): `activities:notify-overdue` emits one
 * ActivityOverdue notification per row that became overdue since the
 * last run; idempotent across repeated runs (per scheduled_at_iso).
 */
class NotifyOverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_emits_one_notification_for_newly_overdue_activities(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        // Activity was marked overdue by the daily mark-overdue run; the
        // cursor starts at now - 1 day by default so this row is in scope.
        $activity = $this->makeOverdue($lead, $owner, now()->subHours(2));

        Artisan::call('activities:notify-overdue');

        $row = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $owner->id)
            ->where('type', 'activity-overdue')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($activity->title, $row->data['title']);
    }

    public function test_idempotent_when_run_again_without_new_overdue_rows(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $this->makeOverdue($lead, $owner, now()->subHours(2));

        Artisan::call('activities:notify-overdue');
        $firstCount = DatabaseNotification::query()->where('type', 'activity-overdue')->count();
        $this->assertSame(1, $firstCount);

        Artisan::call('activities:notify-overdue');
        $secondCount = DatabaseNotification::query()->where('type', 'activity-overdue')->count();
        $this->assertSame(1, $secondCount, 'Second run must not duplicate the overdue notification.');
    }

    public function test_inactive_owner_is_skipped(): void
    {
        $active = User::factory()->create(['is_active' => true]);
        $inactive = User::factory()->create(['is_active' => false]);

        $lead = Lead::factory()->forOwner($active)->create();

        $this->makeOverdue($lead, $active, now()->subHours(2), 'Para activo');
        $this->makeOverdue($lead, $inactive, now()->subHours(2), 'Para inactivo');

        Artisan::call('activities:notify-overdue');

        $rows = DatabaseNotification::query()->where('type', 'activity-overdue')->get();
        $recipients = $rows->pluck('notifiable_id')->all();

        $this->assertContains($active->id, $recipients);
        $this->assertNotContains($inactive->id, $recipients);
    }

    public function test_emits_after_mark_overdue_command_changes_status(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        // Pending past activity → flip to overdue via the daily command.
        Activity::create([
            'type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => $owner->id,
            'title' => 'Llamada vencida',
            'scheduled_at' => now()->subHours(3),
            'status' => 'pending',
            'priority' => 'media',
        ]);

        Artisan::call('activities:mark-overdue');
        Artisan::call('activities:notify-overdue');

        $rows = DatabaseNotification::query()->where('type', 'activity-overdue')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($owner->id, $rows->first()->notifiable_id);
        $this->assertSame('Llamada vencida', $rows->first()->data['title']);
    }

    public function test_pending_future_activity_is_notified(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        // The notify-overdue command queries by status=overdue, so a
        // pending future row should NOT trigger a notification.
        $this->make($lead, $owner, now()->addHours(2), 'Pendiente futura');

        Artisan::call('activities:notify-overdue');

        $count = DatabaseNotification::query()->where('type', 'activity-overdue')->count();
        $this->assertSame(0, $count);
    }

    private function makeOverdue(Lead $lead, User $owner, $when, string $title = 'Vencida'): Activity
    {
        return Activity::create([
            'type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => $owner->id,
            'title' => $title,
            'scheduled_at' => $when,
            'status' => 'overdue',
            'priority' => 'media',
        ]);
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