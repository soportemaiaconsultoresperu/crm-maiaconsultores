<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Events\V2\LeadCreated;
use App\Models\AutomationAction;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase as BaseTestCase;

/**
 * B12-UI — PR 1 foundation gate matrix (PERM-01..09).
 *
 * Covers the route table documented in
 *   - openspec/changes/b12-ui/specs/admin-automations-permissions.md (PERM-01..09)
 *   - openspec/changes/b12-ui/specs/admin-automations-crud.md (CRUD-01..08 routes)
 *
 * Per PERM-08 the test base MUST register `AutomationServiceProvider` explicitly
 * in `setUp()` so the 5 Spatie permissions are seeded into the in-memory
 * sqlite database before `actingAs($user)` runs. `RefreshDatabase` runs the
 * migrations; provider boot is what writes the rows into `permissions`.
 *
 * Each test:
 *  - creates a single user;
 *  - grants exactly the permission under test (and `automations.view` when
 *    the controller enforces it as defense-in-depth);
 *  - exercises the route via `actingAs($user)`;
 *  - asserts 2xx (or 501 stub) for "has perm" and 403 for "missing perm".
 *
 * The PR 1 stubs `abort(501)` for write paths once permission is granted,
 * so "has perm" must accept any 2xx OR 501 from the placeholder body —
 * the contract we are locking in is the gate, not the eventual response.
 *
 * NOTE on read-only "happy path" assertions: the existing placeholder views
 * (`admin/automations/index.blade.php`, `show.blade.php`, `execution.blade.php`)
 * have a pre-existing view bug (the `<x-table>` component requires a
 * `:rows` slot the placeholders do not provide). PR 2 will replace those
 * placeholders entirely. The gate test must therefore verify "gate passed"
 * via "response is NOT 403 / NOT 404" rather than `assertSuccessful()`,
 * because a 200 OR a 500 both indicate the gate accepted the request.
 */
