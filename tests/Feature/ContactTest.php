<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Services\ContactService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * RF-CON-001..003: contact CRUD with the transactional single-primary
 * guarantee (docs/BASE_DATOS.md §3.3).
 */
class ContactTest extends TestCase
{
    use RefreshDatabase;

    private ContactService $service;

    private User $actor;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(ContactService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->customer = Customer::factory()->forOwner($this->actor)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $base = [
            'first_name' => 'Rosa',
            'last_name' => 'Quispe',
            'position' => 'Gerente de Compras',
            'area' => 'Compras',
            'phone' => '+51 966 555 444',
            'whatsapp' => '966 555 444',
            'email' => '  Rosa.Quispe@Example.COM ',
        ];

        return array_merge($base, $overrides);
    }

    public function test_create_fills_email_norm_and_requires_names(): void
    {
        $contact = $this->service->create($this->customer, $this->validData(), $this->actor);

        $this->assertSame('rosa.quispe@example.com', $contact->email_norm);
        $this->assertTrue((bool) $contact->is_active);
        $this->assertFalse((bool) $contact->is_primary);
        $this->assertSame($this->actor->id, $contact->created_by);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->customer, $this->validData(['last_name' => null]), $this->actor);
    }

    public function test_create_with_is_primary_moves_primariness(): void
    {
        $first = $this->service->create($this->customer, $this->validData(), $this->actor);
        $this->service->setPrimary($first, $this->actor);

        $second = $this->service->create(
            $this->customer,
            $this->validData(['email' => 'Segundo@Example.com', 'is_primary' => true]),
            $this->actor,
        );

        $this->assertTrue((bool) $second->refresh()->is_primary);
        $this->assertFalse((bool) $first->refresh()->is_primary);

        $this->service->assertSinglePrimary($this->customer);
        $this->assertSame($second->id, $this->customer->primaryContact()->id);
    }

    public function test_set_primary_moves_primariness_in_one_transaction_with_audit(): void
    {
        $old = $this->service->create($this->customer, $this->validData(), $this->actor);
        $this->service->setPrimary($old, $this->actor);

        $new = $this->service->create(
            $this->customer,
            $this->validData(['email' => 'Nuevo@Example.com']),
            $this->actor,
        );

        $this->service->setPrimary($new, $this->actor);

        $this->assertFalse((bool) $old->refresh()->is_primary);
        $this->assertTrue((bool) $new->refresh()->is_primary);
        $this->service->assertSinglePrimary($this->customer);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $this->customer->id)
            ->where('event', 'contact-primary-changed')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame($old->id, (int) $log->properties['old_contact_id']);
        $this->assertSame($new->id, (int) $log->properties['new_contact_id']);
    }

    public function test_deactivate_primary_leaves_customer_without_primary(): void
    {
        $primary = $this->service->create($this->customer, $this->validData(), $this->actor);
        $this->service->setPrimary($primary, $this->actor);

        $this->service->deactivate($primary, $this->actor, 'Dejó la empresa');

        $this->assertSoftDeleted($primary);

        // No auto-promotion: the customer stays without a primary contact.
        $this->assertNull($this->customer->primaryContact());
        $this->service->assertSinglePrimary($this->customer);

        // Primariness is reassigned explicitly afterwards.
        $replacement = $this->service->create(
            $this->customer,
            $this->validData(['email' => 'Reemplazo@Example.com']),
            $this->actor,
        );
        $this->service->setPrimary($replacement, $this->actor);
        $this->assertSame($replacement->id, $this->customer->primaryContact()->id);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Contact::class)
            ->where('subject_id', $primary->id)
            ->where('event', 'contact-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Dejó la empresa', $log->properties['reason']);
        $this->assertTrue((bool) $log->properties['was_primary']);
    }

    public function test_update_recomputes_email_norm(): void
    {
        $contact = $this->service->create($this->customer, $this->validData(), $this->actor);

        $contact = $this->service->update($contact, ['email' => ' Actualizado@Example.com '], $this->actor);

        $this->assertSame('actualizado@example.com', $contact->email_norm);
        $this->assertSame($this->actor->id, $contact->updated_by);
    }

    public function test_assert_single_primary_detects_invariant_violation(): void
    {
        Contact::factory()->forCustomer($this->customer)->primary()->create();
        Contact::factory()->forCustomer($this->customer)->primary()->create();

        $this->expectException(\RuntimeException::class);

        $this->service->assertSinglePrimary($this->customer);
    }

    public function test_contact_policy_follows_customer_scope(): void
    {
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $salespersonOne = User::factory()->create(['is_active' => true]);
        $salespersonOne->assignRole('vendedor');

        $salespersonTwo = User::factory()->create(['is_active' => true]);
        $salespersonTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($salespersonOne->id);

        $ownCustomer = Customer::factory()->forOwner($salespersonOne)->create();
        $foreignCustomer = Customer::factory()->forOwner($salespersonTwo)->create();

        $own = Contact::factory()->forCustomer($ownCustomer)->create();
        $foreign = Contact::factory()->forCustomer($foreignCustomer)->create();

        $this->assertTrue(Gate::forUser($salespersonOne)->allows('view', $own));
        $this->assertFalse(Gate::forUser($salespersonOne)->allows('view', $foreign));
        $this->assertTrue(Gate::forUser($supervisor)->allows('view', $own));
        $this->assertFalse(Gate::forUser($supervisor)->allows('view', $foreign));
    }
}
