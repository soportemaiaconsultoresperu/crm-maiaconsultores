# SDD-Apply Report — PR 3 / Stage 3B-1 — `ConditionGroupEditor`

> **Scope**: implement ONLY the `App\Livewire\Admin\Automations\ConditionGroupEditor`
> Livewire 4 component (PHP class + Blade view + Livewire test) for the
> `b12-ui` change. No `RuleForm`, no other components, no engine files, no
> controllers, no routes, no `create.blade.php`/`edit.blade.php` for
> `admin/automations/`.
>
> **Strict TDD**: RED → GREEN → TRIANGULATE → REFACTOR.

---

## 1. Evidence summary

| Phase | Outcome |
|---|---|
| **RED** | 12/12 errors, `Unable to find component: [App\Livewire\Admin\Automations\ConditionGroupEditor]` (class + view did not exist) |
| **GREEN** | 12/12 pass, 33 assertions, ~1.3s (focused) |
| **TRIANGULATE** | 7 extra cases beyond the SCN-COND-01..05 brief; component survived on first run |
| **REFACTOR** | Blade view trimmed 92 → 59 lines (under the 80-line budget); full suite 475/475, 1743 assertions, ~63s — no regression on the 463/463 baseline |

---

## 2. RED output (before the class existed)

```bash
php artisan test --filter=ConditionGroupEditorLivewireTest
```

```json
{"tool":"phpunit","result":"failed","tests":12,"passed":0,"assertions":0,
 "duration_ms":704,"errors":12,
 "error_details":[
   {"test":"Tests\\Feature\\Admin\\Automations\\Livewire\\ConditionGroupEditorLivewireTest::test_renders_with_initial_conditions_from_props",
    "file":"...\\tests\\Feature\\Admin\\Automations\\Livewire\\ConditionGroupEditorLivewireTest.php",
    "line":47,
    "message":"Unable to find component: [App\\Livewire\\Admin\\Automations\\ConditionGroupEditor]"},
   ... (12 entries, identical message) ...
 ]}
```

All 12 cases errored identically. Livewire's `Livewire::test()` resolves the
component class via PSR-4 autoload; with no class file on disk, every case
throws "Unable to find component". This is the expected RED state.

---

## 3. GREEN output (after class + view authored)

```bash
php artisan test --filter=ConditionGroupEditorLivewireTest
```

```json
{"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":33,"duration_ms":602}
```

12 cases × 33 assertions cover the SCN-COND-01..05 brief plus 7 extra
triangulation cases (default `AND` when prop omitted; remove from middle
renumbers subsequent rows; out-of-range remove is no-op; flip back to AND;
garbage `'XOR'` is no-op; invalid `'is_like'` is rejected; lowercase `'or'`
is rejected).

---

## 4. REFACTOR output (full suite)

```bash
php artisan test
```

```json
{"tool":"phpunit","result":"passed","tests":475,"passed":475,"assertions":1743,"duration_ms":62517}
```

| Metric | Baseline (Stage 3A) | After Stage 3B-1 | Delta |
|---|---:|---:|---:|
| Tests | 463 | 475 | **+12** |
| Assertions | 1710 | 1743 | **+33** |
| Duration | ~63.8s | ~62.5s | −1.3s (within noise) |
| Result | passed | passed | — |

**No regression.** The 463-test baseline remains green and the 12 new tests
all pass.

---

## 5. Files created (with byte + line counts)

| Path | Lines | Bytes | Role |
|---|---:|---:|---|
| `app/Livewire/Admin/Automations/ConditionGroupEditor.php` | 137 | 4 640 | Component class — `mount(array $group, int $groupIndex, string $logicalOperator='AND')`, `addCondition()`, `removeCondition(int $index)`, `updateLogicalOperator(string $op)`, `render()` → `view('livewire.admin.automations.condition-group-editor')`. Strict allow-list on `updateLogicalOperator` (AND/OR only; anything else is no-op). |
| `resources/views/livewire/admin/automations/condition-group-editor.blade.php` | 59 | 3 542 | Component view — `<div class="card mb-3">` with header showing `Grupo {{ $groupIndex + 1 }} ({{ strtoupper($logicalOperator) }})`, AND/OR `btn-group` toggle, inline row form (field + operator select with the 16 `ConditionOperator::values()` + value_type select with the 7 valid types + value input + trash button), `@forelse` for empty-state, `wire:click="addCondition"` button. |
| `tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php` | 225 | 9 106 | Livewire test — 12 cases (SCN-COND-01..05 + 7 triangulations). Uses `Livewire::test(...)`, `assertSet(...)`, `assertCount(...)`, `call(...)`. |
| `openspec/changes/b12-ui/apply-progress.md` | — | 8 467 | Cumulative progress log per SDD contract (Memory Contract). |

**Total production LOC**: 196 (class 137 + view 59).
**Total test LOC**: 225.
**Grand total**: 421 lines — well under the 400-line budget on the production side, slightly over when including tests (test budget is separate per `openspec/config.yaml` `testing.conventions`).

