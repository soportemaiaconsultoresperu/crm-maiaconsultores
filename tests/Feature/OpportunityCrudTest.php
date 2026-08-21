<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * RF-OPP-001/002/010: opportunity CRUD invariants, defaults, codes and
 * owner-based visibility (ADR-006).
 */
class OpportunityCrudTest extends TestCase
{
    use RefreshDatabase;

    private OpportunityService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->service = app(OpportunityService::class);
        $this->actor = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $base = [
            'title' => 'Consultoría de procesos',
            'lead_id' => Lead::factory()->create()->id,
            'estimated_amount' => 15000,
        ];

        return array_merge($base, $overrides);
    }

    public function test_create_generates_sequential_opp_codes(): void
    {
        $year = now()->format('Y');

        $first = $this->service->create($this->validData(), $this->actor);
        $second = $this->service->create($this->validData(), $this->actor);

        $this->assertSame("OPP-{$year}-00001", $first->code);
        $this->assertSame("OPP-{$year}-00002", $second->code);
    }

    public function test_create_rejects_when_both_lead_and_customer_are_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($this->validData([
            'customer_id' => Customer::factory()->create()->id,
        ]), $this->actor);
    }

    public function test_create_rejects_when_neither_lead_nor_customer_is_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($this->validData([
            'lead_id' => null,
            'customer_id' => null,
        ]), $this->actor);
    }

    public function test_create_applies_defaults(): void
    {
        $opportunity = $this->service->create($this->validData(), $this->actor);

        // First open stage by sort (nueva-oportunidad, default 10%).
        $this->assertSame('nueva-oportunidad', $opportunity->stage->slug);
        $this->assertEquals(10, (float) $opportunity->probability);

        // ADR-004: PEN by default; owner defaults to the actor.
        $this->assertSame('PEN', $opportunity->currency_code);
        $this->assertSame($this->actor->id, $opportunity->owner_id);
        $this->assertSame($this->actor->id, $opportunity->created_by);

        // docs §3.4: initial stage history row with from_stage_id NULL.
        $this->assertSame(1, $opportunity->stageHistories()->count());
        $initialHistory = $opportunity->stageHistories()->first();
        $this->assertNull($initialHistory->from_stage_id);
        $this->assertSame($opportunity->stage_id, $initialHistory->to_stage_id);
    }

    public function test_create_with_customer_uses_explicit_defaults(): void
    {
        $opportunity = $this->service->create($this->validData([
            'lead_id' => null,
            'customer_id' => Customer::factory()->create()->id,
            'currency_code' => 'USD',
            'probability' => 55,
        ]), $this->actor);

        $this->assertNotNull($opportunity->customer_id);
        $this->assertNull($opportunity->lead_id);
        $this->assertSame('USD', $opportunity->currency_code);
        $this->assertEquals(55, (float) $opportunity->probability);
    }

    public function test_update_edits_open_opportunity(): void
    {
        $opportunity = $this->service->create($this->validData(), $this->actor);

        $opportunity = $this->service->update($opportunity, [
            'title' => 'Consultoría ampliada',
            'estimated_amount' => 20000,
        ], $this->actor);

        $this->assertSame('Consultoría ampliada', $opportunity->title);
        $this->assertEquals(20000, (float) $opportunity->estimated_amount);
        $this->assertSame($this->actor->id, $opportunity->updated_by);
    }

    public function test_update_cannot_change_code_or_stage(): void
    {
        $opportunity = $this->service->create($this->validData(), $this->actor);
        $originalCode = $opportunity->code;
        $originalStageId = $opportunity->stage_id;

        $opportunity = $this->service->update($opportunity, [
            'code' => 'OPP-FAKE-99999',
            'stage_id' => \App\Models\PipelineStage::where('slug', 'negociacion')->value('id'),
        ], $this->actor);

        $this->assertSame($originalCode, $opportunity->code);
        $this->assertSame($originalStageId, $opportunity->stage_id);
    }

    public function test_update_of_won_opportunity_throws(): void
    {
        $opportunity = $this->service->create($this->validData(), $this->actor);
        $this->service->markWon($opportunity, ['final_amount' => 12000], $this->actor);

        $this->expectException(InvalidOperationException::class);

        $this->service->update($opportunity->refresh(), ['title' => 'Cambio tardío'], $this->actor);
    }

    public function test_update_of_lost_opportunity_throws(): void
    {
        $opportunity = $this->service->create($this->validData(), $this->actor);
        $reason = \App\Models\LossReason::first();
        $this->service->markLost($opportunity, $reason, $this->actor);

        $this->expectException(InvalidOperationException::class);

        $this->service->update($opportunity->refresh(), ['title' => 'Cambio tardío'], $this->actor);
    }

    public function test_scope_query_respects_data_scope(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');

        $salespersonOne = User::factory()->create();
        $salespersonOne->assignRole('vendedor');

        $salespersonTwo = User::factory()->create();
        $salespersonTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($salespersonOne->id);

        $own = Opportunity::factory()->forOwner($salespersonOne)->create();
        $foreign = Opportunity::factory()->forOwner($salespersonTwo)->create();

        $visibleToSalesperson = $this->service
            ->scopeQuery($salespersonOne)
            ->pluck('id');
        $this->assertContains($own->id, $visibleToSalesperson);
        $this->assertNotContains($foreign->id, $visibleToSalesperson);

        $visibleToSupervisor = $this->service
            ->scopeQuery($supervisor)
            ->pluck('id');
        $this->assertContains($own->id, $visibleToSupervisor);
        $this->assertNotContains($foreign->id, $visibleToSupervisor);

        $visibleToAdmin = $this->service
            ->scopeQuery($admin)
            ->pluck('id');
        $this->assertContains($own->id, $visibleToAdmin);
        $this->assertContains($foreign->id, $visibleToAdmin);
    }

    public function test_policy_view_respects_owner_scope(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $salesperson = User::factory()->create();
        $salesperson->assignRole('vendedor');

        $other = User::factory()->create();
        $other->assignRole('vendedor');

        $own = Opportunity::factory()->forOwner($salesperson)->create();
        $foreign = Opportunity::factory()->forOwner($other)->create();

        $this->assertTrue(Gate::forUser($salesperson)->allows('view', $own));
        $this->assertFalse(Gate::forUser($salesperson)->allows('view', $foreign));
    }
}
