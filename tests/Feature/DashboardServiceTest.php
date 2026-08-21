<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use App\Services\DashboardService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard aggregates (RF-DASH-001..003). Verifies:
 *   - Data scope (ADR-006): vendedor / supervisor / admin see different slices.
 *   - Multimoneda (ADR-004): sums stay grouped per currency; no conversion.
 *   - Overdue semantics: pending-in-past AND status=overdue both count.
 *   - Next-meeting ordering: future asc, limit 5.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(DashboardService::class);

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

    public function test_vendedor_dashboard_only_aggregates_own_records(): void
    {
        $ownLead = $this->makeLead($this->vendedor, ['first_name' => 'Propio']);
        $this->makeLead($this->other, ['first_name' => 'Ajeno']);
        $this->makeActivityFor($ownLead, $this->vendedor, ['title' => 'Llamada'], daysFromNow: 1);

        $payload = $this->service->forUser($this->vendedor);

        // 1 lead in 'nuevo' for this week + 0 sin contactar (because we have an activity).
        $this->assertSame(1, $payload['prospectos_nuevos']);
        $this->assertSame(0, $payload['prospectos_sin_contactar']);
        $this->assertSame(1, $payload['actividades_pendientes']);
    }

    public function test_supervisor_dashboard_aggregates_team_records(): void
    {
        $this->makeLead($this->vendedor, ['first_name' => 'Vendedor']);
        $this->makeLead($this->other, ['first_name' => 'Otro']);
        $this->makeLead($this->supervisor, ['first_name' => 'Sup']);

        $payload = $this->service->forUser($this->supervisor);

        // Team has two vendedores + the supervisor themselves; the "other"
        // vendedor is also attached, so all 3 leads count.
        $this->assertSame(3, $payload['prospectos_nuevos']);
    }

    public function test_admin_dashboard_is_unrestricted(): void
    {
        $this->makeLead($this->vendedor, ['first_name' => 'A']);
        $this->makeLead($this->other, ['first_name' => 'B']);
        $this->makeLead($this->supervisor, ['first_name' => 'C']);

        // Add a fourth owner that is NOT in the supervisor's team.
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->assignRole('vendedor');
        $this->makeLead($outsider, ['first_name' => 'D']);

        $payload = $this->service->forUser($this->admin);

        $this->assertSame(4, $payload['prospectos_nuevos']);
    }

    public function test_funnel_value_preserves_separate_currency_buckets(): void
    {
        $leadA = $this->makeLead($this->vendedor, ['first_name' => 'A']);
        $leadB = $this->makeLead($this->vendedor, ['first_name' => 'B']);

        $this->makeOpportunity($leadA, $this->vendedor, [
            'estimated_amount' => 1500.00,
            'currency_code' => 'PEN',
        ]);
        $this->makeOpportunity($leadB, $this->vendedor, [
            'estimated_amount' => 200.00,
            'currency_code' => 'USD',
        ]);

        $payload = $this->service->forUser($this->vendedor);

        $buckets = $payload['valor_embudo_by_currency'];

        // Both currencies are kept as separate buckets (ADR-004).
        $this->assertArrayHasKey('PEN', $buckets);
        $this->assertArrayHasKey('USD', $buckets);
        $this->assertEqualsWithDelta(1500.00, (float) $buckets['PEN'], 0.01);
        $this->assertEqualsWithDelta(200.00, (float) $buckets['USD'], 0.01);
    }

    public function test_overdue_count_includes_both_pending_past_and_overdue_status(): void
    {
        $lead = $this->makeLead($this->vendedor, ['first_name' => 'Con actividad']);

        // pending + scheduled in the past (scheduler has not yet flagged it)
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Llamada atrasada',
            'status' => 'pending',
            'scheduled_at' => Carbon::now()->subDays(2),
        ]);

        // status=overdue but scheduled_at still in the future
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Marcada como vencida',
            'status' => 'overdue',
            'scheduled_at' => Carbon::now()->addHours(2),
        ]);

        // pending + future (NOT overdue) — should NOT be counted
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Futura',
            'status' => 'pending',
            'scheduled_at' => Carbon::now()->addDay(),
        ]);

        $payload = $this->service->forUser($this->vendedor);

        $this->assertSame(2, $payload['actividades_vencidas']);
        $this->assertSame(1, $payload['actividades_pendientes']);
    }

    public function test_proximas_reuniones_are_future_ordered_ascending(): void
    {
        $lead = $this->makeLead($this->vendedor, ['first_name' => 'Reuniones']);

        $reunion = ActivityType::query()->where('slug', 'reunion')->firstOrFail();

        // Out of order on purpose to validate asc ordering.
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Reunión lejana',
            'type_id' => $reunion->id,
            'scheduled_at' => Carbon::now()->addDays(5),
        ]);
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Reunión cercana',
            'type_id' => $reunion->id,
            'scheduled_at' => Carbon::now()->addHours(3),
        ]);
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Reunión media',
            'type_id' => $reunion->id,
            'scheduled_at' => Carbon::now()->addDays(2),
        ]);

        // past reunion must NOT appear
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Reunión pasada',
            'type_id' => $reunion->id,
            'scheduled_at' => Carbon::now()->subDay(),
        ]);

        // non-reunion type (llamada) future must NOT appear
        $llamada = ActivityType::query()->where('slug', 'llamada')->firstOrFail();
        $this->makeActivityFor($lead, $this->vendedor, [
            'title' => 'Llamada futura',
            'type_id' => $llamada->id,
            'scheduled_at' => Carbon::now()->addHours(2),
        ]);

        $payload = $this->service->forUser($this->vendedor);

        $this->assertCount(3, $payload['proximas_reuniones']);

        $titles = array_column($payload['proximas_reuniones'], 'title');
        $this->assertSame(
            ['Reunión cercana', 'Reunión media', 'Reunión lejana'],
            $titles,
        );
    }

    /**
     * Convenience factory for leads that mirrors what the LeadFactory
     * produces (uses catalog rows from the seeder).
     *
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
    private function makeActivityFor(Lead $lead, User $owner, array $overrides = [], ?int $daysFromNow = null): Activity
    {
        $type = ActivityType::where('slug', 'llamada')->firstOrFail();
        $scheduled = isset($overrides['scheduled_at'])
            ? Carbon::parse($overrides['scheduled_at'])
            : ($daysFromNow !== null ? Carbon::now()->addDays($daysFromNow) : Carbon::now()->addDay());

        unset($overrides['scheduled_at']);

        return Activity::factory()->forLead($lead)->forOwner($owner)->create(array_merge([
            'type_id' => $type->id,
            'scheduled_at' => $scheduled,
            'status' => 'pending',
        ], $overrides));
    }
}
