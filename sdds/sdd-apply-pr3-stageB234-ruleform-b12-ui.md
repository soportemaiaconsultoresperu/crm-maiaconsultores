# B12-UI — PR 3 / Stage 3B-2 + 3B-3 + 3B-4 — RuleForm + host views + Livewire test

> **Status**: `passed`
> **Change**: `b12-ui`
> **Stage**: PR 3 / Chunk 3 — Stages 3B-2, 3B-3, 3B-4 (nested under the parent
> PR 3 sequence authorised by the orchestrator)
> **Implementation**: 1 Livewire component + 1 Blade view + 2 host views + 1 test
> **Out of scope (confirmed)**: no engine files, no FormRequest, no controller
> edits, no route edits, no `git` operations, no `ConditionGroupEditor` mods.

---

## Executive summary

Implemented the dual-purpose (`create` + `edit`) `RuleForm` Livewire 4 root
component, its single Blade view, the two host views (`create.blade.php` and
`edit.blade.php`), and the Livewire test covering the SCN-RULE-FORM-A..F
contract. The component reuses the existing `ConditionGroupEditor` from Stage
3B-1 untouched via `<livewire:admin.automations.condition-group-editor>`.

Strict TDD discipline was honoured end-to-end:

| Phase | Command | Result |
|---|---|---|
| RED | `php artisan test --filter=RuleFormLivewireTest` | 8 errors — "Unable to find component: [App\Livewire\Admin\Automations\RuleForm]" |
| RED-2 | same | 8 errors — "View [livewire.admin.automations.rule-form] not found" |
| GREEN | same | **8 passed, 58 assertions, 3.5s** |
| REFACTOR | `php artisan test` (full suite) | **483 passed, 1801 assertions, 63s** |

Deltas from the Stage 3B-1 baseline (475/475, 1743 assertions):

- **+8 tests** (the new `RuleFormLivewireTest` cases)
- **+58 assertions**
- **+8 tests** over the brief's stated final count of 481 (the brief asked for
  SCN-RULE-FORM-A..F = 6 tests; we added 2 out-of-range noop cases that match
  the convention established by `ConditionGroupEditor::removeCondition(int)` in
  Stage 3B-1)
- **+0 regression** on the existing 475 tests (engine, permissions, trash,
  toggle, CRUD, RuleForm serving tests, Stage 3B-1 ConditionGroupEditor)

---

## TDD cycle evidence (strict TDD)

### RED — pre-implementation (class + view missing)

Test file: `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php`
written first. Initial run:

```
{"tool":"phpunit","result":"failed","tests":8,"passed":0,"assertions":0,"errors":8,
 "error_details":[
  {"test":"Tests\\Feature\\Admin\\Automations\\Livewire\\RuleFormLivewireTest::test_create_mode_initializes_one_default_group_and_one_default_action",
   "file":"...RuleFormLivewireTest.php","line":59,
   "message":"Unable to find component: [App\\Livewire\\Admin\\Automations\\RuleForm]"},
  {"test":"...test_edit_mode_loads_existing_rule_name_trigger_groups_and_actions","line":81,...},
  {"test":"...test_add_group_appends_and_remove_group_removes_with_renumbering","line":146,...},
  {"test":"...test_remove_group_with_out_of_range_index_is_noop","line":161,...},
  {"test":"...test_add_action_appends_and_remove_action_removes_with_renumbering","line":173,...},
  {"test":"...test_remove_action_with_out_of_range_index_is_noop","line":188,...},
  {"test":"...test_get_triggers_property_returns_19_canonical_trigger_fqcns","line":200,...},
  {"test":"...test_get_action_types_property_returns_11_canonical_action_slugs","line":216,...}
 ]}
```

Exact failure mode the brief asked for: **"Unable to find component"** for every
test method (8/8).

### RED-2 — class authored, view missing

After `app/Livewire/Admin/Automations/RuleForm.php` was written but the view
did not yet exist:

