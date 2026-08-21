<?php

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-007 / RF-COT-007/008: the QuotationService::accept() method MUST
 * NOT mutate the linked opportunity. The explicit confirmation step is
 * owned by the controller, which calls OpportunityService::markWon
 * separately. A quotation without an opportunity must accept cleanly.
 */
class QuotationAcceptanceOppTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->service = app(QuotationService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_accept_does_not_mutate_opportunity_state(): void
    {
        $lead = \App\Models\Lead::factory()->create();

        // Create through the service so the initial stage history row is
        // present, then move to the proposal stage (also through the
        // service).
        $opportunityService = app(\App\Services\OpportunityService::class);
        $opportunity = $opportunityService->create([
            'title' => 'Implementación CRM',
            'lead_id' => $lead->id,
            'estimated_amount' => 5000,
            'currency_code' => 'PEN',
        ], $this->actor);

        $propStage = \App\Models\PipelineStage::where('slug', 'propuesta-enviada')->firstOrFail();
        $opportunity = $opportunityService->changeStage($opportunity, $propStage, $this->actor);

        $beforeStage = $opportunity->stage_id;
        $beforeHistoryCount = $opportunity->stageHistories()->count();

        $quotation = $this->service->create([
            'lead_id' => $lead->id,
            'opportunity_id' => $opportunity->id,
            'items' => [
                [
                    'description' => 'Servicio',
                    'quantity' => 1,
                    'unit_price' => 5000,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $this->actor);

        $sent = $this->service->send($quotation, $this->actor);
        $accepted = $this->service->accept($sent, $this->actor);

        $this->assertSame('accepted', $accepted->status);

        $opportunity->refresh();

        // Opportunity stays untouched: still on the same stage, no
        // closed_at, no final_amount, no stage history beyond what was
        // there before the quotation was accepted.
        $this->assertSame($beforeStage, $opportunity->stage_id);
        $this->assertNull($opportunity->closed_at);
        $this->assertNull($opportunity->final_amount);
        $this->assertSame($beforeHistoryCount, $opportunity->stageHistories()->count());
    }

    public function test_accept_without_opportunity_works(): void
    {
        $quotation = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            // no opportunity_id
            'items' => [
                [
                    'description' => 'Standalone',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $this->actor);

        $sent = $this->service->send($quotation, $this->actor);
        $accepted = $this->service->accept($sent, $this->actor);

        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
    }
}
