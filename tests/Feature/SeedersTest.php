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
            'seq.support_ticket.prefix',
            'seq.support_ticket.pad_length',
            'seq.pad_length',
        ] as $key) {
            $this->assertTrue(
                Setting::where('key', $key)->exists(),
                "Setting [{$key}] must exist."
            );
        }

// Roles / permissions / admin user.
        $this->assertSame(3, Role::count());
        $this->assertSame(129, Permission::count(), 'Permissions include the current branch baseline, AdditionalPermissionsSeeder, support lifecycle permissions, and customer-payments.view/manage.');
        $this->assertTrue(Permission::where('name', 'customer-payments.view')->exists());
        $this->assertTrue(Permission::where('name', 'customer-payments.manage')->exists());
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
$this->assertSame(23, Setting::count(), 'Settings must not duplicate on re-seed across current notification/company/sequence/support defaults.');
$this->assertSame(3, Role::count());
$this->assertSame(129, Permission::count());
        $this->assertSame(1, User::count(), 'Admin user is updated, never duplicated');
        $this->assertSame(1, User::first()->roles()->count(), 'Admin keeps exactly one role assignment');
    }
}