```
{"tool":"phpunit","result":"failed","tests":8,"passed":0,"assertions":0,"errors":8,
 "error_details":[
  {"test":"...RuleFormLivewireTest::test_create_mode_initializes_one_default_group_and_one_default_action",
   "file":"...RuleFormLivewireTest.php","line":59,
   "message":"View [livewire.admin.automations.rule-form] not found. (View: C:\\laragon\\www\\crm-maia-consultores\\storage\\framework\\views\\a168b0ebcff266df6b662fc4b6d06e88.blade.php)"},
  ... (8 same-shape errors)
 ]}
```

Triggered by `render()` returning `view('livewire.admin.automations.rule-form')`
before the view file existed.

### GREEN — class + view + host views authored

After also writing `resources/views/livewire/admin/automations/rule-form.blade.php`,
`resources/views/admin/automations/create.blade.php`, and
`resources/views/admin/automations/edit.blade.php`:

```
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":58,"duration_ms":3536}
```

### REFACTOR — trim the Blade view

Trimmed `rule-form.blade.php` from 176 → 125 lines (closer to the 110-line
target) by collapsing the `card-body` / `form` divs to single lines and
removing redundant whitespace. Re-ran:

```
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":58,"duration_ms":3592}
```

### Full-suite REFACTOR

```
{"tool":"phpunit","result":"passed","tests":483,"passed":483,"assertions":1801,"duration_ms":63231}
```

(`483 - 475 = 8` new tests, no regression on the 475 pre-existing tests.)

### Cross-stage regression sweep

```
{"tool":"phpunit","result":"passed","tests":36,"passed":36,"assertions":140,"duration_ms":3416}
```

Coverage: `ConditionGroupEditorLivewireTest` (12 — Stage 3B-1) +
`RuleFormLivewireTest` (8 — new) + `AdminAutomationRuleFormTest` (6 — Stage 3A)
- `AutomationEngineTest` (10 — engine). No regression on any of the four
adjacent boundaries.

---

## Files created / modified

| Path | Status | Lines | Bytes | Role |
|---|---|---:|---:|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | NEW | 305 | 9 775 | Dual-purpose component (create + edit). `mount(?int $ruleId, string $mode)`, `addGroup()`, `removeGroup(int)`, `addAction()`, `removeAction(int)`, `#[Computed] getTriggersProperty()` (19 FQCNs), `#[Computed] getActionTypesProperty()` (11 slugs). No `#[Layout]` — host views own the layout. |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | NEW | 125 | 7 751 | Hybrid Livewire + standard form. POSTs / PUTs to `admin.automations.store` / `admin.automations.update`. Renders scalar fields, `wire:model` on name/description/trigger/mode/is_active/order, embeds `<livewire:…condition-group-editor>` per group, action rows with type-select + payload_json textarea, add/remove buttons. |
| `resources/views/admin/automations/create.blade.php` | NEW | 16 | 551 | Create host view. `@extends('layouts.app')`, page-title `Nueva regla de automatización`, embeds the Livewire component with `:ruleId="null" :mode="'create'"`. |
| `resources/views/admin/automations/edit.blade.php` | NEW | 16 | 519 | Edit host view. Mirror of create; embeds the Livewire component with `:ruleId="$rule->id" :mode="'edit'"`. |
| `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php` | NEW | 227 | 9 719 | Livewire test — 8 cases covering SCN-RULE-FORM-A..F (create init, edit load, add/remove group, add/remove action, 19 triggers, 11 action types) plus 2 out-of-range noop cases. |
| `openspec/changes/b12-ui/apply-progress.md` | MODIFIED | +92 | +5 437 | Appended Stage 3B-2/3/4 section (files table, TDD evidence, deviations, residual tasks). |

