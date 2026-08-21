<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations;

use App\Models\AutomationRule;
use App\Providers\AutomationServiceProvider;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * B12-UI — PR 3 / Stage 3B-2 — RuleForm Livewire component.
 *
 * Dual-purpose root component that powers the rule creation and edit pages.
 * Mounted with `ruleId = null, mode = 'create'` for new rules, or with
 * `ruleId = <int>, mode = 'edit'` to load an existing rule. The view renders
 * a full HTML <form> element that submits to the server-side endpoints
 * `admin.automations.store` / `admin.automations.update` via standard HTTP
 * POST / PUT — Livewire is used for inline state (default `triggers` /
 * `actionTypes` lists, group + action add/remove, immediate re-render of
 * dependent UI) and the browser submits the form fields directly to the
 * controller endpoints.
 *
 * No `#[Layout('layouts.app')]` attribute — the host views
 * (`admin/automations/create.blade.php`, `admin/automations/edit.blade.php`)
 * extend `layouts.app` and embed this component via
 * `<livewire:admin.automations.rule-form>` per Livewire 4 docs.
 *
 * Spec   : openspec/changes/b12-ui/specs/admin-automations-crud.md
 *          CRUD-02 (create), CRUD-03 (update).
 * Design : openspec/changes/b12-ui/design.md §3.2 (Livewire tree),
 *          §3.3 (RuleForm responsibilities).
 * Tasks  : openspec/changes/b12-ui/tasks.md §A.Chunk 3 (PR 3 / Stage 3B-2).
 *
 * @see \Tests\Feature\Admin\Automations\Livewire\RuleFormLivewireTest
 */
class RuleForm extends Component
{
    /**
     * Rule id when editing; null when creating.
     */
    public ?int $ruleId = null;

    /**
     * Mount mode: 'create' | 'edit'. Defaults to 'create'.
     */
    public string $mode = 'create';

    /**
     * Scalar rule fields — bound to the underlying inputs via wire:model.
     */
    public string $name = '';

    public ?string $description = null;

    /**
     * Trigger FQCN. Defaults to '' so the select renders the placeholder
     * option until the user picks one. The FormRequest enforces
     * `required + Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)`.
     */
    public string $trigger_event = '';

    public bool $is_active = false;

    /**
     * Rule mode held under a distinct name (`ruleMode`) to avoid colliding
     * with the FormRequest's `mode` field name. The radio buttons send
     * `name="mode"` on submit; the wire:model keeps the Livewire state in
     * sync under `$ruleMode`.
     */
    public string $ruleMode = 'test';

    public int $order = 1;

    public ?int $owner_id = null;

    /**
     * Condition groups (each has a logical_operator + conditions list).
     *
     * @var list<array{logical_operator:string,position:int,conditions:list<array<string,mixed>>}>
     */
    public array $groups = [];

    /**
     * Action rows. Each entry has the full action payload shape (type,
     * channel, recipient_strategy, payload_json, is_active, position).
     *
     * `payload_json` is held as a JSON string so the textarea round-trips
     * the raw text verbatim — the model casts it back to array on save.
     *
     * @var list<array<string,mixed>>
     */
    public array $actions = [];

    /**
     * Mount the component. If mode='edit' AND a ruleId is provided, load the
     * rule + groups + conditions + actions from the database. Otherwise
     * (create mode, or edit mode without a match) initialise with one empty
     * group and one default add_tag action.
     */
    public function mount(?int $ruleId = null, string $mode = 'create'): void
    {
        $this->ruleId = $ruleId;
        $this->mode = $mode;

        if ($mode === 'edit' && $ruleId !== null) {
            $this->hydrateFromRule($ruleId);
        }

        if ($this->groups === []) {
            $this->groups = [
                $this->defaultGroupRow(1),
            ];
        }

        if ($this->actions === []) {
            $this->actions = [
                $this->defaultActionRow(1),
            ];
        }
    }

    /**
     * SCN-RULE-FORM-C — append a new empty group with the next position.
     */
    public function addGroup(): void
    {
        $this->groups[] = $this->defaultGroupRow(count($this->groups) + 1);
    }

