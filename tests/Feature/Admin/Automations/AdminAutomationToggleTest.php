<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Events\V2\LeadCreated;
use App\Models\AutomationRule;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

/**
 * B12-UI — PR 2 (Stage 2A) — CRUD-05 toggle is_active inline.
 *
 * Covers:
 *   - REQ-CRUD-05 (admin-automations-crud.md)
 *     "The system SHALL provide an inline toggle on the index that
 *      PATCHes only is_active against automations.toggle (alias of update
 *      restricted to that column). The endpoint SHALL validate
 *      is_active ∈ {true, false} and SHALL refuse the call with 403 when
 *      the user lacks automations.manage."
 *
 * Scenarios:
 *   - SCN-CRUD-05-A — manage user flips is_active (false → true).
 *   - SCN-CRUD-05-A — manage user flips is_active (true → false).
 *   - SCN-CRUD-05-B — toggling twice returns the original state.
 *   - SCN-CRUD-05-C — view-only user is forbidden (403).
 *   - SCN-CRUD-05-D — unauthenticated user is redirected to login (302).
 *   - SCN-CRUD-05-E — response is a JSON envelope { ok, is_active, id }.
 *
 * Mirrors `AdminAutomationPermissionsTest` conventions:
 *   - `RefreshDatabase`
 *   - `app()->register(AutomationServiceProvider::class, force: true)` in setUp
 *   - `actingAs($user)` + `givePermissionTo(...)` for the gate matrix
 *
 * Trace:
 *   - Spec  : openspec/changes/b12-ui/specs/admin-automations-crud.md §REQ-CRUD-05
 *   - Design: openspec/changes/b12-ui/design.md §2 (route shape),
 *             §4.1 (gate as first-statement), §5 (no Policy, Gate::authorize)
 *   - Tasks : openspec/changes/b12-ui/tasks.md §A.Chunk 2 (PR 2 — index + toggle)
 */
class AdminAutomationToggleTest extends BaseTestCase
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

    // ------------------------------------------------------------------
    // SCN-CRUD-05-A + SCN-CRUD-05-E — manage user flips is_active and
    // the response is a JSON envelope.
    // ------------------------------------------------------------------

    public function test_manage_user_can_toggle_is_active_from_false_to_true(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Toggle false→true', isActive: false);

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.automations.toggle', $rule));

        // SCN-CRUD-05-E — JSON envelope shape.
        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'is_active' => true,
            'id' => $rule->id,
        ]);

        // SCN-CRUD-05-A — DB state flipped to true.
        $this->assertTrue(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-A: is_active must flip false → true after toggle.'
        );
    }

    public function test_manage_user_can_toggle_is_active_from_true_to_false(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Toggle true→false', isActive: true);

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.automations.toggle', $rule));

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'is_active' => false,
            'id' => $rule->id,
        ]);

        $this->assertFalse(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-A: is_active must flip true → false after toggle.'
        );
    }

    // ------------------------------------------------------------------
    // SCN-CRUD-05-B — toggling twice returns the original state.
    // ------------------------------------------------------------------

    public function test_toggling_twice_returns_to_original_state(): void
    {
        $this->admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = $this->makeRule('Toggle idempotent', isActive: false);

        // First toggle: false → true.
        $this->actingAs($this->admin)
            ->patchJson(route('admin.automations.toggle', $rule))
            ->assertOk()
            ->assertJson(['ok' => true, 'is_active' => true, 'id' => $rule->id]);

        $this->assertTrue($rule->fresh()->is_active, 'First toggle should flip to true.');

        // Second toggle: true → false (back to original).
        $this->actingAs($this->admin)
            ->patchJson(route('admin.automations.toggle', $rule))
            ->assertOk()
            ->assertJson(['ok' => true, 'is_active' => false, 'id' => $rule->id]);

        $this->assertFalse(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-B: toggling twice must return to the original state.'
        );
    }

    // ------------------------------------------------------------------
    // SCN-CRUD-05-C — view-only user is forbidden (403).
    // ------------------------------------------------------------------

    public function test_view_only_user_is_forbidden_from_toggle(): void
    {
        // Only `automations.view`, no `automations.manage`.
        $this->admin->givePermissionTo('automations.view');

        $rule = $this->makeRule('Toggle no-perm', isActive: false);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.automations.toggle', $rule))
            ->assertForbidden();

        // Server MUST NOT mutate the row when forbidden.
        $this->assertFalse(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-C: is_active must remain unchanged when the gate denies the request.'
        );
    }

    // ------------------------------------------------------------------
    // SCN-CRUD-05-D — unauthenticated user.
    //
    // The `admin.automations.toggle` route lives inside the
    // `Route::middleware(['auth', 'active'])->prefix('admin')` group on
    // routes/web.php:31,304. Laravel's `auth` middleware behaves
    // differently depending on the request's `Accept` header (configured
    // in bootstrap/app.php):
    //
    //   - JSON request (Accept: application/json, the form Stage 2B's
    //     fetch() will use)  →  HTTP 401 JSON.
    //   - HTML / browser request (no Accept header)               →  HTTP 302
    //     redirect to `route('login')` per the `redirectGuestsTo` hook.
    //
    // The `can:automations.manage` middleware never runs because the auth
    // gate fires first. SCN-CRUD-05-D expects "401/403 (depending on
    // middleware behaviour; document which)" — we assert BOTH paths so
    // the contract is locked in regardless of the caller's content type.
    // ------------------------------------------------------------------

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $rule = $this->makeRule('Toggle anonymous JSON', isActive: false);

        $response = $this->patchJson(route('admin.automations.toggle', $rule));

        // Documented behaviour: JSON 401 (Laravel default auth middleware
        // for JSON requests; `redirectGuestsTo` only applies to HTML).
        $response->assertStatus(401);

        $this->assertFalse(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-D: the rule must remain unchanged when no user is authenticated.'
        );
    }

    public function test_unauthenticated_html_request_is_redirected_to_login(): void
    {
        $rule = $this->makeRule('Toggle anonymous HTML', isActive: false);

        $response = $this->patch(
            route('admin.automations.toggle', $rule),
            [],
            ['Accept' => 'text/html']
        );

        // Documented behaviour: HTML 302 → /login (per
        // `redirectGuestsTo(fn () => route('login'))` in bootstrap/app.php).
        $response->assertStatus(302);
        $response->assertRedirect(route('login'));

        $this->assertFalse(
            $rule->fresh()->is_active,
            'SCN-CRUD-05-D: the rule must remain unchanged when no user is authenticated.'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeRule(string $name, bool $isActive = false): AutomationRule
    {
        return AutomationRule::create([
            'name' => $name,
            'description' => null,
            'trigger_event' => LeadCreated::class,
            'is_active' => $isActive,
            'order' => 1,
            'mode' => 'test',
            'created_by' => $this->admin->id,
            'owner_id' => $this->admin->id,
        ]);
    }
}