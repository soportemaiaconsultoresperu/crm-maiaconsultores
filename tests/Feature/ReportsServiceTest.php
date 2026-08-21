<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Services\ReportsService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reports (RF-REP-001..006). One test per public method plus a multimoneda
 * regression on oportunidadesPorEtapa. Scope (ADR-006) and multimoneda
 * (ADR-004) are the two cross-cutting invariants.
 */
class ReportsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportsService $service;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(ReportsService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');

        $this->other = User::factory()->create(['is_active' => true]);
        $this->other->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach([$this->vendedor->id, $this->other->id]);
    }

    public function test_prospectos_por_origen_groups_and_calculates_percentage(): void
    {
        $sourceWeb = LeadSource::where('slug', 'web')->firstOrFail();
        $sourceRef = LeadSource::where('slug', 'referido')->firstOrFail();

        $this->makeLead($this->vendedor, ['source_id' => $sourceWeb->id]);
        $this->makeLead($this->vendedor, ['source_id' => $sourceWeb->id]);
        $this->makeLead($this->vendedor, ['source_id' => $sourceRef->id]);

        $rows = $this->service->prospectosPorOrigen($this->vendedor);

        $bySource = collect($rows)->keyBy('source_id');

        $this->assertSame(2, $bySource[$sourceWeb->id]['count']);
        $this->assertSame(66.67, $bySource[$sourceWeb->id]['percentage']);
        $this->assertSame(1, $bySource[$sourceRef->id]['count']);
        $this->assertSame(33.33, $bySource[$sourceRef->id]['percentage']);
    }

    public function test_prospectos_por_origen_is_scoped(): void
    {
        $source = LeadSource::where('slug', 'web')->firstOrFail();

        $this->makeLead($this->vendedor, ['source_id' => $source->id]);
        $this->makeLead($this->other, ['source_id' => $source->id]);

        $rows = $this->service->prospectosPorOrigen($this->vendedor);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_prospectos_por_vendedor_returns_status_mix(): void
    {
        $nuevo = LeadStatus::where('slug', 'nuevo')->firstOrFail();
        $calificado = LeadStatus::where('slug', 'calificado')->firstOrFail();

        $this->makeLead($this->vendedor, ['status_id' => $nuevo->id]);
        $this->makeLead($this->vendedor, ['status_id' => $calificado->id]);
        $this->makeLead($this->other, ['status_id' => $nuevo->id]);

        $rows = $this->service->prospectosPorVendedor($this->vendedor);

        $byOwner = collect($rows)->keyBy('owner_id');

        $this->assertSame(2, $byOwner[$this->vendedor->id]['count']);
        $this->assertCount(2, $byOwner[$this->vendedor->id]['statuses']);
        $this->assertArrayNotHasKey($this->other->id, $byOwner);
    }

    public function test_conversion_de_prospectos_computes_monthly_rate(): void
    {
        $lead = $this->makeLead($this->vendedor, [
            'entered_at' => Carbon::now()->subMonth()->startOfMonth(),
        ]);

        Customer::factory()->create([
            'owner_id' => $this->vendedor->id,
            'converted_from_lead_id' => $lead->id,
            'converted_at' => Carbon::now()->subMonth()->startOfMonth()->addDays(5),
        ]);

        // A second lead in the same window without conversion.
        $this->makeLead($this->vendedor, [
            'entered_at' => Carbon::now()->subMonth()->startOfMonth()->addDay(),
        ]);

        $rows = $this->service->conversionDeProspectos($this->vendedor);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['leads']);
        $this->assertSame(1, $rows[0]['converted']);
        $this->assertSame(50.0, $rows[0]['rate']);
    }

    public function test_oportunidades_por_etapa_preserves_separate_currency_buckets(): void
    {
        $lead = $this->makeLead($this->vendedor);
        $stage = PipelineStage::where('slug', 'propuesta-enviada')->firstOrFail();

        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $stage->id,
            'estimated_amount' => 1000.00,
            'currency_code' => 'PEN',
        ]);
        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $stage->id,
            'estimated_amount' => 250.00,
            'currency_code' => 'USD',
        ]);

        $rows = $this->service->oportunidadesPorEtapa($this->vendedor);

        // Two rows: one bucket per currency under the same stage.
        $this->assertCount(2, $rows);
        $currencies = collect($rows)->pluck('currency_code')->sort()->values()->all();
        $this->assertSame(['PEN', 'USD'], $currencies);

        $pen = collect($rows)->firstWhere('currency_code', 'PEN');
        $usd = collect($rows)->firstWhere('currency_code', 'USD');

        $this->assertEqualsWithDelta(1000.00, $pen['amount'], 0.01);
        $this->assertEqualsWithDelta(250.00, $usd['amount'], 0.01);
        $this->assertSame(1, $pen['count']);
        $this->assertSame(1, $usd['count']);
    }

    public function test_valor_del_embudo_is_open_only_and_grouped_by_owner_currency(): void
    {
        $lead = $this->makeLead($this->vendedor);
        $open = PipelineStage::where('slug', 'nueva-oportunidad')->firstOrFail();
        $won = PipelineStage::where('slug', 'ganada')->firstOrFail();

        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $open->id,
            'estimated_amount' => 500.00,
            'currency_code' => 'PEN',
        ]);
        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $won->id,
            'estimated_amount' => 999.00,
            'currency_code' => 'PEN',
        ]);

        $rows = $this->service->valorDelEmbudo($this->vendedor);

        $pen = collect($rows)->firstWhere('currency_code', 'PEN');

        $this->assertNotNull($pen);
        $this->assertSame($this->vendedor->id, $pen['owner_id']);
        $this->assertSame(1, $pen['count']);
        $this->assertEqualsWithDelta(500.00, $pen['amount'], 0.01);
    }

    public function test_ventas_ganadas_y_perdidas_separates_outcomes_with_currency(): void
    {
        $lead = $this->makeLead($this->vendedor);
        $won = PipelineStage::where('slug', 'ganada')->firstOrFail();
        $lost = PipelineStage::where('slug', 'perdida')->firstOrFail();

        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $won->id,
            'estimated_amount' => 100.00,
            'final_amount' => 100.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(2),
        ]);
        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $lost->id,
            'estimated_amount' => 50.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(3),
        ]);
        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $won->id,
            'estimated_amount' => 30.00,
            'final_amount' => 30.00,
            'currency_code' => 'USD',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(4),
        ]);

        $rows = $this->service->ventasGanadasYPerdidas($this->vendedor);

        $won = collect($rows)->where('outcome', 'won');
        $lost = collect($rows)->where('outcome', 'lost');

        $this->assertCount(2, $won);
        $this->assertCount(1, $lost);
        $this->assertSame(100.0, $won->firstWhere('currency_code', 'PEN')['amount']);
        $this->assertSame(30.0, $won->firstWhere('currency_code', 'USD')['amount']);
    }

    public function test_motivos_de_perdida_groups_by_loss_reason_with_currency(): void
    {
        $lead = $this->makeLead($this->vendedor);
        $lost = PipelineStage::where('slug', 'perdida')->firstOrFail();

        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $lost->id,
            'loss_reason_id' => \App\Models\LossReason::where('slug', 'precio')->first()->id,
            'estimated_amount' => 100.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(1),
        ]);
        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $lost->id,
            'loss_reason_id' => \App\Models\LossReason::where('slug', 'competencia')->first()->id,
            'estimated_amount' => 200.00,
            'currency_code' => 'USD',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(1),
        ]);

        $rows = $this->service->motivosDePerdida($this->vendedor);

        $this->assertCount(2, $rows);
        $byReason = collect($rows)->keyBy('loss_reason');

        $this->assertSame(100.0, $byReason['Precio']['amount']);
        $this->assertSame('PEN', $byReason['Precio']['currency_code']);
        $this->assertSame(200.0, $byReason['Competencia']['amount']);
        $this->assertSame('USD', $byReason['Competencia']['currency_code']);
    }

    public function test_actividades_por_vendedor_returns_status_mix(): void
    {
        $lead = $this->makeLead($this->vendedor);

        $this->makeActivity($lead, $this->vendedor, ['status' => 'pending']);
        $this->makeActivity($lead, $this->vendedor, ['status' => 'pending']);
        $this->makeActivity($lead, $this->vendedor, ['status' => 'completed']);
        $this->makeActivity($lead, $this->other, ['status' => 'pending']);

        $rows = $this->service->actividadesPorVendedor($this->vendedor);

        $byOwner = collect($rows)->keyBy('owner_id');

        $this->assertSame(3, $byOwner[$this->vendedor->id]['count']);
        $this->assertCount(2, $byOwner[$this->vendedor->id]['statuses']);
        $this->assertArrayNotHasKey($this->other->id, $byOwner);
    }

    public function test_actividades_vencidas_uses_age_bucket(): void
    {
        $lead = $this->makeLead($this->vendedor);

        // Both overdue activities inside the 2-3 day bucket.
        $this->makeActivity($lead, $this->vendedor, [
            'status' => 'pending',
            'scheduled_at' => Carbon::now()->subDays(2),
        ]);
        $this->makeActivity($lead, $this->vendedor, [
            'status' => 'overdue',
            'scheduled_at' => Carbon::now()->subDays(3),
        ]);

        $rows = $this->service->actividadesVencidas($this->vendedor);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(2, $row['count']);
        $this->assertGreaterThanOrEqual(2, $row['age_days']);
        $this->assertSame('2-3', $row['age_bucket']);
    }

    public function test_cotizaciones_emitidas_groups_by_status_and_currency(): void
    {
        $lead = $this->makeLead($this->vendedor);

        $this->makeQuotation($lead, $this->vendedor, ['status' => 'draft', 'currency_code' => 'PEN', 'total' => 100]);
        $this->makeQuotation($lead, $this->vendedor, ['status' => 'sent', 'currency_code' => 'PEN', 'total' => 200]);
        $this->makeQuotation($lead, $this->vendedor, ['status' => 'accepted', 'currency_code' => 'USD', 'total' => 50]);

        $rows = $this->service->cotizacionesEmitidas($this->vendedor);

        $byKey = collect($rows)->keyBy(fn ($r) => $r['status'].'|'.$r['currency_code']);

        $this->assertSame(1, $byKey['draft|PEN']['count']);
        $this->assertSame(100.0, $byKey['draft|PEN']['amount']);
        $this->assertSame(1, $byKey['sent|PEN']['count']);
        $this->assertSame(200.0, $byKey['sent|PEN']['amount']);
        $this->assertSame(1, $byKey['accepted|USD']['count']);
        $this->assertSame(50.0, $byKey['accepted|USD']['amount']);
    }

    public function test_cotizaciones_aceptadas_y_rechazadas_filters_to_two_outcomes(): void
    {
        $lead = $this->makeLead($this->vendedor);

        $this->makeQuotation($lead, $this->vendedor, ['status' => 'accepted', 'currency_code' => 'PEN', 'total' => 100]);
        $this->makeQuotation($lead, $this->vendedor, ['status' => 'rejected', 'currency_code' => 'PEN', 'total' => 50]);
        $this->makeQuotation($lead, $this->vendedor, ['status' => 'draft', 'currency_code' => 'PEN', 'total' => 999]);

        $rows = $this->service->cotizacionesAceptadasYRechazadas($this->vendedor);

        $this->assertCount(2, $rows);

        $byOutcome = collect($rows)->keyBy('outcome');

        $this->assertSame(1, $byOutcome['accepted']['count']);
        $this->assertSame(100.0, $byOutcome['accepted']['amount']);
        $this->assertSame(1, $byOutcome['rejected']['count']);
        $this->assertSame(50.0, $byOutcome['rejected']['amount']);
    }

    public function test_rendimiento_comercial_returns_per_owner_row_with_currency(): void
    {
        $lead = $this->makeLead($this->vendedor);
        $won = PipelineStage::where('slug', 'ganada')->firstOrFail();

        $this->makeOpportunity($lead, $this->vendedor, [
            'stage_id' => $won->id,
            'estimated_amount' => 700.00,
            'final_amount' => 700.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(1),
        ]);
        $this->makeQuotation($lead, $this->vendedor, [
            'status' => 'accepted',
            'currency_code' => 'PEN',
            'total' => 700,
        ]);

        $rows = $this->service->rendimientoComercial($this->vendedor);

        $vendedorRow = collect($rows)->firstWhere('owner_id', $this->vendedor->id);

        $this->assertNotNull($vendedorRow);
        $this->assertGreaterThanOrEqual(1, $vendedorRow['leads_count']);
        $this->assertSame(1, $vendedorRow['opportunities_won']);
        $this->assertSame(700.0, $vendedorRow['won_amount_by_currency']['PEN']['amount']);
        $this->assertSame(1, $vendedorRow['quotations_accepted_count']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLead(User $owner, array $overrides = []): Lead
    {
        $source = LeadSource::where('slug', 'web')->firstOrFail();
        $status = LeadStatus::where('slug', 'nuevo')->firstOrFail();

        return Lead::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'source_id' => $source->id,
            'status_id' => $status->id,
            'entered_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOpportunity(Lead $lead, User $owner, array $overrides = []): Opportunity
    {
        $stage = PipelineStage::where('slug', 'nueva-oportunidad')->firstOrFail();

        return Opportunity::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => null,
            'owner_id' => $owner->id,
            'stage_id' => $stage->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActivity(Lead $lead, User $owner, array $overrides = []): Activity
    {
        $type = ActivityType::where('slug', 'llamada')->firstOrFail();

        return Activity::factory()->forLead($lead)->forOwner($owner)->create(array_merge([
            'type_id' => $type->id,
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeQuotation(Lead $lead, User $owner, array $overrides = []): Quotation
    {
        return Quotation::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => null,
            'opportunity_id' => null,
            'owner_id' => $owner->id,
        ], $overrides));
    }
}