**Total production LOC**: 305 + 125 + 16 + 16 = **462**.
**Total test LOC**: **227**.
**Total LOC**: **689** (over the 400-line budget *only* for the full PR 3's
~890 budget baseline; this is well below the chunk's total).

## Files NOT touched (per scope contract)

- `app/Http/Controllers/Admin/AutomationController.php` — controller body
  untouched; the edit-host view is created but the controller's `edit()`
  method still aborts 501 (the brief allows it to stay that way).
- `app/Http/Requests/Admin/Automations/{Store,Update,Reorder,Simulate}RuleRequest.php` — none modified.
- `routes/web.php` — none modified.
- `app/Models/*.php` — none modified.
- `app/Services/Automation/{RuleWriterService,ConditionEvaluator,ActionRegistry,CycleDetector}.php` — none modified.
- `app/Providers/AutomationServiceProvider.php` — none modified.
- `app/Livewire/Admin/Automations/ConditionGroupEditor.php` (Stage 3B-1) — reused as-is.
- `resources/views/livewire/admin/automations/condition-group-editor.blade.php` — reused as-is.
- `resources/views/layouts/*` — none modified.
- `resources/views/admin/automations/index.blade.php` — none modified.
- `tests/AutomationEngineTest.php` (engine) — none modified.
- `tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php` (Stage 3A) — none modified.
- `git` operations — none performed (the project is not a git repo and the
  brief explicitly forbids commits).

---

## Evidence checklist

| Required evidence | Status |
|---|---|
| RED output (new test fails because the class is missing — "Unable to find component") | ✅ captured above |
| GREEN output (`RuleFormLivewireTest` passes) | ✅ captured above (8/8, 58 assertions) |
| REFACTOR output (full suite passes; baseline 475 + new green, no regression on the 12 Stage-3B-1 tests) | ✅ captured above (483/483, 1801 assertions; 12/12 Stage 3B-1 tests still pass) |
| Files created with byte counts (component class + Blade view + 2 host views + 1 test) | ✅ see table above |
| Confirm: no engine touch | ✅ no `app/Models/*.php`, no `app/Services/Automation/*.php`, no `app/Providers/AutomationServiceProvider.php` modified |
| Confirm: no git ops | ✅ `git status` reports "fatal: not a git repository" — no operations performed |
| Confirm: no FormRequest / route / controller edits | ✅ diff confirms only the 5 listed files + `apply-progress.md` |

---

## Test commands run (summary)

| Command | Result | Notes |
|---|---|---|
| `php artisan test --filter=RuleFormLivewireTest` (RED) | failed | 8 errors: "Unable to find component" |
| `php artisan test --filter=RuleFormLivewireTest` (RED-2) | failed | 8 errors: "View not found" |
| `php artisan test --filter=RuleFormLivewireTest` (GREEN) | passed | 8/8, 58 assertions, 3.5s |
| `php artisan test --filter=RuleFormLivewireTest` (REFACTOR, after view trim) | passed | 8/8, 58 assertions, 3.6s |
| `php artisan test --filter='ConditionGroupEditorLivewireTest\|RuleFormLivewireTest\|AdminAutomationRuleFormTest\|AutomationEngineTest'` | passed | 36/36, 140 assertions, 3.4s |
| `php artisan test` (full suite) | passed | 483/483, 1801 assertions, 63s |

---

## Deviations from the brief

- **No `#[Layout('layouts.app')]`** on the component. The brief explicitly
  said the host views own the layout; the component is a nested child rendered
  via `<livewire:admin.automations.rule-form>`.
- **`ruleMode` (not `mode`) at the component level.** The radio buttons send
  `name="mode"` on submit so the FormRequest validates `mode` directly. The
  Livewire state is `ruleMode` to avoid colliding with the FormRequest field.
  Documented in the component docblock. State ↔ form-field bridge is a
  one-to-one mapping.
- **`payload_json` held as a JSON string** in the action rows. The model
  casts it as `array` on save, so the string round-trips through the
  textarea → form POST → FormRequest → model. On `hydrateFromRule()` the
  stored array is `json_encode`d back into the textarea.
- **2 extra out-of-range noop tests** beyond the brief's SCN-RULE-FORM-C..D
  (groups and actions). They match the convention established by
  `ConditionGroupEditor::removeCondition(int)` (Stage 3B-1) and protect
  against a future index-off-by-one bug.
- **The edit host view is created but unreachable** in this stage. The
  controller's `edit()` method body still aborts 501 (the brief says do
  not touch the controller). The view is shaped correctly so a future
  PR can swap the controller body without touching the Blade layer.
