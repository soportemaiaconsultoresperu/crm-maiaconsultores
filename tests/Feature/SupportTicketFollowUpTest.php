<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\{Activity, ActivityType, Contact, Customer, Document, SupportCategory, SupportChannel, SupportPriority, SupportStatus, SupportStatusPeriod, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportDashboardService, SupportTicketLifecycleService, SupportTicketService};
use Database\Seeders\{SupportCatalogSeeder, SupportPermissionsSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Follow-up of the support review: frozen guards, validation, CSV, atomic self-assign, per-form errors, documents, texts, reopen KPIs, create form. */
class SupportTicketFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['support.view.any', 'support.create', 'support.assign', 'support.reassign', 'support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel', 'support.update', 'support.priority.update', 'support.schedule', 'support.reschedule', 'support.updates.create', 'support.observations.create', 'support.observations.lift', 'support.observations.validate', 'support.observations.reject', 'support.reports.view'];

    protected function setUp(): void { parent::setUp(); $this->seed(SupportCatalogSeeder::class); $this->seed(SupportPermissionsSeeder::class); }

    // 1 — frozen tickets
    public function test_closed_or_cancelled_ticket_refuses_reschedule_incident_observation_and_participant(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor, 'incidente-o-error');
        $this->tickets()->assign($ticket, $actor, $actor);
        $activity = $this->scheduleActivity($ticket, $actor);
        $session = $ticket->sessionDetails()->firstOrFail();
        $this->tickets()->cancel($ticket->fresh(), $actor, 'Duplicado');
        $show = route('support.tickets.show', $ticket);

        $this->actingAs($actor)->from($show)->post(route('support.tickets.reschedule', [$ticket, $activity]), ['scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'), 'reason' => 'Cliente pidió otra fecha'])
            ->assertRedirect($show)->assertSessionHas('error', 'No se puede reprogramar la atención de un ticket cerrado o cancelado.');
        $this->actingAs($actor)->from($show)->post(route('support.tickets.incident.store', $ticket), ['system' => 'ERP'])
            ->assertRedirect($show)->assertSessionHas('error', 'No se puede modificar el incidente de un ticket cerrado o cancelado.');
        $this->actingAs($actor)->from($show)->post(route('support.tickets.observations.store', $ticket), ['title' => 'Nueva'])
            ->assertRedirect($show)->assertSessionHas('error', 'No se pueden crear observaciones en un ticket cerrado o cancelado.');
        $this->actingAs($actor)->from($show)->post(route('support.tickets.sessions.participants.store', [$ticket, $session]), ['name' => 'Ana', 'attended' => 1])
            ->assertRedirect($show)->assertSessionHas('error', 'No se pueden registrar participantes en un ticket cerrado o cancelado.');

        $this->assertDatabaseCount('support_reschedules', 0);
        $this->assertDatabaseCount('support_incident_details', 0);
        $this->assertDatabaseCount('support_observations', 0);
        $this->assertDatabaseCount('support_session_participants', 0);

        $this->expectException(InvalidOperationException::class);
        $this->lifecycle()->reschedule($activity->fresh(), now()->addDays(4), 'Otra', $actor);
    }

    public function test_reschedule_is_hidden_once_the_ticket_is_resolved(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor);
        $this->tickets()->assign($ticket, $actor, $actor);
        $activity = $this->scheduleActivity($ticket, $actor);
        $this->assertStringContainsString('action="'.route('support.tickets.reschedule', [$ticket, $activity]).'"', $this->page($actor, $ticket));

        $this->lifecycle()->transition($ticket->fresh(), SupportStatus::SLUG_IN_PROGRESS, $actor);
        $this->lifecycle()->resolve($ticket->fresh(), 'Solución', $actor);
        $this->assertStringNotContainsString('action="'.route('support.tickets.reschedule', [$ticket, $activity]).'"', $this->page($actor, $ticket));
    }

    // 2 — team must be active
    public function test_assign_refuses_an_inactive_team(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor);
        $inactive = Team::query()->create(['name' => 'Equipo inactivo', 'is_active' => false]);

        $this->actingAs($actor)->post(route('support.tickets.assign', $ticket), ['responsible_id' => $actor->id, 'team_id' => $inactive->id])
            ->assertSessionHasErrors(['team_id' => 'El equipo seleccionado no existe o no está activo.'], null, 'assign');
        $this->assertNull($ticket->fresh()->responsible_id);

        try {
            $this->tickets()->assign($ticket, $actor, $actor, $inactive->id);
            $this->fail('Se esperaba un error de validación.');
        } catch (ValidationException $exception) {
            $this->assertSame('El equipo seleccionado no existe o no está activo.', $exception->errors()['team_id'][0]);
        }
    }

    // 3 — CSV formulas
    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor, 'capacitacion', ['code' => '=HYPERLINK("http://x")']);
        $agent = User::factory()->create(['is_active' => true, 'name' => '+cmd|calc']);
        $this->tickets()->assign($ticket, $agent, $actor);

        $csv = $this->actingAs($actor)->get(route('support.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd|calc", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    // 4 — dead code removed
    public function test_unused_effective_seconds_helper_is_gone(): void
    {
        $this->assertFalse(method_exists(SupportTicketLifecycleService::class, 'effectiveSeconds'));
    }

    // 5 — atomic self-assignment
    public function test_start_rolls_back_the_self_assignment_when_the_transition_fails(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor);
        $this->failPeriod(SupportStatus::SLUG_IN_PROGRESS);

        $this->actingAs($actor)->post(route('support.tickets.start', $ticket))->assertSessionHas('error', 'Fallo simulado.');

        $ticket->refresh();
        $this->assertSame(SupportStatus::SLUG_NEW, $ticket->status->slug);
        $this->assertNull($ticket->responsible_id);
        $this->assertSame(0, $ticket->assignments()->count());
    }

    public function test_schedule_rolls_back_the_self_assignment_when_the_transition_fails(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->ticket($actor);
        $type = ActivityType::query()->create(['name' => 'Sesión', 'slug' => 'sesion-rollback', 'is_active' => true]);
        $this->failPeriod(SupportStatus::SLUG_SCHEDULED);

        $this->actingAs($actor)->post(route('support.tickets.schedule', $ticket), ['type_id' => $type->id, 'title' => 'Sesión', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'), 'modality' => 'virtual'])
            ->assertSessionHas('error', 'Fallo simulado.');

        $ticket->refresh();
        $this->assertSame(SupportStatus::SLUG_NEW, $ticket->status->slug);
        $this->assertNull($ticket->responsible_id);
        $this->assertSame(0, $ticket->assignments()->count());
        $this->assertDatabaseCount('support_session_details', 0);
    }

    // 6 — per-form errors
    public function test_action_forms_show_their_own_validation_errors(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->resolved($actor);
        $show = route('support.tickets.show', $ticket);

        $this->actingAs($actor)->from($show)->post(route('support.tickets.close', $ticket), ['reason' => ''])
            ->assertSessionHasErrors('reason', null, 'close');
        $content = $this->rendered($actor, $show, 'post', route('support.tickets.close', $ticket), ['reason' => '']);
        $this->assertStringContainsString('data-form-errors="close"', $content);
        $this->assertStringNotContainsString('data-form-errors="reopen"', $content);

        $this->actingAs($actor)->from($show)->post(route('support.tickets.reopen', $ticket), ['reason' => ''])
            ->assertSessionHasErrors('reason', null, 'reopen');
        $content = $this->rendered($actor, $show, 'post', route('support.tickets.reopen', $ticket), ['reason' => '']);
        $this->assertStringContainsString('data-form-errors="reopen"', $content);
        $this->assertStringNotContainsString('data-form-errors="close"', $content);

        $open = $this->inProgress($actor);
        foreach ([
            'resolve' => [route('support.tickets.resolve', $open), []],
            'hold' => [route('support.tickets.hold', $open), ['kind' => 'otra']],
            'cancel' => [route('support.tickets.cancel', $open), []],
            'schedule' => [route('support.tickets.schedule', $open), []],
            'observation' => [route('support.tickets.observations.store', $open), []],
        ] as $bag => [$url, $payload]) {
            $this->actingAs($actor)->from(route('support.tickets.show', $open))->post($url, $payload)->assertSessionHasErrorsIn($bag);
            $content = $this->rendered($actor, route('support.tickets.show', $open), 'post', $url, $payload);
            $this->assertStringContainsString('data-form-errors="'.$bag.'"', $content);
            $this->assertSame(1, substr_count($content, 'data-form-errors='), "Solo el formulario {$bag} debe mostrar errores.");
        }

        $this->actingAs($actor)->from(route('support.tickets.show', $open))->patch(route('support.tickets.priority.update', $open), [])
            ->assertSessionHasErrors(['priority_id' => 'Seleccione una prioridad.'], null, 'priority');

        $observation = $this->lifecycle()->createObservation($open, ['title' => 'Revisar'], $actor);
        $this->actingAs($actor)->from(route('support.tickets.show', $open))->post(route('support.tickets.observations.transition', [$open, $observation]), ['state' => 'not_applicable'])
            ->assertSessionHasErrorsIn('observation'.$observation->id);
        $this->assertStringContainsString('data-form-errors="observation'.$observation->id.'"', $this->rendered($actor, route('support.tickets.show', $open), 'post', route('support.tickets.observations.transition', [$open, $observation]), ['state' => 'not_applicable']));
    }

    /** Submits a form from the ticket page and returns the page it redirects back to. */
    private function rendered(User $actor, string $from, string $method, string $url, array $payload): string
    {
        return $this->actingAs($actor)->from($from)->followingRedirects()->{$method}($url, $payload)->assertOk()->getContent();
    }

    // 7 — Spanish attribute names
    public function test_create_validation_uses_spanish_field_names(): void
    {
        app()->setLocale('es');
        $actor = $this->actor(self::ALL);

        $errors = $this->actingAs($actor)->post(route('support.tickets.store'), [])->assertSessionHasErrors(['customer_id', 'type_id'])->getSession()->get('errors')->getBag('default');

        $this->assertSame('El campo cliente es obligatorio.', $errors->first('customer_id'));
        $this->assertSame('El campo tipo de ticket es obligatorio.', $errors->first('type_id'));
        $this->assertSame('El campo contacto solicitante es obligatorio.', $errors->first('requester_contact_id'));
        foreach ($errors->all() as $message) {
            $this->assertDoesNotMatchRegularExpression('/\b(customer|requester|type|category|channel|priority) ?(id|contact)/i', $message);
        }
    }

    // 8 — documents on observations, incidents and sessions
    public function test_agent_with_access_sees_and_uploads_observation_documents_and_outsider_gets_403(): void
    {
        Storage::fake('docs');
        $admin = $this->actor(self::ALL);
        $agent = $this->actor(['support.view.own', 'support.attention.start', 'support.schedule', 'documents.upload', 'documents.download']);
        $outsider = $this->actor(['support.view.own', 'documents.upload']);
        $ticket = $this->ticket($admin, 'incidente-o-error');
        $this->tickets()->assign($ticket, $agent, $admin);
        $observation = $this->lifecycle()->createObservation($ticket, ['title' => 'Revisar'], $admin);
        $incident = $this->lifecycle()->saveIncident($ticket->fresh(), ['system' => 'ERP']);
        $this->scheduleActivity($ticket->fresh(), $admin);
        $session = $ticket->sessionDetails()->firstOrFail();

        $this->assertTrue($agent->can('view', $observation), 'observación');
        $this->assertTrue($agent->can('view', $incident), 'incidente');
        $this->assertTrue($agent->can('view', $session), 'sesión');
        $this->assertFalse($outsider->can('view', $observation));

        $content = $this->page($agent, $ticket);
        foreach ([route('support.tickets.observations.documents.store', [$ticket, $observation]), route('support.tickets.incidents.documents.store', [$ticket, $incident]), route('support.tickets.sessions.documents.store', [$ticket, $session])] as $url) {
            $this->assertStringContainsString('action="'.$url.'"', $content);
        }

        $this->actingAs($agent)->post(route('support.tickets.observations.documents.store', [$ticket, $observation]), ['file' => UploadedFile::fake()->create('evidencia.pdf', 1, 'application/pdf')])
            ->assertRedirect(route('support.tickets.show', $ticket));
        $this->assertSame(1, Document::query()->where('docable_id', $observation->id)->where('name', 'evidencia.pdf')->count());

        $this->actingAs($outsider)->post(route('support.tickets.observations.documents.store', [$ticket, $observation]), ['file' => UploadedFile::fake()->create('ajeno.pdf', 1, 'application/pdf')])
            ->assertForbidden();

        // The agent can also download it (the document resolves its ticket through the observation).
        $document = Document::query()->where('docable_id', $observation->id)->where('name', 'evidencia.pdf')->firstOrFail();
        $this->actingAs($agent)->get(route('documents.download', $document))->assertOk();
        $this->actingAs($outsider)->get(route('documents.download', $document))->assertForbidden();

        // The incident relation used by the ticket page exists.
        $this->assertSame(0, $incident->documents()->count());
    }

    // 9 — UX texts
    public function test_ux_texts_for_running_attention_customer_response_and_session_title(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->inProgress($actor);

        $this->actingAs($actor)->post(route('support.tickets.start', $ticket))
            ->assertSessionHas('info', 'La atención ya estaba en marcha; no se realizó ningún cambio.')->assertSessionMissing('status');

        $this->lifecycle()->schedule($ticket->fresh(), ['type_id' => ActivityType::query()->create(['name' => 'Visita', 'slug' => 'visita-titulo', 'is_active' => true])->id, 'title' => 'Visita', 'scheduled_at' => now()->addDay()], ['modality' => 'presential'], $actor);
        $content = $this->page($actor, $ticket);
        $this->assertStringNotContainsString('visible para el cliente', $content);
        $this->assertStringContainsString('Se registrará como respuesta dada al cliente en el historial del ticket.', $content);
        $this->assertStringContainsString('Asistencia: Presencial', $content);
        $this->assertStringNotContainsString('Asistencia: presential', $content);
    }

    // 10 — reopen KPIs
    public function test_reopened_ticket_is_not_counted_as_resolved_while_open(): void
    {
        $actor = $this->actor(self::ALL);
        $ticket = $this->resolved($actor);
        $ticket = $this->lifecycle()->transition($ticket, SupportStatus::SLUG_CLOSED, $actor, 'Validado', false, true);
        $ticket = $this->lifecycle()->transition($ticket, SupportStatus::SLUG_REOPENED, $actor, 'Volvió a fallar');

        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);
        $this->assertNull($ticket->validated_at);
        $first = $ticket->cycles()->orderBy('sequence')->firstOrFail();
        $this->assertNotNull($first->resolved_at);
        $this->assertNotNull($first->closed_at);

        $metrics = app(SupportDashboardService::class)->metrics([], $actor);
        $this->assertSame(0, $metrics['counts']['resolved']);
        $this->assertSame(0, $metrics['counts']['closed']);
        $this->assertSame(1, $metrics['counts']['ever_resolved']);
        $this->assertSame(1, $metrics['counts']['reopened']);
        $this->assertSame(0.0, $metrics['average_resolution_seconds']);
    }

    // 11 — create form fields
    public function test_create_form_offers_team_impact_and_general_observations(): void
    {
        $actor = $this->actor(self::ALL);
        $active = Team::query()->create(['name' => 'Mesa de ayuda', 'is_active' => true]);
        $inactive = Team::query()->create(['name' => 'Equipo archivado', 'is_active' => false]);

        $this->actingAs($actor)->get(route('support.tickets.create'))->assertOk()
            ->assertSee('name="team_id"', false)->assertSee('name="impact"', false)->assertSee('name="general_observations"', false)
            ->assertSee('Mesa de ayuda')->assertDontSee('Equipo archivado');

        $payload = $this->payload();
        $this->actingAs($actor)->post(route('support.tickets.store'), array_merge($payload, ['team_id' => $inactive->id]))
            ->assertSessionHasErrors(['team_id' => 'El equipo seleccionado no existe o no está activo.']);

        $this->actingAs($actor)->post(route('support.tickets.store'), array_merge($payload, ['team_id' => $active->id, 'impact' => 'Alto: detiene la facturación', 'general_observations' => 'Cliente prioritario']))->assertRedirect();
        $this->assertDatabaseHas('support_tickets', ['team_id' => $active->id, 'impact' => 'Alto: detiene la facturación', 'general_observations' => 'Cliente prioritario']);
    }

    private function failPeriod(string $slug): void
    {
        SupportStatusPeriod::creating(function (SupportStatusPeriod $period) use ($slug): void {
            if ($period->period_type === $slug) throw new InvalidOperationException('Fallo simulado.');
        });
    }

    private function scheduleActivity(SupportTicket $ticket, User $actor): Activity
    {
        $type = ActivityType::query()->firstOrCreate(['slug' => 'sesion-soporte-test'], ['name' => 'Sesión de soporte', 'is_active' => true]);

        return $this->lifecycle()->schedule($ticket, ['type_id' => $type->id, 'title' => 'Sesión', 'scheduled_at' => now()->addDay()], ['modality' => 'virtual'], $actor);
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

    /** @return array<string, mixed> */
    private function payload(string $typeSlug = 'capacitacion', array $customer = []): array
    {
        $customer = Customer::factory()->create($customer); $contact = Contact::factory()->forCustomer($customer)->create();
        return ['title' => 'Support', 'customer_id' => $customer->id, 'requester_contact_id' => $contact->id, 'type_id' => SupportTicketType::query()->where('slug', $typeSlug)->value('id'), 'category_id' => SupportCategory::query()->where('slug', 'capacitacion')->value('id'), 'channel_id' => SupportChannel::query()->where('slug', 'registro-interno')->value('id'), 'priority_id' => SupportPriority::query()->where('slug', 'media')->value('id'), 'description' => 'Test'];
    }

    private function ticket(User $actor, string $typeSlug = 'capacitacion', array $customer = []): SupportTicket
    {
        return $this->tickets()->create($this->payload($typeSlug, $customer), $actor);
    }
}
