<?php

namespace Tests\Feature;

use App\Models\{Contact, Customer, SupportCategory, SupportChannel, SupportPriority, SupportResolutionCycle, SupportStatus, SupportStatusPeriod, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportDashboardService, SupportTicketService};
use Database\Seeders\{SupportCatalogSeeder, SupportPermissionsSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportDashboardFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SupportCatalogSeeder::class);
        $this->seed(SupportPermissionsSeeder::class);
    }

    public function test_dashboard_totals_and_csv_respect_own_team_and_any_scope(): void
    {
        $creator = $this->actor(['support.create']);
        $own = $this->actor(['support.reports.view', 'support.view.own']);
        $supervisor = $this->actor(['support.reports.view', 'support.view.team']);
        $member = $this->actor([]);
        $outsider = $this->actor([]);
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $team = Team::query()->create(['name' => 'Soporte A', 'supervisor_id' => $supervisor->id, 'is_active' => true]);
        $team->members()->attach($member->id);

        $ownTicket = $this->ticket($creator, ['responsible_id' => $own->id]);
        $memberTicket = $this->ticket($creator, ['responsible_id' => $member->id]);
        $teamTicket = $this->ticket($creator, ['responsible_id' => $outsider->id, 'team_id' => $team->id]);
        $outsiderTicket = $this->ticket($creator, ['responsible_id' => $outsider->id]);

        $service = app(SupportDashboardService::class);
        $this->assertSame(1, $service->metrics([], $own)['total']);
        $this->assertSame(2, $service->metrics([], $supervisor)['total']);
        $this->assertSame(4, $service->metrics([], $admin)['total']);
        $this->assertSame(1, $service->metrics(['customer_id' => $memberTicket->customer_id], $supervisor)['total']);
        $this->assertSame(0, $service->metrics(['customer_id' => $outsiderTicket->customer_id], $supervisor)['total']);

        $this->assertSame([$ownTicket->code], $this->csvCodes($own));
        $this->assertEqualsCanonicalizing([$memberTicket->code, $teamTicket->code], $this->csvCodes($supervisor));
        $this->assertCount(4, $this->csvCodes($admin));

        $this->actingAs($own)->get(route('support.dashboard'))->assertOk()
            ->assertSee('Tickets recibidos')->assertViewHas('metrics', fn (array $m) => $m['total'] === 1);
    }

    public function test_csv_headers_are_in_spanish(): void
    {
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $this->ticket($admin);
        $rows = $this->csvRows($admin);
        $this->assertSame(['Código', 'Fecha de creación', 'Cliente', 'Responsable', 'Tipo', 'Categoría', 'Prioridad', 'Estado', 'Modalidad'], $rows[0]);
    }

    public function test_averages_are_positive_with_real_timestamps(): void
    {
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $created = Carbon::parse('2026-10-01 08:00:00');
        $this->ticket($admin, ['created_at' => $created, 'first_responded_at' => $created->copy()->addHour(), 'resolved_at' => $created->copy()->addHours(4), 'closed_at' => $created->copy()->addHours(6)]);
        $this->ticket($admin, ['created_at' => $created, 'first_responded_at' => $created->copy()->addHours(3), 'resolved_at' => $created->copy()->addHours(8)]);

        $metrics = app(SupportDashboardService::class)->metrics([], $admin);
        $this->assertEquals(7200.0, $metrics['average_first_response_seconds']);
        $this->assertEquals(21600.0, $metrics['average_resolution_seconds']);
        $this->assertEquals(21600.0, $metrics['average_closure_seconds']);
        $this->assertNull($metrics['resolved_within_target_percent']);
        $this->actingAs($admin)->get(route('support.dashboard'))->assertOk()
            ->assertSee('6 h 0 min')->assertSee('Sin tiempos objetivo configurados');
    }

    public function test_reopened_percent_uses_real_cycles_and_never_exceeds_100(): void
    {
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $created = Carbon::parse('2026-10-01 08:00:00');
        // Reopened twice and resolved again: three cycles, one ticket.
        $reopened = $this->ticket($admin, ['created_at' => $created, 'resolved_at' => $created->copy()->addHours(10)]);
        $this->cycle($reopened, 1, $created, $created->copy()->addHours(2));
        $this->cycle($reopened, 2, $created->copy()->addHours(3), $created->copy()->addHours(5));
        $this->cycle($reopened, 3, $created->copy()->addHours(8), $created->copy()->addHours(10));
        // Resolved once.
        $single = $this->ticket($admin, ['created_at' => $created, 'resolved_at' => $created->copy()->addHours(4)]);
        $this->cycle($single, 1, $created, $created->copy()->addHours(4));
        // Never resolved, but has an open cycle.
        $open = $this->ticket($admin, ['created_at' => $created]);
        $this->cycle($open, 1, $created, null);

        $metrics = app(SupportDashboardService::class)->metrics([], $admin);
        $this->assertEquals(50.0, $metrics['reopened_percent']);
        $this->assertLessThanOrEqual(100.0, $metrics['reopened_percent']);
        // Per-cycle: resolved cycles are those with an end; 2h, 2h, 2h, 4h → 2.5h.
        $this->assertEquals(9000.0, $metrics['average_cycle_seconds']);

        // Only reopened tickets in scope: still capped at 100.
        $onlyReopened = app(SupportDashboardService::class)->metrics(['customer_id' => $reopened->customer_id], $admin);
        $this->assertEquals(100.0, $onlyReopened['reopened_percent']);
    }

    public function test_effective_time_excludes_customer_waiting_periods(): void
    {
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $created = Carbon::parse('2026-10-01 08:00:00');
        $ticket = $this->ticket($admin, ['created_at' => $created, 'resolved_at' => $created->copy()->addHours(10)]);
        $cycle = $this->cycle($ticket, 1, $created, $created->copy()->addHours(10));
        $this->period($ticket, $cycle, SupportStatus::SLUG_IN_PROGRESS, $created, $created->copy()->addHours(2), false);
        $this->period($ticket, $cycle, SupportStatus::SLUG_WAITING_CUSTOMER, $created->copy()->addHours(2), $created->copy()->addHours(5), true);
        $this->period($ticket, $cycle, SupportStatus::SLUG_WAITING_INTERNAL, $created->copy()->addHours(5), $created->copy()->addHours(6), false);
        $this->period($ticket, $cycle, SupportStatus::SLUG_IN_PROGRESS, $created->copy()->addHours(6), $created->copy()->addHours(10), false);
        $this->period($ticket, $cycle, SupportStatus::SLUG_RESOLVED, $created->copy()->addHours(10), null, false);

        $metrics = app(SupportDashboardService::class)->metrics([], $admin);
        $this->assertEquals(36000.0, $metrics['average_resolution_seconds']);
        $this->assertEquals(25200.0, $metrics['average_effective_resolution_seconds']);
        $this->assertEquals(25200.0, $metrics['average_cycle_effective_seconds']);
        $this->actingAs($admin)->get(route('support.dashboard'))->assertOk()->assertSee('Prom. tiempo efectivo')->assertSee('7 h 0 min');
    }

    public function test_dashboard_shows_empty_state_without_tickets(): void
    {
        $admin = $this->actor(['support.reports.view', 'support.view.any']);
        $this->actingAs($admin)->get(route('support.dashboard'))->assertOk()->assertSee('No hay tickets para los filtros seleccionados');
    }

    public function test_sidebar_link_is_visible_only_with_reports_permission(): void
    {
        $reporter = $this->actor(['support.reports.view', 'support.view.own']);
        $agent = $this->actor(['support.view.own']);
        $this->actingAs($reporter)->get(route('support.tickets.index'))->assertOk()->assertSee('data-testid="sidebar-support-dashboard"', false);
        $this->actingAs($agent)->get(route('support.tickets.index'))->assertOk()->assertDontSee('data-testid="sidebar-support-dashboard"', false);
        $this->actingAs($agent)->get(route('support.dashboard'))->assertForbidden();
    }

    private function csvRows(User $user): array
    {
        $content = $this->actingAs($user)->get(route('support.export'))->assertOk()->streamedContent();
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_values(array_filter(preg_split('/\r?\n/', $content)));

        return array_map(fn (string $line) => str_getcsv($line), $lines);
    }

    /** @return list<string> */
    private function csvCodes(User $user): array
    {
        return array_column(array_slice($this->csvRows($user), 1), 0);
    }

    private function actor(array $permissions): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }

    private function ticket(User $actor, array $attributes = []): SupportTicket
    {
        $customer = Customer::factory()->create();
        $contact = Contact::factory()->forCustomer($customer)->create();
        $ticket = app(SupportTicketService::class)->create([
            'title' => 'Soporte',
            'customer_id' => $customer->id,
            'requester_contact_id' => $contact->id,
            'type_id' => SupportTicketType::query()->where('slug', 'capacitacion')->value('id'),
            'category_id' => SupportCategory::query()->where('slug', 'capacitacion')->value('id'),
            'channel_id' => SupportChannel::query()->where('slug', 'registro-interno')->value('id'),
            'priority_id' => SupportPriority::query()->where('slug', 'media')->value('id'),
            'description' => 'Prueba',
        ], $actor);
        $ticket->forceFill($attributes)->saveQuietly();

        return $ticket->fresh();
    }

    private function cycle(SupportTicket $ticket, int $sequence, Carbon $start, ?Carbon $end): SupportResolutionCycle
    {
        return SupportResolutionCycle::query()->create(['ticket_id' => $ticket->id, 'sequence' => $sequence, 'started_at' => $start, 'ended_at' => $end]);
    }

    private function period(SupportTicket $ticket, SupportResolutionCycle $cycle, string $slug, Carbon $start, ?Carbon $end, bool $pauses): void
    {
        SupportStatusPeriod::query()->create(['ticket_id' => $ticket->id, 'cycle_id' => $cycle->id, 'status_id' => SupportStatus::query()->where('slug', $slug)->value('id'), 'period_type' => $slug, 'pauses_clock' => $pauses, 'started_at' => $start, 'ended_at' => $end]);
    }
}
