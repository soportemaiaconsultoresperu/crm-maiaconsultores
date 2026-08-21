<?php

namespace Tests\Feature;

use App\Models\LeadSource;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B08 — Admin HTTP layer (Tanda B).
 *
 * Covers the user-facing surfaces that the controllers and views expose:
 *
 *  - Users: create + edit (with optional random password), reset password
 *    (audit logged), cannot-deactivate-self guard, activate/deactivate.
 *  - Teams: add/remove member (each emits its own audit row).
 *  - Catalogs: create + deactivate via the generic routes.
 *  - Settings: form posts through SettingsService and persists typed values.
 *  - Audit viewer: after a logged-in user performs a sensitive action, the
 *    entry is visible at /admin/audit with subject_type, user_name and event.
 *  - Vendedor cannot reach the admin namespace at all (403 on /admin/users).
 */
class AdminHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
    }

    public function test_admin_can_create_user_with_blank_password_and_generates_temporary(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/users', [
                'name' => 'Nuevo Vendedor',
                'email' => 'nuevo@maia.test',
                'is_active' => 1,
                'role' => 'vendedor',
            ]);

        $response->assertRedirect();

        $created = User::query()->where('email', 'nuevo@maia.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('vendedor'));
        $this->assertTrue($created->is_active);

        // The random password was generated and the controller surfaced a
        // confirmation message that includes the temporary credentials.
        $this->assertNotEmpty($response->getSession()->get('status'));
    }

    public function test_admin_can_edit_user_and_change_role(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $target->assignRole('vendedor');

        $response = $this->actingAs($this->admin)
            ->put("/admin/users/{$target->id}", [
                'name' => 'Renombrado',
                'email' => $target->email,
                'is_active' => 1,
                'role' => 'supervisor',
            ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertSame('Renombrado', $target->name);
        $this->assertTrue($target->hasRole('supervisor'));
        $this->assertFalse($target->hasRole('vendedor'));
    }

    public function test_vendedor_cannot_access_admin_users_index(): void
    {
        $this->actingAs($this->vendedor)
            ->get('/admin/users')
            ->assertForbidden();

        $this->actingAs($this->vendedor)
            ->get('/admin/teams')
            ->assertForbidden();

        $this->actingAs($this->vendedor)
            ->get('/admin/audit')
            ->assertForbidden();
    }

    public function test_reset_password_updates_hash_and_audits_the_change(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $oldHash = $target->password;

        $response = $this->actingAs($this->admin)
            ->post("/admin/users/{$target->id}/reset-password", [
                'password' => 'NuevaClaveSegura-2026',
                'password_confirmation' => 'NuevaClaveSegura-2026',
            ]);

        $response->assertRedirect();
        $target->refresh();

        $this->assertNotSame($oldHash, $target->password);
        $this->assertTrue(Hash::check('NuevaClaveSegura-2026', $target->password));

        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-password-reset')
            ->first();

        $this->assertNotNull($log, 'Reset password must emit a user-password-reset audit entry.');
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertFalse((bool) $log->properties['reset_by_self']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $response = $this->actingAs($this->admin)
            ->post("/admin/users/{$this->admin->id}/set-active", [
                'is_active' => 0,
                'reason' => 'Quiero cerrar mi cuenta',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('is_active');
        $this->assertTrue($this->admin->refresh()->is_active);

        $error = (string) $response->getSession()->get('errors')->first('is_active');
        $this->assertStringContainsString('No puedes desactivarte a ti mismo', $error);
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $target = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->post("/admin/users/{$target->id}/set-active", [
                'is_active' => 0,
                'reason' => 'Rotación de personal',
            ]);

        $response->assertRedirect();
        $this->assertFalse($target->refresh()->is_active);

        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue((bool) $log->properties['old_is_active']);
        $this->assertFalse((bool) $log->properties['new_is_active']);
    }

    public function test_team_member_add_and_remove_emits_individual_audit_rows(): void
    {
        $team = Team::query()->create([
            'name' => 'Equipo Piloto',
            'supervisor_id' => $this->admin->id,
            'is_active' => true,
        ]);

        $member = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post("/admin/teams/{$team->id}/add-member", ['user_id' => $member->id])
            ->assertRedirect();

        $this->assertTrue($team->refresh()->members()->whereKey($member->id)->exists());

        $this->actingAs($this->admin)
            ->post("/admin/teams/{$team->id}/remove-member/{$member->id}")
            ->assertRedirect();

        $this->assertFalse($team->refresh()->members()->whereKey($member->id)->exists());

        $added = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-added')
            ->count();
        $removed = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-removed')
            ->count();

        $this->assertGreaterThanOrEqual(1, $added);
        $this->assertGreaterThanOrEqual(1, $removed);
    }

    public function test_catalog_create_and_deactivate_through_admin_routes(): void
    {
        // Create a new lead source through the admin endpoint.
        $this->actingAs($this->admin)
            ->post('/admin/catalogs/lead-sources', [
                'name' => 'Email marketing',
                'slug' => 'email-marketing',
            ])
            ->assertRedirect();

        $row = LeadSource::query()->where('slug', 'email-marketing')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->is_active);

        // Deactivate it with a reason.
        $this->actingAs($this->admin)
            ->post("/admin/catalogs/lead-sources/{$row->id}/deactivate", [
                'reason' => 'Ya no usamos este canal.',
            ])
            ->assertRedirect();

        $row->refresh();
        $this->assertFalse($row->is_active);

        $log = Activity::query()
            ->where('subject_type', LeadSource::class)
            ->where('subject_id', $row->id)
            ->where('event', 'catalog-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Ya no usamos este canal.', $log->properties['reason']);
    }

    public function test_settings_update_round_trip_through_admin_form(): void
    {
        $payload = [
            'settings' => [
                [
                    'key' => 'pagination_size',
                    'type' => 'integer',
                    'group' => 'general',
                    'value' => 50,
                ],
                [
                    'key' => 'prices_include_tax',
                    'type' => 'boolean',
                    'group' => 'general',
                    'value' => 1,
                ],
                [
                    'key' => 'currency_default',
                    'type' => 'string',
                    'group' => 'general',
                    'value' => 'USD',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->put('/admin/settings', $payload);

        $response->assertRedirect();

        $this->assertSame('50', (string) Setting::query()->where('key', 'pagination_size')->value('value'));
        $this->assertSame('1', (string) Setting::query()->where('key', 'prices_include_tax')->value('value'));
        $this->assertSame('USD', (string) Setting::query()->where('key', 'currency_default')->value('value'));

        $log = Activity::query()
            ->where('subject_type', Setting::class)
            ->where('subject_id', 'pagination_size')
            ->where('event', 'setting-updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_audit_viewer_lists_subject_user_and_event_after_admin_action(): void
    {
        $target = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->post("/admin/users/{$target->id}/set-active", [
                'is_active' => 0,
                'reason' => 'Cese temporal',
            ])
            ->assertRedirect();

        // After the action, the admin can see the entry at /admin/audit with
        // subject_type, the user's name as causer, and the event name.
        $response = $this->actingAs($this->admin)
            ->get('/admin/audit')
            ->assertOk();

        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-deactivated')
            ->first();

        $this->assertNotNull($log);

        $response->assertSee('user-deactivated');
        $response->assertSee(User::class);
        $response->assertSee($this->admin->name);
    }

    public function test_admin_can_navigate_to_each_admin_section(): void
    {
        $this->actingAs($this->admin)->get('/admin/users')->assertOk();
        $this->actingAs($this->admin)->get('/admin/users/create')->assertOk();
        $this->actingAs($this->admin)->get('/admin/teams')->assertOk();
        $this->actingAs($this->admin)->get('/admin/teams/create')->assertOk();
        $this->actingAs($this->admin)->get('/admin/roles')->assertOk();
        $this->actingAs($this->admin)->get('/admin/catalogs/lead-sources')->assertOk();
        $this->actingAs($this->admin)->get('/admin/catalogs/currencies')->assertOk();
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk();
        $this->actingAs($this->admin)->get('/admin/audit')->assertOk();
    }
}