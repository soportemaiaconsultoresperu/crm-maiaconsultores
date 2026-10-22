<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\{Contact, Customer, SupportCategory, SupportChannel, SupportObservation, SupportPriority, SupportStatus, SupportTicket, SupportTicketType, User};
use App\Services\{SupportTicketLifecycleService, SupportTicketService};
use Database\Seeders\{SupportCatalogSeeder, SupportPermissionsSeeder};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportTicketStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['support.view.any', 'support.assign', 'support.reassign', 'support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel', 'support.update', 'support.priority.update', 'support.observations.create', 'support.observations.lift', 'support.observations.validate', 'support.observations.reject'];

    protected function setUp(): void { parent::setUp(); $this->seed(SupportCatalogSeeder::class); $this->seed(SupportPermissionsSeeder::class); }

    // S3 — assign
    public function test_assign_from_new_and_reopened_moves_to_assigned_through_the_lifecycle(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $assigned = $this->tickets()->assign($ticket, $actor, $actor);
        $this->assertSame(SupportStatus::SLUG_ASSIGNED, $assigned->status->slug);
        $this->assertSame(1, $assigned->statusPeriods()->where('period_type', 'asignado')->count());

        $reopened = $this->resolveAndReopen($assigned, $actor);
        $other = User::factory()->create(['is_active' => true]);
        $reassigned = $this->tickets()->assign($reopened, $other, $actor, null, 'Nuevo responsable tras reapertura');
        $this->assertSame(SupportStatus::SLUG_ASSIGNED, $reassigned->status->slug);
        $this->assertSame(2, $reassigned->statusPeriods()->where('period_type', 'asignado')->count());
        $this->assertSame(1, $reassigned->statusPeriods()->whereNull('ended_at')->count());
    }

    public function test_reassign_in_other_open_states_keeps_status_and_records_history(): void
    {
        $actor = $this->actor(); $ticket = $this->inProgress($actor);
        $periods = $ticket->statusPeriods()->count();
        $other = User::factory()->create(['is_active' => true]);
        $reassigned = $this->tickets()->assign($ticket, $other, $actor, null, 'Escalamiento');
        $this->assertSame(SupportStatus::SLUG_IN_PROGRESS, $reassigned->status->slug);
        $this->assertSame($other->id, $reassigned->responsible_id);
        $this->assertSame($periods, $reassigned->statusPeriods()->count());
        $this->assertSame(2, $reassigned->assignments()->count());
    }

    public function test_assign_is_refused_for_resolved_closed_and_cancelled_tickets(): void
    {
        $actor = $this->actor(); $other = User::factory()->create(['is_active' => true]);
        $resolved = $this->resolved($actor);
        $closed = $this->lifecycle()->transition($this->resolved($actor), 'cerrado', $actor, 'Validado por el cliente');
        $cancelled = $this->tickets()->cancel($this->ticket($actor), $actor, 'Duplicado');
        foreach ([$resolved, $closed, $cancelled] as $ticket) {
            try { $this->tickets()->assign($ticket, $other, $actor, null, 'Intento'); $this->fail('Expected refusal for '.$ticket->status->slug); }
            catch (InvalidOperationException $e) { $this->assertStringContainsString('No se puede asignar', $e->getMessage()); }
            $this->assertNotSame($other->id, $ticket->fresh()->responsible_id);
        }
    }

    public function test_inactive_responsible_is_refused_in_service_and_http(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $inactive = User::factory()->create(['is_active' => false]);
        try { $this->tickets()->assign($ticket, $inactive, $actor); $this->fail('Expected refusal.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('responsible_id', $e->errors()); }
        $this->actingAs($actor)->post(route('support.tickets.assign', $ticket), ['responsible_id' => $inactive->id])->assertSessionHasErrors('responsible_id', null, 'assign');
        $this->assertNull($ticket->fresh()->responsible_id);
    }

    // S4 — cancel
    public function test_cancel_goes_through_lifecycle_and_closes_period_and_cycle(): void
    {
        $actor = $this->actor(); $ticket = $this->inProgress($actor);
        $cancelled = $this->tickets()->cancel($ticket, $actor, 'Cliente desistió');
        $this->assertSame(SupportStatus::SLUG_CANCELLED, $cancelled->status->slug);
        $this->assertSame('Cliente desistió', $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cycles()->first()->ended_at);
        $this->assertSame(0, $cancelled->statusPeriods()->whereNull('ended_at')->where('period_type', '!=', 'cancelado')->count());
        $this->assertSame(1, $cancelled->statusPeriods()->where('period_type', 'cancelado')->count());
    }

    public function test_cancel_is_refused_from_resolved_and_closed(): void
    {
        $actor = $this->actor();
        $resolved = $this->resolved($actor);
        $closed = $this->lifecycle()->transition($this->resolved($actor), 'cerrado', $actor, 'Validado');
        foreach ([$resolved, $closed] as $ticket) {
            $slug = $ticket->status->slug;
            $this->actingAs($actor)->from(route('support.tickets.show', $ticket))->post(route('support.tickets.cancel', $ticket), ['reason' => 'Intento'])
                ->assertRedirect(route('support.tickets.show', $ticket))->assertSessionHas('error');
            $this->assertSame($slug, $ticket->fresh()->status->slug);
        }
    }

    // S5 — locking / idempotency / flash
    public function test_double_submit_never_duplicates_periods_or_cycles(): void
    {
        $actor = $this->actor(); $ticket = $this->tickets()->assign($this->ticket($actor), $actor, $actor);
        $stale = $ticket->fresh();
        $this->lifecycle()->transition($ticket->fresh(), 'en-atencion', $actor);
        $this->lifecycle()->transition($stale, 'en-atencion', $actor);
        $this->assertSame(1, $ticket->statusPeriods()->where('period_type', 'en-atencion')->count());
        $this->assertSame(1, $ticket->cycles()->count());

        $this->actingAs($actor)->post(route('support.tickets.cancel', $ticket), ['reason' => 'Duplicado'])->assertRedirect();
        $this->actingAs($actor)->post(route('support.tickets.cancel', $ticket), ['reason' => 'Duplicado'])->assertRedirect();
        $this->assertSame(1, $ticket->statusPeriods()->where('period_type', 'cancelado')->count());
        $this->assertSame(1, $ticket->cycles()->count());
    }

    public function test_refused_transition_redirects_back_with_spanish_error_not_500(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $this->actingAs($actor)->from(route('support.tickets.show', $ticket))->post(route('support.tickets.reopen', $ticket), ['reason' => 'Intento'])
            ->assertStatus(302)->assertRedirect(route('support.tickets.show', $ticket))->assertSessionHas('error', 'Transición de soporte no permitida.');
        $this->assertSame(SupportStatus::SLUG_NEW, $ticket->fresh()->status->slug);
    }

    // S6 — resolve / reopen / cycles
    public function test_resolve_saves_summary_atomically_and_nothing_when_refused(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $this->actingAs($actor)->post(route('support.tickets.resolve', $ticket), ['solution_summary' => 'No debe guardarse'])->assertSessionHas('error');
        $this->assertNull($ticket->fresh()->solution_summary);

        $ticket = $this->inProgress($actor);
        $this->actingAs($actor)->post(route('support.tickets.resolve', $ticket), ['solution_summary' => 'Ajuste aplicado'])->assertSessionHas('status');
        $ticket->refresh();
        $this->assertSame('resuelto', $ticket->status->slug);
        $this->assertSame('Ajuste aplicado', $ticket->solution_summary);
        $this->assertSame('Ajuste aplicado', $ticket->cycles()->first()->solution_summary);
    }

    public function test_resolve_is_refused_while_observations_are_not_lifted(): void
    {
        $actor = $this->actor(); $ticket = $this->inProgress($actor);
        $observation = $this->lifecycle()->createObservation($ticket, ['title' => 'Revisar reporte'], $actor);
        try { $this->lifecycle()->resolve($ticket, 'Solución', $actor); $this->fail('Expected refusal.'); }
        catch (InvalidOperationException $e) { $this->assertStringContainsString('observaciones pendientes', $e->getMessage()); }
        $this->assertNull($ticket->fresh()->solution_summary);
        $this->lifecycle()->transitionObservation($observation, 'lifted', $actor);
        $this->assertSame('resuelto', $this->lifecycle()->resolve($ticket->fresh(), 'Solución', $actor)->status->slug);
    }

    public function test_reopen_and_second_resolution_keep_first_cycle_times(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 0));
        $ticket = $this->inProgress($actor);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
        $ticket = $this->lifecycle()->resolve($ticket, 'Primera solución', $actor);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(11, 0));
        $ticket = $this->lifecycle()->transition($ticket, 'cerrado', $actor, 'Validado', false, true);
        $this->assertNotNull($ticket->validated_at);
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 0));
        $ticket = $this->lifecycle()->transition($ticket, 'reabierto', $actor, 'Volvió a fallar');
        $this->assertSame(2, $ticket->cycles()->count());
        $this->lifecycle()->transition($ticket, 'en-atencion', $actor);
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(12, 0));
        $ticket = $this->lifecycle()->resolve($ticket->fresh(), 'Segunda solución', $actor);

        [$first, $second] = $ticket->cycles()->orderBy('sequence')->get()->all();
        $this->assertSame('2026-10-01 10:00:00', $first->resolved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 11:00:00', $first->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Primera solución', $first->solution_summary);
        $this->assertSame('2026-10-02 12:00:00', $second->resolved_at->format('Y-m-d H:i:s'));
        $this->assertSame('Segunda solución', $ticket->solution_summary);
        $this->assertSame(2, $ticket->cycles()->count());
    }

    // S8 — observations
    public function test_observation_transition_map_refuses_invalid_moves(): void
    {
        $actor = $this->actor(); $ticket = $this->inProgress($actor);
        $o = $this->lifecycle()->createObservation($ticket, ['title' => 'Formato de reporte'], $actor);
        foreach (['validated', 'rejected', 'reopened', 'pending'] as $invalid) {
            try { $this->lifecycle()->transitionObservation($o->fresh(), $invalid, $actor, 'Motivo'); $this->fail("pending → {$invalid} should be refused"); }
            catch (InvalidOperationException $e) { $this->assertStringContainsString('no permitida', $e->getMessage()); }
        }
        $o = $this->lifecycle()->transitionObservation($o, 'in_process', $actor);
        $o = $this->lifecycle()->transitionObservation($o, 'lifted', $actor);
        $o = $this->lifecycle()->transitionObservation($o, 'rejected', $actor, 'Sigue igual');
        $o = $this->lifecycle()->transitionObservation($o, 'lifted', $actor);
        $o = $this->lifecycle()->transitionObservation($o, 'validated', $actor);
        $this->assertNotNull($o->validated_at);
        $o = $this->lifecycle()->transitionObservation($o, 'reopened', $actor, 'Reapareció');
        $this->assertSame('reopened', $o->state);
        $this->assertSame(SupportObservation::LABELS['reopened'], 'Reabierta');
        $this->assertSame(7, $o->histories()->count());
    }

    public function test_observation_transitions_are_guarded_by_their_permission(): void
    {
        $owner = $this->actor(); $ticket = $this->inProgress($owner);
        $o = $this->lifecycle()->createObservation($ticket, ['title' => 'Validar'], $owner);
        $this->lifecycle()->transitionObservation($o, 'lifted', $owner);
        $lifter = $this->actor(['support.view.any', 'support.observations.lift']);
        $this->actingAs($lifter)->post(route('support.tickets.observations.transition', [$ticket, $o]), ['state' => 'validated'])->assertForbidden();
        $this->actingAs($lifter)->post(route('support.tickets.observations.transition', [$ticket, $o]), ['state' => 'rejected', 'reason' => 'No'])->assertForbidden();
        $this->assertSame('lifted', $o->fresh()->state);
        $this->expectException(AuthorizationException::class);
        $this->lifecycle()->transitionObservation($o->fresh(), 'validated', $lifter);
    }

    public function test_close_with_pending_observations_requires_exception_flag_and_permission(): void
    {
        $actor = $this->actor(); $ticket = $this->resolved($actor);
        $this->lifecycle()->createObservation($ticket, ['title' => 'Pendiente del cliente'], $actor);
        $this->actingAs($actor)->post(route('support.tickets.close', $ticket), ['reason' => 'Cierre'])->assertSessionHas('error', 'No se puede cerrar con observaciones pendientes.');
        $this->actingAs($actor)->post(route('support.tickets.close', $ticket), ['reason' => 'Cierre', 'close_pending_exception' => '1'])->assertSessionHas('error', 'No tiene permiso para cerrar con observaciones pendientes.');
        $this->assertSame('resuelto', $ticket->fresh()->status->slug);
        $boss = $this->actor([...self::ALL, 'support.close.with-pending-observations']);
        $this->actingAs($boss)->post(route('support.tickets.close', $ticket), ['reason' => 'Cliente acepta pendiente', 'close_pending_exception' => '1'])->assertSessionHas('status');
        $this->assertSame('cerrado', $ticket->fresh()->status->slug);
    }

    // Service methods for S-C
    public function test_change_priority_requires_permission_active_priority_and_open_ticket(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $high = SupportPriority::query()->where('slug', 'alta')->firstOrFail();
        $changed = $this->tickets()->changePriority($ticket, $high, $actor);
        $this->assertSame($high->id, $changed->priority_id);
        try { $this->tickets()->changePriority($ticket, SupportPriority::query()->where('slug', 'baja')->firstOrFail(), $this->actor(['support.view.any'])); $this->fail('Expected 403.'); }
        catch (AuthorizationException) { $this->assertSame($high->id, $ticket->fresh()->priority_id); }
        $cancelled = $this->tickets()->cancel($ticket->fresh(), $actor, 'Duplicado');
        $this->expectException(InvalidOperationException::class);
        $this->tickets()->changePriority($cancelled, SupportPriority::query()->where('slug', 'baja')->firstOrFail(), $actor);
    }

    public function test_hold_and_resume_use_waiting_states_and_pause_only_for_customer(): void
    {
        $actor = $this->actor(); $ticket = $this->inProgress($actor);
        $onHold = $this->lifecycle()->putOnHold($ticket, 'cliente', $actor, 'Esperando accesos');
        $this->assertSame(SupportStatus::SLUG_WAITING_CUSTOMER, $onHold->status->slug);
        $this->assertTrue($onHold->statusPeriods()->whereNull('ended_at')->first()->pauses_clock);
        $resumed = $this->lifecycle()->resumeFromHold($onHold, $actor);
        $this->assertSame(SupportStatus::SLUG_IN_PROGRESS, $resumed->status->slug);
        $internal = $this->lifecycle()->putOnHold($resumed, 'interna', $actor);
        $this->assertSame(SupportStatus::SLUG_WAITING_INTERNAL, $internal->status->slug);
        $this->assertFalse($internal->statusPeriods()->whereNull('ended_at')->first()->pauses_clock);
        try { $this->lifecycle()->putOnHold($internal, 'otra', $actor); $this->fail('Expected validation.'); } catch (ValidationException) {}
        $this->expectException(InvalidOperationException::class);
        $this->lifecycle()->resumeFromHold($this->resolved($actor), $actor);
    }

    public function test_update_details_edits_descriptive_fields_only_with_permission(): void
    {
        $actor = $this->actor(); $ticket = $this->ticket($actor);
        $category = SupportCategory::query()->where('slug', 'tecnico')->firstOrFail();
        $updated = $this->tickets()->updateDetails($ticket, ['title' => 'Nuevo título', 'description' => 'Detalle', 'impact' => 'Alto', 'category_id' => $category->id, 'status_id' => 999, 'customer_id' => 999], $actor);
        $this->assertSame('Nuevo título', $updated->title);
        $this->assertSame($category->id, $updated->category_id);
        $this->assertSame($ticket->customer_id, $updated->customer_id);
        $this->assertSame(SupportStatus::SLUG_NEW, $updated->status->slug);
        try { $this->tickets()->updateDetails($ticket, ['title' => ''], $actor); $this->fail('Expected validation.'); } catch (ValidationException $e) { $this->assertArrayHasKey('title', $e->errors()); }
        $this->expectException(AuthorizationException::class);
        $this->tickets()->updateDetails($ticket, ['title' => 'X'], $this->actor(['support.view.any']));
    }

    private function resolveAndReopen(SupportTicket $ticket, User $actor): SupportTicket
    {
        $this->lifecycle()->transition($ticket, 'en-atencion', $actor);
        $resolved = $this->lifecycle()->resolve($ticket->fresh(), 'Solución', $actor);
        return $this->lifecycle()->transition($resolved, 'reabierto', $actor, 'Persistió');
    }

    private function inProgress(User $actor): SupportTicket { $ticket = $this->tickets()->assign($this->ticket($actor), $actor, $actor); return $this->lifecycle()->transition($ticket, 'en-atencion', $actor); }
    private function resolved(User $actor): SupportTicket { return $this->lifecycle()->resolve($this->inProgress($actor), 'Solución aplicada', $actor); }
    private function tickets(): SupportTicketService { return app(SupportTicketService::class); }
    private function lifecycle(): SupportTicketLifecycleService { return app(SupportTicketLifecycleService::class); }

    /** @param list<string>|null $permissions */
    private function actor(?array $permissions = null): User
    {
        $permissions ??= self::ALL;
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ($permissions as $permission) Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]); $user->givePermissionTo($permissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        return $user;
    }

    private function ticket(User $actor): SupportTicket
    {
        $customer = Customer::factory()->create(); $contact = Contact::factory()->forCustomer($customer)->create();
        return $this->tickets()->create(['title' => 'Soporte', 'customer_id' => $customer->id, 'requester_contact_id' => $contact->id, 'type_id' => SupportTicketType::query()->where('slug', 'capacitacion')->value('id'), 'category_id' => SupportCategory::query()->where('slug', 'capacitacion')->value('id'), 'channel_id' => SupportChannel::query()->where('slug', 'registro-interno')->value('id'), 'priority_id' => SupportPriority::query()->where('slug', 'media')->value('id'), 'description' => 'Test'], $actor);
    }
}
