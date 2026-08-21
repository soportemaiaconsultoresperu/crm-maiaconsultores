<?php

namespace Tests\Unit;

use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);
        $actor = User::query()->where('email', env('ADMIN_EMAIL'))->first();
        $this->actingAs($actor);

        $this->run = CampaignRun::query()->create([
            'code' => 'CR-2026-99999',
            'name' => 'Test',
            'template_id' => 1,
            'template_hash' => 'x',
            'starts_at' => now(),
            'owner_id' => $actor->id,
            'status' => CampaignRun::STATUS_RUNNING,
        ]);
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
        return CampaignActionItem::query()->create([
            'run_id' => $this->run->id,
            'step_id' => 1,
            'participant_id' => $p->id,
            'status' => $status,
            'scheduled_at' => now(),
        ]);
    }
}