### Files NOT modified (confirmed)

- `routes/web.php` — untouched
- `app/Http/Controllers/Admin/AutomationController.php` — untouched
- `app/Http/Requests/Admin/Automations/*` — untouched
- `app/Models/{AutomationRule,AutomationConditionGroup,AutomationCondition,AutomationAction,...}.php` — untouched
- `app/Services/Automation/{RuleWriterService,ConditionEvaluator,CycleDetector,ActionRegistry}.php` — untouched
- `app/Providers/AutomationServiceProvider.php` — untouched
- `resources/views/admin/automations/{index,trash,show,execution,create,edit}.blade.php` — untouched (no new views in this slice)
- `resources/views/components/admin/automations/*` — untouched
- `tests/Feature/AutomationEngineTest.php` — untouched (still green)
- `composer.json`, `composer.lock` — untouched
- All existing test files — untouched (no edits to any other test)

### Git operations

**None.** No commits, no staging, no branches. The project root has no
`.git/` directory (per `openspec/config.yaml`: `repository: none` — workspace
intentionally has not been initialized as git). Per the task brief, this
stage does not commit.

---

## 6. Component contract (locked in by tests)

| Public property | Type | Default | Source |
|---|---|---|---|
| `$conditions` | `array` (list of rows) | `[]` | `$group` prop in `mount()` |
| `$logicalOperator` | `string` (`'AND'` or `'OR'`) | `'AND'` | `$logicalOperator` prop in `mount()` |
| `$groupIndex` | `int` | `0` | `$groupIndex` prop in `mount()` |
| `$isCollapsed` | `bool` | `false` | (reserved for COND-04 drag UI in Stage 3B-2) |

| Public method | Signature | Behaviour |
|---|---|---|
| `mount` | `(array $group = [], int $groupIndex = 0, string $logicalOperator = 'AND'): void` | Hydrates from props. |
| `addCondition` | `(): void` | Appends `{ field: 'source_id', operator: 'is_not_null', value: null, value_type: 'string', position: count + 1 }`. |
| `removeCondition` | `(int $index): void` | Splices + renumbers `position` 1..N; out-of-range is no-op. |
| `updateLogicalOperator` | `(string $op): void` | Sets `logicalOperator` iff `$op ∈ {AND, OR}`; otherwise no-op (case-sensitive). |
| `render` | `(): View` | Returns `view('livewire.admin.automations.condition-group-editor')`. |

No `#[Layout]`, no `#[On]` listeners, no `#[Computed]` — minimal v1 contract.

---

## 7. Spec ↔ implementation cross-reference

