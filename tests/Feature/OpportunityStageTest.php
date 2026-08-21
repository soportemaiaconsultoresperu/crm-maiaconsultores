<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\OpportunityStageChanged;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RF-OPP-004/005: stage transitions with append-only history, activitylog
 * and owner notification (RF-NOT-001).
 */
class OpportunityStageTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->service = app(OpportunityService::class);
        $this->actor = User::factory()->create();
    }

    private function stage(string $slug): PipelineStage
    {
        return PipelineStage::query()->where('slug', $slug)->firstOrFail();
    }

    public function test_change_stage_updates_stage_and_writes_history(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $fromId = $opportunity->stage_id;
        $to = $this->stage('contacto-realizado');

        $opportunity = $this->service->changeStage($opportunity, $to, $this->actor, 'Cliente respondió');

        $this->assertSame($to->id, $opportunity->stage_id);

        $history = $opportunity->stageHistories()->latest('id')->first();
        $this->assertSame($fromId, $history->from_stage_id);
        $this->assertSame($to->id, $history->to_stage_id);
        $this->assertSame($this->actor->id, $history->user_id);
        $this->assertNotNull($history->changed_at);
        $this->assertSame('Cliente respondió', $history->note);
    }

    public function test_change_stage_does_not_overwrite_probability(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create([
            'probability' => 33,
        ]);

        $opportunity = $this->service->changeStage(
            $opportunity,
            $this->stage('propuesta-enviada'),
            $this->actor
        );

        $this->assertEquals(33, (float) $opportunity->probability);
    }

    public function test_change_stage_logs_activity_with_from_and_to_slugs(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();

        $this->service->changeStage($opportunity, $this->stage('negociacion'), $this->actor);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Opportunity::class)
            ->where('subject_id', $opportunity->id)
            ->where('event', 'opportunity-stage-changed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame('nueva-oportunidad', $log->properties['from_stage']);
        $this->assertSame('negociacion', $log->properties['to_stage']);
    }

    public function test_change_stage_notifies_owner_when_actor_differs(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $opportunity = Opportunity::factory()->forOwner($owner)->create();

        $this->service->changeStage($opportunity, $this->stage('reunion-programada'), $this->actor);

        Notification::assertSentTo(
            $owner,
            OpportunityStageChanged::class,
            fn (OpportunityStageChanged $notification): bool => $notification->toStage === 'Reunión programada'
        );
    }

    public function test_change_stage_on_won_opportunity_throws(): void
    {
        $opportunity = Opportunity::factory()->won()->create();

        $this->expectException(InvalidOperationException::class);

        $this->service->changeStage($opportunity, $this->stage('negociacion'), $this->actor);
    }

    public function test_change_stage_on_lost_opportunity_throws(): void
    {
        $opportunity = Opportunity::factory()->lost()->create();

        $this->expectException(InvalidOperationException::class);

        $this->service->changeStage($opportunity, $this->stage('negociacion'), $this->actor);
    }
}
