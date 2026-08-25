<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\Team;
use App\Models\User;
use App\Services\SupportTicketScopeService;
use App\Services\SupportTicketService;
use Database\Seeders\SupportCatalogSeeder;
use Database\Seeders\SupportPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportTicketS01Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupportCatalogSeeder::class);
        $this->seed(SupportPermissionsSeeder::class);
    }

    public function test_creation_generates_correlative_and_new_status(): void
    {
        $actor = $this->userWith(['support.create']);
        $ticket = $this->service()->create($this->ticketData(), $actor);

        $this->assertSame('SUP-'.now()->format('Y').'-00001', $ticket->code);
        $this->assertSame('Solicitud de capacitación', $ticket->title);
        $this->assertNull($ticket->responsible_id);
        $this->assertNull($ticket->assigned_at);
        $this->assertSame(SupportStatus::SLUG_NEW, $ticket->status->slug);
        $this->assertSame($actor->id, $ticket->created_by);
    }

    public function test_title_is_required_by_service_and_http_request(): void
    {
        $actor = $this->userWith(['support.create']);
        $data = $this->ticketData(['title' => '']);

        try {
            $this->service()->create($data, $actor);
            $this->fail('Expected title validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }

        $this->assertDatabaseMissing('support_tickets', ['title' => '']);
    }

    public function test_requester_contact_must_belong_to_customer(): void
    {
        $actor = $this->userWith(['support.create']);
        $otherCustomer = Customer::factory()->create();
        $wrongContact = Contact::factory()->forCustomer($otherCustomer)->create();

        $this->expectException(ValidationException::class);

        $this->service()->create($this->ticketData(['requester_contact_id' => $wrongContact->id]), $actor);
    }

    public function test_assignment_reassignment_history_and_basic_state_rules(): void
    {
        $actor = $this->userWith(['support.create', 'support.assign', 'support.reassign', 'support.cancel']);
        $firstResponsible = User::factory()->create();
        $secondResponsible = User::factory()->create();
        $team = Team::query()->create(['name' => 'Soporte Lima', 'is_active' => true]);
        $otherTeam = Team::query()->create(['name' => 'Soporte Norte', 'is_active' => true]);
        $ticket = $this->service()->create($this->ticketData(), $actor);

        $assigned = $this->service()->assign($ticket, $firstResponsible, $actor, $team->id);

        $this->assertSame(SupportStatus::SLUG_ASSIGNED, $assigned->status->slug);
        $this->assertSame($firstResponsible->id, $assigned->responsible_id);
        $this->assertNotNull($assigned->assigned_at);
        $this->assertDatabaseHas('support_assignments', [
            'ticket_id' => $ticket->id,
            'previous_responsible_id' => null,
            'new_responsible_id' => $firstResponsible->id,
            'new_team_id' => $team->id,
        ]);

        try {
            $this->service()->assign($assigned, $secondResponsible, $actor, $otherTeam->id);
            $this->fail('Expected reassignment reason validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $reassigned = $this->service()->assign($assigned, $secondResponsible, $actor, $otherTeam->id, 'Escalamiento manual');

        $this->assertSame($secondResponsible->id, $reassigned->responsible_id);
        $this->assertSame(2, $reassigned->assignments()->count());
        $this->assertDatabaseHas('support_assignments', [
            'ticket_id' => $ticket->id,
            'previous_responsible_id' => $firstResponsible->id,
            'new_responsible_id' => $secondResponsible->id,
            'reason' => 'Escalamiento manual',
        ]);

        $cancelled = $this->service()->cancel($reassigned, $actor, 'Solicitud duplicada');
        $this->assertSame(SupportStatus::SLUG_CANCELLED, $cancelled->status->slug);
        $this->assertSame('Solicitud duplicada', $cancelled->cancel_reason);

        $this->expectException(InvalidOperationException::class);
        $this->service()->assign($cancelled, $firstResponsible, $actor, $team->id);
    }

    public function test_internal_note_does_not_set_first_response_but_customer_response_does(): void
    {
        $actor = $this->userWith(['support.create', 'support.updates.create']);
        $ticket = $this->service()->create($this->ticketData(), $actor);

        $internal = $this->service()->addInternalNote($ticket, 'Revisar internamente con sistemas.', $actor);
        $ticket->refresh();

        $this->assertTrue($internal->is_internal);
        $this->assertFalse($internal->is_customer_response);
        $this->assertNull($ticket->first_responded_at);

        $response = $this->service()->addCustomerResponse($ticket, 'Se contactó al cliente y se brindó primera orientación.', $actor);
        $ticket->refresh();

        $this->assertFalse($response->is_internal);
        $this->assertTrue($response->is_customer_response);
        $this->assertNotNull($ticket->first_responded_at);
    }

    public function test_scope_uses_ticket_responsible_team_and_creator(): void
    {
        $supervisor = $this->userWith(['support.view.team']);
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team = Team::query()->create(['name' => 'Soporte Sur', 'supervisor_id' => $supervisor->id, 'is_active' => true]);
        $team->members()->attach($member->id);

        $teamTicket = $this->service()->create($this->ticketData(), $supervisor);
        $this->service()->assign($teamTicket, $member, $supervisor, $team->id);

        $ownDraft = $this->service()->create($this->ticketData(), $supervisor);

        $outsideTicket = $this->service()->create($this->ticketData(), $outsider);
        $this->service()->assign($outsideTicket, $outsider, $outsider, null);

        $visibleIds = app(SupportTicketScopeService::class)
            ->apply(SupportTicket::query(), $supervisor)
            ->pluck('id')
            ->all();

        $this->assertContains($teamTicket->id, $visibleIds);
        $this->assertContains($ownDraft->id, $visibleIds);
        $this->assertNotContains($outsideTicket->id, $visibleIds);
    }

    public function test_policy_requires_support_permissions_and_scope(): void
    {
        $plain = User::factory()->create();
        $owner = $this->userWith(['support.view.own']);
        $other = $this->userWith(['support.view.own']);
        $ticket = $this->service()->create($this->ticketData(), $owner);
        $this->service()->assign($ticket, $owner, $owner, null);

        $this->assertFalse(Gate::forUser($plain)->allows('viewAny', SupportTicket::class));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($other)->allows('view', $ticket));
    }

    public function test_deactivated_catalog_rows_remain_historical_but_cannot_be_used_for_new_tickets(): void
    {
        $actor = $this->userWith(['support.create']);
        $ticket = $this->service()->create($this->ticketData(), $actor);
        $type = $ticket->type;
        $type->update(['is_active' => false]);

        $this->assertSame($type->id, $ticket->fresh()->type->id);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->ticketData(['type_id' => $type->id]), $actor);
    }

    public function test_tickets_are_soft_deleted_not_physically_removed(): void
    {
        $actor = $this->userWith(['support.create']);
        $ticket = $this->service()->create($this->ticketData(), $actor);

        $ticket->delete();

        $this->assertSoftDeleted('support_tickets', ['id' => $ticket->id]);
        $this->assertTrue(SupportTicket::withTrashed()->whereKey($ticket->id)->exists());
    }

    public function test_activity_accepts_support_ticket_subject_key(): void
    {
        $this->assertSame(SupportTicket::class, Activity::morphClass('support_ticket'));
        $this->assertSame('support_ticket', Activity::subjectKey(SupportTicket::class));
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticketData(array $overrides = []): array
    {
        $customer = Customer::factory()->create();
        $contact = Contact::factory()->forCustomer($customer)->create();

        return array_merge([
            'title' => 'Solicitud de capacitación',
            'customer_id' => $customer->id,
            'requester_contact_id' => $contact->id,
            'type_id' => SupportTicketType::query()->where('slug', 'capacitacion')->value('id'),
            'category_id' => SupportCategory::query()->where('slug', 'capacitacion')->value('id'),
            'channel_id' => SupportChannel::query()->where('slug', 'registro-interno')->value('id'),
            'priority_id' => SupportPriority::query()->where('slug', 'media')->value('id'),
            'description' => 'El cliente solicita capacitación funcional inicial.',
        ], $overrides);
    }

    private function service(): SupportTicketService
    {
        return app(SupportTicketService::class);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user;
    }
}
