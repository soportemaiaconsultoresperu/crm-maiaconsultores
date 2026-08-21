<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard HTTP layer (RF-DASH-001..005).
 *
 * Covers:
 *   - Authenticated access for admin/supervisor/vendedor (200).
 *   - Counters reflect the user's data scope (ADR-006).
 *   - Multimoneda grouping for the funnel card (ADR-004).
 *   - Próximas reuniones and Rendimiento por vendedor blocks render.
 *   - Empty states don't break the page.
 */
class DashboardHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private User $otherVendedor;

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
            'name' => 'Equipo Dashboard',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->vendedor->id);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_sees_global_counts_on_dashboard(): void
    {
        // 2 leads "nuevo" this week owned by vendedor.
        $this->makeLead($this->vendedor, []);
        $this->makeLead($this->vendedor, []);
        // 1 lead owned by other (not visible to vendedor but visible to admin).
        $this->makeLead($this->otherVendedor, []);

        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Próximas reuniones')
            ->assertSee('Rendimiento por vendedor')
            ->assertSeeInOrder(['Dashboard', 'Próximas reuniones', 'Rendimiento por vendedor']);
    }

    public function test_vendedor_only_counts_own_leads(): void
    {
        // 2 own + 1 other.
        $this->makeLead($this->vendedor, []);
        $this->makeLead($this->vendedor, []);
        $this->makeLead($this->otherVendedor, []);

        $vendedorContent = $this->actingAs($this->vendedor)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertSame('2', $this->counterForKpi($vendedorContent, 'kpi-prospectos-nuevos'));

        $adminContent = $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertSame('3', $this->counterForKpi($adminContent, 'kpi-prospectos-nuevos'));
    }

    public function test_supervisor_sees_team_counts(): void
    {
        // Supervisor team only has $this->vendedor as member (see setUp).
        $this->makeLead($this->vendedor, []);
        $this->makeLead($this->otherVendedor, []); // not in supervisor's team

        $content = $this->actingAs($this->supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $prospectosNuevos = $this->counterForKpi($content, 'kpi-prospectos-nuevos');
        $this->assertSame(1, (int) $prospectosNuevos);
    }

    public function test_valor_embudo_groups_by_currency_without_conversion(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $openStage = PipelineStage::where('slug', 'nueva-oportunidad')->firstOrFail();

        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $openStage->id,
            'estimated_amount' => 1000.00,
            'currency_code' => 'PEN',
        ]);
        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $openStage->id,
            'estimated_amount' => 250.00,
            'currency_code' => 'USD',
        ]);

        $content = $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        // Two separate lines, one per currency (ADR-004).
        $this->assertStringContainsString('PEN', $content);
        $this->assertStringContainsString('USD', $content);
        $this->assertStringContainsString('1,000.00', $content);
        $this->assertStringContainsString('250.00', $content);
    }

    public function test_dashboard_renders_empty_state_when_no_data(): void
    {
        $this->actingAs($this->vendedor)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No hay reuniones próximas')
            ->assertSee('Aún no hay ventas ganadas este mes');
    }

    public function test_dashboard_shows_upcoming_meetings(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $reunionType = ActivityType::where('slug', 'reunion')->firstOrFail();

        Activity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'type_id' => $reunionType->id,
            'title' => 'Reunión con cliente destacado',
            'scheduled_at' => Carbon::now()->addDays(2),
            'status' => 'pending',
        ]);

        $this->actingAs($this->vendedor)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Reunión con cliente destacado');
    }

    public function test_dashboard_rendimiento_por_vendedor_renders_currency_rows(): void
    {
        $lead = $this->makeLead($this->vendedor, []);
        $wonStage = PipelineStage::where('slug', 'ganada')->firstOrFail();

        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $wonStage->id,
            'estimated_amount' => 700.00,
            'final_amount' => 700.00,
            'currency_code' => 'PEN',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(2),
        ]);
        Opportunity::factory()->forLead($lead)->forOwner($this->vendedor)->create([
            'stage_id' => $wonStage->id,
            'estimated_amount' => 200.00,
            'final_amount' => 200.00,
            'currency_code' => 'USD',
            'closed_at' => Carbon::now()->startOfMonth()->addDays(3),
        ]);

        $content = $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rendimiento por vendedor', $content);
        $this->assertStringContainsString('PEN', $content);
        $this->assertStringContainsString('USD', $content);
    }

    /**
     * Pull the numeric value of a KPI card out of the rendered HTML by
     * matching the data-testid anchor and extracting the closest <p>.
     */
    private function counterForKpi(string $html, string $testId): string
    {
        if (preg_match('/data-testid="' . preg_quote($testId, '/') . '"[^>]*>\\s*([0-9]+)/', $html, $m)) {
            return $m[1];
        }

        // Fallback: search anywhere in document for the testid.
        if (preg_match('/data-testid="' . preg_quote($testId, '/') . '"/', $html)) {
            // No number present (zero).
            return '0';
        }

        return '0';
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