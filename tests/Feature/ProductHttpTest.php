<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B06 Product HTTP layer (RF-PROD-001..003). The catalog is global
 * (no owner-based data scope); only module permissions gate access.
 *
 * Exercises: PROD-YYYY-NNNNN code generation on create, list with filters,
 * edit, deactivation (POST destroy with reason), export scope, 403 for
 * users without the permission.
 */
class ProductHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salesperson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salesperson = User::factory()->create(['is_active' => true]);
        $this->salesperson->assignRole('vendedor');
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'product_type' => 'producto',
            'name' => 'Servicio de consultoría',
            'description' => 'Asesoría comercial especializada.',
            'price' => '350.00',
            'currency_code' => 'PEN',
            'tax_id' => \App\Models\Tax::where('slug', 'gravado-igv')->value('id'),
        ], $overrides);
    }

    public function test_store_creates_product_with_auto_generated_code(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/products', $this->validData());

        $product = Product::query()->where('name', 'Servicio de consultoría')->first();

        $this->assertNotNull($product);
        $response->assertRedirect(route('products.show', $product));
        $response->assertSessionHas('status');

        $year = now()->format('Y');
        $this->assertMatchesRegularExpression("/^PROD-{$year}-\\d{5}$/", $product->code);
    }

    public function test_index_lists_products_with_filters(): void
    {
        Product::factory()->create(['name' => 'Servicio destacado', 'product_type' => 'servicio']);
        Product::factory()->create(['name' => 'Producto secundario', 'product_type' => 'producto']);

        $this->actingAs($this->admin)
            ->get('/products')
            ->assertOk()
            ->assertSee('Servicio destacado')
            ->assertSee('Producto secundario');

        $this->actingAs($this->admin)
            ->get('/products?product_type=servicio')
            ->assertOk()
            ->assertSee('Servicio destacado')
            ->assertDontSee('Producto secundario');
    }

    public function test_edit_and_update_changes_product_data(): void
    {
        $product = Product::factory()->create(['name' => 'Original']);

        $this->actingAs($this->admin)
            ->get("/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('Original');

        $this->actingAs($this->admin)
            ->put("/products/{$product->id}", $this->validData(['name' => 'Renombrado']))
            ->assertRedirect(route('products.show', $product));

        $this->assertSame('Renombrado', $product->refresh()->name);
    }

    public function test_destroy_soft_deletes_with_reason(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->post("/products/{$product->id}", ['reason' => 'Catálogo obsoleto.'])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_destroy_without_reason_returns_validation_error(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->post("/products/{$product->id}", [])
            ->assertSessionHasErrors('reason');

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_export_returns_xlsx_with_permission_and_403_without(): void
    {
        Product::factory()->create();

        // vendedor role lacks products.export.
        $this->actingAs($this->salesperson)
            ->get('/products-export')
            ->assertForbidden();

        $response = $this->actingAs($this->admin)
            ->get('/products-export');

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    public function test_salesperson_sees_global_catalog_in_index(): void
    {
        // Products are global; vendedor should see every catalog row.
        Product::factory()->create(['name' => 'Visible para todos']);
        Product::factory()->create(['name' => 'Segundo producto']);

        $this->actingAs($this->salesperson)
            ->get('/products')
            ->assertOk()
            ->assertSee('Visible para todos')
            ->assertSee('Segundo producto');
    }
}