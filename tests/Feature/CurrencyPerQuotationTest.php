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
 * ADR-004: each quotation carries its own currency_code; PEN and USD
 * totals are stored independently. No consolidation, no conversion.
 */
class CurrencyPerQuotationTest extends TestCase
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

    public function test_pen_and_usd_quotations_store_totals_independently(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->value('id');

        $pen = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'currency_code' => 'PEN',
            'items' => [
                ['description' => 'PEN item', 'quantity' => 2, 'unit_price' => 1000, 'tax_id' => $igv],
            ],
        ], $this->actor);

        $usd = $this->service->create([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'currency_code' => 'USD',
            'items' => [
                ['description' => 'USD item', 'quantity' => 3, 'unit_price' => 200, 'tax_id' => $igv],
            ],
        ], $this->actor);

        // PEN: subtotal 2000, tax 360, total 2360.
        $this->assertSame('PEN', $pen->currency_code);
        $this->assertSame('2000.00', (string) $pen->subtotal);
        $this->assertSame('360.00', (string) $pen->tax_total);
        $this->assertSame('2360.00', (string) $pen->total);

        // USD: subtotal 600, tax 108, total 708.
        $this->assertSame('USD', $usd->currency_code);
        $this->assertSame('600.00', (string) $usd->subtotal);
        $this->assertSame('108.00', (string) $usd->tax_total);
        $this->assertSame('708.00', (string) $usd->total);

        // Independence: the PEN total must not equal the USD total even
        // though the items are similar.
        $this->assertNotSame((string) $pen->total, (string) $usd->total);
    }
}