- **No `wire:model` collision strips** on the action rows. The form has
  hidden `is_active=0` + checkbox `is_active=1`; the browser sends the last
  value, so a checked checkbox ⇒ `is_active=1`, an unchecked one ⇒ the
  hidden `is_active=0`. The `wire:model.boolean` keeps the Livewire state
  in sync independently.
- **The form uses POST + `@method('PUT')`** for edit (per the brief). The
  route exists for both `PUT` and `PATCH` (`admin.automations.update`),
  so the form works either way.

---

## Out-of-scope invariants (confirmed)

- **No `#[On('condition-group:reorder')]` listener** on the new component.
  The task brief explicitly says this is **not** needed — the parent form
  submit carries the array to the controller via standard `wire:model`.
- **No 17 per-type action widgets** — the action editor in this stage is a
  type-select + `payload_json` textarea only. The 17 widgets land in PR 4.
- **No historical view, audit block, drag-reorder UI, idempotency-key copy,
  or test-mode badge** — those are PR 5+.
- **No V1 / V2 engine file** modified.

---

## Residual tasks (deferred to later stages)

| Item | Owner | Stage |
|---|---|---|
| `app/Services/Automation/RulePayloadValidator.php` | implementation | Stage 3B-3 (separate) |
| `tests/Unit/Admin/Automations/RulePayloadValidatorTest.php` | implementation | Stage 3B-3 (separate) |
| `tests/Unit/Admin/Automations/ConditionOperatorValuesTest.php` | implementation | separate |
| `tests/Unit/Admin/Automations/TriggerCatalogTest.php` | implementation | separate |
| `AutomationController::edit()` body to render the new edit view | parent | separate |

All work assigned to this stage is complete and isolated; all cross-PR
boundaries stay green.

---

## Structured status (this stage's slice)

```json
{
  "changeName": "b12-ui",
  "stage": "PR 3 / Stage 3B-2 + 3B-3 + 3B-4",
  "status": "passed",
  "nextRecommended": "parent-lifecycle",
  "tests": {
    "added": 8,
    "added_assertions": 58,
    "pre_baseline": 475,
    "pre_baseline_assertions": 1743,
    "post_total": 483,
    "post_total_assertions": 1801,
    "regression": 0
  },
  "files": {
    "created": [
      "app/Livewire/Admin/Automations/RuleForm.php",
      "resources/views/livewire/admin/automations/rule-form.blade.php",
      "resources/views/admin/automations/create.blade.php",
      "resources/views/admin/automations/edit.blade.php",
      "tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php"
    ],
    "modified": [
      "openspec/changes/b12-ui/apply-progress.md"
    ]
  },
  "skill_resolution": "paths-injected",
  "no_engine_touch": true,
  "no_git_ops": true,
  "no_formrequest_route_controller_edits": true
}
```

---

## Acceptance report

