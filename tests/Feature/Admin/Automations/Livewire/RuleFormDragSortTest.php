<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\RuleForm;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12.5 — UI Polish — wire:sort on RuleForm for groups + actions.
 *
 * Covers the visual drag-to-reorder polish for the rule editor (COND-04 +
 * ACT-09). The persistence half is already in place via
 * `RuleWriterService::reorder(kind=...)` (CRUD-06 exercised in
 * `AdminAutomationRuleFormTest::test_reorder_persists_new_rule_sequence`).
 * This class only verifies the in-memory reorder contract:
 *   - `reorderGroups(array $order)` re-keys `$this->groups` by the new order
 *     and updates `position` to `1..count`.
 *   - `reorderActions(array $order)` does the same for `$this->actions`.
 *   - The view renders the `wire:sort` directive on the groups + actions
 *     containers.
 *
 * Spec   : openspec/changes/b12.5-ui-polish/specs/admin-automations-conditions.md
 *          REQ-COND-04 (B12.5 delta).
 * Design : openspec/changes/b12.5-ui-polish/design.md §2.1.
 * Tasks  : openspec/changes/b12.5-ui-polish/tasks.md Chunk 1.
 *
 * TDD discipline: this file exists BEFORE the component methods are authored.
 * Running it before the GREEN step proves the missing-method failure (RED).
 *
 * @see \App\Livewire\Admin\Automations\RuleForm
 */
class RuleFormDragSortTest extends TestCase
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
    // SCN-COND-04-B12.5 — reorderGroups(array $order) re-keys + renumbers
    // ---------------------------------------------------------------------
    public function test_reorder_groups_updates_positions(): void
    {
        // Start with 3 default groups (added via addGroup x2 after the
        // initial mount). Positions are 1, 2, 3.
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('addGroup')
            ->call('addGroup')
            ->assertCount('groups', 3)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.1.position', 2)
            ->assertSet('groups.2.position', 3);

        // Capture the original groups (logical_operator + conditions; the
        // position will be renumbered after reorder so we do not compare it).
        $originalLogicalOperator = $component->get('groups.0.logical_operator');
        $originalConditions = $component->get('groups.0.conditions');

        // Reorder: new order = [2, 0, 1] (move the group at index 2 to the
        // front, the group at index 0 to position 2, the group at index 1
        // to position 3).
        $component
            ->call('reorderGroups', [2, 0, 1])
            ->assertCount('groups', 3)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.1.position', 2)
            ->assertSet('groups.2.position', 3);

        // The re-keying preserved the original group identities (logical_operator
        // + conditions). positions are renumbered to 1..count.
        $this->assertSame(
            $originalLogicalOperator,
            $component->get('groups.0.logical_operator'),
            'reorderGroups([2, 0, 1]): groups[0].logical_operator must be preserved.'
        );
        $this->assertSame(
            $originalConditions,
            $component->get('groups.0.conditions'),
            'reorderGroups([2, 0, 1]): groups[0].conditions must be preserved.'
        );
    }

    // ---------------------------------------------------------------------
    // SCN-ACT-09-B12.5 — reorderActions(array $order) re-keys + renumbers
    // ---------------------------------------------------------------------
    public function test_reorder_actions_updates_positions(): void
    {
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('addAction')
            ->call('addAction')
            ->assertCount('actions', 3)
            ->assertSet('actions.0.position', 1)
            ->assertSet('actions.1.position', 2)
            ->assertSet('actions.2.position', 3);

        $originalType = $component->get('actions.0.type');
        $originalPayloadJson = $component->get('actions.0.payload_json');

        $component
            ->call('reorderActions', [2, 0, 1])
            ->assertCount('actions', 3)
            ->assertSet('actions.0.position', 1)
            ->assertSet('actions.1.position', 2)
            ->assertSet('actions.2.position', 3);

        $this->assertSame(
            $originalType,
            $component->get('actions.0.type'),
            'reorderActions([2, 0, 1]): actions[0].type must be preserved.'
        );
        $this->assertSame(
            $originalPayloadJson,
            $component->get('actions.0.payload_json'),
            'reorderActions([2, 0, 1]): actions[0].payload_json must be preserved.'
        );
    }

    // ---------------------------------------------------------------------
    // TRIANGULATE — reorderGroups with empty array is a no-op.
    // ---------------------------------------------------------------------
    public function test_reorder_groups_with_empty_array_is_noop(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->assertCount('groups', 1)
            ->call('reorderGroups', [])
            ->assertCount('groups', 1)
            ->assertSet('groups.0.position', 1);
    }

    // ---------------------------------------------------------------------
    // TRIANGULATE — reorderGroups with out-of-range index is a no-op.
    // ---------------------------------------------------------------------
    public function test_reorder_groups_with_out_of_range_index_is_noop(): void
    {
        Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('addGroup')
            ->assertCount('groups', 2)
            ->call('reorderGroups', [5, 99])
            ->assertCount('groups', 2)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.1.position', 2);
    }

    // ---------------------------------------------------------------------
    // TRIANGULATE — reorderGroups with the runtime path ($item, $position)
    // rebuilds the order from the current state + the new position.
    // ---------------------------------------------------------------------
    public function test_reorder_groups_with_runtime_path_rebuilds_order(): void
    {
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create'])
            ->call('addGroup')
            ->call('addGroup')
            ->assertCount('groups', 3)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.1.position', 2)
            ->assertSet('groups.2.position', 3);

        // Runtime path: move the item at index 0 to position 2.
        // wire:sort dispatches ($item, $position) where $item is the key.
        $component
            ->call('reorderGroups', '0', 2)
            ->assertCount('groups', 3)
            ->assertSet('groups.0.position', 1)
            ->assertSet('groups.1.position', 2)
            ->assertSet('groups.2.position', 3);
    }

    // ---------------------------------------------------------------------
    // SCN-COND-04-B12.5-VIEW — view renders the wire:sort directive on the
    // groups + actions containers.
    // ---------------------------------------------------------------------
    public function test_view_renders_wire_sort_containers(): void
    {
        $component = Livewire::test(RuleForm::class, ['ruleId' => null, 'mode' => 'create']);

        $html = (string) $component->html();

        // The render output MUST contain the wire:sort directive on the
        // groups container AND the actions container.
        $this->assertStringContainsString(
            'wire:sort="reorderGroups"',
            $html,
            'rule-form.blade.php must render wire:sort="reorderGroups" on the groups container.'
        );
        $this->assertStringContainsString(
            'wire:sort="reorderActions"',
            $html,
            'rule-form.blade.php must render wire:sort="reorderActions" on the actions container.'
        );

        // The wire:sort:item attribute on each row is the keys-by-index
        // glue that the runtime harness uses. Best-effort assertion: the
        // directive name appears at least twice (one per container).
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($html, 'wire:sort'),
            'rule-form.blade.php must render wire:sort at least twice (groups + actions).'
        );
    }
}
