<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-OPP-006/007: explicit win (final amount required) and loss (reason
 * required), both terminal (ADR-007).
 */
class OpportunityWinLoseTest extends TestCase
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

    public function test_mark_won_requires_a_positive_final_amount(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();

        try {
            $this->service->markWon($opportunity, [], $this->actor);
            $this->fail('Expected InvalidArgumentException for missing final_amount.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('monto final', $e->getMessage());
        }

        try {
            $this->service->markWon($opportunity, ['final_amount' => 0], $this->actor);
            $this->fail('Expected InvalidArgumentException for zero final_amount.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('monto final', $e->getMessage());
        }
    }

    public function test_mark_won_closes_the_opportunity_with_amounts(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();

        $opportunity = $this->service->markWon($opportunity, ['final_amount' => 12345.67], $this->actor);

        $this->assertSame('ganada', $opportunity->stage->slug);
        $this->assertEquals(12345.67, (float) $opportunity->final_amount);
        $this->assertNotNull($opportunity->closed_at);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Opportunity::class)
            ->where('subject_id', $opportunity->id)
            ->where('event', 'opportunity-won')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(12345.67, (float) $log->properties['final_amount']);
        $this->assertSame($opportunity->currency_code, $log->properties['currency_code']);

        // Stage transition also recorded in the append-only history.
        $history = $opportunity->stageHistories()->latest('id')->first();
        $this->assertSame($opportunity->stage_id, $history->to_stage_id);
    }

    public function test_mark_won_respects_explicit_closed_at(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $closedAt = now()->subDays(2)->startOfDay();

        $opportunity = $this->service->markWon($opportunity, [
            'final_amount' => 5000,
            'closed_at' => $closedAt,
        ], $this->actor);

        $this->assertSame($closedAt->toDateString(), $opportunity->closed_at->toDateString());
    }

    public function test_mark_won_twice_throws(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $this->service->markWon($opportunity, ['final_amount' => 5000], $this->actor);

        $this->expectException(InvalidOperationException::class);

        $this->service->markWon($opportunity->refresh(), ['final_amount' => 6000], $this->actor);
    }

    public function test_mark_lost_requires_a_reason(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();

        try {
            $this->service->markLost($opportunity, 0, $this->actor);
            $this->fail('Expected InvalidArgumentException for missing loss reason.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('motivo de pérdida', $e->getMessage());
        }
    }

    public function test_mark_lost_closes_the_opportunity_with_reason(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $reason = \App\Models\LossReason::where('slug', 'precio')->firstOrFail();

        $opportunity = $this->service->markLost($opportunity, $reason, $this->actor, 'Competidor más barato');

        $this->assertSame('perdida', $opportunity->stage->slug);
        $this->assertSame($reason->id, $opportunity->loss_reason_id);
        $this->assertNotNull($opportunity->closed_at);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Opportunity::class)
            ->where('subject_id', $opportunity->id)
            ->where('event', 'opportunity-lost')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Precio', $log->properties['loss_reason']);

        $history = $opportunity->stageHistories()->latest('id')->first();
        $this->assertSame('Competidor más barato', $history->note);
    }

    public function test_after_lost_change_stage_and_update_throw(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $reason = \App\Models\LossReason::firstOrFail();
        $opportunity = $this->service->markLost($opportunity, $reason, $this->actor);

        $stage = \App\Models\PipelineStage::where('slug', 'negociacion')->firstOrFail();

        $this->expectException(InvalidOperationException::class);
        $this->service->changeStage($opportunity->refresh(), $stage, $this->actor);
    }

    public function test_after_won_update_throws(): void
    {
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();
        $this->service->markWon($opportunity, ['final_amount' => 5000], $this->actor);

        $this->expectException(InvalidOperationException::class);
        $this->service->update($opportunity->refresh(), ['title' => 'X'], $this->actor);
    }
}
