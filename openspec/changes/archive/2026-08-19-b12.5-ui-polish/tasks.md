# B12.5 — Implementation Tasks (sdd-tasks)

> **Phase**: sdd-tasks — 3 polish items, 8 files, single-PR scope.
> **Upstream**:
>
> - `openspec/changes/b12.5-ui-polish/proposal.md` (B12.5-POL-01..03, AC-B12.5-1..8).
> - `openspec/changes/b12.5-ui-polish/design.md` (file map + architectural decisions).
> - `openspec/changes/b12-ui/verify-report.md` §5.2..§5.4 (the 3 deferred items).
> - `openspec/config.yaml` `delivery.strict_tdd: true` (RED → GREEN → TRIANGULATE → REFACTOR).
>
> **Mode**: `delivery_strategy: single-pr` (under the 400-line polish budget).
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **No git, no migrations, no engine code, no controller body modifications.**

---

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines (additions + modifications) | **~280** (production ~70 + tests ~190 + view ~20) |
| Review budget (parent preflight) | **400** lines per PR |
| 400-line budget risk | **Low** |
| Chained PRs recommended | **No** — single-PR scope |
| Delivery strategy | **single-pr** |
| Chain strategy | n/a (single-PR) |
| Decision needed before apply | **No** |

```text
Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: n/a
400-line budget risk: Low
```

---

## A. Implementation chunks

### Chunk 1 — `wire:sort` on RuleForm groups + actions  ·  kind: polish  ·  PR 1

| Item | Value |
|---|---|
| LOC estimate (production) | ~50 |
| LOC estimate (tests) | ~110 |
| LOC total | **~160** |
| Cross-batch dependency | none (independent of engine) |
| Spec REQ-ids covered | COND-04 (drag-to-reorder within groups), ACT-09 (drag-to-reorder actions) |
| AC trace | AC-B12.5-1, AC-B12.5-2, AC-B12.5-3 |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | Add `reorderGroups(...$args)` + `reorderActions(...$args)` methods; accept both `array $order` (test path) and `($item, $position)` (wire:sort runtime path) | modified (+~40) |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | Wrap "Condiciones" + "Acciones" loops with `<div wire:sort="...">` containers + `wire:sort:item` per row | modified (+~10) |
| `tests/Feature/Admin/Automations/Livewire/RuleFormDragSortTest.php` | 3 tests: reorder groups, reorder actions, view renders wire:sort | new (~110) |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/Livewire/RuleFormDragSortTest.php
        (asserts reorderGroups accepts array, asserts view contains wire:sort directive)

GREEN:  app/Livewire/Admin/Automations/RuleForm.php  (add reorderGroups + reorderActions)
        resources/views/livewire/admin/automations/rule-form.blade.php  (add wire:sort containers)

REFACTOR: collapse the two reorder methods into a shared private helper if the
          bodies diverge < 5 lines.
```

TDD mode: RED → GREEN → TRIANGULATE → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter=RuleFormDragSortTest`.

---

### Chunk 2 — Cycle-break rendering Feature test  ·  kind: polish  ·  PR 1

| Item | Value |
|---|---|
| LOC estimate (production) | ~10 (view block) |
| LOC estimate (tests) | ~60 |
| LOC total | **~70** |
| Cross-batch dependency | none |
| Spec REQ-ids covered | HIST-07 (cycle-break rendering pinned) |
| AC trace | AC-B12.5-4 |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `resources/views/admin/automations/execution.blade.php` | Add `<details>` block at the bottom of the steps section that iterates over `$rule->cycleBreaks` | modified (+~10) |
| `tests/Feature/Admin/Automations/HistoryAndAuditCycleBreakTest.php` | 1 test: execution detail renders cycle-break `<details>` block with the count + rule name + `details` text | new (~60) |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/HistoryAndAuditCycleBreakTest.php
        (asserts the rendered HTML contains the cycle-break <details> block
         + the count + a literal substring of the reason)

GREEN:  resources/views/admin/automations/execution.blade.php  (add the <details> block)

REFACTOR: extract the <details> block into a partial if it grows past 30 LOC.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter=HistoryAndAuditCycleBreakTest`.

---

### Chunk 3 — Trigger-catalog defensive guard  ·  kind: polish  ·  PR 1

| Item | Value |
|---|---|
| LOC estimate (production) | ~10 (2 FormRequests × ~5 lines) |
| LOC estimate (tests) | ~40 |
| LOC total | **~50** |
| Cross-batch dependency | none |
| Spec REQ-ids covered | COND-08 (trigger-catalog guard) |
| AC trace | AC-B12.5-5, AC-B12.5-6 |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Http/Requests/Admin/Automations/StoreRuleRequest.php` | Replace `'string'` with `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` on `trigger_event`; add `use App\Providers\AutomationServiceProvider;` | modified (+~5) |
| `app/Http/Requests/Admin/Automations/UpdateRuleRequest.php` | Same as above | modified (+~5) |
| `tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php` | 2 tests: store/update with invalid trigger returns 422 | modified (+~40) |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php
        (asserts POST with trigger_event='App\\NotAReal\\Event' returns 422 + errors.trigger_event)
        (asserts PUT with trigger_event='Invalid' returns 422 + errors.trigger_event)

