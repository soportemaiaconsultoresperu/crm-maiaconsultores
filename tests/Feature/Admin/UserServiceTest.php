<?php

namespace Tests\Feature\Admin;

use App\Exceptions\InvalidOperationException;
use App\Models\Team;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * B08 — User administration service tests (RF-USR-001, RF-USR-005,
 * RF-USR-007, RF-USR-008, ADR-006, ADR-008).
 *
 * The six cases pin down the contract callers depend on:
 * - random password generation when no password is supplied,
 * - the defensive self-deactivation guard,
 * - the audit properties emitted on password reset and active toggle,
 * - last_login_at bookkeeping,
 * - and that the vendedor role lacks the admin permissions.
 */
class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->service = app(UserService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_create_generates_a_random_password_when_none_supplied(): void
    {
        $user = $this->service->create([
            'name' => 'Carla Nueva',
            'email' => 'carla.nueva@maia.test',
            'is_active' => true,
            'role' => 'vendedor',
        ], $this->admin);

        $this->assertSame('Carla Nueva', $user->name);
        $this->assertSame('carla.nueva@maia.test', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole('vendedor'));

        // Random password was generated and exposed once.
        $random = $user->getAttribute('random_password');
        $this->assertIsString($random);
        $this->assertSame(UserService::RANDOM_PASSWORD_LENGTH, strlen($random));

        // Hash is bcrypt, NOT the cleartext.
        $this->assertNotSame($random, $user->password);
        $this->assertTrue(Hash::check($random, $user->password));

        // The cleartext value is NOT persisted — refresh strips it.
        $reloaded = $user->refresh();
        $this->assertNull($reloaded->getAttribute('random_password'));

        // Audit row records the creation with the role.
        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('event', 'user-created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('vendedor', $log->properties['role']);
        $this->assertTrue((bool) $log->properties['generated_password']);
    }

    public function test_set_active_rejects_self_deactivation(): void
    {
        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('No puedes desactivarte a ti mismo');

        $this->service->setActive($this->admin, false, $this->admin);
    }

    public function test_reset_password_updates_the_hash_and_audits_the_change(): void
    {
        $target = User::factory()->create([
            'name' => 'Objetivo',
            'email' => 'objetivo@maia.test',
            'is_active' => true,
        ]);

        $oldHash = $target->password;

        $updated = $this->service->resetPassword($target, 'nueva-clave-2026', $this->admin);

        $this->assertNotSame($oldHash, $updated->password, 'Password hash must change on reset.');
        $this->assertTrue(Hash::check('nueva-clave-2026', $updated->password));
        $this->assertFalse(Hash::check('password', $updated->password), 'Old factory password should no longer be valid.');

        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-password-reset')
            ->first();

        $this->assertNotNull($log, 'Resetting a password must emit a user-password-reset audit entry.');
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertFalse((bool) $log->properties['reset_by_self']);
    }

    public function test_set_active_logs_old_and_new_values(): void
    {
        $target = User::factory()->create(['is_active' => true]);

        $updated = $this->service->setActive($target, false, $this->admin);

        $this->assertFalse($updated->is_active);

        $log = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-deactivated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertTrue((bool) $log->properties['old_is_active']);
        $this->assertFalse((bool) $log->properties['new_is_active']);

        // Reactivation: the event name flips and old/new swap.
        $this->service->setActive($updated, true, $this->admin);

        $reactivateLog = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'user-activated')
            ->first();

        $this->assertNotNull($reactivateLog);
        $this->assertFalse((bool) $reactivateLog->properties['old_is_active']);
        $this->assertTrue((bool) $reactivateLog->properties['new_is_active']);
    }

    public function test_record_login_updates_timestamp_without_logging_an_activity(): void
    {
        $target = User::factory()->create(['is_active' => true, 'last_login_at' => null]);

        $this->assertNull($target->last_login_at);

        $this->service->recordLogin($target);

        $this->assertNotNull($target->fresh()->last_login_at);

        // recordLogin must NOT emit any activity_log entry.
        $count = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->count();

        $this->assertSame(0, $count, 'Login should be invisible in the audit log.');
    }

    public function test_vendedor_lacks_the_create_user_admin_permission(): void
    {
        // The vendedor role must not hold users.* admin permissions —
        // guards the policy/controller layer from accidentally letting a
        // seller create a new user. We assert at the permission layer
        // because the service itself never checks auth (controllers do).
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $this->assertTrue(Permission::where('name', 'users.create')->exists(), 'Permission users.create must exist.');
        $this->assertFalse($vendedor->can('users.create'));
        $this->assertFalse($vendedor->can('users.deactivate'));
        $this->assertFalse($vendedor->can('users.reset_password'));
        $this->assertFalse($vendedor->can('users.assign_role'));
    }

    public function test_scope_query_applies_owner_aware_scope_for_admin_users_index(): void
    {
        // Belt and suspenders: the admin sees everyone; the vendedor
        // sees only himself. This pins down the helper the admin
        // controller will use for its paginated listing.
        $other = User::factory()->create(['is_active' => true]);
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $adminCount = $this->service->scopeQuery($this->admin)->count();
        $this->assertSame(3, $adminCount, 'Admin sees every user in the directory.');

        $vendedorCount = $this->service->scopeQuery($vendedor)->count();
        $this->assertSame(1, $vendedorCount, 'Vendedor sees only himself.');
        $this->assertSame($vendedor->id, $this->service->scopeQuery($vendedor)->value('id'));
    }
}