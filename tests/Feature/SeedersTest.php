<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Ubigeo;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_seed_populates_catalogs_roles_and_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Catalogs.
        $this->assertSame(7, PipelineStage::count(), 'Pipeline stages');
        $this->assertSame(3, Currency::count(), 'Currencies');
        $this->assertSame(4, Tax::count(), 'Taxes');
        $this->assertSame(2113, Ubigeo::count(), 'Ubigeo (25 dep + 196 prov + 1892 dist)');

        // Settings keys.
        foreach ([
            'prices_include_tax',
            'currency_default',
            'date_format',
            'pagination_size',
            'quote_validity_days',
            'seq.lead.prefix',
            'seq.customer.prefix',
            'seq.opportunity.prefix',
            'seq.quotation.prefix',
            'seq.pad_length',
        ] as $key) {
            $this->assertTrue(
                Setting::where('key', $key)->exists(),
                "Setting [{$key}] must exist."
            );
        }

// Roles / permissions / admin user.
        $this->assertSame(3, Role::count());
        $this->assertSame(84, Permission::count(), 'Permissions include B06 additions (products.view.team/own + quotations.delete/reject/duplicate + products.export), B08 additions (users.* 6, teams.view, roles.view/manage, catalogs.view/manage, settings.view; settings.manage and teams.manage were idempotent duplicates from B01) and B09 additions (documents.upload, documents.delete). B12 automations.* permissions are registered at runtime by AutomationServiceProvider::boot() and are not seeded by V1 seeders, so the seeder-only count remains 84.');
        $this->assertSame(1, User::count(), 'Only the bootstrap admin user is seeded (no fake users)');
        $this->assertTrue(User::first()->hasRole('admin'));
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(7, PipelineStage::count());
        $this->assertSame(3, Currency::count());
        $this->assertSame(4, Tax::count());
        $this->assertSame(2113, Ubigeo::count());
$this->assertSame(13, Setting::count(), 'Settings must not duplicate on re-seed (B06 added seq.quotation.pad_length and seq.product.*)');
$this->assertSame(3, Role::count());
$this->assertSame(84, Permission::count());
        $this->assertSame(1, User::count(), 'Admin user is updated, never duplicated');
        $this->assertSame(1, User::first()->roles()->count(), 'Admin keeps exactly one role assignment');
    }
}
