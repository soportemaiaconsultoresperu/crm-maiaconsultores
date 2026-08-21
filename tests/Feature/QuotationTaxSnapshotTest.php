<?php

namespace Tests\Feature;

use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-COT-009 / ADR-005: tax_name / tax_rate are copied historically on
 * each line at creation time. A later change in the Tax catalog must
 * NOT alter the historical quotation_items.
 */
class QuotationTaxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->service = app(QuotationService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    public function test_tax_snapshot_is_preserved_when_tax_rate_changes(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();

        $quotation = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'items' => [
                [
                    'description' => 'Consultoría gravada',
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'tax_id' => $igv->id,
                ],
            ],
        ], $this->actor);

        $item = $quotation->items()->first();

        // Snapshot at creation: 18% IGV, name "Gravado IGV".
        $this->assertSame('Gravado IGV', $item->tax_name);
        $this->assertSame(18.0, (float) $item->tax_rate);
        $this->assertSame('180.00', (string) $item->line_tax);
        $this->assertSame('1180.00', (string) $quotation->total);

        // Mutate the catalog: IGV goes from 18% to 10%.
        $igv->rate = 10;
        $igv->save();

        $item->refresh();
        $quotation->refresh();

        // Historical snapshot must be intact.
        $this->assertSame('Gravado IGV', $item->tax_name);
        $this->assertSame(18.0, (float) $item->tax_rate);
        $this->assertSame('180.00', (string) $item->line_tax);
        $this->assertSame('1180.00', (string) $quotation->total);
    }

    public function test_quotation_totals_unchanged_after_tax_renamed(): void
    {
        $exonerado = Tax::where('slug', 'exonerado')->firstOrFail();

        $quotation = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'items' => [
                [
                    'description' => 'Servicio exonerado',
                    'quantity' => 1,
                    'unit_price' => 500,
                    'tax_id' => $exonerado->id,
                ],
            ],
        ], $this->actor);

        $item = $quotation->items()->first();

        $this->assertSame('Exonerado', $item->tax_name);

        // Rename the catalog tax.
        $exonerado->name = 'Exento de impuestos';
        $exonerado->save();

        $item->refresh();

        // Historical line still says "Exonerado".
        $this->assertSame('Exonerado', $item->tax_name);
    }
}
