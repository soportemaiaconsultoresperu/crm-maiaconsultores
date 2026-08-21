<?php

namespace Tests\Feature;

use App\Exports\QuotationsExport;
use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-COT-011 / ADR-006: quotation exports respect the requesting
 * user's data scope. A vendedor only exports quotations they own.
 */
class QuotationExportScopeTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->service = app(QuotationService::class);
    }

    public function test_vendedor_export_contains_only_own_quotations(): void
    {
        $vendedor = User::factory()->create(['is_active' => true])->assignRole('vendedor');
        $otro = User::factory()->create(['is_active' => true])->assignRole('vendedor');

        $own = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'owner_id' => $vendedor->id,
            'items' => [
                [
                    'description' => 'Propio',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $vendedor);

        $foreign = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'owner_id' => $otro->id,
            'items' => [
                [
                    'description' => 'Ajeno',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $otro);

        $codes = (new QuotationsExport([], $vendedor))->query()->pluck('number');

        $this->assertTrue($codes->contains($own->number));
        $this->assertFalse($codes->contains($foreign->number));
    }

    public function test_admin_export_is_unrestricted(): void
    {
        $admin = User::factory()->create(['is_active' => true])->assignRole('admin');
        $vendedor = User::factory()->create(['is_active' => true])->assignRole('vendedor');

        $quotation = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'owner_id' => $vendedor->id,
            'items' => [
                [
                    'description' => 'De vendedor',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $vendedor);

        $codes = (new QuotationsExport([], $admin))->query()->pluck('number');

        $this->assertTrue($codes->contains($quotation->number));
    }
}