GREEN:  app/Http/Requests/Admin/Automations/StoreRuleRequest.php   (add Rule::in guard)
        app/Http/Requests/Admin/Automations/UpdateRuleRequest.php  (add Rule::in guard)

REFACTOR: extract the rule array into a shared constant if both FormRequests
          diverge in the future.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter=AdminAutomationRuleFormTest`.

---

## B. Cross-chunk dependency graph

```
Chunk 1 (wire:sort)         ──┐
Chunk 2 (cycle-break)       ──┼──► Single PR (Polish)
Chunk 3 (trigger-catalog)    ──┘
```

All three chunks are independent and can ship in a single PR. The 400-line budget is comfortable for the combined ~280 LOC.

---

## C. TDD invariants enforced across every chunk

1. **RED before GREEN**: every test file listed in a chunk must exist on disk and FAIL (`phpunit --filter=…`) before the production file(s) it covers are written. The PR is not ready for review until all listed tests pass.
2. **Strict TDD cycle**: `RED → GREEN → TRIANGULATE → REFACTOR` (per `config.yaml` `delivery.tdd_cycle`).
3. **Provider boot in `setUp()`**: every Feature test that touches an `automations.*` permission calls `app()->register(\App\Providers\AutomationServiceProvider::class, force: true)` after `RefreshDatabase` runs migrations (PERM-08).
4. **No engine mutation**: tests assert engine contracts are unchanged — `AutomationEngineTest` (10 tests, 21 assertions, `tests/Feature/AutomationEngineTest.php`) must stay green.
5. **No migrations**: zero file additions to `database/migrations/`.
6. **No controller body**: `app/Http/Controllers/Admin/AutomationController.php` is not touched.
7. **No engine code**: `app/Services/Automation/*` is not touched.
8. **No V1 / V2 paths**: only the B12-UI surface + tests.

---

## D. Tasks ↔ design cross-reference

| REQ-id | Spec | Chunk |
|---|---|---|
| REQ-COND-04 | `admin-automations-conditions.md` (drag-to-reorder within groups) | Chunk 1 |
| REQ-ACT-09 | `admin-automations-actions.md` (drag-to-reorder actions) | Chunk 1 |
| REQ-HIST-07 | `admin-automations-history.md` (cycle-break rendering) | Chunk 2 |
| REQ-COND-08 | `admin-automations-conditions.md` (trigger-catalog guard) | Chunk 3 |

**Coverage**: 4 REQ-ids across 3 specs are now fully covered. No orphan requirements.

---

## E. Files explicitly NOT touched by B12.5

- `database/migrations/*` — no schema changes.
- `app/Services/Automation/*` — engine code untouched.
- `app/Models/Automation*` — models untouched.
- `app/Providers/AutomationServiceProvider.php` — TRIGGER_EVENTS catalog is byte-stable.
- `app/Console/Commands/DispatchDueAutomationSteps.php` — engine scheduling untouched.
- `app/Http/Controllers/Admin/AutomationController.php` — controller body untouched.
- V1, V2 paths outside the B12-UI surface.
- `composer.json`, `package.json`, `.env.example`.
- `bootstrap/providers.php`.
- The 3 archive folders (`openspec/changes/archive/2026-08-18-{b12-ui,b14-whatsapp,b17-notifications}/`).

---

## F. AC ↔ chunks cross-reference

| AC | Description | Chunk | Verification artifact |
|---|---|---|---|
| AC-B12.5-1 | `wire:sort` directive on groups + actions containers | Chunk 1 | `RuleFormDragSortTest::test_view_renders_wire_sort_containers` |
| AC-B12.5-2 | `reorderGroups(array $order)` re-keys + renumbers | Chunk 1 | `RuleFormDragSortTest::test_reorder_groups_updates_positions` |
| AC-B12.5-3 | `reorderActions(array $order)` re-keys + renumbers | Chunk 1 | `RuleFormDragSortTest::test_reorder_actions_updates_positions` |
| AC-B12.5-4 | Execution detail renders cycle-break `<details>` block | Chunk 2 | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` |
| AC-B12.5-5 | `StoreRuleRequest` rejects invalid trigger with 422 | Chunk 3 | `AdminAutomationRuleFormTest::test_store_with_invalid_trigger_returns_422` |
| AC-B12.5-6 | `UpdateRuleRequest` rejects invalid trigger with 422 | Chunk 3 | `AdminAutomationRuleFormTest::test_update_with_invalid_trigger_returns_422` |
| AC-B12.5-7 | Engine regression guard stays at 10/10 / 21 assertions | All chunks | `php artisan test --filter=AutomationEngineTest` |
| AC-B12.5-8 | Full suite stable (648-655 tests / ~2270-2300 assertions) | All chunks | `php artisan test` |

---

## G. Blockers / flags for parent

**None**. All 3 polish items map to existing REQ-ids in the B12-UI specs. The engine contracts are unchanged. The full suite stays under the 400-line budget for a single PR.

---

**End of tasks.**