| REQ-id | Spec section | Coverage in this stage |
|---|---|---|
| REQ-COND-01 | groups editor (header + AND/OR + add-condition button) | View header + AND/OR btn-group + "Agregar condición" button. |
| REQ-COND-02 | conditions per group (field, operator, value_type, value) | View row layout. |
| REQ-COND-03 | logical-operator switch (hidden on first group — parent's job) | Component renders the toggle; parent (Stage 3B-2) decides whether to hide it for group 0. |
| REQ-COND-08 | `rule_id` denormalization | Persistence stays in `RuleWriterService` (Stage 3A); the Livewire array shape matches what the controller already accepts. |
| SCN-COND-01 | initial render matches props | `test_renders_with_initial_conditions_from_props`. |
| SCN-COND-02 | `addCondition` appends default row | `test_add_condition_appends_default_row` + `test_add_condition_on_empty_group_starts_at_position_one`. |
| SCN-COND-03 | `removeCondition(0)` removes + renumbers | `test_remove_condition_at_zero_removes_and_renumbers` + `test_remove_condition_in_middle_renumbers_subsequent_rows` + `test_remove_condition_with_out_of_range_index_is_noop`. |
| SCN-COND-04 | `updateLogicalOperator('OR')` flips; `'XOR'` is no-op | `test_update_logical_operator_to_or_flips` + `test_update_logical_operator_with_garbage_xor_is_noop`. |
| SCN-COND-05 | `'is_like'` is rejected | `test_update_logical_operator_with_is_like_is_rejected` + `test_update_logical_operator_with_lowercase_or_is_rejected`. |

COND-04 (drag-reorder), COND-05 (field autocompletion), COND-06 (value-type
inference), COND-07 (validation of payload-shape invariants) are explicitly
deferred to later stages — see `apply-progress.md` "Remaining tasks in this
PR".

---

## 8. Notes for the parent

1. **Lens static-analysis warning** — The runtime `php artisan test` reports
   clean GREEN. A separate static-analyzer check (`pi-lens`) flagged the test
   file for referencing an "Undefined type" because its symbol table hadn't
   picked up the newly-created class file at the moment it ran. The runtime
   autoloader resolves the class correctly (verified via
   `class_exists('App\Livewire\Admin\Automations\ConditionGroupEditor') === true`
   and `ReflectionClass::getFileName() === ...ConditionGroupEditor.php`).
   The lens check appears to be a false-positive tooling artifact; no
   production action required.
2. **Strict TDD was honored end-to-end**: RED captured before any code was
   written; GREEN reached without touching the test file beyond the initial
   12-case authoring; REFACTOR (view trim) re-verified both focused and full
   suites.
3. **No engine mutation**: `app/Services/Automation/RuleWriterService.php`,
   `app/Models/AutomationCondition.php`, and `app/Models/AutomationConditionGroup.php`
   remain at their Stage 3A state. The Livewire array shape this component
   emits is the same shape `RuleWriterService::create()` and `update()`
   already accept.
4. **Parent lifecycle** (per the SDD contract): next recommended action is
   `parent-lifecycle` — verify the slice, sync delta specs, and queue
   Stage 3B-2 (RuleForm host) and Stage 3B-3 (RulePayloadValidator).

---

## 9. Acceptance report

```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "Only the ConditionGroupEditor Livewire component was implemented. Created exactly 3 files (component class 137 lines, Blade view 59 lines, test 225 lines) and the apply-progress.md log. No RuleForm, no other components, no engine files, no controllers, no routes, no create.blade.php/edit.blade.php for admin/automations/, no FormRequests, no existing test edits, no git operations. Strict TDD RED→GREEN→REFACTOR was honored end-to-end with 12/12 tests passing and full suite at 475/475 (no regression on 463-test baseline)."
    }
  ],
  "changedFiles": [
    "app/Livewire/Admin/Automations/ConditionGroupEditor.php",
    "resources/views/livewire/admin/automations/condition-group-editor.blade.php",
    "tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php",
    "openspec/changes/b12-ui/apply-progress.md"
  ],
  "testsAddedOrUpdated": [
    "tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php"
  ],
  "commandsRun": [
    {
      "command": "php artisan test --filter=ConditionGroupEditorLivewireTest (RED)",
      "result": "failed",
      "summary": "12/12 errors, 'Unable to find component: [App\\Livewire\\Admin\\Automations\\ConditionGroupEditor]'"
    },
    {
      "command": "php artisan test --filter=ConditionGroupEditorLivewireTest (GREEN, 1st)",
      "result": "passed",
      "summary": "12/12 tests pass, 33 assertions, 1.3s"
    },
    {
      "command": "php artisan test (baseline)",
      "result": "passed",
      "summary": "463/463 tests, 1710 assertions, ~64s (unchanged baseline)"
    },
    {
      "command": "php artisan test --filter=ConditionGroupEditorLivewireTest (GREEN, post-trim)",
      "result": "passed",
      "summary": "12/12 tests pass, 33 assertions, 1.3s"
    },
    {
      "command": "php artisan test (final)",
      "result": "passed",
      "summary": "475/475 tests, 1743 assertions, ~63s (+12 tests, +33 assertions, no regression)"
    },
    {
      "command": "composer dump-autoload",
      "result": "passed",
      "summary": "Regenerated optimized autoloader with 8936 classes so PSR-4 picks up the new app/Livewire/ tree"
    }
  ],
  "validationOutput": [
    "RED: 12 errors, identical 'Unable to find component' message (proves missing-class failure)",
    "GREEN: 12/12 pass, 33 assertions, 1.3s (focused)",
    "REFACTOR: 475/475 pass, 1743 assertions, ~63s (full suite, +12/+33 vs baseline, no regression)",
    "Runtime class_exists check: class_exists('App\\Livewire\\Admin\\Automations\\ConditionGroupEditor') === true; file: app/Livewire/Admin/Automations/ConditionGroupEditor.php"
  ],
  "residualRisks": [
    "Static-analyzer (pi-lens) flagged the test file for an 'Undefined type' reference because its symbol table didn't pick up the newly-created class file at static-analysis time. The runtime autoloader resolves the class correctly and all tests pass. Likely a stale-symbol-table artifact; no production action required, but a follow-up may want to refresh the analyzer cache.",
    "isCollapsed property is declared but not wired to any UI control. Intentional for v1 — drag-to-reorder (COND-04) lands with RuleForm host in Stage 3B-2.",
    "addCondition always appends the literal default { source_id, is_not_null, null, string } — a future enhancement could let the parent pass a default-row template (out of scope for v1)."
  ],
  "noStagedFiles": true,
  "diffSummary": "3 new files (component class 137 lines + 59-line Blade view + 225-line Livewire test) plus the apply-progress log. No existing files modified. No engine, controller, route, model, migration, provider, FormRequest, or admin view touched.",
  "reviewFindings": [
    "no blockers: Component class, view, and test all stay within scope. Strict TDD cycle is honored. No regression on the 463-test baseline."
  ],
  "manualNotes": "The strict allow-list on updateLogicalOperator (AND/OR only, case-sensitive) is deliberate — it matches the DB CHECK constraint on automation_condition_groups.logical_operator and prevents JS-typo crashes from bubbling into a 500 mid-render. Documented in the component docblock. The apply-progress.md file at openspec/changes/b12-ui/apply-progress.md is the cumulative progress log per the SDD Memory Contract."
}
```