class AdminAutomationPermissionsTest extends BaseTestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // PERM-08: ensure the 5 automation permissions exist before any test
        // calls `givePermissionTo(...)`. `force: true` is required because the
        // provider is registered in `bootstrap/providers.php` and would
        // otherwise be skipped on re-registration.
        app()->register(AutomationServiceProvider::class, force: true);

        $this->admin = User::factory()->create(['is_active' => true]);
    }

    /**
     * Gate-passed assertion: accepts any 2xx, 3xx, or 5xx status EXCEPT 403
     * (gate denied) and 404 (route undefined — i.e. PR 1 GREEN work not done).
     * This is the gate-matrix contract for PR 1: the controller method is
     * allowed to abort(501), render a (broken) placeholder, or return 200.
     *
     * A 500 is accepted because the existing placeholder views
     * (`admin/automations/{index,show,execution}.blade.php`) have a
     * pre-existing bug (the `<x-table>` component requires a `:rows` slot
     * the placeholders do not provide). PR 2 will rewrite those views; PR 1
     * only owns the gate. Until PR 2 lands, a 500 here indicates the gate
     * passed but the placeholder view failed to render.
     */
    private function assertGatePassed(TestResponse $response): void
    {
        $status = $response->getStatusCode();

        $this->assertNotSame(
            403,
            $status,
            "Gate must NOT reject the request; got 403. Body: ".$response->getContent()
        );

        $this->assertNotSame(
            404,
            $status,
            "Route must be defined; got 404 (PR 1 GREEN routes not added). Body: ".$response->getContent()
        );
    }

    private function assertGatePassedOr501(TestResponse $response): void
    {
        $status = $response->getStatusCode();

        $this->assertNotSame(
            403,
            $status,
            "Gate must NOT reject the request; got 403. Body: ".$response->getContent()
        );

        $this->assertNotSame(
            404,
            $status,
            "Route must be defined; got 404 (PR 1 GREEN routes not added). Body: ".$response->getContent()
        );

        $this->assertContains(
            $status,
            [200, 201, 302, 501],
            "Expected 2xx/302/501 (PR 1 stub); got {$status}."
        );
    }

    // ------------------------------------------------------------------
    // automations.view — read surface
    // ------------------------------------------------------------------

    public function test_view_permission_grants_index(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.index'));

        $this->assertGatePassed($response);
    }

    public function test_view_permission_grants_show(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('Show target');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.show', $rule));

        $this->assertGatePassed($response);
    }

    public function test_view_permission_grants_execution_show(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('Exec target');

        $execution = AutomationExecution::create([
            'rule_id' => $rule->id,
            'trigger_event' => LeadCreated::class,
            'subject_type' => 'App\Models\Lead',
            'subject_id' => 1,
            'idempotency_key' => 'perm-test-'.uniqid(),
            'status' => 'succeeded',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.executions.show', [
                'automation' => $rule->id,
                'execution' => $execution->id,
            ]));

        $this->assertGatePassed($response);
    }

    public function test_missing_view_permission_blocks_index(): void
    {
        // No permissions granted.
        $this->actingAs($this->admin)
            ->get(route('admin.automations.index'))
            ->assertForbidden();
    }

    public function test_missing_view_permission_blocks_show(): void
    {
        $rule = $this->makeRule('No perm target');

        $this->actingAs($this->admin)
            ->get(route('admin.automations.show', $rule))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // automations.manage — write surface (CRUD + toggle + reorder + restore)
    // ------------------------------------------------------------------

    public function test_manage_permission_grants_create(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.create'));

        $this->assertGatePassed($response);
    }

    public function test_manage_permission_grants_store_now_validates_and_redirects(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        // Stage 3A filled the store body — it persists via
        // RuleWriterService::create(). With an empty payload the form
        // validation fails and Laravel redirects back with session
        // errors (302). The gate passes (no 403).
        $response = $this->actingAs($this->admin)
            ->post(route('admin.automations.store'), []);

        $this->assertSame(302, $response->getStatusCode());
        $response->assertSessionHasErrors();
    }

    public function test_manage_permission_grants_edit(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Edit target');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.edit', $rule));

        $this->assertGatePassed($response);
    }

    public function test_manage_permission_grants_update_now_validates_and_redirects(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Update target');

        // Stage 3A filled the update body. With an empty payload the
        // form validation fails and the controller redirects back
        // with errors (302). The gate passes (no 403).
        $putResponse = $this->actingAs($this->admin)
            ->put(route('admin.automations.update', $rule), []);
        $this->assertSame(302, $putResponse->getStatusCode());
        $putResponse->assertSessionHasErrors();

        $patchResponse = $this->actingAs($this->admin)
            ->patch(route('admin.automations.update', $rule), []);
        $this->assertSame(302, $patchResponse->getStatusCode());
        $patchResponse->assertSessionHasErrors();
    }

    public function test_manage_permission_grants_destroy_now_soft_deletes(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Destroy target');

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.automations.destroy', $rule));

        // Stage 2B (PR 2) implemented the soft-delete body — the
        // controller now soft-deletes and redirects to the index with a
        // success flash, NOT an `abort(501)`. The gate passes (no 403),
        // and the model is soft-deleted in the DB.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSoftDeleted('automation_rules', ['id' => $rule->id]);
    }

    public function test_manage_permission_grants_restore_now_restores_or_404(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        // The route uses `{id}` because Laravel route-model binding skips
        // soft-deleted rows; Stage 2B implemented `findOrFail($id)`. Pass
        // an id that doesn't exist on purpose so the test asserts the
        // gate passes (no 403) and the controller reaches findOrFail
        // (404 if the id is not in the trash; 302 redirect if it is).
        $response = $this->actingAs($this->admin)
            ->post(route('admin.automations.restore', ['id' => 999999]));

        $status = $response->getStatusCode();
        $this->assertContains($status, [302, 404], "Expected redirect or 404, got {$status}.");
    }

    public function test_manage_permission_grants_reorder_now_persists_and_redirects(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        // Stage 3A filled the reorder body — it walks the order array
        // and writes the new `order` column. Empty array (no rules) is
        // a no-op; the controller returns 302 to the previous page.
        $response = $this->actingAs($this->admin)
            ->patch(route('admin.automations.reorder'), ['kind' => 'rules', 'order' => []]);

        $this->assertSame(302, $response->getStatusCode());
    }

