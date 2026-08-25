<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerInvoiceCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_permissions_are_seeded_for_admin_and_supervisor_but_not_salesperson(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $salesperson = User::factory()->create(['is_active' => true]);
        $salesperson->assignRole('vendedor');

        $this->assertTrue($admin->can('customer-payments.view'));
        $this->assertTrue($admin->can('customer-payments.manage'));
        $this->assertTrue($supervisor->can('customer-payments.view'));
        $this->assertTrue($supervisor->can('customer-payments.manage'));
        $this->assertFalse($salesperson->can('customer-payments.view'));
        $this->assertFalse($salesperson->can('customer-payments.manage'));
    }

    public function test_customer_invoice_model_requires_customer_and_catalog_status(): void
    {
        $this->seed(CatalogSeeder::class);

        $customer = Customer::factory()->create(['payment_modality' => 'Transferencia']);
        $status = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();

        $invoice = CustomerInvoice::factory()
            ->forCustomer($customer)
            ->forStatus($status)
            ->create([
                'invoice_number' => 'FAC-001',
                'due_date' => '2026-09-15',
                'total_amount' => 1500.00,
            ]);

        $this->assertSame($customer->id, $invoice->customer->id);
        $this->assertSame('Transferencia', $invoice->customer->payment_modality);
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $invoice->status->slug);
    }

    public function test_validation_rule_rejects_inactive_and_unknown_invoice_statuses(): void
    {
        $this->seed(CatalogSeeder::class);

        $inactive = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();
        $inactive->update(['is_active' => false]);

        $activeStatusRule = ['required', 'integer', Rule::exists('invoice_statuses', 'id')->where('is_active', true)];

        $inactiveValidator = validator(['status_id' => $inactive->id], ['status_id' => $activeStatusRule]);
        $unknownValidator = validator(['status_id' => 999999], ['status_id' => $activeStatusRule]);

        $this->assertTrue($inactiveValidator->fails());
        $this->assertTrue($unknownValidator->fails());
    }

    public function test_invoice_number_is_unique_per_customer_not_globally(): void
    {
        $this->seed(CatalogSeeder::class);

        $status = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();
        $firstCustomer = Customer::factory()->create();
        $secondCustomer = Customer::factory()->create();

        CustomerInvoice::factory()->forCustomer($firstCustomer)->forStatus($status)->create([
            'invoice_number' => 'FAC-DUP-001',
        ]);

        $sameCustomerValidator = validator(
            ['invoice_number' => 'FAC-DUP-001'],
            ['invoice_number' => [Rule::unique('customer_invoices', 'invoice_number')->where('customer_id', $firstCustomer->id)]]
        );
        $otherCustomerValidator = validator(
            ['invoice_number' => 'FAC-DUP-001'],
            ['invoice_number' => [Rule::unique('customer_invoices', 'invoice_number')->where('customer_id', $secondCustomer->id)]]
        );

        $this->assertTrue($sameCustomerValidator->fails());
        $this->assertFalse($otherCustomerValidator->fails());
    }

    public function test_writer_can_create_invoice_from_customer_and_validation_rejects_invalid_payloads(): void
    {
        [$writer, $customer, $status] = $this->invoiceContext();

        $this->actingAs($writer)
            ->post(route('customers.invoices.store', $customer), [
                'invoice_number' => 'FAC-100',
                'due_date' => '2026-09-15',
                'total_amount' => '1500.00',
                'status_id' => $status->id,
                'notes' => 'Initial invoice',
            ])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertDatabaseHas('customer_invoices', [
            'customer_id' => $customer->id,
            'invoice_number' => 'FAC-100',
            'total_amount' => '1500.00',
            'status_id' => $status->id,
        ]);

        $this->actingAs($writer)
            ->from(route('customers.show', $customer))
            ->post(route('customers.invoices.store', $customer), [
                'invoice_number' => 'FAC-BAD',
                'total_amount' => '0',
                'status_id' => 999999,
                'payment_date' => '2026-09-16',
                'payment_reference' => 'REF',
                'payment_proof' => 'file.pdf',
                'partial_amount' => '5.00',
                'tax_amount' => '1.00',
                'line_items' => [['description' => 'Nope']],
            ])
            ->assertSessionHasErrors(['due_date', 'total_amount', 'status_id', 'payment_date', 'payment_reference', 'payment_proof', 'partial_amount', 'tax_amount', 'line_items']);
    }

    public function test_writer_can_update_invoice_but_uniqueness_is_scoped_and_retired_invoice_is_not_editable(): void
    {
        [$writer, $customer, $status] = $this->invoiceContext();
        $first = CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create(['invoice_number' => 'FAC-201']);
        $second = CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create(['invoice_number' => 'FAC-202']);

        $this->actingAs($writer)
            ->put(route('customer-invoices.update', $first), [
                'invoice_number' => 'FAC-202',
                'due_date' => '2026-10-01',
                'total_amount' => '2500.00',
                'status_id' => $status->id,
            ])
            ->assertSessionHasErrors(['invoice_number']);

        $this->actingAs($writer)
            ->put(route('customer-invoices.update', $first), [
                'invoice_number' => 'FAC-201-EDIT',
                'due_date' => '2026-10-01',
                'total_amount' => '2500.00',
                'status_id' => $status->id,
            ])
            ->assertRedirect(route('customers.show', $customer));

        $second->update(['retired_at' => now(), 'retired_by' => $writer->id, 'retire_reason' => 'Error de carga']);

        $this->actingAs($writer)
            ->put(route('customer-invoices.update', $second), [
                'invoice_number' => 'FAC-202-EDIT',
                'due_date' => '2026-10-02',
                'total_amount' => '10.00',
                'status_id' => $status->id,
            ])
            ->assertForbidden();
    }

    public function test_mark_paid_changes_status_only_and_missing_paid_status_is_controlled(): void
    {
        [$writer, $customer, $status] = $this->invoiceContext();
        $paid = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_PAID)->firstOrFail();
        $invoice = CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create([
            'invoice_number' => 'FAC-300',
            'due_date' => '2026-09-15',
            'total_amount' => '100.00',
            'notes' => 'Keep notes',
        ]);

        $this->actingAs($writer)
            ->post(route('customer-invoices.mark-paid', $invoice), [
                'payment_date' => '2026-09-16',
                'payment_reference' => 'REF-1',
            ])
            ->assertSessionHasErrors(['payment_date', 'payment_reference']);

        $this->actingAs($writer)
            ->post(route('customer-invoices.mark-paid', $invoice))
            ->assertRedirect(route('customers.show', $customer));

        $invoice->refresh();
        $this->assertSame($paid->id, $invoice->status_id);
        $this->assertSame('FAC-300', $invoice->invoice_number);
        $this->assertSame('100.00', (string) $invoice->total_amount);
        $this->assertSame('Keep notes', $invoice->notes);

        $paid->update(['slug' => 'pagado-missing']);
        $second = CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create(['invoice_number' => 'FAC-301']);

        $this->actingAs($writer)
            ->post(route('customer-invoices.mark-paid', $second))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHasErrors(['status']);
    }

    public function test_retire_is_non_destructive_and_read_only_users_get_403s(): void
    {
        [$writer, $customer, $status] = $this->invoiceContext();
        $invoice = CustomerInvoice::factory()->forCustomer($customer)->forStatus($status)->create(['invoice_number' => 'FAC-400']);
        $reader = $this->readOnlyUser();

        $this->actingAs($reader)->post(route('customers.payment-modality.update', $customer), ['payment_modality' => 'Transferencia'])->assertForbidden();
        $this->actingAs($reader)->post(route('customers.invoices.store', $customer), [])->assertForbidden();
        $this->actingAs($reader)->put(route('customer-invoices.update', $invoice), [])->assertForbidden();
        $this->actingAs($reader)->post(route('customer-invoices.mark-paid', $invoice))->assertForbidden();
        $this->actingAs($reader)->post(route('customer-invoices.retire', $invoice), ['reason' => 'No aplica'])->assertForbidden();

        $this->actingAs($writer)
            ->post(route('customers.payment-modality.update', $customer), ['payment_modality' => ' Transferencia '])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertSame('Transferencia', $customer->refresh()->payment_modality);

        $this->actingAs($writer)
            ->post(route('customer-invoices.retire', $invoice), ['reason' => 'Factura anulada por error'])
            ->assertRedirect(route('customers.show', $customer));

        $invoice->refresh();
        $this->assertNotNull($invoice->retired_at);
        $this->assertSame($writer->id, $invoice->retired_by);
        $this->assertSame('Factura anulada por error', $invoice->retire_reason);
        $this->assertDatabaseHas('customer_invoices', ['id' => $invoice->id, 'deleted_at' => null]);
    }

    /**
     * @return array{User, Customer, InvoiceStatus}
     */
    private function invoiceContext(): array
    {
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);

        $writer = User::factory()->create(['is_active' => true]);
        $writer->assignRole('admin');
        $customer = Customer::factory()->create(['owner_id' => $writer->id]);
        $status = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();

        return [$writer, $customer, $status];
    }

    private function readOnlyUser(): User
    {
        $reader = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('customer-payments.view')->assignRole('vendedor');
        $reader->assignRole('vendedor');

        return $reader;
    }
}
