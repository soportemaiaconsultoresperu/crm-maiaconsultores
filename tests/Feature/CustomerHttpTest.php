<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-CLI-001..006 + RF-CON-001..003 (HTTP layer): routes, authorization,
 * contact CRUD from the customer's ficha, export and deactivation. Roles
 * come from RolesAndPermissionsSeeder (B02 gotcha: actingAs users always
 * carry an explicit is_active = true).
 */
class CustomerHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'juridica',
            'legal_name' => 'Corporación Andina S.A.C.',
            'trade_name' => 'Andina',
            'doc_type' => 'ruc',
            'doc_number' => '20512345678',
            'phone' => '987654321',
            'email' => 'contacto@andina.example.com',
            'owner_id' => $this->salespersonOne->id,
        ], $overrides);
    }

    public function test_salesperson_cannot_open_another_salespersons_customer_show(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get("/customers/{$customer->id}")
            ->assertForbidden();

        $this->actingAs($this->salespersonTwo)
            ->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertSee($customer->code);
    }

    public function test_index_is_scoped_to_own_customers(): void
    {
        $mine = Customer::factory()->forOwner($this->salespersonOne)->create();
        $other = Customer::factory()->forOwner($this->salespersonTwo)->create();

        $this->actingAs($this->salespersonOne)
            ->get('/customers')
            ->assertOk()
            ->assertSee($mine->code)
            ->assertDontSee($other->code);
    }

    public function test_store_creates_cli_coded_customer_and_redirects_to_show(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->post('/customers', $this->validData());

        $customer = Customer::query()->where('doc_number', '20512345678')->first();

        $this->assertNotNull($customer);
        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status');

        $year = now()->format('Y');
        $this->assertMatchesRegularExpression("/^CLI-{$year}-\\d{5}$/", $customer->code);
        $this->assertSame($this->salespersonOne->id, $customer->owner_id);
    }

    public function test_show_renders_contact_table_and_quotations_card(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();

        $this->actingAs($this->salespersonOne)
            ->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertSee($customer->code)
            ->assertSee('Oportunidades')
            ->assertSee('B04')
            ->assertSee('Cotizaciones')
            ->assertSee('Nueva cotización');
    }

    public function test_contact_create_from_customer_show_becomes_primary(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->post("/customers/{$customer->id}/contacts", [
                'customer_id' => $customer->id,
                'first_name' => 'Rosa',
                'last_name' => 'Quispe',
                'position' => 'Gerente de Compras',
                'email' => 'rosa.quispe@andina.example.com',
                'is_primary' => '1',
            ]);

        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status');

        $contact = Contact::query()->where('email', 'rosa.quispe@andina.example.com')->first();

        $this->assertNotNull($contact);
        $this->assertTrue((bool) $contact->is_primary);
        $this->assertSame($customer->id, $contact->customer_id);
    }

    public function test_set_primary_reassigns_primariness_and_keeps_single_primary(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();
        $old = Contact::factory()->forCustomer($customer)->primary()->create();
        $new = Contact::factory()->forCustomer($customer)->create();

        $response = $this->actingAs($this->salespersonOne)
            ->post("/contacts/{$new->id}/set-primary");

        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status');

        $this->assertFalse((bool) $old->refresh()->is_primary);
        $this->assertTrue((bool) $new->refresh()->is_primary);

        $primaryCount = Contact::query()
            ->where('customer_id', $customer->id)
            ->where('is_primary', true)
            ->count();

        $this->assertSame(1, $primaryCount);
    }

    public function test_admin_deactivates_contact_with_reason(): void
    {
        $customer = Customer::factory()->forOwner($this->salespersonOne)->create();
        $contact = Contact::factory()->forCustomer($customer)->create();

        $response = $this->actingAs($this->admin)
            ->post("/contacts/{$contact->id}/destroy", [
                'reason' => 'Ya no labora en la empresa.',
            ]);

        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status');

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_export_returns_xlsx_with_permission_and_403_without(): void
    {
        Customer::factory()->forOwner($this->salespersonOne)->create();

        // Seeded vendedor lacks customers.export.
        $this->actingAs($this->salespersonOne)
            ->get('/customers-export')
            ->assertForbidden();

        $response = $this->actingAs($this->admin)
            ->get('/customers-export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_deactivate_customer_soft_deletes_with_reason(): void
    {
        $customer = Customer::factory()->forOwner($this->admin)->create();

        // Seeded vendedor lacks customers.deactivate; only admin may deactivate.
        $this->actingAs($this->salespersonOne)
            ->post("/customers/{$customer->id}", ['reason' => 'intento'])
            ->assertForbidden();

        $response = $this->actingAs($this->admin)
            ->post("/customers/{$customer->id}", [
                'reason' => 'Cliente solicitó cierre de relación comercial.',
            ]);

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('status');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }
}
