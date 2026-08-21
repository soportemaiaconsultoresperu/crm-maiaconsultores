<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations;

use App\Enums\ConditionOperator;
use Livewire\Component;

/**
 * B12-UI — PR 3 / Stage 3B-1 — ConditionGroupEditor Livewire component.
 *
 * Renders a single AutomationConditionGroup: its AND/OR toggle plus the
 * editable rows of AutomationCondition entries inside the group.
 *
 * Mounted as a child of RuleForm (Stage 3B-2). The parent passes the initial
 * row array as `$group`, this group's index in the rule, and the current
 * logical operator. The parent reads back `$conditions` via wire:model when
 * the user submits the rule form.
 *
 * No #[Layout('layouts.app')] — this is a nested component, not a full page.
 * No #[On('condition-group:reorder')] — v1 relies on the parent's wire:model
 * to capture the final array; drag-reorder (COND-04) lands in PR 3 Stage 3B-2
 * with RuleForm's wire:sort integration.
 *
 * Spec  : openspec/changes/b12-ui/specs/admin-automations-conditions.md
 *         REQ-COND-01..03, REQ-COND-08.
 * Design: openspec/changes/b12-ui/design.md §3.2 (Livewire tree),
 *         §3.3 (ConditionGroupEditor responsibilities).
 *
 * @see \Tests\Feature\Admin\Automations\Livewire\ConditionGroupEditorLivewireTest
 */
class ConditionGroupEditor extends Component
{
    /**
     * Editable rows of AutomationCondition entries.
     *
     * @var list<array{field:string,operator:string,value:mixed,value_type:string,position:int}>
     */
    public array $conditions = [];

    /**
     * Group logical operator. The parent decides whether to hide the toggle
     * for the first group (COND-03). Only AND / OR are valid values; any
     * other input is silently rejected by updateLogicalOperator().
     */
    public string $logicalOperator = 'AND';

    /**
     * Position of this group inside the rule (0-indexed). The view renders
     * `$groupIndex + 1` as the human label.
     */
    public int $groupIndex = 0;

    /**
     * UI-only collapsed flag for future expansion (COND-04 drag handles).
     * Not asserted by tests in v1.
     */
    public bool $isCollapsed = false;

    /**
     * Hydrate the component from the parent's props.
     *
     * @param  list<array{field:string,operator:string,value:mixed,value_type:string,position:int}>  $group
     */
    public function mount(array $group = [], int $groupIndex = 0, string $logicalOperator = 'AND'): void
    {
        $this->conditions = $group;
        $this->groupIndex = $groupIndex;
        $this->logicalOperator = $logicalOperator;
    }

    /**
     * SCN-COND-02 — append a default condition row. The default mirrors
     * the engine's "matches if field is set" preset so an admin can add
     * a placeholder condition quickly.
     */
    public function addCondition(): void
    {
        $this->conditions[] = [
            'field' => 'source_id',
            'operator' => ConditionOperator::IS_NOT_NULL,
            'value' => null,
            'value_type' => 'string',
            'position' => count($this->conditions) + 1,
        ];
    }

    /**
     * SCN-COND-03 — splice the row at $index and renumber positions so
     * they stay contiguous (1..N). Out-of-range indexes are a no-op.
     */
    public function removeCondition(int $index): void
    {
        if (! isset($this->conditions[$index])) {
            return;
        }

        array_splice($this->conditions, $index, 1);
        $this->renumberPositions();
    }

    /**
     * SCN-COND-04 / SCN-COND-05 — flip the group operator.
     *
     * Strict allow-list: only 'AND' and 'OR' are accepted. Anything else
     * (including a typo like 'XOR', a lowercase 'or', or an operator name
     * like 'is_like') is silently rejected so the embedded form never
     * crashes mid-render. The Blade call site wires both buttons to fixed
     * AND/OR strings; garbage can only come from a JS injection, which is
     * preferable to throw a 500 mid-typing.
     */
    public function updateLogicalOperator(string $op): void
    {
        if (! in_array($op, ['AND', 'OR'], true)) {
            return;
        }

        $this->logicalOperator = $op;
    }

    public function render()
    {
        return view('livewire.admin.automations.condition-group-editor');
    }

    /**
     * Renumber the `position` column so rows stay 1..N contiguous after a
     * splice. Called from removeCondition() only — addCondition() appends
     * the correct position directly.
     */
    private function renumberPositions(): void
    {
        foreach ($this->conditions as $i => $condition) {
            $this->conditions[$i]['position'] = $i + 1;
        }
    }
}