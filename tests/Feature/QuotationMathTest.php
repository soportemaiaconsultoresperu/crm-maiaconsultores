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
 * RF-COT-003 / ADR-005: server-side recalculation of subtotal,
 * discount_total, tax_total and total from line items. Three items
 * with mixed tax/discount exercise all branches of the math.
 */
class QuotationMathTest extends TestCase
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

        $this->service = app(QuotationService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        $base = [
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'currency_code' => 'PEN',
            'items' => [],
        ];

        return array_merge($base, $overrides);
    }

    public function test_three_items_with_mixed_tax_and_discount(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();
        $exonerado = Tax::where('slug', 'exonerado')->firstOrFail();

        $quotation = $this->service->create($this->validData([
            'items' => [
                // 2 units @ 100 = 200, tax 18% (IGV), no discount.
                [
                    'description' => 'Item A gravado',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_id' => $igv->id,
                    'discount_amount' => 0,
                ],
                // 1 unit @ 50 = 50, exonerado (0%), discount 10.
                [
                    'description' => 'Item B exonerado con descuento',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'tax_id' => $exonerado->id,
                    'discount_amount' => 10,
                ],
                // 4 units @ 25 = 100, no tax, no discount.
                [
                    'description' => 'Item C libre',
                    'quantity' => 4,
                    'unit_price' => 25,
                    'tax_id' => null,
                    'discount_amount' => 0,
                ],
            ],
        ]), $this->actor);

        $items = $quotation->items()->orderBy('id')->get()->values();

        // Item A: subtotal 200, discount 0, tax 36, total 236.
        $this->assertSame('200.00', (string) $items[0]->line_subtotal);
        $this->assertSame('0.00', (string) $items[0]->discount_amount);
        $this->assertSame('36.00', (string) $items[0]->line_tax);
        $this->assertSame('236.00', (string) $items[0]->line_total);
        $this->assertSame(18.0, (float) $items[0]->tax_rate);
        $this->assertSame($igv->name, $items[0]->tax_name);

        // Item B: subtotal 50, discount 10, tax 0, total 40.
        $this->assertSame('50.00', (string) $items[1]->line_subtotal);
        $this->assertSame('10.00', (string) $items[1]->discount_amount);
        $this->assertSame('0.00', (string) $items[1]->line_tax);
        $this->assertSame('40.00', (string) $items[1]->line_total);
        $this->assertSame(0.0, (float) $items[1]->tax_rate);
        $this->assertSame($exonerado->name, $items[1]->tax_name);

        // Item C: subtotal 100, no tax, no discount, total 100.
        $this->assertSame('100.00', (string) $items[2]->line_subtotal);
        $this->assertSame('0.00', (string) $items[2]->discount_amount);
        $this->assertSame('0.00', (string) $items[2]->line_tax);
        $this->assertSame('100.00', (string) $items[2]->line_total);

        // Header: subtotal 350, discount 10, tax 36, total 376.
        $this->assertSame('350.00', (string) $quotation->subtotal);
        $this->assertSame('10.00', (string) $quotation->discount_total);
        $this->assertSame('36.00', (string) $quotation->tax_total);
        $this->assertSame('376.00', (string) $quotation->total);
    }

    public function test_header_totals_recompute_when_items_change(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();

        $quotation = $this->service->create($this->validData([
            'items' => [
                ['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => $igv->id],
            ],
        ]), $this->actor);

        $this->assertSame('100.00', (string) $quotation->subtotal);
        $this->assertSame('18.00', (string) $quotation->tax_total);
        $this->assertSame('118.00', (string) $quotation->total);

        $updated = $this->service->update($quotation, [
            'items' => [
                ['description' => 'Y', 'quantity' => 2, 'unit_price' => 200, 'tax_id' => $igv->id],
            ],
        ], $this->actor);

        $this->assertSame('400.00', (string) $updated->subtotal);
        $this->assertSame('72.00', (string) $updated->tax_total);
        $this->assertSame('472.00', (string) $updated->total);
    }

    public function test_subtotal_ignores_payload_totals(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();

        // Caller sends bogus totals — service must overwrite them.
        $quotation = $this->service->create($this->validData([
            'subtotal' => 999.99,
            'tax_total' => 999.99,
            'total' => 999.99,
            'items' => [
                ['description' => 'Honesto', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => $igv->id],
            ],
        ]), $this->actor);

        $this->assertSame('100.00', (string) $quotation->subtotal);
        $this->assertSame('18.00', (string) $quotation->tax_total);
        $this->assertSame('118.00', (string) $quotation->total);
    }

    public function test_no_items_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($this->validData(['items' => []]), $this->actor);
    }

    public function test_both_lead_and_customer_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($this->validData([
            'customer_id' => \App\Models\Customer::factory()->create()->id,
            'items' => [
                ['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => null],
            ],
        ]), $this->actor);
    }
}
