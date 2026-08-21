<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Models\AutomationRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B12-UI — PR 2 / Stage 2B — CRUD-07 (soft-delete via destroy) + CRUD-08 (restore).
 *
 * Covers the trash lifecycle: deleting a rule from the active set, browsing the
 * papelera tab, restoring a soft-deleted rule, and the standard permission
 * gating. The Stage 2A toggle flow is NOT in scope of this class.
 *
 * Each test uses `RefreshDatabase` (set in setUp) plus an explicit boot of
 * the AutomationServiceProvider so the 5 B12 permissions exist before the
 * test runs (PERM-08).
 */
class AdminAutomationTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PERM-08: ensure the 5 automation permissions exist before any test
        // calls `givePermissionTo(...)`. `force: true` re-registers the
        // provider after RefreshDatabase wiped the tables.
        app()->register(\App\Providers\AutomationServiceProvider::class, force: true);

        // Seed the 84 V1 permissions + the 3 default roles.
        $this->seed(RolesAndPermissionsSeeder::class);

        // Re-register so the 5 B12 permissions are also assigned to the
        // admin role that the seeder just created (the boot path normally
        // does this on first boot; for tests after RefreshDatabase we
        // need the explicit re-run).
        app()->register(\App\Providers\AutomationServiceProvider::class, force: true);
    }

    /**
     * Helper: return a user with the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /**
     * Helper: create one AutomationRule (active) and return it.
     */
    private function createRule(array $overrides = []): AutomationRule
    {
        return AutomationRule::query()->create(array_merge([
            'name' => 'Test rule '.uniqid(),
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'is_active' => true,
            'order' => 10,
            'mode' => 'live',
            'created_by' => null,
            'owner_id' => null,
        ], $overrides));
    }

    // ---------------------------------------------------------------------
    // CRUD-07-A: user with manage can DELETE and the rule is soft-deleted
    // ---------------------------------------------------------------------
    public function test_manage_user_can_soft_delete_rule(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);
        $rule = $this->createRule();

        $response = $this->actingAs($user)->delete(route('admin.automations.destroy', $rule));

        $response->assertRedirect();
        $this->assertSoftDeleted('automation_rules', ['id' => $rule->id]);
    }

    // ---------------------------------------------------------------------
    // CRUD-07-B: soft-deleted rule does NOT appear on default index
    // ---------------------------------------------------------------------
    public function test_soft_deleted_rule_is_hidden_from_default_index(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);

        $active = $this->createRule(['name' => 'Active rule']);
        $trashed = $this->createRule(['name' => 'Soon-to-be-trashed']);
        $trashed->delete();

        $response = $this->actingAs($user)->get(route('admin.automations.index'));

        $response->assertOk();
        $response->assertSee('Active rule');
        $response->assertDontSee('Soon-to-be-trashed');
    }

    // ---------------------------------------------------------------------
    // CRUD-07-C: user without manage gets 403 on destroy
    // ---------------------------------------------------------------------
    public function test_view_only_user_cannot_destroy(): void
    {
        $user = $this->userWithPermissions(['automations.view']);
        $rule = $this->createRule();

        $response = $this->actingAs($user)->delete(route('admin.automations.destroy', $rule));

        $response->assertForbidden();
        $this->assertDatabaseHas('automation_rules', ['id' => $rule->id, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------------
    // CRUD-08-A: POST restore re-activates the rule and removes deleted_at
    // ---------------------------------------------------------------------
    public function test_restore_brings_soft_deleted_rule_back(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);
        $rule = $this->createRule();
        $rule->delete();

        $this->assertSoftDeleted('automation_rules', ['id' => $rule->id]);

        $response = $this->actingAs($user)->post(
            route('admin.automations.restore', ['id' => $rule->id]),
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('automation_rules', [
            'id' => $rule->id,
            'deleted_at' => null,
        ]);
    }

    // ---------------------------------------------------------------------
    // CRUD-08-B / Papelera tab: GET trash shows ONLY soft-deleted rules
    // ---------------------------------------------------------------------
    public function test_trash_lists_only_soft_deleted_rules(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);

        $active = $this->createRule(['name' => 'Survives on index']);
        $trashed = $this->createRule(['name' => 'Visible on trash only']);
        $trashed->delete();

        $response = $this->actingAs($user)->get(route('admin.automations.trash'));

        $response->assertOk();
        $response->assertSee('Visible on trash only');
        $response->assertDontSee('Survives on index');
    }
}
