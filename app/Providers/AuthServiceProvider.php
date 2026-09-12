<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCertificateTemplate;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\CustomerInvoice;
use App\Models\SupportTicket;
use App\Policies\Courses\CourseAcademicDocumentPolicy;
use App\Policies\Courses\CourseActivityPolicy;
use App\Policies\Courses\CourseCertificateTemplatePolicy;
use App\Policies\Courses\CourseCommercialDocumentPolicy;
use App\Policies\Courses\CourseEditionPolicy;
use App\Policies\Courses\CourseEnrollmentPolicy;
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
 * This is the canonical Spatie 6+ pattern for Laravel 11/12/13, with one
 * product decision layered on top: the `admin` ROLE short-circuits every ability
 * check and is granted access unconditionally. That bypass is real and
 * load-bearing — it is why the admin keeps working when a specific permission
 * row is missing from the database. It also means REVOKING a permission from the
 * admin does NOT hide or block anything for them: only non-admin roles can be
 * restricted by permissions alone. Do not rely on a permission rollback to lock
 * an admin out of a surface.
 */
class AuthServiceProvider extends BaseAuthServiceProvider
{
    /**
     * Register the application's policies.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        CourseAcademicDocument::class => CourseAcademicDocumentPolicy::class,
        CourseActivity::class => CourseActivityPolicy::class,
        CourseCertificateTemplate::class => CourseCertificateTemplatePolicy::class,
        CourseCommercialDocument::class => CourseCommercialDocumentPolicy::class,
        CourseEdition::class => CourseEditionPolicy::class,
        CourseEnrollment::class => CourseEnrollmentPolicy::class,
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
