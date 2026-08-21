<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-012 / RF-LEAD-010: the next action is the most proximate future
 * PENDING activity of a lead.
 */
class LeadNextActionTest extends TestCase
{
    use RefreshDatabase;

    private LeadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LeadService::class);
    }

    private function activity(Lead $lead, array $overrides = []): Activity
    {
        return Activity::create(array_merge([
            'type_id' => \App\Models\ActivityType::query()->firstOrCreate(
                ['slug' => 'llamada'],
                ['name' => 'Llamada', 'sort' => 1, 'is_active' => true],
            )->id,
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'owner_id' => User::factory()->create()->id,
            'title' => 'Llamada de seguimiento',
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
            'priority' => 'media',
        ], $overrides));
    }

    public function test_returns_only_the_future_pending_activity(): void
    {
        $lead = Lead::factory()->create();

        $futurePending = $this->activity($lead, ['scheduled_at' => now()->addDays(2)]);
        $this->activity($lead, ['scheduled_at' => now()->subDay(), 'status' => 'pending']); // past pending
        $this->activity($lead, ['scheduled_at' => now()->addDays(3), 'status' => 'completed']); // future but done

        $next = $this->service->nextAction($lead);

        $this->assertNotNull($next);
        $this->assertSame($futurePending->id, $next->id);
    }

    public function test_returns_the_earliest_when_several_future_pending_exist(): void
    {
        $lead = Lead::factory()->create();

        $earliest = $this->activity($lead, ['scheduled_at' => now()->addDays(1)]);
        $this->activity($lead, ['scheduled_at' => now()->addDays(5)]);

        $this->assertSame($earliest->id, $this->service->nextAction($lead)->id);
    }

    public function test_returns_null_when_there_is_no_future_pending_activity(): void
    {
        $lead = Lead::factory()->create();

        $this->activity($lead, ['scheduled_at' => now()->subDay(), 'status' => 'pending']);
        $this->activity($lead, ['scheduled_at' => now()->addDay(), 'status' => 'cancelled']);

        $this->assertNull($this->service->nextAction($lead));
    }

    public function test_returns_null_for_a_lead_without_activities(): void
    {
        $lead = Lead::factory()->create();

        $this->assertNull($this->service->nextAction($lead));
    }
}