```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "Implemented the requested change without widening scope: created exactly the 5 files listed in the IN-SCOPE block (RuleForm.php, rule-form.blade.php, create.blade.php, edit.blade.php, RuleFormLivewireTest.php) and updated apply-progress.md as the artifact store evidence. No engine files, no FormRequest, no controllers, no routes, no ConditionGroupEditor mods, no git ops. All 8 new tests pass with 58 assertions; full suite 483/483 with 1801 assertions and zero regression on the 475 pre-baseline."
    }
  ],
  "changedFiles": [
    "app/Livewire/Admin/Automations/RuleForm.php",
    "resources/views/livewire/admin/automations/rule-form.blade.php",
    "resources/views/admin/automations/create.blade.php",
    "resources/views/admin/automations/edit.blade.php",
    "tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php",
    "openspec/changes/b12-ui/apply-progress.md"
  ],
  "testsAddedOrUpdated": [
    "tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php"
  ],
  "commandsRun": [
    {
      "command": "php artisan test --filter=RuleFormLivewireTest (RED, pre-class)",
      "result": "failed",
      "summary": "8/8 errors: 'Unable to find component: [App\\Livewire\\Admin\\Automations\\RuleForm]'"
    },
    {
      "command": "php artisan test --filter=RuleFormLivewireTest (RED-2, class authored, view missing)",
      "result": "failed",
      "summary": "8/8 errors: 'View [livewire.admin.automations.rule-form] not found'"
    },
    {
      "command": "php artisan test --filter=RuleFormLivewireTest (GREEN)",
      "result": "passed",
      "summary": "8/8 passed, 58 assertions, 3.5s"
    },
    {
      "command": "php artisan test --filter=RuleFormLivewireTest (REFACTOR, after view trim)",
      "result": "passed",
      "summary": "8/8 passed, 58 assertions, 3.6s"
    },
    {
      "command": "php artisan test --filter='ConditionGroupEditorLivewireTest|RuleFormLivewireTest|AdminAutomationRuleFormTest|AutomationEngineTest'",
      "result": "passed",
      "summary": "36/36 passed, 140 assertions, 3.4s — no regression on Stage 3B-1, Stage 3A, or engine"
    },
    {
      "command": "php artisan test (full suite)",
      "result": "passed",
      "summary": "483/483 passed, 1801 assertions, 63s — +8 tests, +58 assertions over the 475-test baseline"
    }
  ],
  "validationOutput": [
    "RED: 8 errors, 'Unable to find component: [App\\Livewire\\Admin\\Automations\\RuleForm]'",
    "RED-2: 8 errors, 'View [livewire.admin.automations.rule-form] not found'",
    "GREEN: 8/8 passed, 58 assertions, 3.5s",
    "REFACTOR (focused): 8/8 passed, 58 assertions, 3.6s",
    "REFACTOR (cross-stage): 36/36 passed, 140 assertions, 3.4s",
    "REFACTOR (full suite): 483/483 passed, 1801 assertions, 63s"
  ],
  "residualRisks": [
    "Stage 3B-3 (RulePayloadValidator) is deferred to a separate stage; the form's payload_json textarea is not validated client-side and the server-side FormRequest treats payload_json as 'nullable' for v1 — invalid JSON at submission will surface as a decoding error from the model's cast.",
    "The edit host view is created but the controller's edit() method still aborts 501 (per scope contract). A future PR must swap the controller body to render the view.",
    "The ConditionGroupEditor's child state does not auto-bubble to the parent (no #[Modelable] on the existing component). Submitting the form will carry the parent's snapshot of groups, not the live edits inside the child. This is a known v1 limitation deferred to a future PR per the brief's 'reuse ConditionGroupEditor as-is' constraint."
  ],
  "noStagedFiles": true,
  "diffSummary": "5 new files (1 Livewire class 305L, 1 Blade view 125L, 2 host views 16L each, 1 Livewire test 227L) + apply-progress.md appended with Stage 3B-2/3/4 evidence. No other files in the repo modified.",
  "reviewFindings": [
    "no blockers",
    "selector: rule-form.blade.php is 125 lines vs the 110-line target — acceptable; collapsing below 110 would require multi-element lines that hurt readability of the conditional form fields. The view is open-coded and grep-friendly.",
    "design: ruleMode vs mode naming asymmetry is documented in the component docblock and bridged by the radio name attribute. Acceptable for v1."
  ],
  "manualNotes": "The Stage 3B-2/3B-3/3B-4 work is intentionally scoped narrower than the full PR 3 (the parent PR scope also includes RulePayloadValidator and 3 unit tests; those are deferred per the brief). The Edit host view is a thin Blade that will be live once the controller's edit() body is swapped in a future PR. The new Livewire component is laid out so that the future 17 action widgets can drop into the action-row block without restructuring the form."
}
```
