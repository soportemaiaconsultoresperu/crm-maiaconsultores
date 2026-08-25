<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceCalendarAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['calendar.view', 'customers.view.any', 'customer-payments.view', 'customer-payments.manage']);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('vendedor');
    }

    public function test_chargeable_invoices_are_visible_and_overdue_status_is_persisted_before_calendar_read(): void
    {
        $customer = Customer::factory()->forOwner($this->owner)->create(['trade_name' => 'Cliente A']);
        $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-PROC-001');
        $this->invoice($customer, InvoiceStatus::SLUG_OVERDUE, '2026-09-15', 'FAC-VENC-001');
        $pastDue = $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-14', 'FAC-OLD-001');

        $this->artisan('invoices:mark-overdue', ['--date' => '2026-09-15'])->assertSuccessful();

        $this->assertSame(InvoiceStatus::SLUG_OVERDUE, $pastDue->refresh()->status->slug);

        $response = $this->actingAs($this->admin)->get(route('calendar.index', [
            'view' => 'month',
            'anchor' => '2026-09-15',
        ]));

        $response->assertOk()
            ->assertSee('FAC-PROC-001')
            ->assertSee('FAC-VENC-001')
            ->assertSee('FAC-OLD-001')
            ->assertSee('Cliente A')
            ->assertSee('Factura')
            ->assertSee(route('customers.show', $customer));
    }

    public function test_due_date_move_and_repeated_saves_do_not_leave_duplicates_or_orphans(): void
    {
        $customer = Customer::factory()->forOwner($this->owner)->create();
        $invoice = $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-MOVE-001');

        $this->actingAs($this->admin)->put(route('customer-invoices.update', $invoice), [
            'invoice_number' => 'FAC-MOVE-001',
            'due_date' => '2026-09-20',
            'total_amount' => '900.00',
            'status_id' => $invoice->status_id,
            'notes' => 'Updated once',
        ])->assertRedirect(route('customers.show', $customer));

        $this->actingAs($this->admin)->put(route('customer-invoices.update', $invoice->refresh()), [
            'invoice_number' => 'FAC-MOVE-001',
            'due_date' => '2026-09-20',
            'total_amount' => '900.00',
            'status_id' => $invoice->status_id,
            'notes' => 'Updated twice',
        ])->assertRedirect(route('customers.show', $customer));

        $oldDate = $this->actingAs($this->admin)->get(route('calendar.index', ['view' => 'day', 'anchor' => '2026-09-15']));
        $newDate = $this->actingAs($this->admin)->get(route('calendar.index', ['view' => 'day', 'anchor' => '2026-09-20']));

        $oldDate->assertOk()->assertDontSee('FAC-MOVE-001');
        $newDate->assertOk()->assertSee('FAC-MOVE-001');
        $this->assertSame(1, substr_count($newDate->getContent(), 'FAC-MOVE-001'));
    }

    public function test_paid_credit_note_retired_and_unauthorized_financial_events_are_suppressed(): void
    {
        $customer = Customer::factory()->forOwner($this->owner)->create();
        $this->invoice($customer, InvoiceStatus::SLUG_PAID, '2026-09-15', 'FAC-PAID-001');
        $this->invoice($customer, InvoiceStatus::SLUG_CREDIT_NOTE, '2026-09-15', 'FAC-CREDIT-001');
        $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-RETIRED-001', ['retired_at' => now(), 'retire_reason' => 'Anulada']);
        $visible = $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-VISIBLE-001');

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['view' => 'day', 'anchor' => '2026-09-15']))
            ->assertOk()
            ->assertSee('FAC-VISIBLE-001')
            ->assertDontSee('FAC-PAID-001')
            ->assertDontSee('FAC-CREDIT-001')
            ->assertDontSee('FAC-RETIRED-001');

        $calendarOnly = User::factory()->create(['is_active' => true]);
        $calendarOnly->givePermissionTo(['calendar.view', 'customers.view.any']);

        $this->actingAs($calendarOnly)
            ->get(route('calendar.index', ['view' => 'day', 'anchor' => '2026-09-15']))
            ->assertOk()
            ->assertDontSee('FAC-VISIBLE-001')
            ->assertDontSee((string) $visible->total_amount);
    }

    public function test_invoice_calendar_projection_does_not_send_external_notifications(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();

        $customer = Customer::factory()->forOwner($this->owner)->create();
        $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-NO-NOTIFY');

        $this->actingAs($this->admin)
            ->get(route('calendar.index', ['view' => 'day', 'anchor' => '2026-09-15']))
            ->assertOk()
            ->assertSee('FAC-NO-NOTIFY');

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function invoice(Customer $customer, string $statusSlug, string $dueDate, string $number, array $attributes = []): CustomerInvoice
    {
        $status = InvoiceStatus::query()->where('slug', $statusSlug)->firstOrFail();

        return CustomerInvoice::factory()
            ->forCustomer($customer)
            ->forStatus($status)
            ->create(array_merge([
                'invoice_number' => $number,
                'due_date' => $dueDate,
                'total_amount' => '1234.50',
            ], $attributes));
    }
}
