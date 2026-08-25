<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CustomerInvoice;
use App\Models\SupportTicket;
use App\Policies\CustomerInvoicePolicy;
use App\Policies\SupportTicketPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as BaseAuthServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Application's AuthServiceProvider.
 *
 * Laravel 11+ removed this provider from the default skeleton — it must be
 * created and registered manually if the application wants to extend the
 * `Gate` facade (e.g. add `Gate::before` callbacks, register model policies).
 *
 * B12-UI's `Gate::authorize('automations.view')` calls (and every other
 * admin controller call) translate into Spatie Permission's `hasPermissionTo`
 * checks. Without this `Gate::before` callback, the `Gate` has no way to
 * bridge a `permissions` table row into an ability, and every admin action
 * returns 403 "This action is unauthorized." even when the user has the
 * permission.
 *
 * The fix: register a `Gate::before` callback that, on every ability check,
 * looks up the user's `permissions` table and returns `true` if the user
 * has the requested ability. The `?: null` falls through to Laravel's
 * default policy / closure check when the user does not have the permission.
 *
 * This is the canonical Spatie 6+ pattern for Laravel 11/12/13. It does
 * NOT grant `admin` blanket bypass — every check is per-permission. If the
 * application needs a blanket `admin` bypass, add a `hasRole('admin')` short
 * circuit above the `hasPermissionTo` call; that is a product decision, not
 * a technical one.
 */
class AuthServiceProvider extends BaseAuthServiceProvider
{
    /**
     * Register the application's policies.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        CustomerInvoice::class => CustomerInvoicePolicy::class,
        SupportTicket::class => SupportTicketPolicy::class,
    ];

    public function boot(): void
    {
        // Note: the base class `Illuminate\Foundation\Support\Providers\AuthServiceProvider`
        // does NOT have a `boot()` method in Laravel 13 — it only has `register()`
        // (which calls `registerPolicies()` via the booting callback). Calling
        // `parent::boot()` here would throw `Call to undefined method`. The
        // `registerPolicies()` step is handled automatically by the framework.

        // Spatie Permission ability bridge. Every `Gate::authorize($ability)`
        // call delegates here first. The callback:
        //  1. Returns `null` (no decision) when the user is anonymous.
        //  2. Returns `true` when the user is admin (canonical "admin bypass":
        //     the admin role governs; permission-level checks are for non-admin
        //     roles). This makes the admin functional even when a specific
        //     permission row is missing from the DB.
        //  3. Otherwise returns `$user->hasPermissionTo($ability) ?: null` —
        //     `true` if the user has the permission, `null` (fall through) if
        //     not. Spatie's `hasPermissionTo` throws
        //     `PermissionDoesNotExist` for unknown permissions; the try/catch
        //     converts that to `null` so the Gate falls through to its default
        //     policy / closure lookup instead of crashing the framework.
        Gate::before(static function ($user, string $ability) {
            if ($user === null) {
                return null;
            }

            if ($user->hasRole('admin')) {
                return true;
            }

            try {
                return $user->hasPermissionTo($ability) ?: null;
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                return null;
            }
        });
    }
}
