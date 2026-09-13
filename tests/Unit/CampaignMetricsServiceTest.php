<?php

namespace Tests\Unit;

use App\Models\ActivityType;
use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\CampaignMetricsService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit test for the KPI computation formula and cache round-trip.
 */
class CampaignMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CampaignRun $run;
    private CampaignMetricsService $service;
    private int $actionTypeId = 0;
    private int $stepCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);
        // Resolve the actor the way the working suites do: create the user and
        // assign the role explicitly. `env('ADMIN_EMAIL')` is null under a
        // cached config, which made every test in this class ERROR in setUp.
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole('admin');
        $this->actingAs($actor);

        $this->run = CampaignRun::query()->create([
            'code' => 'CR-2026-99999',
            'name' => 'Test',
            'template_id' => CampaignTemplate::query()->create([
                'name' => 'Metrics template',
                'objective' => 'custom',
                'status' => 'active',
                'owner_id' => $actor->id,
            ])->id,
            'template_hash' => 'x',
            'starts_at' => now(),
            'owner_id' => $actor->id,
            'status' => CampaignRun::STATUS_RUNNING,
        ]);
        $this->actionTypeId = (int) ActivityType::query()->where('slug', 'llamada')->value('id');
        $this->service = app(CampaignMetricsService::class);
    }

    public function test_compute_returns_zero_progress_when_no_items(): void
    {
        $metrics = $this->service->compute($this->run);
        $this->assertSame(0, $metrics['total']);
        $this->assertSame(0, $metrics['progress']);
    }

    public function test_compute_handles_division_by_zero(): void
    {
        $participant = $this->makeParticipant();
        $this->makeItem($participant, CampaignActionItem::STATUS_CANCELLED);
        $this->makeItem($participant, CampaignActionItem::STATUS_NOT_APPLICABLE);

        $metrics = $this->service->compute($this->run);
        // Both cancelled and not_applicable are excluded from denominator.
        $this->assertSame(0, $metrics['progress']);
    }

    public function test_compute_progress_correct(): void
    {
        $participant = $this->makeParticipant();
        $this->makeItem($participant, CampaignActionItem::STATUS_COMPLETED);
        $this->makeItem($participant, CampaignActionItem::STATUS_COMPLETED);
        $this->makeItem($participant, CampaignActionItem::STATUS_PENDING);
        $this->makeItem($participant, CampaignActionItem::STATUS_CANCELLED);

        $metrics = $this->service->compute($this->run);
        // total=4, cancelled=1, denominator = 4 - 1 = 3, completed=2
        // progress = 2 / 3 * 100 = 67 (rounded)
        $this->assertSame(4, $metrics['total']);
        $this->assertSame(2, $metrics['completed']);
        $this->assertSame(1, $metrics['cancelled']);
        $this->assertSame(67, $metrics['progress']);
    }

    public function test_recompute_cache_persists_to_run(): void
    {
        $participant = $this->makeParticipant();
        $this->makeItem($participant, CampaignActionItem::STATUS_COMPLETED);

        $this->service->recomputeCache($this->run);
        $this->run->refresh();

        $this->assertIsArray($this->run->progress_cache);
        $this->assertSame(1, $this->run->progress_cache['completed']);
    }

    private function makeParticipant(): CampaignParticipant
    {
        return CampaignParticipant::query()->create([
            'run_id' => $this->run->id,
            'subject_type' => 'lead',
            'subject_id' => 1,
            'assigned_to' => $this->run->owner_id,
            'status' => CampaignParticipant::STATUS_ACTIVE,
            'display_name' => 'Test',
        ]);
    }

    private function makeItem(CampaignParticipant $p, string $status): CampaignActionItem
    {
        // `campaign_action_items` is UNIQUE(step_id, participant_id): each item
        // needs its own step, otherwise the fixture violates the constraint that
        // the (masked) foreign-key error was hiding.
        $step = CampaignStep::query()->create([
            'is_template' => false,
            'template_id' => null,
            'run_id' => $this->run->id,
            'source_step_id' => null,
            'order' => ++$this->stepCount,
            'action_type_id' => $this->actionTypeId,
            'title' => 'Llamada',
            'day_offset' => 0,
            'scheduled_time' => '09:00',
            'status' => CampaignStep::STATUS_ACTIVE,
        ]);

        return CampaignActionItem::query()->create([
            'run_id' => $this->run->id,
            'step_id' => $step->id,
            'participant_id' => $p->id,
            'status' => $status,
            'scheduled_at' => now(),
        ]);
    }
}
