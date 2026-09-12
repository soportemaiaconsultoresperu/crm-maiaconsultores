<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\PipelineStage;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Ubigeo;
use App\Models\User;
use Database\Seeders\CourseSystemAuthorSeeder;
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
        $this->assertSame(143, Permission::count(), 'Permissions include the current branch baseline, AdditionalPermissionsSeeder, support lifecycle permissions, customer-payments.view/manage, and the 13 course-talks.* permissions now wired into the full seed by DatabaseSeeder (130 + 13).');
        $this->assertTrue(Permission::where('name', 'customer-payments.view')->exists());
        $this->assertTrue(Permission::where('name', 'customer-payments.manage')->exists());
        // The bootstrap admin and the SYSTEM author of automatic document
        // generation are the only users the full seed creates. Neither is a fake
        // user: the admin is the bootstrap account, and the SYSTEM author is the
        // non-human account the automatic certificate generation is attributed to
        // (it exists because `documents.uploaded_by` is NOT NULL). The admin is
        // identified by its ROLE, not by an env lookup: reading `env()` in a test
        // returns null under a cached config and would make the assertion pass
        // vacuously.
        $this->assertSame(1, User::role('admin')->count(), 'Exactly one bootstrap admin is seeded.');
        $this->assertSame(
            1,
            User::query()->where('email', CourseSystemAuthorSeeder::EMAIL)->count(),
            'Exactly one SYSTEM author is seeded.'
        );
        $this->assertSame(
            0,
            User::query()
                ->where('email', '!=', CourseSystemAuthorSeeder::EMAIL)
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'admin'))
                ->count(),
            'No user other than the bootstrap admin and the SYSTEM author is seeded (no fake users)'
        );
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
$this->assertSame(143, Permission::count(), 'Re-seeding must not duplicate the 13 course-talks.* permissions (143 stays 143).');
        $this->assertSame(1, User::role('admin')->count(), 'Admin user is updated, never duplicated');
        $this->assertSame(
            1,
            User::query()->where('email', CourseSystemAuthorSeeder::EMAIL)->count(),
            'The SYSTEM author is updated, never duplicated'
        );
        $this->assertSame(2, User::count(), 'Re-seeding created no third user row');
        $this->assertSame(1, User::first()->roles()->count(), 'Admin keeps exactly one role assignment');
    }
}
