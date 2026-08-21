<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'PEN',
            'name' => 'Sol Peruano',
            'symbol' => 'S/',
            'decimals' => 2,
            'is_active' => true,
        ]);

        Tax::create([
            'name' => 'IGV 18%',
            'slug' => 'igv-18',
            'rate' => 18.00,
            'is_active' => true,
        ]);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'code' => 'PROD-001',
            'product_type' => 'servicio',
            'name' => 'Consultoría comercial',
            'price' => 1500.00,
            'currency_code' => 'PEN',
            'tax_id' => Tax::first()->id,
            'is_active' => true,
        ]);
    }

    public function test_updating_a_product_price_logs_activity_with_old_and_new_values(): void
    {
        $product = $this->createProduct();

        $product->update(['price' => 1800.50]);

        $activity = Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity, 'An activity_log row must exist for the price update (ADR-008).');

        $properties = $activity->properties;

        $this->assertEquals(1800.5, (float) $properties['attributes']['price']);
        $this->assertEquals(1500.0, (float) $properties['old']['price']);
    }

    public function test_creating_a_product_logs_activity(): void
    {
        $product = $this->createProduct();

        $activity = Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('1500.00', (string) $activity->properties['attributes']['price']);
    }

    public function test_unchanged_attributes_are_not_logged(): void
    {
        $product = $this->createProduct();

        // Update with the same price: logOnlyDirty keeps the log clean.
        $product->update(['price' => 1500.00]);

        $dirtyUpdate = Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNull($dirtyUpdate);
    }
}
