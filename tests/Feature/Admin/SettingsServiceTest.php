<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B08 — Settings service tests (RF-CFG-004, RF-CFG-005).
 *
 * Three rules pin down the contract:
 * - set() persists typed values and emits a setting-updated audit row.
 * - get() returns the value cast through its declared type, falling
 *   back to the supplied default when the key is absent.
 * - all() returns a key=>value array with all values already cast.
 */
class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->service = app(SettingsService::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_set_persists_typed_values_and_audits(): void
    {
        $this->service->set('pagination_size', 50, 'integer', 'general', $this->admin);

        $row = Setting::query()->whereKey('pagination_size')->first();
        $this->assertNotNull($row);
        $this->assertSame('integer', $row->type);
        $this->assertSame('50', $row->value, 'Integer settings store the string-encoded value.');

        // Booleans normalise to '1' / '0'.
        $this->service->set('prices_include_tax', true, 'boolean', 'general', $this->admin);
        $row = Setting::query()->whereKey('prices_include_tax')->first();
        $this->assertSame('1', $row->value);

        // Audit row exists for the second write (first is the actor's first
        // upsert; both are audited, but we at least assert one is present).
        $log = Activity::query()
            ->where('subject_type', Setting::class)
            ->where('subject_id', 'prices_include_tax')
            ->where('event', 'setting-updated')
            ->first();

        $this->assertNotNull($log, 'Setting writes must emit a setting-updated audit row.');
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('1', $log->properties['new_value']);
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $this->assertNull($this->service->get('nonexistent'));
        $this->assertSame('fallback', $this->service->get('nonexistent', 'fallback'));

        // Casting works on existing seeded values.
        $this->service->set('pagination_size', 30, 'integer', 'general', $this->admin);
        $this->assertSame(30, $this->service->get('pagination_size'));

        $this->service->set('prices_include_tax', false, 'boolean', 'general', $this->admin);
        $this->assertFalse($this->service->get('prices_include_tax'));

        $this->service->set('quote_validity_days', 15, 'integer', 'quotations', $this->admin);
        $this->assertSame(15, $this->service->get('quote_validity_days'));
    }

    public function test_all_returns_keyed_map_with_typed_values(): void
    {
        $this->service->set('pagination_size', 30, 'integer', 'general', $this->admin);
        $this->service->set('prices_include_tax', true, 'boolean', 'general', $this->admin);
        $this->service->set('currency_default', 'USD', 'string', 'general', $this->admin);

        $all = $this->service->all();

        $this->assertSame(30, $all['pagination_size']);
        $this->assertTrue($all['prices_include_tax']);
        $this->assertSame('USD', $all['currency_default']);
    }
}