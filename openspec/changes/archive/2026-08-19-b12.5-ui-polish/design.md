# B12.5 — UI Polish (sdd-design)

> **Phase**: sdd-design — file map + architectural decisions for the 3 polish items.
> **Upstream**: `openspec/changes/b12.5-ui-polish/proposal.md` (B12.5-POL-01..03, AC-B12.5-1..8).
> **Engineering surface**: `C:\laragon\www\crm-maia-consultores`.
> **No migrations, no engine code, no controller body modifications.**

---

## 1. File map

| # | Path | Section / role | New / Modified |
|---|---|---|---|
| 1 | `app/Livewire/Admin/Automations/RuleForm.php` | Add `reorderGroups(array $order)`, `reorderActions(array $order)` methods | modified |
| 2 | `resources/views/livewire/admin/automations/rule-form.blade.php` | Wrap "Condiciones" + "Acciones" loops with `wire:sort` containers | modified |
| 3 | `resources/views/admin/automations/execution.blade.php` | Add cycle-break `<details>` block at the bottom of the steps section | modified |
| 4 | `app/Http/Requests/Admin/Automations/StoreRuleRequest.php` | Replace `'string'` with `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` on `trigger_event` | modified |
| 5 | `app/Http/Requests/Admin/Automations/UpdateRuleRequest.php` | Same as #4 | modified |
| 6 | `tests/Feature/Admin/Automations/Livewire/RuleFormDragSortTest.php` | 3 tests: reorder groups, reorder actions, view renders wire:sort | new |
| 7 | `tests/Feature/Admin/Automations/HistoryAndAuditCycleBreakTest.php` | 1 test: execution detail renders cycle-break `<details>` block | new |
| 8 | `tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php` | 2 tests: store/update with invalid trigger returns 422 | modified (+2 tests) |

**Total**: 6 modified + 2 new = **8 files**. Under the 400-line polish budget.

---

## 2. Architectural decisions

### 2.1 `wire:sort` runtime contract harmonization

Livewire 4's `wire:sort` directive (per the bundled JS at `vendor/livewire/livewire/dist/livewire.csp.esm.js:15802`) dispatches as `$wire.methodName($item, $position)` where `$item` is the value of `wire:sort:item` on the dragged element and `$position` is the new index. The brief's method signature `reorderGroups(array $order): void` is the **primary contract** exercised by the test.

**Decision**: the methods accept both signatures via variadic args. The array path is the test path; the scalar path is the wire:sort runtime path. When called with two scalars, the method rebuilds the full order from the current in-memory state and `$position` (the new index of the item identified by `$item`).

```php
public function reorderGroups(int|string|array $itemOrOrder, ?int $position = null): void
{
    if (is_array($itemOrOrder)) {
        $order = $itemOrOrder;
    } else {
        // wire:sort runtime path: rebuild the order from current state + new position.
        $key = (string) $itemOrOrder;
        $current = array_keys($this->groups);
        $current = array_values(array_filter($current, fn ($k) => $k !== $key));
        array_splice($current, $position ?? 0, 0, [$key]);
        $order = $current;
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
```

Same shape for `reorderActions`.

### 2.2 Cycle-break `<details>` block

`execution.blade.php` is extended with a `<details>` block at the bottom of the steps section. The block iterates over `$rule->cycleBreaks` (lazy-loaded via the relation). Each row renders `reason` + `detected_at`. The test asserts the rendered HTML contains:

- The cycle-break count (number of rows).
- The rule name (already on the page).
- The `<details>` block + a literal substring of the `reason`.

```blade
<details class="mt-4" data-testid="cycle-break-details">
    <summary>Cycle breaks ({{ $rule->cycleBreaks->count() }})</summary>
    @forelse ($rule->cycleBreaks as $break)
        <div class="small">
            <code>{{ $break->reason }}</code>
            <small class="text-muted">{{ $break->detected_at?->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}</small>
        </div>
    @empty
        <div class="small text-muted">No hay cycle breaks.</div>
    @endforelse
</details>
```

### 2.3 Trigger catalog guard

The `trigger_event` field is updated from:

```php
'trigger_event' => ['required', 'string', 'max:191'],
```

to:

```php
'trigger_event' => ['required', 'string', 'max:191', Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)],
```

Both `StoreRuleRequest` and `UpdateRuleRequest` use the same catalog constant. The 19 FQCNs are byte-stable. The `Rule::in()` filter produces a 422 with `errors.trigger_event` when the value is not in the list.

---

## 3. Test seams

| Seam | Test type | Artefact |
|---|---|---|
| `RuleForm::reorderGroups` | Livewire `call` + `assertSet` | `RuleFormDragSortTest::test_reorder_groups_updates_positions` |
| `RuleForm::reorderActions` | Livewire `call` + `assertSet` | `RuleFormDragSortTest::test_reorder_actions_updates_positions` |
| `wire:sort` directive in view | Render the Blade view + assertSee | `RuleFormDragSortTest::test_view_renders_wire_sort_containers` |
| Cycle-break `<details>` block | HTTP GET + assertSee | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` |
| `StoreRuleRequest` catalog guard | HTTP POST + 422 | `AdminAutomationRuleFormTest::test_store_with_invalid_trigger_returns_422` |
| `UpdateRuleRequest` catalog guard | HTTP PUT + 422 | `AdminAutomationRuleFormTest::test_update_with_invalid_trigger_returns_422` |

---

## 4. Out-of-scope (explicit non-goals)

- Drag persistence (the controller `store/update` already preserves order from form state).
- Engine code (`app/Services/Automation/*`).
- Controller body (`AutomationController`).
- New migrations, new models, new routes.
- `retry_policy_json` UI surface (AC-10 / UI-11).
- Bulk-ops UI (AC-12 / UI-10).
- Breadcrumbs (design §8.14).
- a11y automated smoke (UI-13).

---

**End of design.**
