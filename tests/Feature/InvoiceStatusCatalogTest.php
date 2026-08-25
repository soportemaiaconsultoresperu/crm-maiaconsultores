<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InvoiceStatus;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceStatusCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeder_creates_required_invoice_statuses(): void
    {
        $this->seed(CatalogSeeder::class);

        $required = [
            InvoiceStatus::SLUG_PAID => 'Pagado',
            InvoiceStatus::SLUG_OVERDUE => 'Vencida',
            InvoiceStatus::SLUG_IN_PROCESS => 'En proceso',
            InvoiceStatus::SLUG_CREDIT_NOTE => 'Nota de crédito',
        ];

        foreach ($required as $slug => $name) {
            $this->assertDatabaseHas('invoice_statuses', [
                'slug' => $slug,
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }

    public function test_admin_can_open_invoice_status_catalog(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/catalogs/invoice-statuses')
            ->assertOk()
            ->assertSee('Estados de factura')
            ->assertSee('Pagado')
            ->assertSee('Vencida')
            ->assertSee('En proceso')
            ->assertSee('Nota de crédito');
    }

    public function test_invoice_statuses_are_not_created_inline_from_invoice_data(): void
    {
        $this->seed(CatalogSeeder::class);

        $customer = Customer::factory()->create();

        $this->expectException(QueryException::class);

        $customer->invoices()->create([
            'invoice_number' => 'FAC-INLINE-001',
            'due_date' => '2026-09-15',
            'total_amount' => 1500.00,
            'status_id' => 'Pendiente especial',
            'notes' => 'No debe crear un estado libre desde Pagos.',
        ]);
    }
}
