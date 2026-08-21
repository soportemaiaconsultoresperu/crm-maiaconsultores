<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Services\ProductService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-PROD-001..003 + ADR-006 (product is a global catalog with no
 * owner-based data scope; the scope only gates downstream filters).
 */
class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->service = app(ProductService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'product_type' => 'producto',
            'name' => 'Servicio de consultoría',
            'price' => 1500.50,
            'currency_code' => 'PEN',
            'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
            'is_active' => true,
        ], $overrides);
    }

    public function test_create_generates_sequential_prod_codes(): void
    {
        $year = now()->format('Y');

        $first = $this->service->create($this->validData(), $this->actor);
        $second = $this->service->create($this->validData(['name' => 'Otro servicio']), $this->actor);

        $this->assertSame("PROD-{$year}-00001", $first->code);
        $this->assertSame("PROD-{$year}-00002", $second->code);
        $this->assertSame('producto', $first->product_type);
        $this->assertTrue($first->is_active);
    }

    public function test_price_persists_with_two_decimals(): void
    {
        $product = $this->service->create($this->validData([
            'price' => 1234.56,
        ]), $this->actor);

        $this->assertSame('1234.56', (string) $product->fresh()->price);
    }

    public function test_update_keeps_code_immutable(): void
    {
        $product = $this->service->create($this->validData(), $this->actor);

        $updated = $this->service->update($product, [
            'code' => 'PROD-FAKE-99999',
            'name' => 'Nuevo nombre',
            'price' => 2000.00,
        ], $this->actor);

        $this->assertNotSame('PROD-FAKE-99999', $updated->code);
        $this->assertSame('Nuevo nombre', $updated->name);
        $this->assertSame($product->code, $updated->code);
        $this->assertSame($this->actor->id, $updated->updated_by);
    }

    public function test_deactivate_soft_deletes_and_logs_reason(): void
    {
        $product = $this->service->create($this->validData(), $this->actor);

        $this->service->deactivate($product, $this->actor, 'Discontinuado');

        $this->assertSoftDeleted($product);
        $this->assertNotNull(Product::withTrashed()->find($product->id));

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('event', 'product-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, $log->causer_id);
        $this->assertSame('Discontinuado', $log->properties['reason']);
    }

    public function test_export_query_is_scoped_for_vendedor_but_returns_catalog(): void
    {
        // Products are a global catalog (no owner_id column on the data
        // side), so every user with products.view.* sees every row; the
        // scope guard is a no-op for products but must still produce a
        // valid builder.
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $p1 = Product::factory()->create();
        $p2 = Product::factory()->inactive()->create();

        $vendedorRows = $this->service->exportQuery($vendedor, [])->pluck('id');
        $adminRows = $this->service->exportQuery($admin, [])->pluck('id');

        $this->assertTrue($vendedorRows->contains($p1->id));
        $this->assertTrue($vendedorRows->contains($p2->id));
        $this->assertEqualsCanonicalizing(
            $adminRows->sort()->values()->all(),
            $vendedorRows->sort()->values()->all(),
        );

        $filtered = $this->service->exportQuery($admin, ['is_active' => true])->pluck('id');
        $this->assertTrue($filtered->contains($p1->id));
        $this->assertFalse($filtered->contains($p2->id));
    }
}
