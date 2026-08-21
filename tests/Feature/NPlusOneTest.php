<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B09 / N+1 audit — query count assertions for the major list pages.
 *
 * Goal: catch regressions that reintroduce per-row queries on the
 * main list views. We seed a small dataset, enable the query log, hit
 * the index endpoint, and assert the total SQL count stays bounded.
 *
 * The bounds are loose enough to absorb minor changes (extra select
 * columns, session checks) but tight enough that any obvious N+1
 * (e.g. 25 rows × 4 relations = +100 queries) will explode.
 */
class NPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_leads_index_does_not_trigger_n_plus_one(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('vendedor');
        Lead::factory()->count(15)->forOwner($owner)->create();

        $count = $this->countQueriesFor(fn () => $this->actingAs($this->admin)->get('/leads'));

        // Baseline: a few dozen queries for a paginated list of 15 records
        // (paginate + session + auth + eager relations). 60 is the cap; the
        // pre-fix number was 75+.
        $this->assertLessThanOrEqual(
            60,
            $count,
            "leads.index executed {$count} queries; expected ≤ 60 (baseline, no N+1)."
        );
    }

    public function test_customers_index_does_not_trigger_n_plus_one(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('vendedor');
        Customer::factory()->count(15)->forOwner($owner)->create();

        $count = $this->countQueriesFor(fn () => $this->actingAs($this->admin)->get('/customers'));

        $this->assertLessThanOrEqual(
            60,
            $count,
            "customers.index executed {$count} queries; expected ≤ 60 (baseline, no N+1)."
        );
    }

    public function test_opportunities_index_does_not_trigger_n_plus_one(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('vendedor');
        Opportunity::factory()->count(15)->forOwner($owner)->create();

        $count = $this->countQueriesFor(fn () => $this->actingAs($this->admin)->get('/opportunities'));

        $this->assertLessThanOrEqual(
            70,
            $count,
            "opportunities.index executed {$count} queries; expected ≤ 70 (baseline, no N+1)."
        );
    }

    public function test_quotations_index_does_not_trigger_n_plus_one(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('vendedor');
        Quotation::factory()->count(15)->forOwner($owner)->create();

        $count = $this->countQueriesFor(fn () => $this->actingAs($this->admin)->get('/quotations'));

        $this->assertLessThanOrEqual(
            70,
            $count,
            "quotations.index executed {$count} queries; expected ≤ 70 (baseline, no N+1)."
        );
    }

    /**
     * Run the closure with the query log enabled and return the total
     * number of executed statements.
     */
    private function countQueriesFor(\Closure $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $action();

        $log = DB::getQueryLog();

        DB::disableQueryLog();

        return count($log);
    }
}