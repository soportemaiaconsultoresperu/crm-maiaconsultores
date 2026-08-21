<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Models\AutomationAction;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B12-UI — PR 3 / Stage 3A — CRUD-02 (store), CRUD-03 (update), CRUD-04 (clone),
 * CRUD-06 (reorder) — the server-side half of the rule editor.
 *
 * The Livewire `RuleForm` and `ConditionGroupEditor` are wired in Stage 3B /
 * PR 3b. This class only covers the persistence + validation + gate path.
 */
class AdminAutomationRuleFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(\App\Providers\AutomationServiceProvider::class, force: true);
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\AutomationServiceProvider::class, force: true);
    }

    private function manageUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['automations.view', 'automations.manage']);

        return $user;
    }

    private function viewOnlyUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['automations.view']);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mi regla',
            'description' => 'Descripción de prueba',
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'is_active' => true,
            'order' => 10,
            'mode' => 'test',
            'owner_id' => null,
            'groups' => [
                [
                    'logical_operator' => 'AND',
                    'position' => 1,
                    'conditions' => [
                        [
                            'field' => 'source_id',
                            'operator' => 'is_not_null',
                            'value' => null,
                            'value_type' => 'string',
                            'position' => 1,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'add_tag',
                    'position' => 1,
                    'channel' => null,
                    'recipient_strategy' => null,
                    'payload_json' => ['tag_slug' => 'high-priority'],
                    'retry_policy_json' => null,
                    'is_active' => true,
                ],
            ],
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // CRUD-02 — store creates rule + groups + conditions + actions
    // ---------------------------------------------------------------------
    public function test_store_persists_rule_with_groups_conditions_and_actions(): void
    {
        $user = $this->manageUser();
        $payload = $this->validPayload();

        $response = $this->actingAs($user)->post(route('admin.automations.store'), $payload);

        $response->assertRedirect();  // 302 to admin.automations.show of the new rule.
        $this->assertDatabaseHas('automation_rules', [
            'name' => 'Mi regla',
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'mode' => 'test',
            'created_by' => $user->id,
        ]);
        $rule = AutomationRule::query()->where('name', 'Mi regla')->first();
        $this->assertNotNull($rule);
        $this->assertSame(1, $rule->conditionGroups()->count());
        $this->assertSame(1, $rule->conditionGroups()->first()->conditions()->count());
        $this->assertSame('source_id', $rule->conditionGroups()->first()->conditions()->first()->field);
        $this->assertSame(1, $rule->actions()->count());
        $this->assertSame('add_tag', $rule->actions()->first()->type);
    }

    // ---------------------------------------------------------------------
    // CRUD-02 — view-only user cannot store
    // ---------------------------------------------------------------------
    public function test_view_only_user_cannot_store(): void
    {
        $user = $this->viewOnlyUser();

        $response = $this->actingAs($user)->post(route('admin.automations.store'), $this->validPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('automation_rules', 0);
    }

    // ---------------------------------------------------------------------
    // CRUD-02 — missing required field validation
    // ---------------------------------------------------------------------
    public function test_store_validates_required_name(): void
    {
        $user = $this->manageUser();
        $payload = $this->validPayload();
        unset($payload['name']);

        $response = $this->actingAs($user)->post(route('admin.automations.store'), $payload);

        $response->assertSessionHasErrors(['name']);
        $this->assertDatabaseCount('automation_rules', 0);
    }

    // ---------------------------------------------------------------------
    // CRUD-03 — update modifies rule + children
    // ---------------------------------------------------------------------
    public function test_update_persists_changes_to_rule_and_children(): void
    {
        $user = $this->manageUser();
        $rule = AutomationRule::query()->create([
            'name' => 'Original',
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'is_active' => true,
            'order' => 1,
            'mode' => 'live',
        ]);
        $group = AutomationConditionGroup::query()->create([
            'rule_id' => $rule->id,
            'logical_operator' => 'AND',
            'position' => 1,
        ]);
        AutomationCondition::query()->create([
            'group_id' => $group->id,
            'rule_id' => $rule->id,
            'field' => 'source_id',
            'operator' => 'is_not_null',
            'value' => null,
            'value_type' => 'string',
            'position' => 1,
        ]);

        $payload = $this->validPayload([
            'name' => 'Editada',
            'mode' => 'live',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)
            ->put(route('admin.automations.update', $rule), $payload);

        $response->assertRedirect();
        $this->assertDatabaseHas('automation_rules', [
            'id' => $rule->id,
            'name' => 'Editada',
            'mode' => 'live',
            'is_active' => 0,
        ]);
    }

    // ---------------------------------------------------------------------
    // CRUD-04 — clone replicates rule + groups + conditions + actions
    // ---------------------------------------------------------------------
    public function test_clone_creates_a_copy_with_new_ids_and_disabled(): void
    {
        $user = $this->manageUser();
        $rule = AutomationRule::query()->create([
            'name' => 'Original',
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'is_active' => true,
            'order' => 1,
            'mode' => 'live',
        ]);
        $group = AutomationConditionGroup::query()->create([
            'rule_id' => $rule->id,
            'logical_operator' => 'AND',
            'position' => 1,
        ]);
        AutomationCondition::query()->create([
            'group_id' => $group->id,
            'rule_id' => $rule->id,
            'field' => 'source_id',
            'operator' => 'is_not_null',
            'value' => null,
            'value_type' => 'string',
            'position' => 1,
        ]);
        AutomationAction::query()->create([
            'rule_id' => $rule->id,
            'type' => 'add_tag',
            'position' => 1,
            'payload_json' => ['tag_slug' => 'high-priority'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->post(route('admin.automations.clone', $rule));

        $response->assertRedirect();
        $clones = AutomationRule::query()->where('name', 'Original (copia)')->get();
        $this->assertCount(1, $clones);
        $clone = $clones->first();
        $this->assertNotSame($rule->id, $clone->id);
        $this->assertFalse((bool) $clone->is_active);
        $this->assertSame('test', $clone->mode);
        // Children replicated with new IDs under the new rule.
        $this->assertSame(1, $clone->conditionGroups()->count());
        $this->assertSame(1, $clone->conditionGroups()->first()->conditions()->count());
        $this->assertSame(1, $clone->actions()->count());
        $this->assertNotSame($rule->conditionGroups()->first()->id, $clone->conditionGroups()->first()->id);
    }

    // ---------------------------------------------------------------------
    // CRUD-06 — reorder persists new sequence for rules
    // ---------------------------------------------------------------------
    public function test_reorder_persists_new_rule_sequence(): void
    {
        $user = $this->manageUser();
        $a = AutomationRule::query()->create(['name' => 'A', 'trigger_event' => \App\Events\V2\LeadCreated::class, 'is_active' => true, 'order' => 1, 'mode' => 'live']);
        $b = AutomationRule::query()->create(['name' => 'B', 'trigger_event' => \App\Events\V2\LeadCreated::class, 'is_active' => true, 'order' => 2, 'mode' => 'live']);
        $c = AutomationRule::query()->create(['name' => 'C', 'trigger_event' => \App\Events\V2\LeadCreated::class, 'is_active' => true, 'order' => 3, 'mode' => 'live']);

        $payload = ['kind' => 'rules', 'order' => [$c->id, $a->id, $b->id]];

        $response = $this->actingAs($user)
            ->patch(route('admin.automations.reorder'), $payload);

        $response->assertRedirect();
        $this->assertSame(1, AutomationRule::query()->find($c->id)->order);
        $this->assertSame(2, AutomationRule::query()->find($a->id)->order);
        $this->assertSame(3, AutomationRule::query()->find($b->id)->order);
    }

    // ---------------------------------------------------------------------
    // CRUD-02 / COND-08 (B12.5-POL-03) — POST with a trigger_event not in
    // the catalog returns 422 + errors.trigger_event.
    // ---------------------------------------------------------------------
    public function test_store_with_invalid_trigger_returns_422(): void
    {
        $user = $this->manageUser();
        $payload = $this->validPayload(['trigger_event' => 'App\\NotAReal\\Event']);

        $response = $this->actingAs($user)->postJson(route('admin.automations.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['trigger_event']);
        $this->assertDatabaseCount('automation_rules', 0);
    }

    // ---------------------------------------------------------------------
    // CRUD-03 / COND-08 (B12.5-POL-03) — PUT with a trigger_event not in
    // the catalog returns 422 + errors.trigger_event.
    // ---------------------------------------------------------------------
    public function test_update_with_invalid_trigger_returns_422(): void
    {
        $user = $this->manageUser();
        $rule = AutomationRule::query()->create([
            'name' => 'Original',
            'trigger_event' => \App\Events\V2\LeadCreated::class,
            'is_active' => true,
            'order' => 1,
            'mode' => 'live',
        ]);

        $payload = $this->validPayload(['trigger_event' => 'Invalid']);

        $response = $this->actingAs($user)
            ->putJson(route('admin.automations.update', $rule), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['trigger_event']);
        $this->assertDatabaseHas('automation_rules', [
            'id' => $rule->id,
            'name' => 'Original',
        ]);
    }
}
