<?php

namespace Tests\Feature\Admin;

use App\Exceptions\InvalidOperationException;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\CatalogService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B08 — Catalog administration service tests (RF-CFG-001, RF-CFG-002).
 *
 * Four rules pin down the contract:
 * - create() on a generic catalog (LeadSource) inserts the row and emits
 *   a catalog-created audit entry.
 * - deactivate() flips is_active=0 with a reason (never deletes) and
 *   the row stays queryable.
 * - Uniqueness on the natural key is enforced (slug for LeadSource).
 * - activate() brings a row back.
 */
class CatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private CatalogService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->service = app(CatalogService::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_create_on_lead_source_inserts_row_and_audits(): void
    {
        $row = $this->service->create(LeadSource::class, [
            'name' => 'Pídelo',
            'slug' => 'pidelo',
            'sort' => 10,
            'is_active' => true,
        ], $this->admin);

        $this->assertNotNull($row->id);
        $this->assertSame('Pídelo', $row->name);
        $this->assertSame('pidelo', $row->slug);
        $this->assertTrue((bool) $row->is_active);

        $log = Activity::query()
            ->where('subject_type', LeadSource::class)
            ->where('subject_id', $row->id)
            ->where('event', 'catalog-created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('Pídelo', $log->properties['name']);
        $this->assertSame('pidelo', $log->properties['unique_value']);
    }

    public function test_deactivate_flips_active_flag_with_reason_and_does_not_delete(): void
    {
        $row = $this->service->create(LeadSource::class, [
            'name' => 'Pídelo',
            'slug' => 'pidelo',
        ], $this->admin);

        $this->service->deactivate(LeadSource::class, $row, $this->admin, 'Origen en desuso');

        $fresh = $row->fresh();
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertNotNull($fresh, 'The row must remain in the table; catalogs are never deleted.');

        $log = Activity::query()
            ->where('subject_type', LeadSource::class)
            ->where('subject_id', $row->id)
            ->where('event', 'catalog-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Origen en desuso', $log->properties['reason']);
    }

    public function test_uniqueness_on_slug_is_enforced(): void
    {
        $this->service->create(LeadSource::class, [
            'name' => 'Pídelo',
            'slug' => 'pidelo',
        ], $this->admin);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existe un registro con slug="pidelo"');

        $this->service->create(LeadSource::class, [
            'name' => 'Otro',
            'slug' => 'pidelo',
        ], $this->admin);
    }

    public function test_activate_brings_a_row_back(): void
    {
        $row = $this->service->create(LeadSource::class, [
            'name' => 'Pídelo',
            'slug' => 'pidelo',
        ], $this->admin);

        $this->service->deactivate(LeadSource::class, $row, $this->admin, 'Temporal');

        $this->service->activate(LeadSource::class, $row->fresh(), $this->admin);

        $this->assertTrue((bool) $row->fresh()->is_active);

        $log = Activity::query()
            ->where('subject_type', LeadSource::class)
            ->where('subject_id', $row->id)
            ->where('event', 'catalog-activated')
            ->first();

        $this->assertNotNull($log, 'Activation must emit a catalog-activated audit row.');

        // Activating an already-active row is a no-op state guard.
        $this->expectException(InvalidOperationException::class);
        $this->service->activate(LeadSource::class, $row->fresh(), $this->admin);
    }
}