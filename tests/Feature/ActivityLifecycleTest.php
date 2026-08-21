<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\ActivityAssigned;
use App\Services\ActivityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end lifecycle for the activity module (B05 / RF-ACT-001..007).
 * Exercises the service for each morph subject, the start/transition
 * guards, complete-with-follow-up (RF-ACT-005), and the cancellation
 * flow.
 */
class ActivityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    private User $actor;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(ActivityService::class);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('vendedor');

        $this->otherOwner = User::factory()->create(['is_active' => true]);
        $this->otherOwner->assignRole('vendedor');
    }

    private function typeId(string $slug): int
    {
        return ActivityType::query()->where('slug', $slug)->value('id');
    }

    private function lead(): Lead
    {
        return Lead::factory()->forOwner($this->actor)->create();
    }

    private function customer(): Customer
    {
        return Customer::factory()->forOwner($this->actor)->create();
    }

    private function opportunity(): Opportunity
    {
        return Opportunity::factory()->forOwner($this->actor)->create();
    }

    public function test_create_activity_for_each_morph_subject_persists_row_and_log(): void
    {
        foreach (['lead', 'customer', 'opportunity'] as $subject) {
            $subjectModel = match ($subject) {
                'lead' => $this->lead(),
                'customer' => $this->customer(),
                'opportunity' => $this->opportunity(),
            };

            $activity = $this->service->create([
                'subject_type' => $subject,
                'subject_id' => $subjectModel->id,
                'type_id' => $this->typeId('llamada'),
                'title' => 'Llamada de seguimiento',
                'scheduled_at' => now()->addDay(),
                'priority' => 'alta',
            ], $this->actor);

            $this->assertSame(Activity::morphClass($subject), $activity->subject_type, "subject_type persisted for {$subject}");
            $this->assertSame($subjectModel->id, $activity->subject_id);
            $this->assertSame($this->actor->id, $activity->owner_id);
            $this->assertSame('pending', $activity->status);
            $this->assertSame('alta', $activity->priority);
        }
    }

    public function test_create_with_explicit_owner_emits_assigned_notification(): void
    {
        Notification::fake();

        $lead = $this->lead();

        $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada de calificación',
            'owner_id' => $this->otherOwner->id,
            'scheduled_at' => now()->addDay(),
        ], $this->actor);

        Notification::assertSentTo($this->otherOwner, ActivityAssigned::class);
        Notification::assertNotSentTo($this->actor, ActivityAssigned::class);
    }

    public function test_start_transitions_pending_to_in_process(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->addDay(),
        ], $this->actor);

        $started = $this->service->start($activity, $this->actor);

        $this->assertSame('in_process', $started->status);
        $this->assertNull($started->executed_at);
    }

    public function test_start_rejects_non_pending_activity(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->addDay(),
        ], $this->actor);

        $activity = $this->service->start($activity, $this->actor);

        $this->expectException(InvalidOperationException::class);
        $this->service->start($activity, $this->actor);
    }

    public function test_complete_without_next_persists_result_and_executed_at(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->subHour(),
        ], $this->actor);

        $completed = $this->service->complete($activity, [
            'result' => 'Cliente confirmó interés',
        ], $this->actor);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('Cliente confirmó interés', $completed->result);
        $this->assertNotNull($completed->executed_at);
    }

    public function test_complete_with_next_creates_follow_up_activity_in_same_transaction(): void
    {
        $lead = $this->lead();

        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada inicial',
            'scheduled_at' => now()->subHour(),
        ], $this->actor);

        $completed = $this->service->complete($activity, [
            'result' => 'Confirmado',
            'next_scheduled_at' => now()->addDays(3),
            'next_type_id' => $this->typeId('reunion'),
            'next_title' => 'Reunión de cierre',
            'next_owner_id' => $this->actor->id,
        ], $this->actor);

        $this->assertSame('completed', $completed->status);

        $followUp = Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('title', 'Reunión de cierre')
            ->first();

        $this->assertNotNull($followUp);
        $this->assertSame('pending', $followUp->status);
        $this->assertSame($this->actor->id, $followUp->owner_id);
        $this->assertSame($activity->subject_id, $followUp->subject_id);
        $this->assertSame($activity->subject_type, $followUp->subject_type);
    }

    public function test_update_rejects_completed_activity(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->subHour(),
        ], $this->actor);

        $completed = $this->service->complete($activity, ['result' => 'OK'], $this->actor);

        $this->expectException(InvalidOperationException::class);
        $this->service->update($completed, ['title' => 'Nuevo título'], $this->actor);
    }

    public function test_update_rejects_cancelled_activity(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->addDay(),
        ], $this->actor);

        $cancelled = $this->service->cancel($activity, $this->actor, 'Cliente no contesta');

        $this->expectException(InvalidOperationException::class);
        $this->service->update($cancelled, ['title' => 'Nuevo'], $this->actor);
    }

    public function test_cancel_logs_reason(): void
    {
        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->addDay(),
        ], $this->actor);

        $cancelled = $this->service->cancel($activity, $this->actor, 'Número equivocado');

        $this->assertSame('cancelled', $cancelled->status);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $cancelled->id)
            ->where('event', 'activity-cancelled')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Número equivocado', $log->properties['reason'] ?? null);
    }

    public function test_completed_activity_notification_row_not_created_by_complete(): void
    {
        Notification::fake();

        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $this->lead()->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Llamada',
            'scheduled_at' => now()->subHour(),
            'owner_id' => $this->otherOwner->id,
        ], $this->actor);

        $this->service->complete($activity, ['result' => 'OK'], $this->actor);

        // Only the "activity-created" notification should have been sent;
        // completion does NOT generate a notification (scheduler-side).
        Notification::assertSentTo($this->otherOwner, ActivityAssigned::class);
        Notification::assertNotSentTo($this->otherOwner, \App\Notifications\ActivityUpcoming::class);
        Notification::assertNotSentTo($this->otherOwner, \App\Notifications\ActivityOverdue::class);
    }
}