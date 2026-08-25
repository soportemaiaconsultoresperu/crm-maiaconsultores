<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportTicketType;
use App\Models\User;
use App\Services\SupportDashboardService;
use App\Services\SupportTicketLifecycleService;
use App\Services\SupportTicketService;
use Database\Seeders\SupportCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupportTicketS02S07Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(SupportCatalogSeeder::class); }

    public function test_schedule_reschedule_incident_and_observation_history_are_preserved(): void
    {
        [$ticket, $actor] = $this->ticket(); app(SupportTicketService::class)->assign($ticket, $actor, $actor);
        $type = ActivityType::query()->create(['name'=>'Atención', 'slug'=>'atencion', 'is_active'=>true]);
        $service = app(SupportTicketLifecycleService::class);
        $activity = $service->schedule($ticket, ['type_id'=>$type->id, 'title'=>'Sesión', 'scheduled_at'=>now()->addDay()], ['modality'=>'virtual', 'topic'=>'Inicio'], $actor);
        $service->reschedule($activity, now()->addDays(2), 'Cliente solicitó cambio', $actor);
        $service->saveIncident($ticket, ['system'=>'CRM', 'actual_result'=>'Error reproducible']);
        $observation = $service->createObservation($ticket, ['title'=>'Validar acceso'], $actor);
        $service->transitionObservation($observation, 'lifted', $actor);
        $service->transitionObservation($observation, 'validated', $actor);
        $this->assertDatabaseCount('support_reschedules', 1); $this->assertDatabaseHas('support_incident_details', ['ticket_id'=>$ticket->id, 'system'=>'CRM']);
        $this->assertSame(3, $observation->fresh()->histories()->count());
    }

    public function test_lifecycle_creates_periods_and_a_new_cycle_on_reopen(): void
    {
        [$ticket, $actor] = $this->ticket(); $tickets = app(SupportTicketService::class); $tickets->assign($ticket, $actor, $actor);
        $lifecycle = app(SupportTicketLifecycleService::class);
        $lifecycle->transition($ticket->fresh(), 'en-atencion', $actor);
        $ticket->refresh(); $ticket->solution_summary = 'Configuración corregida'; $ticket->save();
        $lifecycle->transition($ticket, 'resuelto', $actor);
        $reopened = $lifecycle->transition($ticket->fresh(), 'reabierto', $actor, 'Persistió el problema');
        $this->assertSame('reabierto', $reopened->status->slug); $this->assertSame(2, $reopened->cycles()->count());
        $this->assertGreaterThanOrEqual(3, $reopened->statusPeriods()->count());
    }

    public function test_reject_requires_reason_and_dashboard_has_safe_empty_averages(): void
    {
        [$ticket, $actor] = $this->ticket(); $observation = app(SupportTicketLifecycleService::class)->createObservation($ticket, ['title'=>'Pendiente'], $actor);
        try { app(SupportTicketLifecycleService::class)->transitionObservation($observation, 'rejected', $actor); $this->fail('Expected validation.'); } catch (ValidationException $e) { $this->assertArrayHasKey('reason', $e->errors()); }
        $metrics = app(SupportDashboardService::class)->metrics();
        $this->assertSame(0.0, $metrics['average_resolution_seconds']); $this->assertSame(0.0, $metrics['reopened_percent']);
    }

    /** @return array{0:\App\Models\SupportTicket,1:User} */
    private function ticket(): array
    {
        $actor=User::factory()->create(); $customer=Customer::factory()->create(); $contact=Contact::factory()->forCustomer($customer)->create();
        return [app(SupportTicketService::class)->create(['title'=>'Support','customer_id'=>$customer->id,'requester_contact_id'=>$contact->id,'type_id'=>SupportTicketType::query()->where('slug','capacitacion')->value('id'),'category_id'=>SupportCategory::query()->where('slug','capacitacion')->value('id'),'channel_id'=>SupportChannel::query()->where('slug','registro-interno')->value('id'),'priority_id'=>SupportPriority::query()->where('slug','media')->value('id'),'description'=>'Test'], $actor),$actor];
    }
}