    /**
     * SCN-RULE-FORM-C — splice the group at $index and renumber positions
     * so they stay contiguous (1..N). Out-of-range indexes are a no-op.
     */
    public function removeGroup(int $index): void
    {
        if (! isset($this->groups[$index])) {
            return;
        }

        array_splice($this->groups, $index, 1);
        $this->renumberGroups();
    }

    /**
     * SCN-RULE-FORM-D — append a default add_tag action row with the next
     * position.
     */
    public function addAction(): void
    {
        $this->actions[] = $this->defaultActionRow(count($this->actions) + 1);
    }

    /**
     * SCN-RULE-FORM-D — splice the action at $index and renumber positions.
     * Out-of-range indexes are a no-op.
     */
    public function removeAction(int $index): void
    {
        if (! isset($this->actions[$index])) {
            return;
        }

        array_splice($this->actions, $index, 1);
        $this->renumberActions();
    }

    /**
     * B12.5-POL-01 — SCN-COND-04-B12.5 — re-key $this->groups by the new order
     * and update positions to 1..count.
     *
     * Two calling conventions are supported:
     *
     *   1. Test path: `reorderGroups(array $order)` where `$order` is a list
     *      of old integer indexes (e.g. `[2, 0, 1]` = new groups[0] = old
     *      groups[2], new groups[1] = old groups[0], new groups[2] = old
     *      groups[1]).
     *   2. Runtime path: `reorderGroups($item, $position)` invoked by
     *      Livewire 4's `wire:sort` directive, which dispatches as
     *      `$wire.methodName($item, $position)` per the bundled JS at
     *      `vendor/livewire/livewire/dist/livewire.csp.esm.js`. The order
     *      is rebuilt from the current in-memory state plus the new position
     *      of the item identified by `$item`.
     *
     * The method does NOT persist to DB; the persist is via the existing
     * `save()` + controller endpoint, which already preserves the order in
     * the form state (CRUD-06).
     *
     * @param  int|string|array<int|string>  $itemOrOrder
     */
    public function reorderGroups(int|string|array $itemOrOrder, ?int $position = null): void
    {
        $order = $this->resolveReorder($this->groups, $itemOrOrder, $position);

        if ($order === null) {
            return;
        }

        $reordered = [];
        foreach ($order as $i => $oldIndex) {
            if (isset($this->groups[$oldIndex])) {
                $reordered[$i] = $this->groups[$oldIndex];
            }
        }
        $this->groups = $reordered;
        $this->renumberGroups();
    }

    /**
     * B12.5-POL-01 — SCN-ACT-09-B12.5 — re-key $this->actions by the new order
     * and update positions to 1..count. Same calling-convention contract as
     * `reorderGroups()` above.
     *
     * @param  int|string|array<int|string>  $itemOrOrder
     */
    public function reorderActions(int|string|array $itemOrOrder, ?int $position = null): void
    {
        $order = $this->resolveReorder($this->actions, $itemOrOrder, $position);

        if ($order === null) {
            return;
        }

        $reordered = [];
        foreach ($order as $i => $oldIndex) {
            if (isset($this->actions[$oldIndex])) {
                $reordered[$i] = $this->actions[$oldIndex];
            }
        }
        $this->actions = $reordered;
        $this->renumberActions();
    }

    /**
     * Resolve the new order from the two calling conventions documented on
     * `reorderGroups()` / `reorderActions()`. Returns `null` when the input
     * is malformed (out-of-range items, empty list, etc.) so the caller
     * can no-op without corrupting state.
     *
     * @param  array<int|string, mixed>  $current
     * @param  int|string|array<int|string>  $itemOrOrder
     * @return list<int|string>|null
     */
    private function resolveReorder(array $current, int|string|array $itemOrOrder, ?int $position): ?array
    {
        if (is_array($itemOrOrder)) {
            if ($itemOrOrder === []) {
                return null;
            }
            foreach ($itemOrOrder as $oldIndex) {
                if (! array_key_exists($oldIndex, $current)) {
                    return null;
                }
            }
            return array_values($itemOrOrder);
        }

        // Runtime path — wire:sort dispatches ($item, $position). Rebuild
        // the full order from the current array keys + the new position of
        // the item identified by $item.
        $key = (string) $itemOrOrder;
        $keys = array_keys($current);
        $keyExists = false;
        foreach ($keys as $existing) {
            if ((string) $existing === $key) {
                $keyExists = true;
                break;
            }
        }
        if (! $keyExists) {
            return null;
        }

        $remaining = [];
        foreach ($keys as $existing) {
            if ((string) $existing !== $key) {
                $remaining[] = $existing;
            }
        }
        $insertAt = max(0, min($position ?? 0, count($remaining)));
        array_splice($remaining, $insertAt, 0, [$key]);

        return $remaining;
    }