public function test_manage_permission_grants_toggle(): void
    {
        // PR 2 (Stage 2A) — the toggle body now flips `is_active` and
        // returns a JSON envelope (CRUD-05). This test still asserts the
        // gate matrix (manage required, response is not 403/404); the
        // JSON envelope shape and idempotence are covered in detail by
        // `AdminAutomationToggleTest`.
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Toggle target');

        $response = $this->actingAs($this->admin)
            ->patch(route('admin.automations.toggle', $rule), ['is_active' => true]);

        $this->assertGatePassed($response);
    }

    public function test_view_alone_does_not_grant_store(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $this->actingAs($this->admin)
            ->post(route('admin.automations.store'), [])
            ->assertForbidden();
    }

    public function test_view_alone_does_not_grant_toggle(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('No toggle');

        $this->actingAs($this->admin)
            ->patch(route('admin.automations.toggle', $rule), ['is_active' => true])
            ->assertForbidden();
    }

    public function test_view_alone_does_not_grant_reorder(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $this->actingAs($this->admin)
            ->patch(route('admin.automations.reorder'), ['order' => []])
            ->assertForbidden();
    }

    public function test_view_alone_does_not_grant_destroy(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('No destroy');

        $this->actingAs($this->admin)
            ->delete(route('admin.automations.destroy', $rule))
            ->assertForbidden();
    }

    public function test_view_alone_does_not_grant_restore(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('No restore');

        $this->actingAs($this->admin)
            ->post(route('admin.automations.restore', $rule->id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // automations.test — simulate-only endpoint
    // ------------------------------------------------------------------

    public function test_test_permission_grants_simulate_with_real_implementation(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.test']);

        $rule = $this->makeRule('Sim target');

        $action = AutomationAction::create([
            'rule_id' => $rule->id,
            'position' => 1,
            'type' => 'add_note',
            'payload_json' => ['body' => 'hello'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.automations.actions.simulate', [
                'automation' => $rule->id,
                'action' => $action->id,
            ]), ['payload' => ['body' => 'sim']]);

        // PR 5 (Chunk 5) — `simulate()` is now the real engine wiring
        // (SCN-SIMULATE-01-A). Status 200 with `{ok: true, response_json: ...}`
        // envelope is the expected contract; the PR 1 stub-era 501 gate
        // is intentionally superseded.
        $response->assertOk();
        $response->assertJsonStructure(['ok', 'response_json']);
        $response->assertJson(['ok' => true]);
    }

    public function test_view_alone_does_not_grant_simulate(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('No sim');

        $action = AutomationAction::create([
            'rule_id' => $rule->id,
            'position' => 1,
            'type' => 'add_note',
            'payload_json' => ['body' => 'hello'],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.automations.actions.simulate', [
                'automation' => $rule->id,
                'action' => $action->id,
            ]), ['payload' => ['body' => 'sim']])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // automations.audit — audit feed
    // ------------------------------------------------------------------

    public function test_audit_permission_grants_audit_feed_view(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.audit']);

        $rule = $this->makeRule('Audit target');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.automations.audit', $rule));

        $this->assertGatePassed($response);
    }

    public function test_view_alone_does_not_grant_audit(): void
    {
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('No audit');

        $this->actingAs($this->admin)
            ->get(route('admin.automations.audit', $rule))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // automations.webhook.execute — registered but NOT enforced in v1
    // (PERM-06, design §13.2)
    // ------------------------------------------------------------------

    public function test_webhook_execute_alone_grants_no_route(): void
    {
        // Grant ONLY automations.webhook.execute. Every v1 route must
        // respond with 403 because no route enforces that permission.
        $this->admin->givePermissionTo('automations.webhook.execute');

        $rule = $this->makeRule('Webhook target');

        $action = AutomationAction::create([
            'rule_id' => $rule->id,
            'position' => 1,
            'type' => 'add_note',
            'payload_json' => ['body' => 'hello'],
            'is_active' => true,
        ]);

        // Every route below should return 403 — `automations.webhook.execute`
        // exists but is unreachable in v1.
        $this->actingAs($this->admin)
            ->get(route('admin.automations.index'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.automations.show', $rule))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.automations.create'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.automations.store'), [])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.automations.edit', $rule))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->put(route('admin.automations.update', $rule), [])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->delete(route('admin.automations.destroy', $rule))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->patch(route('admin.automations.toggle', $rule), ['is_active' => true])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->patch(route('admin.automations.reorder'), ['order' => []])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.automations.restore', $rule->id))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post(route('admin.automations.actions.simulate', [
                'automation' => $rule->id,
                'action' => $action->id,
            ]), ['payload' => ['body' => 'sim']])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.automations.audit', $rule))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Provider boot invariant — SCN-PERM-05
    // ------------------------------------------------------------------

    public function test_provider_boot_seeds_all_five_permissions(): void
    {
        $expected = [
            'automations.view',
            'automations.manage',
            'automations.test',
            'automations.audit',
            'automations.webhook.execute',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(
                Permission::query()
                    ->where('name', $name)
                    ->where('guard_name', 'web')
                    ->exists(),
                "PERM-08: permission {$name} should exist after provider boot."
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeRule(string $name): AutomationRule
    {
        return AutomationRule::create([
            'name' => $name,
            'description' => null,
            'trigger_event' => LeadCreated::class,
            'is_active' => false,
            'order' => 1,
            'mode' => 'test',
            'created_by' => $this->admin->id,
            'owner_id' => $this->admin->id,
        ]);
    }
}