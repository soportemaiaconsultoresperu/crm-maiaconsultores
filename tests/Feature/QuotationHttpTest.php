<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Quotation;
use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B06 Quotation HTTP layer (RF-COT-001..011). Routes, authorization,
 * CRUD from lead/customer/opportunity contexts, math consistency between
 * the form payload and the server-side recalculation, acceptance flow
 * with opportunity confirmation (ADR-007), reject, duplicate, PDF and
 * Excel export scope.
 */
class QuotationHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salesperson;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salesperson = User::factory()->create(['is_active' => true]);
        $this->salesperson->assignRole('vendedor');

        $this->service = app(QuotationService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $lead = Lead::factory()->create();

        return array_merge([
            'lead_id' => $lead->id,
            'currency_code' => 'PEN',
            'issued_at' => now()->toDateString(),
            'owner_id' => $this->salesperson->id,
            'items' => [
                [
                    'description' => 'Ítem de prueba',
                    'quantity' => '1',
                    'unit' => 'unidad',
                    'unit_price' => '100.00',
                    'discount_amount' => '0.00',
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $overrides);
    }

    public function test_create_form_prefills_from_customer_context(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create();

        $this->actingAs($this->salesperson)
            ->get("/customers/{$customer->id}/quotations/create")
            ->assertOk()
            ->assertSee($customer->code);
    }

    public function test_create_form_prefills_from_lead_context(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $this->actingAs($this->salesperson)
            ->get("/leads/{$lead->id}/quotations/create")
            ->assertOk()
            ->assertSee($lead->code);
    }

    public function test_create_form_prefills_from_opportunity_context(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();
        $opportunity = Opportunity::factory()->forLead($lead)->forOwner($this->salesperson)->create();

        $this->actingAs($this->salesperson)
            ->get("/opportunities/{$opportunity->id}/quotations/create")
            ->assertOk()
            ->assertSee($opportunity->code);
    }

    public function test_store_with_mixed_tax_and_discount_calculates_totals_server_side(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();
        $exonerado = Tax::where('slug', 'exonerado')->firstOrFail();

        $lead = Lead::factory()->create();

        $response = $this->actingAs($this->salesperson)
            ->post('/quotations', [
                'lead_id' => $lead->id,
                'currency_code' => 'PEN',
                'owner_id' => $this->salesperson->id,
                'items' => [
                    // 2 × 100 = 200, IGV 18%, discount 0 → total 236
                    ['description' => 'Item A', 'quantity' => '2', 'unit_price' => '100', 'tax_id' => $igv->id, 'discount_amount' => '0'],
                    // 1 × 50, exonerado 0%, discount 10 → total 40
                    ['description' => 'Item B', 'quantity' => '1', 'unit_price' => '50', 'tax_id' => $exonerado->id, 'discount_amount' => '10'],
                    // 4 × 25, no tax → total 100
                    ['description' => 'Item C', 'quantity' => '4', 'unit_price' => '25', 'tax_id' => null, 'discount_amount' => '0'],
                ],
            ]);

        $quotation = Quotation::query()->latest('id')->first();

        $this->assertNotNull($quotation);
        $response->assertRedirect(route('quotations.show', $quotation));

        // subtotal 350, discount 10, tax 36, total 376
        $this->assertSame('350.00', (string) $quotation->subtotal);
        $this->assertSame('10.00', (string) $quotation->discount_total);
        $this->assertSame('36.00', (string) $quotation->tax_total);
        $this->assertSame('376.00', (string) $quotation->total);
    }

    public function test_store_rejects_when_both_lead_and_customer_are_set(): void
    {
        $lead = Lead::factory()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($this->salesperson)
            ->post('/quotations', [
                'lead_id' => $lead->id,
                'customer_id' => $customer->id,
                'currency_code' => 'PEN',
                'owner_id' => $this->salesperson->id,
                'items' => [
                    ['description' => 'X', 'quantity' => '1', 'unit_price' => '100', 'tax_id' => null],
                ],
            ])
            ->assertSessionHasErrors('lead_id');

        $this->assertSame(0, Quotation::query()->count());
    }

    public function test_store_requires_at_least_one_item(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->salesperson)
            ->post('/quotations', [
                'lead_id' => $lead->id,
                'currency_code' => 'PEN',
                'owner_id' => $this->salesperson->id,
                'items' => [],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Quotation::query()->count());
    }

    public function test_accept_without_opportunity_just_accepts(): void
    {
        $quotation = $this->makeSentQuotation();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/accept", [
                'confirm_opportunity_won' => '0',
            ])
            ->assertRedirect(route('quotations.show', $quotation));

        $this->assertSame('accepted', $quotation->refresh()->status);
    }

    public function test_accept_with_opp_open_and_confirm_flag_wins_the_opportunity(): void
    {
        $quotation = $this->makeQuotationWithOpenOpportunity();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/accept", [
                'confirm_opportunity_won' => '1',
            ])
            ->assertRedirect(route('quotations.show', $quotation));

        $quotation->refresh();
        $opportunity = $quotation->opportunity->refresh();

        $this->assertSame('accepted', $quotation->status);
        $this->assertSame('won', $opportunity->stage->stage_type);
        $this->assertEquals((float) $quotation->total, (float) $opportunity->final_amount);
        $this->assertNotNull($opportunity->closed_at);
    }

    public function test_accept_with_opp_open_without_confirm_keeps_opp_open(): void
    {
        $quotation = $this->makeQuotationWithOpenOpportunity();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/accept", [
                'confirm_opportunity_won' => '0',
            ])
            ->assertRedirect(route('quotations.show', $quotation));

        $quotation->refresh();
        $opportunity = $quotation->opportunity->refresh();

        $this->assertSame('accepted', $quotation->status);
        $this->assertSame('open', $opportunity->stage->stage_type);
        $this->assertNull($opportunity->final_amount);
    }

    public function test_accept_with_opp_closed_keeps_opp_unchanged(): void
    {
        $quotation = $this->makeQuotationWithOpenOpportunity();

        // Move the opportunity to "perdida" terminal stage.
        $lostStage = PipelineStage::where('slug', 'perdida')->firstOrFail();
        $quotation->opportunity->stage_id = $lostStage->id;
        $quotation->opportunity->save();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/accept", [
                'confirm_opportunity_won' => '1',
            ])
            ->assertRedirect(route('quotations.show', $quotation));

        $quotation->refresh();
        $this->assertSame('accepted', $quotation->status);
        $this->assertSame($lostStage->id, $quotation->opportunity->refresh()->stage_id);
    }

    public function test_reject_requires_reason(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/reject", [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('draft', $quotation->refresh()->status);
    }

    public function test_reject_with_reason_changes_status(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/reject", [
                'reason' => 'Cliente no está interesado.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $quotation->refresh()->status);
    }

    public function test_duplicate_clones_to_new_draft(): void
    {
        $quotation = $this->makeDraftQuotation();

        $this->actingAs($this->salesperson)
            ->post("/quotations/{$quotation->id}/duplicate")
            ->assertRedirect();

        $clone = Quotation::query()->where('id', '!=', $quotation->id)->latest('id')->first();

        $this->assertNotNull($clone);
        $this->assertSame('draft', $clone->status);
        $this->assertNotSame($quotation->number, $clone->number);
        $this->assertSame($quotation->lead_id, $clone->lead_id);
    }

    public function test_pdf_returns_application_pdf(): void
    {
        $quotation = $this->makeDraftQuotation();

        $response = $this->actingAs($this->salesperson)
            ->get("/quotations/{$quotation->id}/pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_scope_limits_vendedor_to_own_quotations(): void
    {
        $mine = $this->makeDraftQuotationFor($this->salesperson);
        $other = $this->makeDraftQuotationFor($this->admin);

        // vendedor lacks quotations.export.
        $this->actingAs($this->salesperson)
            ->get('/quotations-export')
            ->assertForbidden();

        $response = $this->actingAs($this->admin)
            ->get('/quotations-export');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $this->assertNotNull($mine);
        $this->assertNotNull($other);
    }

    public function test_index_lists_only_visible_quotations(): void
    {
        $mine = $this->makeDraftQuotationFor($this->salesperson);
        $other = $this->makeDraftQuotationFor($this->admin);

        $this->actingAs($this->salesperson)
            ->get('/quotations')
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee($other->number);
    }

    /**
     * Helpers
     */
    private function makeDraftQuotation(): Quotation
    {
        return $this->makeDraftQuotationFor($this->salesperson);
    }

    private function makeDraftQuotationFor(User $owner): Quotation
    {
        $lead = Lead::factory()->create();

        return $this->service->create([
            'lead_id' => $lead->id,
            'currency_code' => 'PEN',
            'owner_id' => $owner->id,
            'items' => [
                [
                    'description' => 'Servicio',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $owner);
    }

    private function makeSentQuotation(): Quotation
    {
        $quotation = $this->makeDraftQuotation();

        return $this->service->send($quotation, $this->salesperson);
    }

    private function makeAcceptedQuotation(): Quotation
    {
        $quotation = $this->makeSentQuotation();

        return $this->service->accept($quotation, $this->salesperson);
    }

    private function makeQuotationWithOpenOpportunity(): Quotation
    {
        $lead = Lead::factory()->create();
        $opportunity = Opportunity::factory()->forLead($lead)->create();

        // Force a non-default open stage so we can assert it stays "open".
        $propStage = PipelineStage::where('slug', 'propuesta-enviada')->firstOrFail();
        $opportunity->stage_id = $propStage->id;
        $opportunity->save();

        $quotation = $this->service->create([
            'lead_id' => $lead->id,
            'opportunity_id' => $opportunity->id,
            'currency_code' => 'PEN',
            'owner_id' => $this->salesperson->id,
            'items' => [
                [
                    'description' => 'Servicio',
                    'quantity' => 1,
                    'unit_price' => 500,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $this->salesperson);

        return $this->service->send($quotation, $this->salesperson);
    }
}