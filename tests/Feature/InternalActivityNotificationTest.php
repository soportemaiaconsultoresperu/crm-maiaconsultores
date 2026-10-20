<?php

namespace Tests\Feature;

use App\Models\InternalActivity;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Internal activity notifications (odd/tasks/internal-activities.md, T3: S6).
 * Database notifications to participants on create/update and a reminder
 * for occurrences starting in the next 24 hours.
 */
class InternalActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->creator = User::factory()->create(['is_active' => true]);
        $this->creator->assignRole('vendedor');
        $this->alice = User::factory()->create(['is_active' => true]);
        $this->alice->assignRole('vendedor');
        $this->bob = User::factory()->create(['is_active' => true]);
        $this->bob->assignRole('vendedor');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Capacitación interna',
            'starts_at' => '2026-10-21 09:00',
            'audience_type' => 'all',
        ], $overrides);
    }

    private function notificationsOf(User $user, string $type): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_id', $user->id)
            ->where('type', $type)
            ->count();
    }

    public function test_participants_are_notified_on_create_except_the_creator(): void
    {
        $this->actingAs($this->creator)->post(route('internal-activities.store'), $this->payload([
            'participant_ids' => [$this->alice->id, $this->creator->id],
        ]))->assertRedirect();

        $this->assertSame(1, $this->notificationsOf($this->alice, 'internal-activity-invited'));
        $this->assertSame(0, $this->notificationsOf($this->creator, 'internal-activity-invited'));
        $this->assertSame(0, $this->notificationsOf($this->bob, 'internal-activity-invited'));

        $notification = DatabaseNotification::query()->where('notifiable_id', $this->alice->id)->firstOrFail();
        $this->assertStringContainsString('Capacitación interna', $notification->data['message']);
        $this->assertSame(InternalActivity::query()->value('id'), $notification->data['internal_activity_id']);
    }

    public function test_update_notifies_existing_participants_and_invites_new_ones(): void
    {
        $this->actingAs($this->creator)->post(route('internal-activities.store'), $this->payload([
            'participant_ids' => [$this->alice->id],
        ]));
        $activity = InternalActivity::query()->firstOrFail();

        $this->actingAs($this->creator)->put(route('internal-activities.update', $activity), $this->payload([
            'title' => 'Capacitación movida',
            'participant_ids' => [$this->alice->id, $this->bob->id],
        ]))->assertRedirect();

        $this->assertSame(1, $this->notificationsOf($this->alice, 'internal-activity-invited'));
        $this->assertSame(1, $this->notificationsOf($this->alice, 'internal-activity-updated'));
        $this->assertSame(1, $this->notificationsOf($this->bob, 'internal-activity-invited'));
        $this->assertSame(0, $this->notificationsOf($this->bob, 'internal-activity-updated'));
    }

    public function test_reminder_command_notifies_participants_once_per_occurrence(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->actingAs($this->creator)->post(route('internal-activities.store'), $this->payload([
            'starts_at' => '2026-10-21 09:00',
            'recurrence_frequency' => 'daily',
            'recurrence_until' => '2026-10-25',
            'participant_ids' => [$this->alice->id],
        ]));
        $this->actingAs($this->creator)->post(route('internal-activities.store'), $this->payload([
            'title' => 'Lejana',
            'starts_at' => '2026-10-30 09:00',
            'participant_ids' => [$this->bob->id],
        ]));

        $this->artisan('internal-activities:notify-upcoming')->assertSuccessful();
        $this->artisan('internal-activities:notify-upcoming')->assertSuccessful();

        $this->assertSame(1, $this->notificationsOf($this->alice, 'internal-activity-upcoming'));
        $this->assertSame(0, $this->notificationsOf($this->bob, 'internal-activity-upcoming'));

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->artisan('internal-activities:notify-upcoming')->assertSuccessful();
        $this->assertSame(2, $this->notificationsOf($this->alice, 'internal-activity-upcoming'));
    }

    public function test_reminder_skips_inactive_participants(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->actingAs($this->creator)->post(route('internal-activities.store'), $this->payload([
            'participant_ids' => [$this->alice->id],
        ]));
        $this->alice->update(['is_active' => false]);

        $this->artisan('internal-activities:notify-upcoming')->assertSuccessful();

        $this->assertSame(0, $this->notificationsOf($this->alice, 'internal-activity-upcoming'));
    }
}
