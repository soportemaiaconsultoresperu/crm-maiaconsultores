<?php

namespace Tests\Feature;

use App\Support\Permissions\SeededGrants;
use Database\Seeders\AdditionalConsentPermissionsSeeder;
use Database\Seeders\AdditionalEmailPermissionsSeeder;
use Database\Seeders\AdditionalNotificationPermissionsSeeder;
use Database\Seeders\AdditionalWhatsAppPermissionsSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Production runs `migrate --force && db:seed --force` on EVERY deploy, so the
 * permission seeders must grant each default permission to a role ONCE and
 * never revoke or re-grant it afterwards: what the admin adjusts on the role
 * screen survives the next deploy. The `seeded_role_grants` ledger records
 * which defaults were already handed out.
 */
class SeededGrantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Role permission sets the full seed produced BEFORE the ledger existed
     * (captured from the syncPermissions-based seeders). A fresh database must
     * keep producing exactly these.
     */
    private const FRESH_SEED_SNAPSHOT = [
        'admin' => [
            'activities.complete', 'activities.create', 'activities.deactivate', 'activities.delete',
            'activities.export', 'activities.update', 'activities.view.any', 'activities.view.own',
            'activities.view.team', 'audit.view', 'calendar.view', 'campaign_templates.create',
            'campaign_templates.duplicate', 'campaign_templates.update', 'campaign_templates.view',
            'campaigns.add_contacts', 'campaigns.cancel', 'campaigns.complete', 'campaigns.create',
            'campaigns.duplicate', 'campaigns.mark_realized', 'campaigns.override_completion',
            'campaigns.pause', 'campaigns.register_actions', 'campaigns.remove_contacts',
            'campaigns.reschedule', 'campaigns.schedule', 'campaigns.start', 'campaigns.update',
            'campaigns.view', 'campaigns.view_reports', 'catalogs.manage', 'catalogs.view',
            'contacts.create', 'contacts.deactivate', 'contacts.export', 'contacts.update',
            'contacts.view.any', 'contacts.view.own', 'contacts.view.team', 'content_projects.archive',
            'content_projects.assign_members', 'content_projects.create', 'content_projects.update',
            'content_projects.view', 'content_projects.view_all', 'course-talks.activities.manage',
            'course-talks.attendance.manage', 'course-talks.audit.view',
            'course-talks.commercial-documents.manage', 'course-talks.documents.generate',
            'course-talks.documents.revoke', 'course-talks.documents.send', 'course-talks.editions.manage',
            'course-talks.grades.manage', 'course-talks.participants.manage', 'course-talks.sessions.manage',
            'course-talks.templates.manage', 'course-talks.view', 'customer-payments.manage',
            'customer-payments.view', 'customers.create', 'customers.deactivate', 'customers.export',
            'customers.update', 'customers.view.any', 'customers.view.own', 'customers.view.team',
            'demo-data.manage', 'documents.delete', 'documents.download', 'documents.upload',
            'documents.view.any', 'documents.view.own', 'documents.view.team', 'leads.assign',
            'leads.convert', 'leads.create', 'leads.deactivate', 'leads.export', 'leads.import',
            'leads.update', 'leads.view.any', 'leads.view.own', 'leads.view.team', 'opportunities.create',
            'opportunities.deactivate', 'opportunities.export', 'opportunities.lose', 'opportunities.update',
            'opportunities.view.any', 'opportunities.view.own', 'opportunities.view.team', 'opportunities.win',
            'products.create', 'products.deactivate', 'products.export', 'products.update',
            'products.view.any', 'quotations.accept', 'quotations.create', 'quotations.deactivate',
            'quotations.export', 'quotations.update', 'quotations.view.any', 'quotations.view.own',
            'quotations.view.team', 'reports.view', 'roles.manage', 'roles.view', 'settings.manage',
            'settings.view', 'support.assign', 'support.attention.start', 'support.cancel',
            'support.catalogs.manage', 'support.close', 'support.close.with-pending-observations',
            'support.create', 'support.observations.create', 'support.observations.lift',
            'support.observations.reject', 'support.observations.validate', 'support.priority.update',
            'support.reassign', 'support.reopen', 'support.reports.view', 'support.reschedule',
            'support.resolve', 'support.schedule', 'support.update', 'support.updates.create',
            'support.view.any', 'support.view.own', 'support.view.team', 'teams.manage', 'teams.view',
            'users.assign_role', 'users.create', 'users.deactivate', 'users.manage',
            'users.reset_password', 'users.update', 'users.view',
        ],
        'supervisor' => [
            'activities.complete', 'activities.create', 'activities.delete', 'activities.update',
            'activities.view.team', 'audit.view', 'calendar.view', 'campaign_templates.create',
            'campaign_templates.duplicate', 'campaign_templates.update', 'campaign_templates.view',
            'campaigns.add_contacts', 'campaigns.cancel', 'campaigns.complete', 'campaigns.create',
            'campaigns.duplicate', 'campaigns.mark_realized', 'campaigns.override_completion',
            'campaigns.pause', 'campaigns.register_actions', 'campaigns.remove_contacts',
            'campaigns.reschedule', 'campaigns.schedule', 'campaigns.start', 'campaigns.update',
            'campaigns.view', 'campaigns.view_reports', 'catalogs.manage', 'catalogs.view',
            'contacts.create', 'contacts.update', 'contacts.view.team', 'content_projects.view',
            'course-talks.view', 'customer-payments.manage', 'customer-payments.view', 'customers.create',
            'customers.update', 'customers.view.team', 'documents.download', 'documents.upload',
            'documents.view.team', 'leads.create', 'leads.update', 'leads.view.team',
            'opportunities.create', 'opportunities.update', 'opportunities.view.team', 'products.view.any',
            'quotations.create', 'quotations.update', 'quotations.view.team', 'reports.view', 'roles.view',
            'settings.manage', 'settings.view', 'support.assign', 'support.attention.start',
            'support.cancel', 'support.close', 'support.close.with-pending-observations', 'support.create',
            'support.observations.create', 'support.observations.lift', 'support.observations.reject',
            'support.observations.validate', 'support.priority.update', 'support.reassign',
            'support.reopen', 'support.reports.view', 'support.reschedule', 'support.resolve',
            'support.schedule', 'support.update', 'support.updates.create', 'support.view.team',
            'teams.manage', 'teams.view', 'users.view',
        ],
        'vendedor' => [
            'activities.complete', 'activities.create', 'activities.delete', 'activities.update',
            'activities.view.own', 'calendar.view', 'campaigns.mark_realized', 'campaigns.reschedule',
            'campaigns.view', 'campaigns.view_reports', 'contacts.create', 'contacts.update',
            'contacts.view.own', 'customers.create', 'customers.update', 'customers.view.own',
            'documents.delete', 'documents.download', 'documents.upload', 'documents.view.own',
            'leads.convert', 'leads.create', 'leads.export', 'leads.update', 'leads.view.own',
            'opportunities.create', 'opportunities.lose', 'opportunities.update', 'opportunities.view.own',
            'opportunities.win', 'products.view.any', 'quotations.accept', 'quotations.create',
            'quotations.update', 'quotations.view.own', 'reports.view', 'support.create', 'support.update',
            'support.updates.create', 'support.view.own',
        ],
    ];

    /** What the four standalone module seeders added on top of the full seed. */
    private const MODULE_SEEDERS_ADDITIONS = [
        'admin' => [
            'consent.audit', 'consent.grant', 'consent.revoke', 'consent.view',
            'email.account.manage', 'email.audit', 'email.send', 'email.shared.use',
            'email.template.manage', 'email.view', 'notifications.audit', 'notifications.manage',
            'notifications.send', 'notifications.view', 'whatsapp.account.manage', 'whatsapp.audit',
            'whatsapp.conversation.assign', 'whatsapp.send', 'whatsapp.template.manage', 'whatsapp.view',
        ],
        'supervisor' => [
            'consent.audit', 'consent.view', 'email.view', 'notifications.audit',
            'notifications.view', 'whatsapp.view',
        ],
        'vendedor' => [],
    ];

    private function deploySeed(bool $withModuleSeeders = false): void
    {
        $this->seed(DatabaseSeeder::class);

        if ($withModuleSeeders) {
            $this->seed([
                AdditionalConsentPermissionsSeeder::class,
                AdditionalEmailPermissionsSeeder::class,
                AdditionalNotificationPermissionsSeeder::class,
                AdditionalWhatsAppPermissionsSeeder::class,
            ]);
        }
    }

    /** @return list<string> */
    private function permissionsOf(string $roleName): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::findByName($roleName, 'web')->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_fresh_seed_produces_exactly_the_pre_ledger_role_permissions(): void
    {
        $this->deploySeed();

        foreach (self::FRESH_SEED_SNAPSHOT as $role => $expected) {
            $this->assertSame($expected, $this->permissionsOf($role), "Role [{$role}] after a fresh seed.");
        }

        // A second deploy on an untouched database changes nothing.
        $this->deploySeed();

        foreach (self::FRESH_SEED_SNAPSHOT as $role => $expected) {
            $this->assertSame($expected, $this->permissionsOf($role), "Role [{$role}] after a re-seed.");
        }
    }

    public function test_fresh_seed_with_module_seeders_matches_the_pre_ledger_role_permissions(): void
    {
        $this->deploySeed(withModuleSeeders: true);

        foreach (self::FRESH_SEED_SNAPSHOT as $role => $base) {
            $expected = collect($base)->merge(self::MODULE_SEEDERS_ADDITIONS[$role])->sort()->values()->all();

            $this->assertSame($expected, $this->permissionsOf($role), "Role [{$role}] after the module seeders.");
        }
    }

    public function test_a_default_the_admin_removed_stays_removed_after_re_seeding(): void
    {
        $this->deploySeed(withModuleSeeders: true);

        Role::findByName('vendedor', 'web')->revokePermissionTo(['leads.export', 'support.create']);
        Role::findByName('supervisor', 'web')->revokePermissionTo(['consent.view', 'course-talks.view']);
        Role::findByName('admin', 'web')->revokePermissionTo('content_projects.view_all');

        $this->deploySeed(withModuleSeeders: true);

        $vendedor = $this->permissionsOf('vendedor');
        $this->assertNotContains('leads.export', $vendedor);
        $this->assertNotContains('support.create', $vendedor);
        $this->assertContains('leads.convert', $vendedor, 'Untouched defaults are kept.');

        $supervisor = $this->permissionsOf('supervisor');
        $this->assertNotContains('consent.view', $supervisor);
        $this->assertNotContains('course-talks.view', $supervisor);

        $this->assertNotContains('content_projects.view_all', $this->permissionsOf('admin'));
    }

    public function test_a_permission_removed_by_the_admin_is_not_regranted_when_the_app_boots_again(): void
    {
        $this->deploySeed(withModuleSeeders: true);

        // The module providers grant on every boot; boot them once so their
        // defaults are in the ledger, as in production after a deploy.
        $providers = [
            \App\Providers\EmailServiceProvider::class,
            \App\Providers\WhatsAppServiceProvider::class,
            \App\Providers\NotificationServiceProvider::class,
            \App\Providers\ConsentServiceProvider::class,
            \App\Providers\AutomationServiceProvider::class,
        ];
        $boot = function () use ($providers): void {
            foreach ($providers as $provider) {
                (new $provider($this->app))->boot();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };
        $boot();

        Role::findByName('supervisor', 'web')->revokePermissionTo(['email.view', 'automations.view']);
        $boot();

        $supervisor = $this->permissionsOf('supervisor');
        $this->assertNotContains('email.view', $supervisor);
        $this->assertNotContains('automations.view', $supervisor);
    }

    public function test_a_non_default_permission_the_admin_added_stays_after_re_seeding(): void
    {
        $this->deploySeed();

        Role::findByName('supervisor', 'web')->givePermissionTo(['users.create', 'leads.view.any']);
        Role::findByName('vendedor', 'web')->givePermissionTo('leads.import');

        $this->deploySeed();

        $supervisor = $this->permissionsOf('supervisor');
        $this->assertContains('users.create', $supervisor);
        $this->assertContains('leads.view.any', $supervisor);
        $this->assertContains('leads.import', $this->permissionsOf('vendedor'));
    }

    public function test_a_new_default_is_granted_once_and_a_later_removal_sticks(): void
    {
        $this->deploySeed();

        Permission::create(['name' => 'reports.brand_new', 'guard_name' => 'web']);
        $vendedor = Role::findByName('vendedor', 'web');

        SeededGrants::grantOnce($vendedor, ['reports.view', 'reports.brand_new', 'does.not.exist']);

        $this->assertContains('reports.brand_new', $this->permissionsOf('vendedor'));
        $this->assertDatabaseHas('seeded_role_grants', ['role_id' => $vendedor->id, 'permission_name' => 'reports.brand_new']);
        $this->assertDatabaseMissing('seeded_role_grants', ['permission_name' => 'does.not.exist']);
        $this->assertFalse(Permission::query()->where('name', 'does.not.exist')->exists(), 'grantOnce never creates permissions.');

        $vendedor->revokePermissionTo('reports.brand_new');
        SeededGrants::grantOnce($vendedor->fresh(), ['reports.view', 'reports.brand_new']);
        $this->deploySeed();

        $this->assertNotContains('reports.brand_new', $this->permissionsOf('vendedor'));
    }

    public function test_an_existing_database_without_ledger_rows_is_bootstrapped_once(): void
    {
        $this->deploySeed();

        // A database seeded before the ledger existed: grants, no ledger rows.
        DB::table('seeded_role_grants')->delete();
        Role::findByName('vendedor', 'web')->revokePermissionTo('leads.export');
        Role::findByName('supervisor', 'web')->givePermissionTo('users.create');

        // First deploy with the ledger: defaults are granted once (documented
        // bootstrap), customisations beyond the defaults are kept.
        $this->deploySeed();

        $this->assertContains('leads.export', $this->permissionsOf('vendedor'));
        $this->assertContains('users.create', $this->permissionsOf('supervisor'));

        $vendedorId = Role::findByName('vendedor', 'web')->id;
        $this->assertDatabaseHas('seeded_role_grants', ['role_id' => $vendedorId, 'permission_name' => 'leads.export']);
        $this->assertDatabaseHas('seeded_role_grants', ['role_id' => $vendedorId, 'permission_name' => 'support.view.own']);

        // From then on the ledger protects the admin's choices.
        Role::findByName('vendedor', 'web')->revokePermissionTo('leads.export');
        $this->deploySeed();

        $this->assertNotContains('leads.export', $this->permissionsOf('vendedor'));
    }
}
