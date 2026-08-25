<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\User;
use App\Services\OverdueInvoiceProcessor;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OverdueInvoiceProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
    }

    public function test_processor_persists_past_due_en_proceso_invoice_as_vencida(): void
    {
        $invoice = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15');

        $result = app(OverdueInvoiceProcessor::class)->process(today: now()->parse('2026-09-16'));

        $this->assertSame(1, $result->updated);
        $this->assertSame(InvoiceStatus::SLUG_OVERDUE, $invoice->refresh()->status->slug);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CustomerInvoice::class,
            'subject_id' => $invoice->id,
            'event' => 'customer-invoice-marked-overdue',
        ]);
    }

    public function test_paid_credit_note_and_retired_past_due_invoices_are_protected(): void
    {
        $paid = $this->invoice(InvoiceStatus::SLUG_PAID, '2026-09-15');
        $creditNote = $this->invoice(InvoiceStatus::SLUG_CREDIT_NOTE, '2026-09-15');
        $retired = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', ['retired_at' => now(), 'retire_reason' => 'Anulada']);

        $result = app(OverdueInvoiceProcessor::class)->process(today: now()->parse('2026-09-16'));

        $this->assertSame(0, $result->updated);
        $this->assertSame(InvoiceStatus::SLUG_PAID, $paid->refresh()->status->slug);
        $this->assertSame(InvoiceStatus::SLUG_CREDIT_NOTE, $creditNote->refresh()->status->slug);
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $retired->refresh()->status->slug);
    }

    public function test_repeated_processing_is_idempotent_and_does_not_duplicate_overdue_audit(): void
    {
        $invoice = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15');
        $processor = app(OverdueInvoiceProcessor::class);

        $first = $processor->process(today: now()->parse('2026-09-16'));
        $second = $processor->process(today: now()->parse('2026-09-16'));

        $this->assertSame(1, $first->updated);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, Activity::query()
            ->where('subject_type', CustomerInvoice::class)
            ->where('subject_id', $invoice->id)
            ->where('event', 'customer-invoice-marked-overdue')
            ->count());
    }

    public function test_command_date_option_is_deterministic(): void
    {
        $before = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15');
        $sameDay = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, '2026-09-16');

        $this->artisan('invoices:mark-overdue', ['--date' => '2026-09-16'])
            ->assertSuccessful()
            ->expectsOutput('Customer invoices marked as overdue: 1');

        $this->assertSame(InvoiceStatus::SLUG_OVERDUE, $before->refresh()->status->slug);
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $sameDay->refresh()->status->slug);
    }

    public function test_explicit_invoice_writes_process_immediately_but_customer_and_calendar_gets_do_not_mutate(): void
    {
        $writer = User::factory()->create(['is_active' => true]);
        $writer->assignRole('admin');
        $customer = Customer::factory()->create(['owner_id' => $writer->id]);
        $status = InvoiceStatus::query()->where('slug', InvoiceStatus::SLUG_IN_PROCESS)->firstOrFail();

        $this->actingAs($writer)
            ->post(route('customers.invoices.store', $customer), [
                'invoice_number' => 'FAC-WRITE-001',
                'due_date' => now()->subDay()->toDateString(),
                'total_amount' => '100.00',
                'status_id' => $status->id,
            ])
            ->assertRedirect(route('customers.show', $customer));

        $writtenInvoice = CustomerInvoice::query()->where('invoice_number', 'FAC-WRITE-001')->firstOrFail();
        $this->assertSame(InvoiceStatus::SLUG_OVERDUE, $writtenInvoice->status->slug);

        $showInvoice = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, now()->subDay()->toDateString(), ['customer_id' => $customer->id, 'invoice_number' => 'FAC-GET-001']);
        $this->actingAs($writer)->get(route('customers.show', $customer))->assertOk();
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $showInvoice->refresh()->status->slug);

        $calendarInvoice = $this->invoice(InvoiceStatus::SLUG_IN_PROCESS, now()->subDay()->toDateString(), ['customer_id' => $customer->id, 'invoice_number' => 'FAC-GET-002']);
        $this->actingAs($writer)->get(route('calendar.index', ['anchor' => now()->toDateString()]))->assertOk();
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $calendarInvoice->refresh()->status->slug);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function invoice(string $statusSlug, string $dueDate, array $attributes = []): CustomerInvoice
    {
        $status = InvoiceStatus::query()->where('slug', $statusSlug)->firstOrFail();
        $customer = isset($attributes['customer_id']) ? null : Customer::factory()->create();

        return CustomerInvoice::factory()
            ->forStatus($status)
            ->create(array_merge([
                'customer_id' => $customer?->id,
                'due_date' => $dueDate,
            ], $attributes));
    }
}
