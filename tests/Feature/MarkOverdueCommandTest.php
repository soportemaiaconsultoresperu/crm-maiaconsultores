<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\ActivityOverdue;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RF-ACT-003 core: `activities:mark-overdue` flips pending rows whose
 * scheduled_at is in the past to "overdue", and is idempotent across
 * repeated runs.
 */
class MarkOverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_marks_pending_overdue_activities(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $pastPending = $this->makePending($lead, $owner, now()->subDay());
        $futurePending = $this->makePending($lead, $owner, now()->addDay());
        $alreadyOverdue = $this->makePending($lead, $owner, now()->subDays(2));
        $alreadyOverdue->update(['status' => 'overdue']);
        $completedPast = $this->makePending($lead, $owner, now()->subDay());
        $completedPast->update(['status' => 'completed']);

        $exitCode = Artisan::call('activities:mark-overdue');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);

        $this->assertSame('overdue', $pastPending->fresh()->status);
        $this->assertSame('pending', $futurePending->fresh()->status, 'Future pending is not touched.');
        $this->assertSame('overdue', $alreadyOverdue->fresh()->status, 'Already overdue stays overdue.');
        $this->assertSame('completed', $completedPast->fresh()->status, 'Completed past is not touched.');

        $this->assertStringContainsString('Activities marked as overdue: 1', $output);
    }

    public function test_is_idempotent_on_second_run(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $this->makePending($lead, $owner, now()->subDay());

        Artisan::call('activities:mark-overdue');

        $firstRunOverdueCount = Activity::query()->where('status', 'overdue')->count();
        $this->assertSame(1, $firstRunOverdueCount);

        Artisan::call('activities:mark-overdue');

        $secondRunOverdueCount = Activity::query()->where('status', 'overdue')->count();
        $this->assertSame(1, $secondRunOverdueCount, 'Second run must not double the overdue set.');
    }

    public function test_dry_run_reports_count_without_writing(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forOwner($owner)->create();

        $this->makePending($lead, $owner, now()->subDay());

        $exitCode = Artisan::call('activities:mark-overdue', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertStringContainsString('1', $output);

        $this->assertSame(0, Activity::query()->where('status', 'overdue')->count());
    }

    public function test_no_pending_past_activities_returns_zero(): void
    {
        $exitCode = Artisan::call('activities:mark-overdue');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0', $output);
    }

    private function makePending(Lead $lead, User $owner, $when): Activity
    {
        return Activity::create([
            'type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => $owner->id,
            'title' => 'Llamada',
            'scheduled_at' => $when,
            'status' => 'pending',
            'priority' => 'media',
        ]);
    }
}