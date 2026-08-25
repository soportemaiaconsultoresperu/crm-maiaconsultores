<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPaymentsCardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');

        $this->salesperson = User::factory()->create(['is_active' => true]);
        $this->salesperson->assignRole('vendedor');
    }

    public function test_authorized_user_sees_payments_card_with_modality_and_safe_v1_scope(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create([
            'payment_modality' => 'Transferencia',
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Pagos')
            ->assertSee('Transferencia')
            ->assertSee('Modalidad de pago')
            ->assertSee('Nueva factura')
            ->assertDontSee('Pago parcial')
            ->assertDontSee('Referencia de pago')
            ->assertDontSee('Conciliación')
            ->assertDontSee('Líneas de factura')
            ->assertDontSee('Integración contable');
    }

    public function test_user_without_financial_read_permission_does_not_see_card_or_invoice_data(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create();
        $status = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();
        CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create([
            'invoice_number' => 'FAC-SENSITIVE',
            'total_amount' => 1200,
        ]);

        $this->actingAs($this->salesperson)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Pagos')
            ->assertDontSee('FAC-SENSITIVE')
            ->assertDontSee('1,200.00')
            ->assertDontSee('En proceso');
    }

    public function test_empty_modality_and_no_invoices_state_are_visible_to_authorized_user(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create([
            'payment_modality' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Modalidad pendiente')
            ->assertSee('Sin facturas registradas')
            ->assertSee('Nueva factura');
    }

    public function test_writer_can_update_customer_payment_modality(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create([
            'payment_modality' => null,
        ]);

        $this->actingAs($this->admin)
            ->post(route('customers.payment-modality.update', $customer), [
                'payment_modality' => 'Crédito 30 días',
            ])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertSame('Crédito 30 días', $customer->refresh()->payment_modality);
    }

    public function test_read_only_financial_user_cannot_update_modality_and_sees_no_edit_controls(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create([
            'payment_modality' => 'Transferencia',
        ]);
        $readOnly = User::factory()->create(['is_active' => true]);
        $readOnly->givePermissionTo(['customers.view.any', 'customer-payments.view']);

        $this->actingAs($readOnly)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Pagos')
            ->assertSee('Transferencia')
            ->assertDontSee('Guardar modalidad')
            ->assertDontSee('Nueva factura');

        $this->actingAs($readOnly)
            ->post(route('customers.payment-modality.update', $customer), [
                'payment_modality' => 'Contado',
            ])
            ->assertForbidden();

        $this->assertSame('Transferencia', $customer->refresh()->payment_modality);
    }

    public function test_invoice_rows_show_required_fields_and_writer_actions(): void
    {
        $customer = Customer::factory()->forOwner($this->salesperson)->create();
        $inProcess = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();
        $paid = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_PAID)->firstOrFail();

        CustomerInvoice::factory()->forCustomer($customer)->forStatus($inProcess)->create([
            'invoice_number' => 'FAC-001',
            'due_date' => '2026-09-15',
            'total_amount' => 1500,
            'notes' => 'Primer vencimiento',
        ]);
        CustomerInvoice::factory()->forCustomer($customer)->forStatus($paid)->create([
            'invoice_number' => 'FAC-002',
            'due_date' => '2026-10-01',
            'total_amount' => 2500.5,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('FAC-001')
            ->assertSee('15/09/2026')
            ->assertSee('1,500.00')
            ->assertSee('En proceso')
            ->assertSee('Primer vencimiento')
            ->assertSee('FAC-002')
            ->assertSee('2,500.50')
            ->assertSee('Pagado')
            ->assertSee('Editar')
            ->assertSee('Marcar pagada')
            ->assertSee('Retirar');
    }
}
