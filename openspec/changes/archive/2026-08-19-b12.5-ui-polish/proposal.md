# B12.5 — UI Polish (sdd-proposal)

> **Phase**: sdd-proposal — surface 3 deferred polish items the B12-UI verify phase identified as non-blocker follow-ups.
> **Change**: `b12.5-ui-polish` — incremental smoothing of the B12-UI surface.
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **Upstream**: `openspec/changes/b12-ui/verify-report.md` §5.2 (COND-08), §5.3 (COND-04 + ACT-09), §5.4 (HIST-07). All three items are non-blocker partial coverages from the B12-UI archive.

---

## 1. Purpose

B12-UI merged and closed at 540/540 / 1955 assertions / ~70-100s green. The B12-UI verify phase identified 5 non-blocker partial REQ-id coverages (verify-report.md §5.1..§5.5). This change ships **3** of those 5 items:

1. **COND-04 + ACT-09** — `wire:sort` directive on the rule editor for groups + actions (visual drag-to-reorder polish; persistence half is already in place via `RuleWriterService::reorder`).
2. **HIST-07** — Feature test that pins the cycle-break rendering contract in `execution.blade.php`.
3. **COND-08** — Defensive `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` guard on the FormRequests.

The remaining 2 items (REQ-UI-13 a11y smoke + AC-6 cosmetic trailing period) are deferred to a future b12.6+ ticket per the brief's "if skipped, defer" rule.

---

## 2. Scope

### 2.1 In-scope

| Item | File(s) | Spec REQ-id |
|---|---|---|
| B12.5-POL-01 — `wire:sort` on groups + actions | `app/Livewire/Admin/Automations/RuleForm.php`, `resources/views/livewire/admin/automations/rule-form.blade.php` | COND-04, ACT-09 |
| B12.5-POL-02 — Cycle-break rendering test | `tests/Feature/Admin/Automations/HistoryAndAuditCycleBreakTest.php` | HIST-07 |
| B12.5-POL-03 — Trigger catalog defensive guard | `app/Http/Requests/Admin/Automations/{StoreRuleRequest,UpdateRuleRequest}.php`, `tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php` | COND-08 |

### 2.2 Out-of-scope

- Drag-and-drop reorder persistence (the `wire:sort` updates the in-memory `position`; the controller endpoint persists on save).
- B11/B13/B14 cross-cutting changes.
- New migrations, new models, new routes.
- `retry_policy_json` UI surface (AC-10 / UI-11).
- Bulk-ops UI (AC-12 / UI-10).
- Breadcrumbs (design §8.14).
- a11y automated smoke via Playwright/Dusk (UI-13 — out of scope per the brief's "if skipped, defer" rule).
- Engine code modifications (no `app/Services/Automation/*` changes).
- Controller body modifications (only the FormRequests + RuleForm + view are in scope).
- V1, V2 paths.

---

## 3. Acceptance criteria

| AC | Description | Verification |
|---|---|---|
| **AC-B12.5-1** | `wire:sort` directive attached to the groups container and the actions container in `rule-form.blade.php` | `RuleFormDragSortTest::test_view_renders_wire_sort_containers` |
| **AC-B12.5-2** | `reorderGroups(array $order)` re-keys `$this->groups` by the new order and updates `position` to 1..count | `RuleFormDragSortTest::test_reorder_groups_updates_positions` |
| **AC-B12.5-3** | `reorderActions(array $order)` re-keys `$this->actions` by the new order and updates `position` to 1..count | `RuleFormDragSortTest::test_reorder_actions_updates_positions` |
| **AC-B12.5-4** | Execution detail view renders the cycle-break `<details>` block with the count, rule name, and `details` text | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` |
| **AC-B12.5-5** | `StoreRuleRequest` rejects `trigger_event` not in `AutomationServiceProvider::TRIGGER_EVENTS` with 422 + `errors.trigger_event` | `AdminAutomationRuleFormTest::test_store_with_invalid_trigger_returns_422` |
| **AC-B12.5-6** | `UpdateRuleRequest` rejects `trigger_event` not in `AutomationServiceProvider::TRIGGER_EVENTS` with 422 + `errors.trigger_event` | `AdminAutomationRuleFormTest::test_update_with_invalid_trigger_returns_422` |
| **AC-B12.5-7** | Engine regression guard stays at 10/10 / 21 assertions | `php artisan test --filter=AutomationEngineTest` |
| **AC-B12.5-8** | Full suite remains stable (target **648-655 tests / ~2270-2300 assertions**) | `php artisan test` |

---

## 4. Review workload forecast

| Field | Value |
|---|---|
| Estimated changed lines | ≈ **280** (production ~70 + tests ~190 + view ~20) |
| Review budget | 400 lines per PR |
| 400-line budget risk | **Low** |
| Chained PRs recommended | **No** — single-PR runs under the 400-line budget |
| Decision needed before apply | **No** |
| Chain strategy | n/a (single-PR) |

```text
Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: n/a
400-line budget risk: Low
```

---

## 5. Risk assessment

| Risk | Severity | Mitigation |
|---|---|---|
| `wire:sort` runtime contract mismatch (Livewire 4 passes `($item, $position)`, brief spec uses `array $order`) | low | The test calls the method directly with an array; the view contains the directive. The runtime dispatch is harmonized in §6.1. |
| Cycle-break rendering not yet in `execution.blade.php` (verify-report §5.4 said "plumbed" but the actual view is missing the block) | medium | The view is touched to add the `<details>` block; the test asserts the rendered text. |
| Trigger-catalog guard regression on existing tests | low | The 19 TRIGGER_EVENTS are unchanged; the `Rule::in()` filter accepts the same set the dropdown renders. |
| Test count drift from 642 → 648-655 | low | 6 new tests (3 wire:sort + 1 cycle-break + 2 trigger-catalog) + 0 removed. |

---

## 6. Design notes

### 6.1 `wire:sort` runtime contract

Livewire 4's `wire:sort` directive compiles to `$wire.methodName($item, $position)` (the JavaScript bundle at `vendor/livewire/livewire/dist/livewire.csp.esm.js` confirms: `params: [key, position]`). The brief's method signature `reorderGroups(array $order): void` is the **primary contract** — the test calls the method directly with an array.

The view uses `wire:sort="reorderGroups"`. Livewire 4 dispatches with `($item, $position)`. The method accepts both signatures via variadic args; when called with two scalars, the in-memory state is used to reconstruct the full order from the current DOM order. For the test, the array path is exercised.

### 6.2 Cycle-break rendering block

`execution.blade.php` (the view) is extended with a `<details>` block at the bottom of the steps section that iterates over `$rule->cycleBreaks` (lazy-loaded via the relation). The block renders the count, the rule name, and the `reason` text per row. The test asserts the rendered HTML contains the substrings.

### 6.3 Trigger catalog guard

The `trigger_event` field on `StoreRuleRequest` and `UpdateRuleRequest` is updated from `'string'` to `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)`. The 19-entry list is the same source the dropdown renders, so the guard is a defensive boundary against the column going stale (refactor removes a trigger between save and re-edit).

---

## 7. Rollback

This change touches **6 files** (2 modified + 1 new test + 1 view extension + 2 FormRequests). No migrations, no engine code, no controller body. The rollback is a single `git revert` once git is initialized post-archive.

---

**End of proposal.**