    /**
     * SCN-RULE-FORM-E — the canonical list of 19 trigger FQCNs, read from
     * AutomationServiceProvider::TRIGGER_EVENTS (the catalog source of
     * truth per CRUD-02 + COND-08).
     *
     * Cached by Livewire's #[Computed] attribute so the value is computed
     * once per request and reused across re-renders.
     *
     * @return list<string>
     */
    #[Computed]
    public function getTriggersProperty(): array
    {
        return AutomationServiceProvider::TRIGGER_EVENTS;
    }

    /**
     * SCN-RULE-FORM-F — the canonical list of 11 action slugs, read from
     * the keys of AutomationServiceProvider::ACTION_TYPES (the catalog
     * source of truth).
     *
     * @return list<string>
     */
    #[Computed]
    public function getActionTypesProperty(): array
    {
        return array_keys(AutomationServiceProvider::ACTION_TYPES);
    }

    public function render()
    {
        return view('livewire.admin.automations.rule-form');
    }

    /**
     * Populate the component state from a persisted rule. The query is
     * gated by `$this->mode === 'edit'` checked in mount() — this method
     * itself is private and does not redundantly check the mode.
     */
    private function hydrateFromRule(int $ruleId): void
    {
        $rule = AutomationRule::query()
            ->with(['conditionGroups.conditions', 'actions'])
            ->find($ruleId);

        if ($rule === null) {
            return;
        }

        $this->name = (string) $rule->name;
        $this->description = $rule->description;
        $this->trigger_event = (string) $rule->trigger_event;
        $this->is_active = (bool) $rule->is_active;
        $this->ruleMode = (string) ($rule->mode ?? 'test');
        $this->order = (int) $rule->order;
        $this->owner_id = $rule->owner_id !== null ? (int) $rule->owner_id : null;

        $this->groups = $rule->conditionGroups
            ->sortBy('position')
            ->values()
            ->map(fn ($group) => [
                'logical_operator' => (string) $group->logical_operator,
                'position' => (int) $group->position,
                'conditions' => $group->conditions
                    ->sortBy('position')
                    ->values()
                    ->map(fn ($condition) => [
                        'field' => (string) $condition->field,
                        'operator' => (string) $condition->operator,
                        'value' => $condition->value,
                        'value_type' => (string) ($condition->value_type ?? 'string'),
                        'position' => (int) $condition->position,
                    ])
                    ->all(),
            ])
            ->all();

        $this->actions = $rule->actions
            ->sortBy('position')
            ->values()
            ->map(fn ($action) => [
                'type' => (string) $action->type,
                'channel' => $action->channel,
                'recipient_strategy' => $action->recipient_strategy,
                'payload_json' => $action->payload_json !== null
                    ? json_encode(
                        $action->payload_json,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    )
                    : '{}',
                'is_active' => (bool) $action->is_active,
                'position' => (int) $action->position,
            ])
            ->all();
    }

    /**
     * @return array{logical_operator:string,position:int,conditions:list<array<string,mixed>>}
     */
    private function defaultGroupRow(int $position): array
    {
        return [
            'logical_operator' => 'AND',
            'position' => $position,
            'conditions' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultActionRow(int $position): array
    {
        return [
            'type' => 'add_tag',
            'channel' => null,
            'recipient_strategy' => null,
            'payload_json' => '{}',
            'is_active' => true,
            'position' => $position,
        ];
    }

    private function renumberGroups(): void
    {
        foreach ($this->groups as $i => $group) {
            $this->groups[$i]['position'] = $i + 1;
        }
    }

    private function renumberActions(): void
    {
        foreach ($this->actions as $i => $action) {
            $this->actions[$i]['position'] = $i + 1;
        }
    }
}
