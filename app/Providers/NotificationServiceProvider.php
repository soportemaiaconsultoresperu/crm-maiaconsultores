<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B17 — Notification module wiring.
 *
 *  - register(): no service bindings yet (the dispatch service, retry jobs
 *    and channel adapters land in B17 Pasada B / C).
 *  - boot(): explicit {@see registerNotificationPermissions()} that
 *    materialises the four B17 permissions and grants the read-only set
 *    (`view`, `audit`) to admin and supervisor; the admin-only set
 *    (`manage`, `send`) stays admin-only per the B17 proposal.
 *
 *  Pattern is intentionally identical to {@see EmailServiceProvider} and
 *  {@see WhatsAppServiceProvider}: guarded by Schema::hasTable('permissions')
 *  inside a try/catch so the boot path never fails when the DB is
 *  unreachable or the schema is not yet migrated (the dedicated seeder
 *  catches up afterwards).
 *
 *  Per docs/v2/01-roadmap.md §2.7 and §10 decisions D-21a..D-21g.
 *  D-21f (new-device detection) and D-21g (SLA) are explicitly out of
 *  scope for V2 — the four mandatory administrative / security triggers
 *  that this provider enables are D-21a, D-21b, D-21c and D-21d.
 */
class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Permissions introduced by B17.
     *
     *  - notifications.view    — read inbox (own deliveries).
     *  - notifications.manage  — configure preferences for any user
     *                            (admin-only; D-21a..D-21e governance).
     *  - notifications.audit   — see all outbound deliveries + retries
     *                            across every user (audit surface).
     *  - notifications.send    — force a notification dispatch on behalf
     *                            of the system (admin-only).
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'notifications.view',
        'notifications.manage',
        'notifications.audit',
        'notifications.send',
    ];

    /**
     * Permissions granted to the supervisor role for v1 (read-only view +
     * audit). `manage` and `send` stay admin-only per the B17 proposal so
     * the configuration surface is not delegated downward.
     *
     * @var list<string>
     */
    public const SUPERVISOR_GRANTS = [
        'notifications.view',
        'notifications.audit',
    ];

    /**
     * Permissions granted to the admin role (everything).
     *
     * @var list<string>
     */
    public const ADMIN_GRANTS = self::PERMISSIONS;

    public function register(): void
    {
        // B17 Pasada B — service bindings (NotificationService dispatcher,
        // channel adapters, retry job). Intentionally left empty here so
        // tests can swap the provider factory without touching the rest of
        // the framework. The dispatch service will sit alongside the B13
        // EmailService and B14 WhatsAppService, dispatched through Laravel's
        // Bus for retry policy.
    }

    public function boot(): void
    {
        $this->registerNotificationPermissions();

        // B17 / D-21a..c — wire the three mandatory B17 triggers as event
        // listeners. The fourth (D-21d NotificationFailedPermanently) is
        // emitted by SendOutboundDelivery::failed() but its listener is
        // deferred to a follow-up B17.x change (no V2 listener needed —
        // the operator can read the failed deliveries view).
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\V2\IntegrationAccountDisconnected::class,
            [\App\Listeners\V2\NotifyAdminsOnIntegrationEvent::class, 'handleIntegrationAccountDisconnected'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\V2\AutomationCycleDetected::class,
            [\App\Listeners\V2\NotifyAdminsOnIntegrationEvent::class, 'handleAutomationCycleDetected'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\V2\IntegrationFailedPermanently::class,
            [\App\Listeners\V2\NotifyAdminsOnIntegrationEvent::class, 'handleIntegrationFailedPermanently'],
        );
    }

    /**
     * B17 — ensure the four notification permissions exist and that admin
     * and supervisor receive the right grants. Idempotent and registered at
     * boot time so V1 permission counts (84 + 6 email + 6 whatsapp = 96)
     * stay deterministic.
     */
    public function registerNotificationPermissions(): void
    {
        // Guard for early boot contexts (e.g. test bootstrap before the
        // Spatie permission tables exist, or the configured DB is unreachable).
        // If the schema isn't ready or the DB connection fails, the permission
        // registrations will be performed by the dedicated seeder in
        // production deployments — never break the boot path with a fatal here.
        try {
            if (! Schema::hasTable('permissions')) {
                return;
            }
        } catch (\Throwable $e) {
            // DB unreachable (driver down, credentials wrong, schema not yet
            // migrated). Exit silently; the dedicated seeder will catch up.
            if (function_exists('logger')) {
                logger()->debug('NotificationServiceProvider: permissions guard skipped', [
                    'reason' => $e::class,
                ]);
            }
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin !== null) {
            $existing = $admin->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::ADMIN_GRANTS)));
            $admin->syncPermissions($merged);
        }

        $supervisor = Role::query()
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->first();

        if ($supervisor !== null) {
            $existing = $supervisor->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, self::SUPERVISOR_GRANTS)));
            $supervisor->syncPermissions($merged);
        }
    }
}
