<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-LEAD-013 / ADR-001 (HTTP layer): the conversion form prefills from the
 * lead, the POST creates exactly one customer per lead, double conversion
 * surfaces as a flash error and never a second customer.
 */
class ConversionHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salesperson = User::factory()->create(['is_active' => true]);
        $this->salesperson->assignRole('vendedor');
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'juridica',
            'legal_name' => 'Corporación Andina S.A.C.',
            'doc_type' => 'ruc',
            'doc_number' => '20512345678',
            'email' => 'contacto@andina.example.com',
        ], $overrides);
    }

    public function test_convert_form_requires_leads_convert_permission(): void
    {
        $lead = Lead::factory()->forOwner($this->admin)->create();
        $ownLead = Lead::factory()->forOwner($this->salesperson)->create();

        // Seeded supervisor lacks leads.convert.
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $this->actingAs($supervisor)
            ->get("/leads/{$lead->id}/convert")
            ->assertForbidden();

        $this->actingAs($this->salesperson)
            ->get("/leads/{$ownLead->id}/convert")
            ->assertOk();
    }

    public function test_convert_form_prefills_customer_fields_from_lead(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create([
            'person_type' => 'juridica',
            'company_name' => 'Corporación Andina S.A.C.',
            'first_name' => 'Rosa',
        ]);

        $this->actingAs($this->salesperson)
            ->get("/leads/{$lead->id}/convert")
            ->assertOk()
            ->assertSee('value="Corporación Andina S.A.C."', false)
            ->assertSee('value="Rosa"', false)
            ->assertSee($lead->code);
    }

    public function test_convert_post_creates_customer_with_contact_and_redirects_to_show(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $response = $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload() + [
                'contact' => [
                    'first_name' => 'Rosa',
                    'last_name' => 'Quispe',
                    'position' => 'Gerente de Compras',
                    'email' => 'rosa.quispe@andina.example.com',
                ],
            ]);

        $customer = Customer::query()->where('doc_number', '20512345678')->first();

        $this->assertNotNull($customer);
        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status', "Prospecto {$lead->code} convertido: cliente {$customer->code} creado correctamente.");

        $this->assertSame($lead->id, $customer->converted_from_lead_id);
        $this->assertSame('convertido', $lead->refresh()->status->slug);

        $contact = $customer->contacts()->first();
        $this->assertNotNull($contact);
        $this->assertTrue((bool) $contact->is_primary);
    }

    public function test_double_conversion_flashes_error_and_creates_no_second_customer(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $first = $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload());
        $first->assertRedirect();

        $second = $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload([
                'legal_name' => 'Segunda Intento S.A.C.',
                'doc_number' => '20999888777',
            ]));

        $second->assertRedirect(route('leads.show', $lead));
        $second->assertSessionHas('error');

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, Customer::query()->where('doc_number', '20999888777')->count());

        // The GET form also refuses an already-converted lead.
        $customer = Customer::query()->first();

        $this->actingAs($this->salesperson)
            ->get("/leads/{$lead->id}/convert")
            ->assertRedirect(route('leads.show', $lead));
        $this->assertSame(1, Customer::query()->count());
        $this->assertNotNull($customer);
    }

    public function test_customer_show_displays_conversion_banner_linking_to_lead(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload())
            ->assertRedirect();

        $customer = Customer::query()->firstOrFail();

        $this->actingAs($this->salesperson)
            ->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertSee($customer->code)
            ->assertSee('creado a partir del prospecto', false)
            ->assertSee($lead->code);
    }

    public function test_lead_show_hides_convert_button_after_conversion(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $this->actingAs($this->salesperson)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Convertir a cliente');

        $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload())
            ->assertRedirect();

        $this->actingAs($this->salesperson)
            ->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertDontSee('Convertir a cliente');
    }

    public function test_convert_post_validation_error_rerenders_without_customer(): void
    {
        $lead = Lead::factory()->forOwner($this->salesperson)->create();

        $response = $this->actingAs($this->salesperson)
            ->post("/leads/{$lead->id}/convert", $this->customerPayload([
                'legal_name' => null,
                'doc_type' => 'ruc',
                'doc_number' => '12', // wrong length for RUC
            ]));

        $response->assertSessionHasErrors(['legal_name', 'doc_number']);
        $this->assertSame(0, Customer::query()->count());
        $this->assertNotSame('convertido', $lead->refresh()->status->slug);
    }
}
