<?php

namespace Tests\Feature;

use App\Models\{ActivityType, Contact, Customer, SupportCategory, SupportChannel, SupportObservation, SupportPriority, SupportStatus, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportTicketLifecycleService, SupportTicketService};
use Database\Seeders\{SupportCatalogSeeder, SupportPermissionsSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportTicketUiTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['support.view.any', 'support.assign', 'support.reassign', 'support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel', 'support.update', 'support.priority.update', 'support.schedule', 'support.reschedule', 'support.observations.create', 'support.observations.lift', 'support.observations.validate', 'support.observations.reject'];

    protected function setUp(): void { parent::setUp(); $this->seed(SupportCatalogSeeder::class); $this->seed(SupportPermissionsSeeder::class); }

    // Action buttons per state
    public function test_new_ticket_shows_assign_start_and_cancel_but_not_resolve_close_or_reopen(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $content = $this->page($actor, $ticket);

        foreach (['support.tickets.assign', 'support.tickets.start', 'support.tickets.cancel'] as $route) $this->assertStringContainsString('action="'.route($route, $ticket).'"', $content);
        foreach (['support.tickets.resolve', 'support.tickets.close', 'support.tickets.reopen', 'support.tickets.hold', 'support.tickets.resume'] as $route) $this->assertStringNotContainsString('action="'.route($route, $ticket).'"', $content);
    }

    public function test_in_progress_ticket_shows_resolve_and_both_hold_kinds(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->inProgress($actor);
        $content = $this->page($actor, $ticket);

        $this->assertStringContainsString('action="'.route('support.tickets.resolve', $ticket).'"', $content);
        $this->assertStringContainsString('action="'.route('support.tickets.hold', $ticket).'"', $content);
        $this->assertStringContainsString('value="cliente"', $content);
        $this->assertStringContainsString('value="interna"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.resume', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.reopen', $ticket).'"', $content);
        $this->assertStringContainsString('Atención en marcha', $content);
    }

    public function test_waiting_ticket_shows_resume_and_not_resolve(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->inProgress($actor);
        $this->lifecycle()->putOnHold($ticket, 'cliente', $actor, 'Esperando datos');
        $content = $this->page($actor, $ticket);

        $this->assertStringContainsString('action="'.route('support.tickets.resume', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.resolve', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.hold', $ticket).'"', $content);
        $this->assertStringContainsString('En espera del cliente', $content);
    }

    public function test_reopen_only_when_resolved_or_closed(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->resolved($actor);
        $content = $this->page($actor, $ticket);
        $this->assertStringContainsString('action="'.route('support.tickets.reopen', $ticket).'"', $content);
        $this->assertStringContainsString('action="'.route('support.tickets.close', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.cancel', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.assign', $ticket).'"', $content);
        $this->assertStringContainsString('name="customer_validated"', $content);

        $this->lifecycle()->transition($ticket, 'cerrado', $actor, 'Validado');
        $content = $this->page($actor, $ticket);
        $this->assertStringContainsString('action="'.route('support.tickets.reopen', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.close', $ticket).'"', $content);
        $this->assertStringNotContainsString('action="'.route('support.tickets.priority.update', $ticket).'"', $content);
        $this->assertStringNotContainsString(route('support.tickets.edit', $ticket), $content);

        $new = $this->ticket($actor);
        $this->assertStringNotContainsString('action="'.route('support.tickets.reopen', $new).'"', $this->page($actor, $new));
    }

    public function test_buttons_hidden_without_permission(): void
    {
        $viewer = $this->actor(['support.view.any']); $ticket = $this->inProgress($this->actor(self::ALL));
        $content = $this->page($viewer, $ticket);
        foreach (['support.tickets.assign', 'support.tickets.resolve', 'support.tickets.cancel', 'support.tickets.hold', 'support.tickets.priority.update', 'support.tickets.observations.store', 'support.tickets.incident.store'] as $route) {
            $this->assertStringNotContainsString('action="'.route($route, $ticket).'"', $content);
        }
        $this->assertStringNotContainsString(route('support.tickets.edit', $ticket), $content);
    }

    public function test_close_with_pending_checkbox_requires_permission_and_unfinished_observations(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->resolved($actor);
        $this->assertStringNotContainsString('name="close_pending_exception"', $this->page($actor, $ticket));

        $observation = $this->lifecycle()->createObservation($ticket, ['title' => 'Revisar reporte'], $actor);
        $this->lifecycle()->transitionObservation($observation, 'lifted', $actor);
        $this->assertStringNotContainsString('name="close_pending_exception"', $this->page($actor, $ticket));

        $privileged = $this->actor(array_merge(self::ALL, ['support.close.with-pending-observations']));
        $this->assertStringContainsString('name="close_pending_exception"', $this->page($privileged, $ticket));

        $this->lifecycle()->transitionObservation($observation, 'validated', $actor);
        $this->assertStringNotContainsString('name="close_pending_exception"', $this->page($privileged, $ticket));
    }

    public function test_incident_form_only_for_incident_tickets(): void
    {
        $actor = $this->actor(self::ALL);
        $training = $this->ticket($actor);
        $this->assertStringNotContainsString('action="'.route('support.tickets.incident.store', $training).'"', $this->page($actor, $training));

        $incident = $this->ticket($actor, 'incidente-o-error');
        $this->assertStringContainsString('action="'.route('support.tickets.incident.store', $incident).'"', $this->page($actor, $incident));
    }

    public function test_reschedule_only_for_open_activities(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $this->tickets()->assign($ticket, $actor, $actor);
        $type = ActivityType::query()->create(['name' => 'Sesión', 'slug' => 'sesion-ui', 'is_active' => true]);
        $open = $this->lifecycle()->schedule($ticket->fresh(), ['type_id' => $type->id, 'title' => 'Sesión abierta', 'scheduled_at' => now()->addDay()], ['modality' => 'virtual'], $actor);
        $done = $this->lifecycle()->schedule($ticket->fresh(), ['type_id' => $type->id, 'title' => 'Sesión hecha', 'scheduled_at' => now()->addDay()], ['modality' => 'virtual'], $actor);
        $done->forceFill(['status' => 'completed'])->save();

        $content = $this->page($actor, $ticket);
        $this->assertStringContainsString(route('support.tickets.reschedule', [$ticket, $open]), $content);
        $this->assertStringNotContainsString(route('support.tickets.reschedule', [$ticket, $done]), $content);
    }

    // Priority, edit, hold/resume over HTTP
    public function test_priority_change_allowed_forbidden_and_refused(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $high = SupportPriority::query()->where('slug', '!=', 'media')->where('is_active', true)->firstOrFail();

        $this->actingAs($actor)->from(route('support.tickets.show', $ticket))->patch(route('support.tickets.priority.update', $ticket), ['priority_id' => $high->id])
            ->assertRedirect(route('support.tickets.show', $ticket))->assertSessionHas('status');
        $this->assertSame($high->id, $ticket->fresh()->priority_id);

        $this->actingAs($actor)->patch(route('support.tickets.priority.update', $ticket), [])->assertSessionHasErrors(['priority_id' => 'Seleccione una prioridad.']);

        $noPerm = $this->actor(['support.view.any']);
        $this->actingAs($noPerm)->patch(route('support.tickets.priority.update', $ticket), ['priority_id' => $high->id])->assertForbidden();

        $cancelled = $this->tickets()->cancel($this->ticket($actor), $actor, 'Duplicado');
        $this->actingAs($actor)->patch(route('support.tickets.priority.update', $cancelled), ['priority_id' => $high->id])
            ->assertSessionHas('error', 'No se puede cambiar la prioridad de un ticket cerrado o cancelado.');
    }

    public function test_edit_details_allowed_forbidden_and_refused(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $this->actingAs($actor)->get(route('support.tickets.edit', $ticket))->assertOk()->assertSee('Editar ticket')->assertDontSee('name="customer_id"', false)->assertDontSee('name="priority_id"', false);

        $payload = ['title' => 'Título corregido', 'description' => 'Detalle corregido', 'impact' => 'Alto', 'general_observations' => 'Nota', 'type_id' => $ticket->type_id, 'category_id' => $ticket->category_id, 'channel_id' => $ticket->channel_id, 'customer_id' => 999];
        $this->actingAs($actor)->put(route('support.tickets.update', $ticket), $payload)->assertRedirect(route('support.tickets.show', $ticket));
        $fresh = $ticket->fresh();
        $this->assertSame('Título corregido', $fresh->title);
        $this->assertSame($ticket->customer_id, $fresh->customer_id);

        $this->actingAs($actor)->put(route('support.tickets.update', $ticket), array_merge($payload, ['title' => '']))->assertSessionHasErrors(['title' => 'El título es obligatorio.']);

        $noPerm = $this->actor(['support.view.any']);
        $this->actingAs($noPerm)->get(route('support.tickets.edit', $ticket))->assertForbidden();
        $this->actingAs($noPerm)->put(route('support.tickets.update', $ticket), $payload)->assertForbidden();

        $cancelled = $this->tickets()->cancel($this->ticket($actor), $actor, 'Duplicado');
        $this->actingAs($actor)->put(route('support.tickets.update', $cancelled), array_merge($payload, ['type_id' => $cancelled->type_id, 'category_id' => $cancelled->category_id, 'channel_id' => $cancelled->channel_id]))
            ->assertSessionHas('error', 'No se puede editar un ticket cerrado o cancelado.');
    }

    public function test_hold_and_resume_over_http(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->inProgress($actor);

        $this->actingAs($actor)->post(route('support.tickets.hold', $ticket), ['kind' => 'otra'])->assertSessionHasErrors(['kind' => 'Seleccione el tipo de espera: cliente o interna.']);
        $this->actingAs($actor)->post(route('support.tickets.hold', $ticket), ['kind' => 'interna', 'reason' => 'Esperando proveedor'])->assertSessionHas('status');
        $this->assertSame(SupportStatus::SLUG_WAITING_INTERNAL, $ticket->fresh()->status->slug);

        $this->actingAs($actor)->post(route('support.tickets.resume', $ticket))->assertSessionHas('status');
        $this->assertSame(SupportStatus::SLUG_IN_PROGRESS, $ticket->fresh()->status->slug);

        $new = $this->ticket($actor);
        $this->actingAs($actor)->post(route('support.tickets.hold', $new), ['kind' => 'cliente'])->assertSessionHas('error', 'Transición de soporte no permitida.');
        $this->actingAs($actor)->post(route('support.tickets.resume', $new))->assertSessionHas('error', 'El ticket no está en espera.');

        $noPerm = $this->actor(['support.view.any']);
        $this->actingAs($noPerm)->post(route('support.tickets.hold', $ticket), ['kind' => 'cliente'])->assertForbidden();
        $this->actingAs($noPerm)->post(route('support.tickets.resume', $ticket))->assertForbidden();
    }

    // Histories and observations
    public function test_history_card_shows_assignments_reschedules_and_status_timeline(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $first = User::factory()->create(['is_active' => true, 'name' => 'Primera Responsable']);
        $second = User::factory()->create(['is_active' => true, 'name' => 'Segundo Responsable']);
        $this->tickets()->assign($ticket, $first, $actor);
        $this->tickets()->assign($ticket->fresh(), $second, $actor, null, 'Escalamiento a nivel 2');
        $type = ActivityType::query()->create(['name' => 'Sesión', 'slug' => 'sesion-hist', 'is_active' => true]);
        $activity = $this->lifecycle()->schedule($ticket->fresh(), ['type_id' => $type->id, 'title' => 'Sesión remota', 'scheduled_at' => now()->addDay()], ['modality' => 'virtual'], $actor);
        $this->lifecycle()->reschedule($activity, now()->addDays(2), 'Cliente pidió otra fecha', $actor);

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))->assertOk()
            ->assertSeeText('Historial')
            ->assertSeeText('Asignaciones')->assertSeeText('Escalamiento a nivel 2')->assertSeeText('Primera Responsable')->assertSeeText('Segundo Responsable')
            ->assertSeeText('Reprogramaciones')->assertSeeText('Cliente pidió otra fecha')
            ->assertSeeText('Estados')->assertSeeText('Asignado')->assertSeeText('Programado');
    }

    public function test_observation_buttons_follow_allowed_transitions_and_permissions(): void
    {
        $actor = $this->actor(self::ALL); $ticket = $this->ticket($actor);
        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))->assertSeeText('No hay observaciones registradas.');

        $due = now()->addDays(3);
        $observation = $this->lifecycle()->createObservation($ticket, ['title' => 'Ajustar reporte', 'responsible_id' => $actor->id, 'due_at' => $due], $actor);
        $content = $this->page($actor, $ticket);
        $this->assertStringContainsString('Pendiente', $content);
        $this->assertStringContainsString($due->format('d/m/Y'), $content);
        foreach (['in_process', 'lifted', 'not_applicable'] as $state) $this->assertStringContainsString('data-observation-target="'.$state.'"', $content);
        foreach (['validated', 'rejected', 'reopened'] as $state) $this->assertStringNotContainsString('data-observation-target="'.$state.'"', $content);

        $this->lifecycle()->transitionObservation($observation, 'lifted', $actor);
        $lifterOnly = $this->actor(['support.view.any', 'support.observations.lift']);
        $content = $this->page($lifterOnly, $ticket);
        $this->assertStringContainsString('Levantada', $content);
        $this->assertStringNotContainsString('data-observation-target="validated"', $content);
        $this->assertStringNotContainsString('data-observation-target="rejected"', $content);

        $content = $this->page($actor, $ticket);
        $this->assertStringContainsString('data-observation-target="validated"', $content);
        $this->assertStringContainsString('data-observation-target="rejected"', $content);
        $this->assertMatchesRegularExpression('/data-observation-target="rejected".*?name="reason"[^>]*required|name="reason"[^>]*required[^>]*>.*?data-observation-target="rejected"/s', $content);
    }

    // List
    public function test_list_search_by_code_customer_and_title(): void
    {
        $actor = $this->actor(self::ALL);
        $alpha = $this->ticket($actor, 'capacitacion', ['legal_name' => 'Alfa Industrial SAC', 'trade_name' => null], 'Error en planilla');
        $beta = $this->ticket($actor, 'capacitacion', ['legal_name' => 'Beta Comercial SAC', 'trade_name' => null], 'Consulta de reportes');

        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => 'Alfa']))->assertOk()->assertSee($alpha->code)->assertDontSee($beta->code);
        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => $beta->code]))->assertOk()->assertSee($beta->code)->assertDontSee($alpha->code);
        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => 'planilla']))->assertOk()->assertSee($alpha->code)->assertDontSee($beta->code);
        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => 'zzz-no-existe']))->assertOk()->assertSeeText('No hay tickets que coincidan con la búsqueda.');
        // Wildcards typed by the user are literal: "%" and "_" match nothing here.
        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => '%']))->assertOk()->assertDontSee($alpha->code)->assertDontSee($beta->code);
        $this->actingAs($actor)->get(route('support.tickets.index', ['q' => '_']))->assertOk()->assertDontSee($alpha->code)->assertDontSee($beta->code);
    }

    public function test_list_mis_tickets_filter_and_columns(): void
    {
        $actor = $this->actor(self::ALL); $other = User::factory()->create(['is_active' => true, 'name' => 'Otra Persona']);
        $mine = $this->ticket($actor); $theirs = $this->ticket($actor);
        $this->tickets()->assign($mine, $actor, $actor);
        $this->tickets()->assign($theirs, $other, $actor);

        $this->actingAs($actor)->get(route('support.tickets.index'))->assertOk()->assertSee($mine->code)->assertSee($theirs->code)->assertSeeText('Mis tickets')->assertSeeText('Actualizado');
        $this->actingAs($actor)->get(route('support.tickets.index', ['mine' => 1]))->assertOk()->assertSee($mine->code)->assertDontSee($theirs->code);
    }

    // Dashboard dropdowns
    public function test_dashboard_dropdowns_are_limited_to_scope(): void
    {
        $admin = $this->actor(self::ALL);
        $own = $this->actor(['support.view.own', 'support.reports.view']);
        $stranger = User::factory()->create(['is_active' => true, 'name' => 'Usuario Ajeno']);
        $team = Team::query()->create(['name' => 'Equipo Ajeno', 'is_active' => true]);
        $mine = $this->ticket($admin, 'capacitacion', ['code' => 'CLI-PROPIO']);
        $this->tickets()->assign($mine, $own, $admin);
        $foreign = $this->ticket($admin, 'capacitacion', ['code' => 'CLI-AJENO']);
        $this->tickets()->assign($foreign, $stranger, $admin, $team->id);

        $this->actingAs($own)->get(route('support.dashboard'))->assertOk()
            ->assertSee('CLI-PROPIO')->assertDontSee('CLI-AJENO')
            ->assertDontSee('Usuario Ajeno')->assertDontSee('Equipo Ajeno')
            ->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$own->id]);

        $reporter = $this->actor(['support.view.any', 'support.reports.view']);
        $this->actingAs($reporter)->get(route('support.dashboard'))->assertOk()->assertSee('CLI-AJENO')->assertSee('Usuario Ajeno')->assertSee('Equipo Ajeno');
    }

    private function page(User $user, SupportTicket $ticket): string { return $this->actingAs($user)->get(route('support.tickets.show', $ticket))->assertOk()->getContent(); }
    private function tickets(): SupportTicketService { return app(SupportTicketService::class); }
    private function lifecycle(): SupportTicketLifecycleService { return app(SupportTicketLifecycleService::class); }
    private function inProgress(User $actor): SupportTicket { $ticket = $this->ticket($actor); $this->tickets()->assign($ticket, $actor, $actor); return $this->lifecycle()->transition($ticket->fresh(), SupportStatus::SLUG_IN_PROGRESS, $actor); }
    private function resolved(User $actor): SupportTicket { return $this->lifecycle()->resolve($this->inProgress($actor), 'Solución aplicada', $actor); }

    private function actor(array $permissions): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ($permissions as $permission) Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        return $user;
    }

    private function ticket(User $actor, string $typeSlug = 'capacitacion', array $customer = [], string $title = 'Support'): SupportTicket
    {
        $customer = Customer::factory()->create($customer); $contact = Contact::factory()->forCustomer($customer)->create();
        return $this->tickets()->create(['title' => $title, 'customer_id' => $customer->id, 'requester_contact_id' => $contact->id, 'type_id' => SupportTicketType::query()->where('slug', $typeSlug)->value('id'), 'category_id' => SupportCategory::query()->where('slug', 'capacitacion')->value('id'), 'channel_id' => SupportChannel::query()->where('slug', 'registro-interno')->value('id'), 'priority_id' => SupportPriority::query()->where('slug', 'media')->value('id'), 'description' => 'Test'], $actor);
    }
}
