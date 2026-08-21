<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * User administration (B08 / RF-USR-001, RF-USR-005, RF-USR-007, RF-USR-008,
 * ADR-006, ADR-008).
 *
 * The User model itself does not use LogsActivity: every change is logged
 * explicitly here so we have meaningful `event` names (user-created,
 * user-password-reset, user-activated, user-deactivated, user-role-changed)
 * instead of generic `updated` events. The activity subject is the user
 * being acted upon; the causer is the admin performing the action.
 *
 * Sensitive operations:
 * - The password is never logged (properties never include the hash).
 * - Self-deactivation is forbidden (defensive guard inside setActive).
 * - `recordLogin()` deliberately does NOT write an activitylog row: logging
 *   every login would create useless noise; the `users.last_login_at`
 *   column is the source of truth for "último acceso" (RF-USR-006).
 *
 * Visibility for admin views:
 * - The users table has no `owner_id`, so we apply scope through
 *   DataScopeService::appliesToUsers (see that method for rationale).
 * - `scopeQuery()` returns the same builder paginated views use.
 */
class UserService
{
    /**
     * Length of the random password generated when no password is provided
     * on user creation. 16 chars keeps it strong enough while still being
     * copy-pasteable.
     */
    public const RANDOM_PASSWORD_LENGTH = 16;

    public function __construct(
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Create a new user. If `$data['password']` is empty/absent, a random
     * password is generated and stored in the returned `random_password`
     * attribute on the model so the controller can show it once to the
     * admin (the value is never persisted, only echoed back on this call).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        $roleName = $data['role'] ?? null;
        unset($data['role']);

        $generatedPassword = null;

        if (empty($data['password'])) {
            $generatedPassword = self::randomPassword();
            $data['password'] = $generatedPassword;
        }

        return DB::transaction(function () use ($data, $actor, $roleName, $generatedPassword): User {
            $user = new User();
            $user->name = (string) ($data['name'] ?? '');
            $user->email = (string) ($data['email'] ?? '');
            $user->password = $data['password'];
            $user->is_active = (bool) ($data['is_active'] ?? true);
            $user->email_verified_at = $data['email_verified_at'] ?? null;
            $user->save();

            if ($roleName !== null && $roleName !== '') {
                $this->syncRole($user, (string) $roleName);
            }

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'user-created',
                'description' => "Usuario {$user->name} creado",
                'properties' => [
                    'email' => $user->email,
                    'is_active' => (bool) $user->is_active,
                    'role' => $roleName,
                    'generated_password' => $generatedPassword !== null,
                ],
            ]);

            if ($generatedPassword !== null) {
                // Surface the cleartext password exactly once; never store it.
                $user->setAttribute('random_password', $generatedPassword);
            }

            return $user;
        });
    }

    /**
     * Update user metadata. The password is intentionally NOT mutated here:
     * password changes go through resetPassword() so the audit event has
     * a dedicated, unambiguous name.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $oldEmail = $user->email;

            if (array_key_exists('name', $data)) {
                $user->name = (string) $data['name'];
            }

            if (array_key_exists('email', $data)) {
                $user->email = (string) $data['email'];
            }

            if (array_key_exists('is_active', $data)) {
                $user->is_active = (bool) $data['is_active'];
            }

            $user->save();

            if (array_key_exists('role', $data)) {
                $roleName = $data['role'] !== null ? (string) $data['role'] : null;
                $this->syncRole($user, $roleName);
            }

            $properties = [];
            if ($oldEmail !== $user->email) {
                $properties['old_email'] = $oldEmail;
                $properties['new_email'] = $user->email;
            }

            if ($properties !== []) {
                Activity::query()->create([
                    'log_name' => 'default',
                    'subject_type' => User::class,
                    'subject_id' => $user->id,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'event' => 'user-updated',
                    'description' => "Usuario {$user->name} actualizado",
                    'properties' => $properties,
                ]);
            }

            return $user->refresh();
        });
    }

    /**
     * Reset a user's password (RF-USR-005). The new password is stored
     * hashed and audited with the dedicated event `user-password-reset`.
     * The cleartext password is never persisted.
     */
    public function resetPassword(User $user, string $newPassword, User $actor): User
    {
        return DB::transaction(function () use ($user, $newPassword, $actor): User {
            $user->password = $newPassword;
            $user->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'user-password-reset',
                'description' => "Contraseña restablecida para {$user->name}",
                'properties' => [
                    'reset_by_self' => $user->id === $actor->id,
                ],
            ]);

            return $user->refresh();
        });
    }

    /**
     * Activate or deactivate a user (RF-USR-001). The admin cannot
     * deactivate themselves — the defensive guard throws
     * InvalidOperationException so the controller can render a clear
     * Spanish message.
     *
     * @throws InvalidOperationException When $actor tries to deactivate themselves.
     */
    public function setActive(User $user, bool $active, User $actor): User
    {
        if (! $active && $user->id === $actor->id) {
            throw new InvalidOperationException(
                'No puedes desactivarte a ti mismo; pide a otro administrador que lo haga.'
            );
        }

        return DB::transaction(function () use ($user, $active, $actor): User {
            $previous = (bool) $user->is_active;
            $user->is_active = $active;
            $user->save();

            $event = $active ? 'user-activated' : 'user-deactivated';
            $description = $active
                ? "Usuario {$user->name} activado"
                : "Usuario {$user->name} desactivado";

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => $event,
                'description' => $description,
                'properties' => [
                    'old_is_active' => $previous,
                    'new_is_active' => (bool) $user->is_active,
                ],
            ]);

            return $user->refresh();
        });
    }

    /**
     * Stamp `last_login_at`. Intentionally side-effect free with respect to
     * the activity log: a login is not a sensitive operation worth
     * auditing, and logging it on every sign-in would create useless
     * noise in the audit viewer.
     */
    public function recordLogin(User $user): User
    {
        $user->last_login_at = now();
        $user->save();

        return $user;
    }

    /**
     * Owner-aware query for the admin users index (RF-USR-008, ADR-006).
     * The users table has no `owner_id` column, so we delegate to
     * DataScopeService::appliesToUsers which translates the same
     * admin/supervisor/vendedor semantics to `WHERE users.id IN (...)`.
     *
     * @return Builder<User>
     */
    public function scopeQuery(User $user): Builder
    {
        return $this->dataScope->appliesToUsers(User::query(), $user);
    }

    /**
     * Generate a random password of length RANDOM_PASSWORD_LENGTH using
     * alphanumeric characters. Public so callers (tests, factories, console
     * commands) can reuse the exact policy.
     */
    public static function randomPassword(int $length = self::RANDOM_PASSWORD_LENGTH): string
    {
        return Str::password($length, true, true, false);
    }

    /**
     * Replace the user's role assignment with the given role name. Passing
     * null or an empty string removes all role assignments. The role must
     * exist in the database; we never auto-create roles from this layer
     * (the seeder is the single source of role truth).
     */
    private function syncRole(User $user, ?string $roleName): void
    {
        if ($roleName === null || $roleName === '') {
            $user->syncRoles([]);

            return;
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            throw new \InvalidArgumentException(
                "El rol \"{$roleName}\" no existe."
            );
        }

        $user->syncRoles([$roleName]);
    }
}