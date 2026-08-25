<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Enums\ConditionOperator;
use App\Livewire\Admin\Automations\ConditionGroupEditor;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 3 / Stage 3B-1 — ConditionGroupEditor Livewire component.
 *
 * Covers the live-state contract of the child component that RuleForm hosts
 * (Stage 3B-2). The component is exercised in isolation here; the host's
 * integration tests live with RuleForm.
 *
 * Spec  : openspec/changes/b12-ui/specs/admin-automations-conditions.md
 *         SCN-COND-01..SCN-COND-07, REQ-COND-01..03, REQ-COND-08.
 * Design: openspec/changes/b12-ui/design.md §3.2 (Livewire tree),
 *         §3.3 (ConditionGroupEditor responsibilities).
 * Tasks : openspec/changes/b12-ui/tasks.md §A.Chunk 3 (PR 3).
 *
 * TDD discipline: this file exists BEFORE the component / view are authored.
 * Running it before the GREEN step proves the missing-class failure (RED).
 */
class ConditionGroupEditorLivewireTest extends TestCase
{
    /**
     * Two-condition fixture: status_id eq 2 / owner_id eq 5.
     *
     * @return list<array{field:string,operator:string,value:mixed,value_type:string,position:int}>
     */
    private function sampleConditions(): array
    {
        return [
            ['field' => 'status_id', 'operator' => 'eq', 'value' => '2', 'value_type' => 'string', 'position' => 1],
            ['field' => 'owner_id', 'operator' => 'eq', 'value' => '5', 'value_type' => 'int', 'position' => 2],
        ];
    }

    // ------------------------------------------------------------------
    // SCN-COND-01 — initial render hydrates `$conditions`, `$groupIndex`,
    // and `$logicalOperator` from the constructor props.
    // ------------------------------------------------------------------
    public function test_renders_with_initial_conditions_from_props(): void
    {
        $conditions = $this->sampleConditions();

        Livewire::test(ConditionGroupEditor::class, [
            'group' => $conditions,
            'groupIndex' => 1,
            'logicalOperator' => 'OR',
        ])
            ->assertSet('conditions', $conditions)
            ->assertSet('groupIndex', 1)
            ->assertSet('logicalOperator', 'OR')
            ->assertCount('conditions', 2)
            ->assertSet('conditions.0.field', 'status_id')
            ->assertSet('conditions.0.operator', 'eq')
            ->assertSet('conditions.1.field', 'owner_id')
            ->assertSet('conditions.1.position', 2);
    }

    public function test_default_logical_operator_is_and_when_prop_omitted(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => [],
            'groupIndex' => 0,
        ])
            ->assertSet('logicalOperator', 'AND');
    }

    // ------------------------------------------------------------------
    // SCN-COND-02 — addCondition appends one default row.
    // ------------------------------------------------------------------
    public function test_add_condition_appends_default_row(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('addCondition')
            ->assertCount('conditions', 3)
            ->assertSet('conditions.2.field', 'source_id')
            ->assertSet('conditions.2.operator', ConditionOperator::IS_NOT_NULL)
            ->assertSet('conditions.2.value', null)
            ->assertSet('conditions.2.value_type', 'int')
            ->assertSet('conditions.2.position', 3);
    }

    public function test_add_condition_on_empty_group_starts_at_position_one(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => [],
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('addCondition')
            ->assertCount('conditions', 1)
            ->assertSet('conditions.0.position', 1);
    }

    // ------------------------------------------------------------------
    // SCN-COND-03 — removeCondition(0) removes the first row AND
    // renumbers `position` so positions stay contiguous (1..N).
    // ------------------------------------------------------------------
    public function test_remove_condition_at_zero_removes_and_renumbers(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('removeCondition', 0)
            ->assertCount('conditions', 1)
            ->assertSet('conditions.0.field', 'owner_id')
            ->assertSet('conditions.0.position', 1);
    }

    public function test_remove_condition_in_middle_renumbers_subsequent_rows(): void
    {
        $three = [
            ['field' => 'a', 'operator' => 'eq', 'value' => '1', 'value_type' => 'string', 'position' => 1],
            ['field' => 'b', 'operator' => 'eq', 'value' => '2', 'value_type' => 'string', 'position' => 2],
            ['field' => 'c', 'operator' => 'eq', 'value' => '3', 'value_type' => 'string', 'position' => 3],
        ];

        Livewire::test(ConditionGroupEditor::class, [
            'group' => $three,
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('removeCondition', 1)
            ->assertCount('conditions', 2)
            ->assertSet('conditions.0.field', 'a')
            ->assertSet('conditions.0.position', 1)
            ->assertSet('conditions.1.field', 'c')
            ->assertSet('conditions.1.position', 2);
    }

    public function test_remove_condition_with_out_of_range_index_is_noop(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('removeCondition', 99)
            ->assertCount('conditions', 2)
            ->assertSet('conditions.0.position', 1)
            ->assertSet('conditions.1.position', 2);
    }

    // ------------------------------------------------------------------
    // SCN-COND-04 — updateLogicalOperator('OR') flips the operator; a
    // garbage value ('XOR') is a NO-OP (does not throw, does not mutate).
    //
    // Design choice: garbage is a no-op, not an exception, because the
    // component is embedded in the rule form and a typo should never crash
    // the page render. The Blade call site wires both buttons to a fixed
    // AND/OR value, so a real garbage value can only come from a JS
    // injection; rejecting it silently is the safer contract.
    // ------------------------------------------------------------------
    public function test_update_logical_operator_to_or_flips(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('updateLogicalOperator', 'OR')
            ->assertSet('logicalOperator', 'OR');
    }

    public function test_update_logical_operator_to_and_flips_back(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'OR',
        ])
            ->call('updateLogicalOperator', 'AND')
            ->assertSet('logicalOperator', 'AND');
    }

    public function test_update_logical_operator_with_garbage_xor_is_noop(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            ->call('updateLogicalOperator', 'XOR')
            ->assertSet('logicalOperator', 'AND');
    }

    // ------------------------------------------------------------------
    // SCN-COND-05 — invalid operator ('is_like') is rejected — the
    // logicalOperator stays at its previous value ('OR') after the call.
    // ------------------------------------------------------------------
    public function test_update_logical_operator_with_is_like_is_rejected(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'OR',
        ])
            ->call('updateLogicalOperator', 'is_like')
            ->assertSet('logicalOperator', 'OR');
    }

    public function test_update_logical_operator_with_lowercase_or_is_rejected(): void
    {
        Livewire::test(ConditionGroupEditor::class, [
            'group' => $this->sampleConditions(),
            'groupIndex' => 0,
            'logicalOperator' => 'AND',
        ])
            // Case-sensitive allow-list — 'or' is not 'OR'.
            ->call('updateLogicalOperator', 'or')
            ->assertSet('logicalOperator', 'AND');
    }
}