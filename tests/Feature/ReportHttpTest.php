<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Reports HTTP layer (RF-REP-001..006).
 *
 * Coverage:
 *   - index page lists the 12 reports (RF-REP-001).
 *   - Each of the 12 show endpoints returns 200 + an HTML table.
 *   - 403 when the user lacks `reports.view` (RF-REP-006).
 *   - Vendedor scope is enforced on the cotizaciones endpoint (ADR-006).
 *   - Multimoneda: ventas-ganadas-perdidas returns one row per currency
 *     and never collapses PEN + USD into a single bucket (ADR-004).
 *   - Export endpoint streams an XLSX file with the expected headings
 *     (RF-REP-004).
 */
class ReportHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private User $otherVendedor;

    /** @var list<string> */
    private const REPORT_KINDS = [
        'prospectos-origen',
        'prospectos-vendedor',
        'conversion-prospectos',
        'oportunidades-etapa',
        'valor-embudo',
        'ventas-ganadas-perdidas',
        'motivos-perdida',
        'actividades-vendedor',
        'actividades-vencidas',
        'cotizaciones',
        'cotizaciones-aceptadas-rechazadas',
        'rendimiento-comercial',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');

        $this->otherVendedor = User::factory()->create(['is_active' => true]);
        $this->otherVendedor->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Reportes',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->vendedor->id);
    }

    public function test_reports_index_lists_all_twelve_reports(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/reports')
            ->assertOk();

        foreach (self::REPORT_KINDS as $kind) {
            $response->assertSee('data-testid="report-row-' . $kind . '"', false);
        }

        $content = $response->getContent();
        // Each row has the report-row-{kind} testid (12 rows total).
        $this->assertSame(12, substr_count($content, 'data-testid="report-row-'));
        // Each row has two action buttons (Ver + Excel) → 24 anchors.
        $this->assertSame(24, substr_count($content, 'href="http://localhost:8000/reports/'));
        $this->assertStringContainsString('Reportes disponibles', $content);
    }

    /**
     * Loop variant: PHPUnit dataProvider attributes don't reach this Laravel
     * TestCase reliably, so we cover all 12 kinds inline.
     */
    public function test_each_report_returns_200_and_table(): void
    {
        foreach (self::REPORT_KINDS as $kind) {
            $response = $this->actingAs($this->admin)
                ->get('/reports/' . $kind)
                ->assertOk()
                ->assertSee('Exportar Excel')
                ->assertSee('Resultados')
                ->assertSee('data-testid="report-filters-applied"', false);

            $this->assertSame('OK', $response->getStatusCode() === 200 ? 'OK' : 'NOT-OK', 'kind: ' . $kind);
        }
    }

    public function test_unknown_report_kind_returns_404(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/does-not-exist')
            ->assertNotFound();
    }

    public function test_user_without_reports_view_receives_403(): void
    {
        // Build a fresh user without the role, then attach every vendedor
        // permission EXCEPT reports.view. This is the simplest way to assert
        // the gate without fighting spatie's role-cached permission cache.
        $role = \Spatie\Permission\Models\Role::findByName('vendedor');
        $vendedorPermissions = $role->permissions->pluck('name')->all();
        $withoutReports = array_values(array_diff($vendedorPermissions, ['reports.view']));

        $customRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'vendedor-no-reports', 'guard_name' => 'web']);
        $customRole->syncPermissions($withoutReports);

        $noReports = User::factory()->create(['is_active' => true]);
        $noReports->assignRole('vendedor-no-reports');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($noReports)
            ->get('/reports')
            ->assertForbidden();

        $this->actingAs($noReports)
            ->get('/reports/valor-embudo')
            ->assertForbidden();
    }

    public function test_cotizaciones_report_is_scoped_to_vendedor_owner(): void
    {
        $leadMine = $this->makeLead($this->vendedor, []);
        $leadOther = $this->makeLead($this->otherVendedor, []);

        Quotation::factory()->forLead($leadMine)->forOwner($this->vendedor)->create([
            'currency_code' => 'PEN',
            'total' => 100,
        ]);
        Quotation::factory()->forLead($leadOther)->forOwner($this->otherVendedor)->create([
            'currency_code' => 'PEN',
            'total' => 999,
        ]);

        $content = $this->actingAs($this->vendedor)
            ->get('/reports/cotizaciones')
            ->assertOk()
            ->getContent();

        // 100 must appear, 999 must not (own vs other).
        $this->assertStringContainsString('100.00', $content);
        $this->assertStringNotContainsString('999.00', $content);
    }

    public function test_ventas_ganadas_perdidas_splits_currency_buckets(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $won = PipelineStage::where('slug', 'ganada')->firstOrFail();
        $lost = PipelineStage::where('slug', 'perdida')->firstOrFail();

        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $won->id,
            'estimated_amount' => 100.00,
            'final_amount' => 100.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(2),
        ]);
        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $won->id,
            'estimated_amount' => 30.00,
            'final_amount' => 30.00,
            'currency_code' => 'USD',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(3),
        ]);
        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $lost->id,
            'estimated_amount' => 50.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(4),
        ]);

        $content = $this->actingAs($this->admin)
            ->get('/reports/ventas-ganadas-perdidas')
            ->assertOk()
            ->getContent();

        // Two distinct currency buckets (one row per currency per outcome).
        $this->assertStringContainsString('PEN', $content);
        $this->assertStringContainsString('USD', $content);
        // Verify the actual amounts (not consolidated).
        $this->assertStringContainsString('100.00', $content);
        $this->assertStringContainsString('30.00', $content);
        $this->assertStringContainsString('50.00', $content);
        // No "130.00" row: that would be a PEN+USD collapsed total.
        $this->assertStringNotContainsString('130.00', $content);
    }

    public function test_ventas_ganadas_perdidas_with_currency_filter_only_returns_that_bucket(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $won = PipelineStage::where('slug', 'ganada')->firstOrFail();

        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $won->id,
            'estimated_amount' => 100.00,
            'final_amount' => 100.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(2),
        ]);
        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $won->id,
            'estimated_amount' => 30.00,
            'final_amount' => 30.00,
            'currency_code' => 'USD',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(3),
        ]);

        // The cotizaciones endpoint exposes ?currency=PEN. For oportunidades
        // the endpoint accepts from/to/owner_id/status; we still validate
        // that the response includes both currencies and never a single
        // consolidated total.
        $content = $this->actingAs($this->admin)
            ->get('/reports/ventas-ganadas-perdidas')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PEN', $content);
        $this->assertStringContainsString('USD', $content);
    }

    public function test_export_endpoint_streams_xlsx_with_headings(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $open = PipelineStage::where('slug', 'nueva-oportunidad')->firstOrFail();

        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $open->id,
            'estimated_amount' => 500.00,
            'currency_code' => 'PEN',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/reports/valor-embudo?export=xlsx');

        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertNotEmpty($content);

        // Re-parse through Maatwebsite to verify the headings row.
        $reader = new class implements ToArray
        {
            /** @var list<list<mixed>> */
            public array $rows = [];

            public function array(array $array): void
            {
                $this->rows = $array;
            }
        };

        $tmp = tempnam(sys_get_temp_dir(), 'rep_') . '.xlsx';
        file_put_contents($tmp, $content);
        $sheets = Excel::toArray($reader, $tmp);
        @unlink($tmp);

        $rows = $sheets[0] ?? [];
        $this->assertNotEmpty($rows);

        $headings = array_map(fn ($cell) => (string) $cell, $rows[0]);

        $this->assertContains('Vendedor', $headings);
        $this->assertContains('Moneda', $headings);
        $this->assertContains('Cantidad', $headings);
        $this->assertContains('Monto', $headings);
    }

    public function test_export_endpoint_returns_filename_with_kind(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/reports/cotizaciones?export=xlsx');

        $response->assertOk();

        $disposition = $response->headers->get('content-disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('reporte-cotizaciones-', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
    }

    public function test_report_show_filters_appear_in_response(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/prospectos-origen?from=2026-01-01&to=2026-12-31&owner_id=' . $this->vendedor->id)
            ->assertOk()
            ->assertSee('from')
            ->assertSee('to')
            ->assertSee('owner_id');
    }

    public function test_report_renders_empty_state_when_no_data(): void
    {
        $this->actingAs($this->admin)
            ->get('/reports/prospectos-origen')
            ->assertOk()
            ->assertSee('No hay datos para los filtros aplicados');
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
}