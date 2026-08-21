<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only product factory (no fake business data outside tests).
 *
 * Catalog rows (currency, category, tax) are lazily created with the
 * seeder values so the factory works with or without a seeded database.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => sprintf('PROD-%s-%05d', now()->format('Y'), random_int(1, 99999)),
            'product_type' => 'producto',
            'name' => fake('es_PE')->unique()->words(3, true),
            'category_id' => fn () => ProductCategory::query()->firstOrCreate(
                ['slug' => 'consultoria'],
                ['name' => 'Consultoría', 'sort' => 1, 'is_active' => true],
            )->id,
            'description' => null,
            'price' => fake()->randomFloat(2, 10, 1000),
            'currency_code' => fn () => Currency::query()->firstOrCreate(
                ['code' => 'PEN'],
                ['name' => 'Sol Peruano', 'symbol' => 'S/', 'decimals' => 2, 'is_active' => true],
            )->code,
            'tax_id' => fn () => Tax::query()->firstOrCreate(
                ['slug' => 'gravado-igv'],
                ['name' => 'Gravado IGV', 'rate' => 18, 'sort' => 1, 'is_active' => true],
            )->id,
            'is_active' => true,
        ];
    }

    /**
     * "servicio" product type.
     */
    public function servicio(): static
    {
        return $this->state(fn (): array => ['product_type' => 'servicio']);
    }

    /**
     * "producto" product type (explicit, mirrors default).
     */
    public function producto(): static
    {
        return $this->state(fn (): array => ['product_type' => 'producto']);
    }

    /**
     * Inactive product (is_active = false).
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Explicit owner (mostly cosmetic — products are a global catalog).
     */
    public function forOwner(User $owner): static
    {
        return $this->state(fn (): array => ['created_by' => $owner->id]);
    }
}
