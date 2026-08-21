<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Events\V2\LeadCreated;
use App\Livewire\Admin\Automations\RuleForm;
use App\Models\AutomationAction;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationRule;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 3 / Stage 3B-2 — RuleForm Livewire component.
 *
 * Covers the live-state contract of the dual-purpose (create + edit) rule
 * form. The form actually submits via a standard HTTP POST/PUT to the
 * server-side endpoints (admin.automations.store / admin.automations.update);
 * those paths are exercised by
 *   tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php
 * (Stage 3A, shipped earlier). This class only verifies the Livewire state
 * contract: initial state, mount-when-edit, add/remove of groups + actions,
 * and the `triggers` / `actionTypes` computed properties.
 *
 * Spec   : openspec/changes/b12-ui/specs/admin-automations-crud.md
 *          CRUD-02 (create), CRUD-03 (update).
 * Design : openspec/changes/b12-ui/design.md §3.2 (Livewire tree),
 *          §3.3 (RuleForm responsibilities).
 * Tasks  : openspec/changes/b12-ui/tasks.md §A.Chunk 3 (PR 3 / Stage 3B-2).
 *
 * TDD discipline: this file exists BEFORE the component / view are authored.
 * Running it before the GREEN step proves the missing-class failure (RED).
 *
 * @see \App\Livewire\Admin\Automations\RuleForm
 */
class RuleFormLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PERM-08 — keep the provider under explicit control so the test
        // surface is independent of whether the host bootstrap registered
        // it (the host registration is conditional; tests must opt-in).
        app()->register(AutomationServiceProvider::class, force: true);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-A — mount(null, 'create') initializes one default empty
    // group AND one default add_tag action.
    // ---------------------------------------------------------------------
    public function test_create_mode_initializes_one_default_group_and_one_default_action(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->assertSet('mode', 'create')
            ->assertSet('ruleId', null)
            ->assertSet('name', '')
            ->assertSet('ruleMode', 'test')
            ->assertSet('is_active', false)
            ->assertCount('groups', 1)
            ->assertSet('groups.0.logical_operator', 'AND')
            ->assertSet('groups.0.position', 1)
            ->assertCount('groups.0.conditions', 0)
            ->assertCount('actions', 1)
            ->assertSet('actions.0.type', 'add_tag')
            ->assertSet('actions.0.position', 1)
            ->assertSet('actions.0.is_active', true);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-B — mount($ruleId, 'edit') loads the existing rule's
    // name + trigger + groups[0].conditions[0] + actions[0] from the DB.
    // ---------------------------------------------------------------------
    public function test_edit_mode_loads_existing_rule_name_trigger_groups_and_actions(): void
    {
        $rule = AutomationRule::query()->create([
            'name' => 'Regla existente',
            'description' => 'Para editar',
            'trigger_event' => LeadCreated::class,
            'is_active' => true,
            'order' => 7,
            'mode' => 'live',
            'created_by' => null,
            'owner_id' => null,
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
            'channel' => null,
            'recipient_strategy' => 'current',
            'payload_json' => ['tag_slug' => 'high-priority'],
            'is_active' => true,
        ]);

        Livewire::test(RuleForm::class, ['ruleId' => $rule->id, 'mode' => 'edit'])
            ->assertSet('mode', 'edit')
            ->assertSet('ruleId', $rule->id)
            ->assertSet('name', 'Regla existente')
            ->assertSet('description', 'Para editar')
            ->assertSet('trigger_event', LeadCreated::class)
            ->assertSet('is_active', true)
            ->assertSet('order', 7)
            ->assertSet('ruleMode', 'live')
            ->assertCount('groups', 1)
            ->assertSet('groups.0.logical_operator', 'AND')
            ->assertSet('groups.0.position', 1)
            ->assertCount('groups.0.conditions', 1)
            ->assertSet('groups.0.conditions.0.field', 'source_id')
            ->assertSet('groups.0.conditions.0.operator', 'is_not_null')
            ->assertSet('groups.0.conditions.0.position', 1)
            ->assertCount('actions', 1)
            ->assertSet('actions.0.type', 'add_tag')
            ->assertSet('actions.0.position', 1)
            ->assertSet('actions.0.is_active', true);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-C — addGroup appends a new empty group; removeGroup(0)
    // removes it (and renumbers positions).
    // ---------------------------------------------------------------------
    public function test_add_group_appends_and_remove_group_removes_with_renumbering(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->assertCount('groups', 1)
            ->call('addGroup')
            ->assertCount('groups', 2)
            ->assertSet('groups.1.logical_operator', 'AND')
            ->assertSet('groups.1.position', 2)
            ->assertCount('groups.1.conditions', 0)
            ->call('removeGroup', 0)
            ->assertCount('groups', 1)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.0.logical_operator', 'AND');
    }

    public function test_remove_group_with_out_of_range_index_is_noop(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('removeGroup', 99)
            ->assertCount('groups', 1)
            ->assertSet('groups.0.position', 1);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-D — addAction appends a default add_tag row;
    // removeAction(0) removes it (and renumbers positions).
    // ---------------------------------------------------------------------
    public function test_add_action_appends_and_remove_action_removes_with_renumbering(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->assertCount('actions', 1)
            ->call('addAction')
            ->assertCount('actions', 2)
            ->assertSet('actions.1.type', 'add_tag')
            ->assertSet('actions.1.position', 2)
            ->assertSet('actions.1.is_active', true)
            ->call('removeAction', 0)
            ->assertCount('actions', 1)
            ->assertSet('actions.0.position', 1)
            ->assertSet('actions.0.type', 'add_tag');
    }

    public function test_remove_action_with_out_of_range_index_is_noop(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('removeAction', 99)
            ->assertCount('actions', 1)
            ->assertSet('actions.0.position', 1);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-E — getTriggersProperty returns the 19 canonical FQCNs
    // from AutomationServiceProvider::TRIGGER_EVENTS.
    // ---------------------------------------------------------------------
    public function test_get_triggers_property_returns_19_canonical_trigger_fqcns(): void
    {
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create']);
        $instance = $component->instance();

        $triggers = $instance->getTriggersProperty();

        $this->assertCount(19, $triggers);
        $this->assertSame(AutomationServiceProvider::TRIGGER_EVENTS, $triggers);
        $this->assertContains(LeadCreated::class, $triggers);
    }

    // ---------------------------------------------------------------------
    // SCN-RULE-FORM-F — getActionTypesProperty returns the 11 canonical
    // action slugs from array_keys(AutomationServiceProvider::ACTION_TYPES).
    // ---------------------------------------------------------------------
    public function test_get_action_types_property_returns_11_canonical_action_slugs(): void
    {
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create']);
        $instance = $component->instance();

        $types = $instance->getActionTypesProperty();

        $this->assertCount(11, $types);
        $this->assertSame(array_keys(AutomationServiceProvider::ACTION_TYPES), $types);
        $this->assertContains('add_tag', $types);
    }
}
