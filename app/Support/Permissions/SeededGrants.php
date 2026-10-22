<?php

namespace App\Support\Permissions;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Grant once" for the permission seeders.
 *
 * Production runs `db:seed --force` on every deploy. A seeder that called
 * `syncPermissions()` wiped every adjustment the admin made on the role screen,
 * and the merge-then-sync seeders re-granted defaults the admin had removed on
 * purpose. Instead, every default a seeder hands to a role is recorded in the
 * `seeded_role_grants` ledger; a default that already has a ledger row is the
 * admin's to keep or remove and is never granted again.
 *
 * Pure add: this never revokes a permission and never creates one (names that
 * do not exist as permissions are skipped).
 *
 * Bootstrap: on a database seeded before the ledger existed there are no rows,
 * so the first run grants every current default once (re-granting a default
 * the admin had removed before the ledger shipped) and records it; from then on
 * the admin's choices stick.
 */
final class SeededGrants
{
    public const TABLE = 'seeded_role_grants';

    /**
     * @param  iterable<string>  $permissionNames
     */
    public static function grantOnce(Role $role, iterable $permissionNames): void
    {
        $names = array_values(array_unique(is_array($permissionNames)
            ? $permissionNames
            : iterator_to_array($permissionNames, false)));

        if ($names === []) {
            return;
        }

        $existing = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        if ($existing === []) {
            return;
        }

        // The service providers also grant on boot, which can run before the
        // ledger migration exists: keep the previous additive behavior then.
        if (! self::ledgerExists()) {
            $role->givePermissionTo($existing);

            return;
        }

        $alreadySeeded = DB::table(self::TABLE)
            ->where('role_id', $role->getKey())
            ->whereIn('permission_name', $existing)
            ->pluck('permission_name')
            ->all();

        $pending = array_values(array_diff($existing, $alreadySeeded));

        if ($pending === []) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // givePermissionTo() only attaches what the role is missing; names the
        // role already holds are just recorded in the ledger.
        $role->givePermissionTo($pending);

        $now = now();

        DB::table(self::TABLE)->insertOrIgnore(array_map(
            fn (string $name): array => [
                'role_id' => $role->getKey(),
                'permission_name' => $name,
                'granted_at' => $now,
            ],
            $pending,
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private static ?bool $ledgerExists = null;

    private static function ledgerExists(): bool
    {
        // Once it exists it stays; a missing table is re-checked (it may be
        // created by a migration later in the same process).
        if (self::$ledgerExists === true) {
            return true;
        }

        return self::$ledgerExists = \Illuminate\Support\Facades\Schema::hasTable(self::TABLE);
    }
}
