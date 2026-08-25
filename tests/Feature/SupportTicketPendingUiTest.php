<?php

namespace Tests\Feature;

use App\Models\{ActivityType, Contact, Customer, SupportCategory, SupportChannel, SupportPriority, SupportTicketType, User};
use App\Services\{SupportDashboardService, SupportTicketLifecycleService, SupportTicketService};
use Database\Seeders\{SupportCatalogSeeder, SupportPermissionsSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportTicketPendingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(SupportCatalogSeeder::class); $this->seed(SupportPermissionsSeeder::class); }

    public function test_lifecycle_route_and_session_participant_are_persisted(): void
    {
        $actor=$this->actor(['support.view.own','support.attention.start','support.schedule']); $ticket=$this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket,$actor,$actor);
        $type=ActivityType::query()->create(['name'=>'Atención','slug'=>'atencion','is_active'=>true]);
        app(SupportTicketLifecycleService::class)->schedule($ticket->fresh(),['type_id'=>$type->id,'title'=>'Sesión','scheduled_at'=>now()->addDay()],['modality'=>'virtual'], $actor);
        $this->actingAs($actor)->post(route('support.tickets.start',$ticket))->assertRedirect();
        $this->assertSame('en-atencion',$ticket->fresh()->status->slug);
        $session=$ticket->fresh()->sessionDetails()->firstOrFail();
        $this->actingAs($actor)->post(route('support.tickets.sessions.participants.store',[$ticket,$session]),['name'=>'Ana Cliente','email'=>'ana@example.test','attended'=>true])->assertRedirect();
        $this->assertDatabaseHas('support_session_participants',['support_session_detail_id'=>$session->id,'email'=>'ana@example.test','attended'=>true]);
    }

    public function test_ticket_document_upload_uses_private_fake_disk(): void
    {
        Storage::fake('docs');
        $actor=$this->actor(['support.view.own','documents.upload']); $ticket=$this->ticket($actor);
        $this->actingAs($actor)->post(route('support.tickets.documents.store',$ticket), ['file'=>UploadedFile::fake()->create('evidence.txt',1,'text/plain')])->assertRedirect(route('support.tickets.show',$ticket));
        $document=$ticket->fresh()->documents()->firstOrFail();
        $this->assertTrue(Storage::disk('docs')->exists($document->path));
    }

    public function test_dashboard_filters_and_csv_export_are_available(): void
    {
        $actor=$this->actor(['support.reports.view']); $ticket=$this->ticket($actor); $other=$this->ticket($actor);
        $metrics=app(SupportDashboardService::class)->metrics(['customer_id'=>$ticket->customer_id]);
        $this->assertSame(1,$metrics['total']);
        $this->actingAs($actor)->get(route('support.export',['customer_id'=>$ticket->customer_id]))->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
        $this->assertNotSame($ticket->customer_id,$other->customer_id);
    }

    public function test_detail_allows_assignment_shows_requester_and_hides_start_when_in_progress(): void
    {
        $actor = $this->actor(['support.view.any', 'support.assign', 'support.attention.start']);
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Responsable Soporte']);
        $ticket = $this->ticket($actor);

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeText('Contacto solicitante')
        ->assertSeeText($ticket->requester->first_name)
        ->assertSeeText('Seleccione responsable');

        $this->actingAs($actor)->post(route('support.tickets.assign', $ticket), [
        'responsible_id' => $responsible->id,
        ])->assertRedirect();

        $this->assertSame($responsible->id, $ticket->fresh()->responsible_id);

        $this->actingAs($actor)->post(route('support.tickets.start', $ticket))->assertRedirect();

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeText('Atención en marcha')
        ->assertDontSee('>Iniciar atención<', false);
    }

    public function test_reassignment_ui_requires_reason_and_surfaces_validation_error(): void
    {
        $actor = $this->actor(['support.view.any', 'support.assign', 'support.reassign']);
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Responsable Actual']);
        $nextResponsible = User::factory()->create(['is_active' => true, 'name' => 'Responsable Nuevo']);
        $ticket = $this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket, $responsible, $actor);

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket->fresh()))
            ->assertOk()
            ->assertSee('placeholder="Motivo de reasignación (obligatorio)"', false)
            ->assertSee('name="reason"', false)
            ->assertSee('required', false)
            ->assertSeeText('Reasignar');

        $this->actingAs($actor)
            ->from(route('support.tickets.show', $ticket))
            ->post(route('support.tickets.assign', $ticket), ['responsible_id' => $nextResponsible->id])
            ->assertRedirect(route('support.tickets.show', $ticket))
            ->assertSessionHasErrors('reason');
    }

    public function test_schedule_route_assigns_new_unassigned_ticket_and_persists_split_session_payload(): void
    {
        $actor = $this->actor(['support.view.any', 'support.schedule']);
        $ticket = $this->ticket($actor);
        $type = ActivityType::query()->create(['name' => 'Capacitación', 'slug' => 'capacitacion-test', 'is_active' => true]);

        $this->actingAs($actor)->post(route('support.tickets.schedule', $ticket), [
            'type_id' => $type->id,
            'title' => 'Sesión de soporte',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'modality' => 'virtual',
            'topic' => 'Uso del sistema',
            'objective' => 'Resolver consulta',
            'agenda' => 'Revisión guiada',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($actor->id, $ticket->responsible_id);
        $this->assertSame('programado', $ticket->status->slug);
        $this->assertDatabaseHas('activities', ['title' => 'Sesión de soporte', 'subject_type' => \App\Models\SupportTicket::class, 'subject_id' => $ticket->id]);
        $this->assertDatabaseHas('support_session_details', ['ticket_id' => $ticket->id, 'modality' => 'virtual', 'topic' => 'Uso del sistema', 'objective' => 'Resolver consulta', 'agenda' => 'Revisión guiada']);
    }

    public function test_schedule_route_can_add_activity_without_regressing_in_progress_ticket(): void
    {
        $actor = $this->actor(['support.view.any', 'support.attention.start', 'support.schedule']);
        $ticket = $this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket, $actor, $actor);
        $this->actingAs($actor)->post(route('support.tickets.start', $ticket))->assertRedirect();
        $this->assertSame('en-atencion', $ticket->fresh()->status->slug);
        $type = ActivityType::query()->create(['name' => 'Seguimiento', 'slug' => 'seguimiento-progress-test', 'is_active' => true]);

        $this->actingAs($actor)->post(route('support.tickets.schedule', $ticket), [
            'type_id' => $type->id,
            'title' => 'Seguimiento técnico',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'modality' => 'virtual',
            'topic' => 'Revisión de avance',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('en-atencion', $ticket->status->slug);
        $this->assertDatabaseHas('activities', ['title' => 'Seguimiento técnico', 'subject_type' => \App\Models\SupportTicket::class, 'subject_id' => $ticket->id]);
        $this->assertDatabaseHas('support_session_details', ['ticket_id' => $ticket->id, 'modality' => 'virtual', 'topic' => 'Revisión de avance']);
    }

    public function test_schedule_route_can_add_another_activity_to_already_scheduled_ticket(): void
    {
        $actor = $this->actor(['support.view.any', 'support.schedule']);
        $ticket = $this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket, $actor, $actor);
        $type = ActivityType::query()->create(['name' => 'Atención remota', 'slug' => 'atencion-remota-test', 'is_active' => true]);

        foreach (['Primera sesión', 'Segunda sesión'] as $title) {
            $this->actingAs($actor)->post(route('support.tickets.schedule', $ticket), [
                'type_id' => $type->id,
                'title' => $title,
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'modality' => 'virtual',
            ])->assertRedirect();
        }

        $ticket->refresh();
        $this->assertSame('programado', $ticket->status->slug);
        $this->assertSame(2, $ticket->activities()->count());
    }

    public function test_detail_post_actions_include_sweetalert_confirmation_and_loading_attributes(): void
    {
        $actor = $this->actor(['support.view.any', 'support.reassign', 'support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel', 'support.updates.create', 'support.schedule', 'support.update', 'support.observations.create']);
        $responsible = User::factory()->create(['is_active' => true]);
        $ticket = $this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket, $responsible, $actor);
        $type = ActivityType::query()->create(['name' => 'Seguimiento', 'slug' => 'seguimiento-test', 'is_active' => true]);
        app(SupportTicketLifecycleService::class)->schedule($ticket->fresh(), ['type_id' => $type->id, 'title' => 'Actividad previa', 'scheduled_at' => now()->addDay()], ['modality' => 'virtual', 'topic' => 'Seguimiento'], $actor);
        app(SupportTicketLifecycleService::class)->createObservation($ticket->fresh(), ['title' => 'Validar evidencia'], $actor);

        $content = $this->actingAs($actor)->get(route('support.tickets.show', $ticket->fresh()))->assertOk()->getContent();

        foreach (['support.tickets.assign', 'support.tickets.start', 'support.tickets.resolve', 'support.tickets.close', 'support.tickets.reopen', 'support.tickets.cancel', 'support.tickets.notes.store', 'support.tickets.responses.store', 'support.tickets.schedule', 'support.tickets.incident.store', 'support.tickets.observations.store'] as $routeName) {
            $this->assertStringContainsString('action="'.route($routeName, $ticket).'"', $content);
        }

        $this->assertStringContainsString('support/tickets/'.$ticket->id.'/activities/', $content);
        $this->assertStringContainsString('support/tickets/'.$ticket->id.'/observations/', $content);
        $this->assertStringContainsString('support/tickets/'.$ticket->id.'/sessions/', $content);
        $this->assertSame(14, substr_count($content, 'data-swal-confirm'));
        $this->assertSame(14, substr_count($content, 'data-swal-loading'));
    }

    public function test_resolve_close_reopen_and_cancel_texts_remain_visible_on_ticket_detail(): void
    {
        $actor = $this->actor(['support.view.any', 'support.assign', 'support.attention.start', 'support.resolve', 'support.close', 'support.reopen', 'support.cancel']);
        $ticket = $this->ticket($actor);
        app(SupportTicketService::class)->assign($ticket, $actor, $actor);

        $this->actingAs($actor)->post(route('support.tickets.start', $ticket))->assertRedirect();

        $solution = 'Se ajustó la configuración y se validó con el cliente.';
        $this->actingAs($actor)->post(route('support.tickets.resolve', $ticket), [
            'solution_summary' => $solution,
        ])->assertRedirect();

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Registro de acciones')
            ->assertSeeText('Resumen de solución')
            ->assertSeeText($solution);

        $closeReason = 'Cliente confirmó que la solución quedó validada.';
        $this->actingAs($actor)->post(route('support.tickets.close', $ticket), [
            'reason' => $closeReason,
        ])->assertRedirect();

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Validación de cierre')
            ->assertSeeText($closeReason);

        $reopenReason = 'Cliente reportó una nueva evidencia relacionada.';
        $this->actingAs($actor)->post(route('support.tickets.reopen', $ticket), [
            'reason' => $reopenReason,
        ])->assertRedirect();

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Motivo de reapertura')
            ->assertSeeText($reopenReason);

        $cancelReason = 'Se cancela por solicitud duplicada.';
        $this->actingAs($actor)->post(route('support.tickets.cancel', $ticket), [
            'reason' => $cancelReason,
        ])->assertRedirect();

        $this->actingAs($actor)->get(route('support.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeText('Motivo de cancelación')
            ->assertSeeText($cancelReason);
    }

    public function test_create_form_marks_contacts_by_customer_for_client_side_filtering(): void
    {
        $actor = $this->actor(['support.create']);
        $firstCustomer = Customer::factory()->create();
        $secondCustomer = Customer::factory()->create();
        Contact::factory()->forCustomer($firstCustomer)->create(['first_name' => 'Ana', 'last_name' => 'Zapata']);
        Contact::factory()->forCustomer($firstCustomer)->create(['first_name' => 'Bruno', 'last_name' => 'Alvarez']);
        Contact::factory()->forCustomer($secondCustomer)->create(['first_name' => 'Carlos', 'last_name' => 'Mendoza']);

        $response = $this->actingAs($actor)->get(route('support.tickets.create'));

        $response->assertOk()
        ->assertSee('data-customer-id="'.$firstCustomer->id.'"', false)
        ->assertSee('data-customer-id="'.$secondCustomer->id.'"', false)
        ->assertSee('Seleccione primero un cliente', false);

        $this->assertLessThan(
        strpos($response->getContent(), 'Zapata Ana'),
        strpos($response->getContent(), 'Alvarez Bruno'),
        'Contacts should be rendered alphabetically by last name, then first name.'
        );
    }

    private function actor(array $permissions): User { app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); foreach($permissions as $permission) Permission::firstOrCreate(['name'=>$permission,'guard_name'=>'web']); $user=User::factory()->create(['is_active' => true]); $user->givePermissionTo($permissions); app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions(); return $user; }
    private function ticket(User $actor) { $customer=Customer::factory()->create(); $contact=Contact::factory()->forCustomer($customer)->create(); return app(SupportTicketService::class)->create(['title'=>'Support','customer_id'=>$customer->id,'requester_contact_id'=>$contact->id,'type_id'=>SupportTicketType::query()->where('slug','capacitacion')->value('id'),'category_id'=>SupportCategory::query()->where('slug','capacitacion')->value('id'),'channel_id'=>SupportChannel::query()->where('slug','registro-interno')->value('id'),'priority_id'=>SupportPriority::query()->where('slug','media')->value('id'),'description'=>'Test'], $actor); }
}
