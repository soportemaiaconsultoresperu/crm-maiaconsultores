<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Opportunity;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-012: the next action of an opportunity is its most proximate future
 * PENDING activity; "Sin próximo seguimiento" when null (mirror of
 * LeadNextActionTest).
 */
class OpportunityNextActionTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->service = app(OpportunityService::class);
    }

    private function activity(Opportunity $opportunity, array $overrides = []): Activity
    {
        return Activity::create(array_merge([
            'type_id' => ActivityType::query()->firstOrCreate(
                ['slug' => 'llamada'],
                ['name' => 'Llamada', 'sort' => 1, 'is_active' => true],
            )->id,
            'subject_type' => Opportunity::class,
            'subject_id' => $opportunity->id,
            'owner_id' => \App\Models\User::factory()->create()->id,
            'title' => 'Llamada de seguimiento',
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
            'priority' => 'media',
        ], $overrides));
    }

    public function test_returns_only_the_future_pending_activity(): void
    {
        $opportunity = Opportunity::factory()->create();

        $futurePending = $this->activity($opportunity, ['scheduled_at' => now()->addDays(2)]);
        $this->activity($opportunity, ['scheduled_at' => now()->subDay(), 'status' => 'pending']); // past pending
        $this->activity($opportunity, ['scheduled_at' => now()->addDays(3), 'status' => 'completed']); // future but done

        $next = $this->service->nextAction($opportunity);

        $this->assertNotNull($next);
        $this->assertSame($futurePending->id, $next->id);
    }

    public function test_returns_the_earliest_when_several_future_pending_exist(): void
    {
        $opportunity = Opportunity::factory()->create();

        $earliest = $this->activity($opportunity, ['scheduled_at' => now()->addDays(1)]);
        $this->activity($opportunity, ['scheduled_at' => now()->addDays(5)]);

        $this->assertSame($earliest->id, $this->service->nextAction($opportunity)->id);
    }

    public function test_returns_null_when_there_is_no_future_pending_activity(): void
    {
        $opportunity = Opportunity::factory()->create();

        $this->activity($opportunity, ['scheduled_at' => now()->subDay(), 'status' => 'pending']);
        $this->activity($opportunity, ['scheduled_at' => now()->addDay(), 'status' => 'cancelled']);

        $this->assertNull($this->service->nextAction($opportunity));
    }

    public function test_returns_null_for_an_opportunity_without_activities(): void
    {
        $opportunity = Opportunity::factory()->create();

        $this->assertNull($this->service->nextAction($opportunity));
    }
}
