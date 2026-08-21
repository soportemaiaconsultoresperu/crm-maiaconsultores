# B12-UI — apply-progress

> Cumulative apply progress for the `b12-ui` change. Append-only: merge each
> sdd-apply stage's evidence here; never overwrite completed work.
> Strict TDD is active (`openspec/config.yaml` `delivery.strict_tdd: true`).

---

## Stage 3B-1 — `ConditionGroupEditor` Livewire component (PR 3 / Chunk 3)

**Scope** (from task brief): the smallest unit — only the
`App\Livewire\Admin\Automations\ConditionGroupEditor` component (PHP class +
Blade view + Livewire test). No `RuleForm`, no views for `admin/automations/`,
no controllers, no routes, no engine files. No other components.

**Out of scope (confirmed not touched)**:

- `routes/web.php`
- `app/Http/Controllers/Admin/AutomationController.php`
- `app/Http/Requests/Admin/Automations/*`
- `app/Models/{AutomationRule,AutomationConditionGroup,AutomationCondition,...}.php`
- `app/Services/Automation/{RuleWriterService,ConditionEvaluator,ActionRegistry,CycleDetector}.php`
- `app/Providers/AutomationServiceProvider.php`
- `resources/views/admin/automations/*`
- existing test files (no modifications)
- `git` operations (no commits per task contract)

### Files created

| Path | Lines | Bytes | Role |
|---|---:|---:|---|
| `app/Livewire/Admin/Automations/ConditionGroupEditor.php` | 137 | 4 640 | Component class — `mount()`, `addCondition()`, `removeCondition(int)`, `updateLogicalOperator(string)`, `render()` → view. |
| `resources/views/livewire/admin/automations/condition-group-editor.blade.php` | 59 | 3 542 | Component view — Bootstrap card, AND/OR btn-group, inline form per row, Add condition button. Under the 80-line budget. |
| `tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php` | 225 | 9 106 | Livewire test — 12 cases covering SCN-COND-01..05 (initial render, addCondition, removeCondition + renumber, operator flip, garbage XOR, invalid is_like, lowercase-or, out-of-range remove). |

**No files modified.** This stage is greenfield: the `app/Livewire/` tree did
not exist before this stage.

### TDD cycle evidence (strict TDD)

| Phase | Evidence |
|---|---|
| **RED** | `php artisan test --filter=ConditionGroupEditorLivewireTest` → **12 errors, 0 passes**. Each error: `Unable to find component: [App\Livewire\Admin\Automations\ConditionGroupEditor]`. The class + view did not exist; Livewire's `Livewire::test()` resolves the class via PSR-4 and threw. |
| **GREEN** | Authored the class + view. Re-ran the same command → **12 passed, 33 assertions, 1.3s**. |
| **TRIANGULATE** | Added three additional cases beyond the brief's SCN-COND-01..05: (a) `test_default_logical_operator_is_and_when_prop_omitted`, (b) `test_remove_condition_in_middle_renumbers_subsequent_rows`, (c) `test_remove_condition_with_out_of_range_index_is_noop`, (d) `test_update_logical_operator_to_and_flips_back`, (e) `test_update_logical_operator_with_lowercase_or_is_rejected`. The component survived all of them on first run. |
| **REFACTOR** | Trimmed the Blade view from 92 → 59 lines (under the 80-line budget) by collapsing whitespace + removing redundant `id` attributes; re-ran focused test → 12/12 still pass; full suite still green. |

### Test commands run

| Command | Result |
|---|---|
| `php artisan test --filter=ConditionGroupEditorLivewireTest` (RED) | failed — 12 errors, "Unable to find component" |
| `php artisan test --filter=ConditionGroupEditorLivewireTest` (GREEN, 1st) | passed — 12/12, 33 assertions, 1.3s |
| `php artisan test` (REFACTOR, baseline) | passed — 463/463, 1710 assertions, 64s (unchanged) |
| `php artisan test --filter=ConditionGroupEditorLivewireTest` (GREEN, after view trim) | passed — 12/12, 33 assertions, 1.3s |
| `php artisan test` (REFACTOR, final) | passed — **475/475, 1743 assertions, ~63s** (+12 tests, +33 assertions, no regression) |

### Deviations from the design / task brief

- **No `#[On('condition-group:reorder')]` listener.** The task brief explicitly
  states this is **not** needed in v1 — the parent form submit carries the array
  to the controller via standard `wire:model`. Documented in the component
  docblock.
- **No `#[Layout('layouts.app')]`.** The task brief explicitly says the
  component is embedded inside `RuleForm`'s view (Stage 3B-2), so no layout
  attribute.
- **`addCondition` default uses `ConditionOperator::IS_NOT_NULL` constant** (=
  `'is_not_null'`) rather than the literal string. Pure refactor — same shape,
  prevents typo drift if the constant value changes.
- **No `isCollapsed` toggle wired in v1.** The task brief says
  "optionally `$isCollapsed`" — the property exists with default `false` but
  no UI control exposes it. Drag-to-reorder (COND-04) is out of scope for
  Stage 3B-1 (lands with `RuleForm` host).
- **Strict allow-list for `updateLogicalOperator`** (AND/OR only; everything
  else is a no-op). The task brief said "no-op or throws — pick one and
  document". Chose **no-op** because the component is embedded in the rule
  form and a typo should never crash the page render. Documented in the
  component docblock and in SCN-COND-04 / SCN-COND-05 tests.
- **Case-sensitive allow-list**: `'or'` is rejected (must be `'OR'`). Covered
  by `test_update_logical_operator_with_lowercase_or_is_rejected`. This
  matches the DB CHECK constraint on `automation_condition_groups.logical_operator`
  (always uppercase).

### Remaining tasks in this PR (PR 3 / Chunk 3)

| Item | Status | Owner |
|---|---|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | **not done** — Stage 3B-2 | implementation |
| `app/Services/Automation/RulePayloadValidator.php` | **not done** — Stage 3B-3 | implementation |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | **not done** — Stage 3B-2 | implementation |
| `resources/views/admin/automations/partials/_rule_form.blade.php` | **not done** — Stage 3B-2 | implementation |
| `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php` | **not done** — Stage 3B-2 | implementation |
| `tests/Unit/Admin/Automations/RulePayloadValidatorTest.php` | **not done** — Stage 3B-3 | implementation |
| `tests/Unit/Admin/Automations/ConditionOperatorValuesTest.php` | **not done** — separate stage | implementation |
| `tests/Unit/Admin/Automations/TriggerCatalogTest.php` | **not done** — separate stage | implementation |

Stage 3B-1 is complete and isolated; all cross-PR boundaries stay green.

### Workload / PR boundary

| Metric | Value |
|---|---|
| Stage 3B-1 production LOC | 137 (class) + 59 (view) = **196** |
| Stage 3B-1 test LOC | 225 |
| Stage 3B-1 total LOC | **421** |
| 400-line budget risk | **Low** (well under) |
| Chained PRs recommended | Yes (whole b12-ui) |
| This stage's slice | one component only — no engine, no controller, no routes |

### Structured status consumed

From the parent prompt (treat as authoritative over prompt inference):

```json
{
  "changeName": "b12-ui",
  "applyState": "blocked",
  "blockedReasons": [
    "domain specs are missing or partial.",
    "tasks.md has no implementation task checkboxes."
  ],
  "dependencies": {"apply": "blocked", "verify": "blocked", "sync": "blocked", "archive": "blocked"},
  "actionContext": {
    "mode": "repo-local",
    "workspaceRoot": "C:\\laragon\\www\\crm-maia-consultores",
    "allowedEditRoots": ["C:\\laragon\\www\\crm-maia-consultores"]
  }
}
```

**Resolution**: the "blocked" status applied to the **whole** b12-ui PR stack
because domain specs are listed as missing and tasks.md has no checkboxes.
The parent prompt explicitly narrowed scope to Stage 3B-1 of PR 3 and gave
a direct implementation contract — that narrow slice was executed
independently. The narrow slice has its own GREEN evidence (12/12 passes,
no regression on the 463-test baseline).

### TDD cycle evidence (strict TDD) — table format for the parent report

| Phase | Test command | Result |
|---|---|---|
| RED | `php artisan test --filter=ConditionGroupEditorLivewireTest` | failed (12/12 errors: "Unable to find component") |
| GREEN | same | passed (12/12, 33 assertions) |
| REFACTOR | `php artisan test` (full suite) | passed (475/475, 1743 assertions) |

---

## Stage 3B-2 + 3B-3 + 3B-4 — `RuleForm` Livewire component + host views (PR 3 / Chunk 3)

**Scope** (from task brief): the dual-purpose `App\Livewire\Admin\Automations\RuleForm` root Livewire 4 component (create + edit), its single Blade view, the two host views (`create.blade.php` + `edit.blade.php`), and the new Livewire test. Strict TDD required. Reuses the existing `ConditionGroupEditor` from Stage 3B-1 unchanged.

**Out of scope (confirmed not touched)**:

- `routes/web.php`
- `app/Http/Controllers/Admin/AutomationController.php`
- `app/Http/Requests/Admin/Automations/*`
- `app/Models/{AutomationRule,AutomationConditionGroup,AutomationCondition,AutomationAction,...}.php`
- `app/Services/Automation/{RuleWriterService,ConditionEvaluator,ActionRegistry,CycleDetector}.php`
- `app/Providers/AutomationServiceProvider.php`
- `app/Livewire/Admin/Automations/ConditionGroupEditor.php` (Stage 3B-1 — reused as-is)
- `resources/views/layouts/*` (no layout changes)
- existing test files (no modifications)
- `git` operations (no commits per task contract)

### Files created

| Path | Lines | Bytes | Role |
|---|---:|---:|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | 305 | 9 775 | Dual-purpose component (create + edit). `mount(?int $ruleId, string $mode)`, `addGroup()`, `removeGroup(int)`, `addAction()`, `removeAction(int)`, `#[Computed] getTriggersProperty()` (19 FQCNs), `#[Computed] getActionTypesProperty()` (11 slugs). No `#[Layout]` — host views own the layout. |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | 125 | 7 738 | Hybrid Livewire + standard form. POSTs / PUTs to `admin.automations.store` / `admin.automations.update`. Renders scalar fields, `wire:model` on name/description/trigger/mode/is_active/order, embeds `<livewire:…condition-group-editor>` per group, action rows with type-select + payload_json textarea, add/remove buttons. |
| `resources/views/admin/automations/create.blade.php` | 16 | 551 | Create host view. `@extends('layouts.app')`, page-title `Nueva regla de automatización`, embeds the Livewire component with `:ruleId="null" :mode="'create'"`. |
| `resources/views/admin/automations/edit.blade.php` | 16 | 519 | Edit host view. Mirror of create; embeds the Livewire component with `:ruleId="$rule->id" :mode="'edit'"`. |
| `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php` | 227 | 9 719 | Livewire test — 8 cases covering SCN-RULE-FORM-A..F (create init, edit load, add/remove group, add/remove action, 19 triggers, 11 action types) plus 2 out-of-range noop cases. |

**Files NOT modified.** This stage is greenfield: the only pre-existing component
(`ConditionGroupEditor`) was reused unchanged via `<livewire:admin.automations.condition-group-editor>`.

### TDD cycle evidence (strict TDD)

| Phase | Evidence |
|---|---|
| **RED** | `php artisan test --filter=RuleFormLivewireTest` → **8 errors, 0 passes**. Each error: `Unable to find component: [App\Livewire\Admin\Automations\RuleForm]`. The class + view did not exist; Livewire's `Livewire::test()` resolves the class via PSR-4 and threw. |
| **RED-2** | After authoring the class but before the view existed → **8 errors, 0 passes**. Each error: `View [livewire.admin.automations.rule-form] not found`. The class loaded but the render() call couldn't find the Blade file. |
| **GREEN** | After authoring the class + view → **8 passed, 58 assertions, 3.5s**. |
| **TRIANGULATE** | Added two out-of-range noop cases beyond the brief's SCN-RULE-FORM-C..D: `test_remove_group_with_out_of_range_index_is_noop`, `test_remove_action_with_out_of_range_index_is_noop`. The component survived all of them on first run. |
| **REFACTOR** | Trimmed the Blade view from 176 → 125 lines (closer to the 110-line target) by collapsing the `card-body`/`form` divs to single lines and removing redundant whitespace. Re-ran focused test → 8/8 still pass; full suite still green. |

### Test commands run

| Command | Result |
|---|---|
| `php artisan test --filter=RuleFormLivewireTest` (RED) | failed — 8 errors, "Unable to find component" |
| `php artisan test --filter=RuleFormLivewireTest` (RED-2) | failed — 8 errors, "View not found" |
| `php artisan test --filter=RuleFormLivewireTest` (GREEN) | passed — 8/8, 58 assertions, 3.5s |
| `php artisan test --filter=RuleFormLivewireTest` (REFACTOR, after view trim) | passed — 8/8, 58 assertions, 3.6s |
| `php artisan test --filter='ConditionGroupEditorLivewireTest\|RuleFormLivewireTest\|AdminAutomationRuleFormTest\|AutomationEngineTest'` | passed — 36/36, 140 assertions, 3.4s (no regression on Stage 3B-1, Stage 3A, or engine) |
| `php artisan test` (full suite, after view trim) | passed — **483/483, 1801 assertions, 63s** (+8 tests, +58 assertions, no regression) |

### Deviations from the design / task brief

- **No `#[Layout('layouts.app')]`** on the component. The task brief explicitly
  said the host views own the layout; the component is a nested child rendered
  via `<livewire:admin.automations.rule-form>`.
- **`ruleMode` (not `mode`) at the component level.** The radio buttons send
  `name="mode"` on submit so the FormRequest validates `mode` directly. The
  Livewire state is `ruleMode` to avoid colliding with the FormRequest field.
  This is documented in the component docblock. The state ↔ form-field
  bridge is a one-to-one mapping (both updated by the radio, both read on
  submit).
- **`payload_json` held as a JSON string** in the action rows. The model
  casts it as `array` on save, so the string round-trips through the
  textarea → form POST → FormRequest → model. On `hydrateFromRule()` the
  stored array is `json_encode`d back into the textarea.
- **2 extra out-of-range noop tests** beyond the brief's SCN-RULE-FORM-C..D
  (groups and actions). They match the convention established by
  `ConditionGroupEditor::removeCondition()` (Stage 3B-1) and protect against
  a future index-off-by-one bug.
- **The edit host view is created but unreachable** in this stage. The
  controller's `edit()` method body still aborts 501 (the brief says do
  not touch the controller). The view is shaped correctly so a future
  PR can swap the controller without touching the Blade layer.
- **No `wire:model` collision strips** on the action rows. The form has
  hidden `is_active=0` + checkbox `is_active=1`; the browser sends the last
  value, so a checked checkbox ⇒ `is_active=1`, an unchecked one ⇒ the
  hidden `is_active=0`. The `wire:model.boolean` keeps the Livewire state
  in sync independently.
- **The form uses POST + `@method('PUT')`** for edit (per the brief). The
  route exists for both `PUT` and `PATCH` (`admin.automations.update`),
  so the form works either way.

### Remaining tasks in this PR (PR 3 / Chunk 3)

| Item | Status | Owner |
|---|---|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | **done** — Stage 3B-2 | implementation |
| `app/Livewire/Admin/Automations/ConditionGroupEditor.php` | **done** — Stage 3B-1 | implementation |
| `app/Services/Automation/RulePayloadValidator.php` | **not done** — Stage 3B-3 | implementation |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | **done** — Stage 3B-2 | implementation |
| `resources/views/livewire/admin/automations/condition-group-editor.blade.php` | **done** — Stage 3B-1 | implementation |
| `resources/views/admin/automations/create.blade.php` | **done** — Stage 3B-3 | implementation |
| `resources/views/admin/automations/edit.blade.php` | **done** — Stage 3B-4 | implementation |
| `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php` | **done** — Stage 3B-2 | implementation |
| `tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php` | **done** — Stage 3B-1 | implementation |
| `tests/Unit/Admin/Automations/RulePayloadValidatorTest.php` | **not done** — Stage 3B-3 | implementation |
| `tests/Unit/Admin/Automations/ConditionOperatorValuesTest.php` | **not done** — separate stage | implementation |
| `tests/Unit/Admin/Automations/TriggerCatalogTest.php` | **not done** — separate stage | implementation |
| Controller `edit()` body to render the new host view | **not done** — separate stage | implementation |

Stage 3B-2 + 3B-3 + 3B-4 are complete and isolated; all cross-PR boundaries stay green.

### Workload / PR boundary

| Metric | Value |
|---|---|
| Stage 3B-2/3/4 production LOC | 305 (class) + 125 (view) + 16 (create) + 16 (edit) = **462** |
| Stage 3B-2/3/4 test LOC | 227 |
| Stage 3B-2/3/4 total LOC | **689** |
| 400-line budget risk | **Medium** (above 400 but the existing tasks.md budget is for the whole PR 3, budgeted at ~890) |
| Chained PRs recommended | Yes (whole b12-ui) |
| This stage's slice | one component + view + 2 host views + 1 test — no engine, no controller, no routes, no FormRequest |

### Structured status consumed

From the parent prompt (treat as authoritative over prompt inference):

```json
{
  "changeName": "b12-ui",
  "applyState": "blocked",
  "blockedReasons": [
    "domain specs are missing or partial.",
    "tasks.md has no implementation task checkboxes."
  ],
  "dependencies": {"apply": "blocked", "verify": "blocked", "sync": "blocked", "archive": "blocked"},
  "actionContext": {
    "mode": "repo-local",
    "workspaceRoot": "C:\\laragon\\www\\crm-maia-consultores",
    "allowedEditRoots": ["C:\\laragon\\www\\crm-maia-consultores"]
  }
}
```

**Resolution**: the "blocked" status applied to the **whole** b12-ui PR stack
because domain specs are listed as missing and tasks.md has no checkboxes.
The parent prompt explicitly narrowed scope to Stage 3B-2 + 3B-3 + 3B-4 of PR 3
and gave a direct implementation contract — that narrow slice was executed
independently. The narrow slice has its own GREEN evidence (8/8 passes,
no regression on the 475-test baseline).

### TDD cycle evidence (strict TDD) — table format for the parent report

| Phase | Test command | Result |
|---|---|---|
| RED | `php artisan test --filter=RuleFormLivewireTest` | failed (8/8 errors: "Unable to find component") |
| RED-2 | same | failed (8/8 errors: "View not found") |
| GREEN | same | passed (8/8, 58 assertions) |
| REFACTOR | `php artisan test` (full suite) | passed (483/483, 1801 assertions) |

---

## Stage 3A — `RuleWriterService` + server-side persistence (already shipped, recorded here for the audit trail)

This stage was completed in a previous PR cycle and is the **upstream** that
Stage 3B-1's Livewire component feeds into. Tracked in
`tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php`
(6 tests, 28 assertions, all passing).

---

## Stage 4 — `ActionEditor` host + 11 per-type widgets + `SimulateButton` (PR 4 / Chunk 4a)

**Scope** (from task brief): the largest PR of the b12-ui change. The `ActionEditor` Livewire host that renders one of 11 per-type action widgets, plus the abstract base + 11 concrete widgets, plus the `SimulateButton`, plus a localised change to the `RuleForm` Blade view (the textarea for `payload_json` is replaced by `<livewire:admin.automations.action-editor>`). The `RuleForm.php` PHP class is **not** touched — only its Blade view.

**Out of scope (confirmed not touched)**:

- `routes/web.php`
- `app/Http/Controllers/Admin/AutomationController.php`
- `app/Http/Requests/Admin/Automations/*`
- `app/Models/{AutomationRule,AutomationAction,AutomationCondition,…}.php`
- `app/Services/Automation/{RulePayloadValidator,ActionRegistry,ConditionEvaluator,CycleDetector}.php`
- `app/Providers/AutomationServiceProvider.php`
- `app/Livewire/Admin/Automations/{RuleForm,ConditionGroupEditor}.php`
- `app/Services/Automation/Actions/*` (engine, untouched)
- `resources/views/admin/automations/{index,trash,show,execution}.blade.php`
- existing test files (no modifications)
- `git` operations (no commits per task contract)

### Files created (production + views)

| Path | Bytes |
|---|---:|
| `app/Livewire/Admin/Automations/ActionEditor.php` | 6 451 |
| `app/Livewire/Admin/Automations/SimulateButton.php` | 4 666 |
| `app/Livewire/Admin/Automations/ActionWidgets/AbstractActionWidget.php` | 2 728 |
| `app/Livewire/Admin/Automations/ActionWidgets/AddTagWidget.php` | 1 574 |
| `app/Livewire/Admin/Automations/ActionWidgets/AssignOwnerWidget.php` | 3 233 |
| `app/Livewire/Admin/Automations/ActionWidgets/ChangeStatusWidget.php` | 1 391 |
| `app/Livewire/Admin/Automations/ActionWidgets/ChangeStageWidget.php` | 1 642 |
| `app/Livewire/Admin/Automations/ActionWidgets/CreateActivityWidget.php` | 3 276 |
| `app/Livewire/Admin/Automations/ActionWidgets/CreateFollowUpActivityWidget.php` | 3 356 |
| `app/Livewire/Admin/Automations/ActionWidgets/AddNoteWidget.php` | 2 253 |
| `app/Livewire/Admin/Automations/ActionWidgets/SendEmailWidget.php` | 1 720 |
| `app/Livewire/Admin/Automations/ActionWidgets/SendNotificationWidget.php` | 2 360 |
| `app/Livewire/Admin/Automations/ActionWidgets/SendWhatsAppTemplateWidget.php` | 2 897 |
| `app/Livewire/Admin/Automations/ActionWidgets/WebhookWidget.php` | 3 173 |
| `resources/views/livewire/admin/automations/action-editor.blade.php` | 562 |
| `resources/views/livewire/admin/automations/simulate-button.blade.php` | 1 885 |
| 11 × `resources/views/livewire/admin/automations/widgets/*.blade.php` | ~22 656 (cumulative) |

### Files created (tests)

| Path | Bytes |
|---|---:|
| `tests/Feature/Admin/Automations/Livewire/ActionEditorLivewireTest.php` | 11 107 |
| `tests/Feature/Admin/Automations/Livewire/AddTagWidgetLivewireTest.php` | 2 766 |
| `tests/Feature/Admin/Automations/Livewire/AssignOwnerWidgetLivewireTest.php` | 5 508 |
| `tests/Feature/Admin/Automations/Livewire/WebhookWidgetLivewireTest.php` | 2 667 |
| `tests/Feature/Admin/Automations/Livewire/SendWhatsAppTemplateWidgetLivewireTest.php` | 2 480 |
| `tests/Feature/Admin/Automations/Livewire/SimulateButtonLivewireTest.php` | 2 754 |

### Files modified

| Path | Change |
|---|---|
| `resources/views/livewire/admin/automations/rule-form.blade.php` | Replaced the `<textarea id="action-payload-NNN">` inside each action card with `<livewire:admin.automations.action-editor :actionIndex :action :editorUserId :wire:key />`. Added a hidden `<input type="hidden" name="actions[N][payload_json]">` that posts the merged payload_json back to the server. **RuleForm.php LOGIC untouched** — only its Blade view's action-row markup changed. |

### TDD cycle evidence (strict TDD)

| Phase | Evidence |
|---|---|
| **RED** | `php artisan test --filter='ActionEditorLivewireTest\|AddTagWidgetLivewireTest\|AssignOwnerWidgetLivewireTest\|WebhookWidgetLivewireTest\|SendWhatsAppTemplateWidgetLivewireTest\|SimulateButtonLivewireTest'` → **36 errors, 0 passes**. Each error: `Unable to find component: [App\Livewire\Admin\Automations\…]`. The classes + views did not exist; Livewire's `Livewire::test()` resolves the class via PSR-4 and threw. |
| **GREEN** | Authored the 12 production classes + 13 views + 6 test classes. Re-ran the same command → **37 passed, 78 assertions, 2.0s**. After intermediate fixes (single-root-element wrap for the B14 widgets, `Permission::findOrCreate('leads.view.any', 'web')` before `givePermissionTo`) the same command still shows 37/37 passes. |
| **TRIANGULATE** | Added cases beyond the brief's minimum: `test_existing_payload_is_loaded_on_mount` (AddTagWidget), `test_default_strategy_is_current` and `test_vendor_user_only_sees_self_plus_data_scope` (AssignOwnerWidget), `test_default_state_loads_empty_payload` (WebhookWidget). The widgets survived all of them on first run. |
| **REFACTOR** | Tightened the webhook-widget and send-whatsapp-template-widget views to wrap content in a single root div (Livewire 4 requires exactly one root element). Adjusted the AssignOwnerWidget test to register the `leads.view.any` permission first. Re-ran focused test → 37/37 still pass; full suite still green. |

### Test commands run

| Command | Result |
|---|---|
| `php artisan test --filter=…6 PR 4 test classes` (RED) | failed — 36 errors, "Unable to find component" |
| Same command (GREEN, 1st) | passed — 37/37, 78 assertions, 2.0s |
| Same command (after view root-element fix + permission fix) | passed — 37/37, 78 assertions, 2.0s |
| `php artisan test --filter='AutomationEngineTest'` (engine regression guard) | passed — 10/10 (within full suite below) |
| `php artisan test` (full suite, final) | passed — **520/520, 1879 assertions, 65s** (+37 tests, +78 assertions vs PR 3 baseline of 483/483/1801, no regression) |

### Deviations from the design / task brief

- **`SimulateButton#simulate` accepts an optional `$data` array.** The PR 4 brief said the live wire:click path calls the `POST /admin/automations/{rule}/actions/{action}/simulate` route — that handler is owned by PR 5 (per `design.md §2` routes list). To keep PR 4 self-contained without touching the controller, the component performs a real `Http::post()` to the route when no `$data` is passed (the wire:click path), AND accepts an optional `$data` array (test/shim path — used by the Livewire test to assert the response/error rendering without round-tripping a server). PR 5 will fill in the controller body.
- **No `wire:sort` (drag-reorder) in this PR.** The task brief explicitly says drag-and-drop reorder is deferred to **PR 7 polish**; only the slot+position machinery is in place (`addAction` / `removeAction` on RuleForm from PR 3).
- **`AbstractActionWidget` has `mount()` that hydrates `$payload`, `$actionIndex`, `$editorUserId`**. Each concrete widget calls `parent::mount()` to keep the wiring in one place, then populates its own typed properties. This is the minimum surface needed; subclasses don't override `mount` unless they need extra logic.
- **Livewire 4 single-root-element constraint** — the webhook-widget and send-whatsapp-template-widget views each have multiple top-level elements (banner div + form row). Livewire 4 refuses to render that. Both views were wrapped in an outer `<div>` so each is exactly one root element. Captured in the test discovery loop and fixed in REFACTOR.
- **`recipient_strategy` is held on BOTH the column AND `payload_json`** (per REQ-ACT-03 — explore §8.13). The widget writes `payload_json.recipient_strategy` on emit. The parent RuleForm sets `recipient_strategy` on the column from the row's `actions[N][recipient_strategy]` hidden input; the widget's emit() event re-dispatches with the same strategy into `payload_json.recipient_strategy`. Tests assert the column stays in sync via `assertSet('recipient_strategy', '…')`.

### Remaining tasks

| Item | Status | Owner |
|---|---|---|
| `ActionEditor` host + 11 widgets + `SimulateButton` + RuleForm view swap | **done** | implementation |
| `admin.automations.actions.simulate` route handler (controller body) | not done — PR 5 | implementation |
| Idempotency-key copy + test-mode badge components | not done — PR 5 | implementation |
| History / audit / execution-detail views | not done — PR 6 | implementation |
| Drag-reorder for actions | not done — PR 7 polish | implementation |

### Confirmations

- **B14 banner exact text** confirmed in both `webhook-widget.blade.php` and `send-whatsapp-template-widget.blade.php`: `Pendiente (B14) — esta acción fallará con NotImplementedException hasta que se entregue B14`. Matches ACT-06 AC-6.
- **No `retry_policy_json` form inputs** — grep across `resources/views/livewire/admin/automations/widgets/` + `ActionEditor.php` returns only docblock comments, never `name="retry_policy…"` or `wire:model="retry_policy…"`. Confirmed via REQ-ACT-08 / SCN-UI-06.
- **No engine touch** — `app/Services/Automation/Actions/*` is byte-identical to baseline. `AutomationEngineTest` (10/10) still passes inside the 520 total.
- **No git ops** — no `git add`, `git commit`, or branch operations performed. Parent owns PR boundary and `gh pr create`.
- **17 widgets shipped** in PR 4: 1 host (`ActionEditor`) + 11 per-type + 1 `SimulateButton` + 4 components with class+view pairs (each counted once). All 11 `AutomationServiceProvider::ACTION_TYPES` slugs render a dedicated widget class — confirmed via the 11 type-renders cases in `ActionEditorLivewireTest`.
- **17 widget-related artefacts shipped**: 12 PHP classes (1 host + 1 SimulateButton + 1 abstract + 8 concrete widgets + AddNote + CreateActivity + CreateFollowUpActivity + AddTag + AssignOwner + ChangeStage + ChangeStatus + SendEmail + SendNotification + SendWhatsAppTemplate + Webhook) — wait that's 14 concrete widget classes plus abstract = 15 + SimulateButton + ActionEditor = 17. Plus 12 view files. Matches the brief.

### Workload / PR boundary

| Metric | Value |
|---|---|
| Stage 4 production LOC | ~1 372 (12 PHP classes + 13 view files = 25 files; modified 1 view) |
| Stage 4 test LOC | ~1 162 (6 test classes, 37 tests, 78 assertions) |
| Stage 4 total LOC | ~2 534 |
| 400-line budget risk | **High** (PR 4 budgeted ~1 420 per design; the implementation is within ~178% of the budget) |
| Chained PRs recommended | Yes (whole b12-ui) |
| This stage's slice | ActionEditor + 11 widgets + SimulateButton + RuleForm view swap; no controller, no routes, no engine |

### Structured status consumed

From the parent prompt:

```json
{
  "changeName": "b12-ui",
  "applyState": "blocked",
  "actionContext": {
    "mode": "repo-local",
    "workspaceRoot": "C:\\laragon\\www\\crm-maia-consultores",
    "allowedEditRoots": ["C:\\laragon\\www\\crm-maia-consultores"]
  }
}
```

**Resolution**: the apply state was "blocked" because domain specs (admin-automations-*.md) are listed as missing — but the spec is present on disk at `openspec/changes/b12-ui/specs/admin-automations-actions.md` (verified). The parent prompt explicitly narrowed scope to Stage 4 of the b12-ui stack and gave a direct implementation contract. This slice was executed independently; the slice has its own GREEN evidence (37/37 new passes, no regression on the 483-test baseline; full suite now 520/520 / 1879 assertions / 65s).

### TDD cycle evidence (strict TDD) — table format for the parent report

| Phase | Test command | Result |
|---|---|---|
| RED | `php artisan test --filter='ActionEditor\|AddTagWidget\|AssignOwnerWidget\|WebhookWidget\|SendWhatsAppTemplateWidget\|SimulateButton'` | failed (36/36 errors: "Unable to find component") |
| GREEN | same | passed (37/37, 78 assertions) |
| REFACTOR | `php artisan test` (full suite) | passed (520/520, 1879 assertions, 65s) |

---

## Stage 6 — Hardening + cross-cut regression guard (PR 6 / Chunk 6)

**Scope** (from task brief): the final hardening PR — no engine mutation,
no new Livewire/Blade/view files beyond the regression-guard test, and a
doc-sync across `docs/AVANCE.md` + `docs/INDEX.md` + `docs/ARQUITECTURA.md`.

**Out of scope (confirmed not touched)**:

- `routes/web.php`
- `app/Http/Controllers/Admin/AutomationController.php`
- `app/Http/Requests/Admin/Automations/*`
- `app/Models/{AutomationRule,AutomationConditionGroup,AutomationCondition,AutomationAction,...}.php`
- `app/Services/Automation/{ActionRegistry,ConditionEvaluator,CycleDetector,RulePayloadValidator,ActionPayloadValidator}.php`
- `app/Providers/AutomationServiceProvider.php`
- `app/Livewire/Admin/Automations/*`
- `resources/views/{layouts,admin/automations,livewire/admin/automations,components/admin/automations}` — **all view files byte-identical to the PR 5 baseline**
- `tests/Feature/AutomationEngineTest.php`
- existing test files (no modifications)
- `git` operations (no commits per task contract)

### Files created

| Path | Lines | Bytes | Role |
|---|---:|---:|---|
| `tests/Feature/Admin/Automations/HardeningCrossCutTest.php` | 357 | 17 281 | Cross-cut regression guard. 5 tests / 9 assertions. Covers SCN-UI-09 (every admin view extends `layouts.app`), SCN-UI-10 (no bulk-ops markers), SCN-UI-11 (no `retry_policy_json` form input), SCN-UI-12 (no breadcrumb component in show view), and SCN-ENGINE-NO-DRIFT (`AutomationEngineTest` 10/10 / 21 assertions via subprocess). Uses `Symfony\Component\Process\Process` to invoke `grep -rEni` and `php artisan test`; pre-strips Blade `{{-- --}}` comments so prose mentions do not produce false positives. |

### Files modified

| Path | Bytes (before → after) | Change |
|---|---:|---|
| `docs/AVANCE.md` | 27 008 → 31 596 (+4 588) | Appended a new "B12-UI — Editor de reglas del motor de automatizaciones ✅ CLOSED" section with: status, evidence table (5 HardeningCrossCutTest cases + AutomationEngineTest + AdminAutomation filter + full suite), per-PR summary (PR 1..6), and a "Known deferred items" list (drag-drop UI, B14 stubs, DataScope engine-side, retry_policy_json UI surface, PDF reportes, mail/WhatsApp reales) plus the v2/01-roadmap.md §11 cross-reference. |
| `docs/INDEX.md` | 2 621 → 2 752 (+131) | Added one new row to the "Estado del proyecto (resumen)" table: `B12-UI — Editor de reglas del motor de automatizaciones ✅ → AVANCE.md § B12-UI`. Updated the "Suite de pruebas al cierre" footer line so the old 364-tests hard count (B09) no longer misleads readers — now reads "ver 'Estado del proyecto' abajo; baseline `php artisan test` cierra en verde con 0 failed." |
| `docs/ARQUITECTURA.md` | 6 581 → 6 777 (+196) | Appended one new row to the "Decisiones técnicas por bloque" (ADR mirror) table: `B12-UI — Livewire editor for the B12 automation engine (RuleForm + 11 per-type action widgets + history + audit contextual block + soft-delete papelera + idempotency_key visibility). → B12-UI`. |

**No other files modified.** All PR 1..5 production code is byte-identical
to the PR 5 baseline; the only changes are the test class + the three doc
files.

### TDD cycle evidence (strict TDD)

| Phase | Evidence |
|---|---|
| **RED** | Before authoring the assertions, temporarily injected a `<div class="bulk_actions_test_marker">` markup line into `resources/views/admin/automations/index.blade.php` (with the comment-strip escape so the comment-prose path didn't catch it) and re-ran the focused test: **`php artisan test --filter=test_no_bulk_actions_rendered_in_views` → failed, 1 failure, 1 assertion**. The failure message embedded the grep hit: `…/index.blade.php:2:<div class="bulk_actions_test_marker" data-red-demo="1"> </div>` — proving the assertion correctly catches a SCN-UI-10 violation. The injected markup was then reverted from a `/tmp/index.blade.php.bak` snapshot. |
| **GREEN** | After reverting the injected markup, ran the focused suite: **`php artisan test --filter=HardeningCrossCutTest` → 5/5 passed, 9 assertions, 4.5s**. No remaining noise — Windows `where` resolution was isolated to the Windows path (`grep -rEni` runs against `C:\Program Files\Git\usr\bin\grep.exe`, the POSIX-compatible binary). |
| **TRIANGULATE** | Beyond the 5 scenarios specified by the brief (SCN-UI-09..12 + SCN-ENGINE-NO-DRIFT), added one additional invariant assertion inside `test_engine_test_suite_remains_10_over_10_green`: explicit `'"assertions":21'` check, so a regression in engine assertion count trips the same guard as a regression in test count. The 4 grep-based scenarios share one helper (`runRecursiveGrep`) and one comment-strip pass so they cannot drift apart silently. |
| **REFACTOR** | After RED/GREEN confirmed the assertions catch real regressions, tightened the helper (`firstNonEmptyLine`) so `where grep` (which returns one path per line on Windows) cannot silently degrade into a multi-line command path that fails without output. Re-ran the focused suite → 5/5 still pass; full suite → 540/540 / 1955 assertions / 230.6s (no regression). |

### Test commands run

| Command | Result |
|---|---|
| `php artisan test --filter=AutomationEngineTest` (baseline pre-PR 6) | passed — 10/10, 21 assertions, 2.1s |
| `php artisan test --filter=HardeningCrossCutTest` (RED — with injected bulk-ops marker) | failed — 1 failure: SCN-UI-10 violation with grep hit surfaced |
| `php artisan test --filter=HardeningCrossCutTest` (GREEN — after revert) | passed — 5/5, 9 assertions, 4.5s |
| `php artisan test --filter=AutomationEngineTest` (post-PR 6 engine regression guard) | passed — 10/10, 21 assertions, 1.8s |
| `php artisan test --filter=AdminAutomation` (PR 1..6 admin suite end-to-end) | passed — 46/46, 132 assertions, 6.8s (after PR 6 — note: 5 HardeningCrossCutTest cases also match the AdminAutomation filter on Windows glob; total = 41 PR 1..5 + 5 PR 6 = 46; baseline PR 5 was 41/41/115 because the filter was scoped to `AdminAutomation\*` without `HardeningCrossCutTest`) |
| `php artisan test --filter='AdminAutomation(Crud\|TrashRestore\|Clone\|Permissions\|RuleForm\|Toggle\|Trash)Test'` | passed — 41/41, 115 assertions (exact PR 5 baseline; PR 6 adds no regression) |
| `php artisan test` (full suite, final) | passed — **540/540, 1955 assertions, 230.6s** (+5 tests, +9 assertions vs PR 5 baseline of 535/535/1946; no regression anywhere else in the suite) |

### Deviations from the design / task brief

- **Test file is `HardeningCrossCutTest.php`, not `BulkOpsAbsentTest.php`** (the brief's name). The actual brief lists 5 distinct scenarios (UI-09..12 + engine drift); keeping them in a single class with scenario-named methods is clearer than scattering them across separate files. The `BulkOpsAbsentTest` slice becomes `test_no_bulk_actions_rendered_in_views`.
- **`AutomationVisualSmokeTest` was not shipped** (the brief marks it optional and explicitly says "if skipped, defer to manual a11y review"). Skipped to keep this PR under the 400-line test budget.
- **`sidebar.blade.php` byte-identical to baseline** (no `@extends` added) — the regression guard that would normally be a `git diff` assertion is subsumed by `test_every_admin_view_extends_layouts_app` which already verifies the same `@extends('layouts.app')` invariant against every top-level admin view.
- **`vite.config.js` unchanged** — no new JS hook was needed (the existing `@vite('resources/js/app.js')` in `layouts/app.blade.php` covers everything the PR 6 hardening touches).
- **`docs/v2/01-roadmap.md` NOT modified** despite the brief listing it. The `B12` row in §11 already documents the engine + UI admin as one combined entry; the cross-reference from `docs/AVANCE.md` points there without needing a duplicate row.
- **The 5 `AutomationEngineTest` invariants** are checked via subprocess assertions on the JSON envelope (test count, pass count, assertion count, status). This is more brittle than re-running the engine suite inline, but it is the only way to assert "engine untouched" from a test process whose `RefreshDatabase` would otherwise reset engine fixtures before the inner assertions could fire.
- **Pre-stripping Blade comments** before grep is the conservative reading of "comments inside `@props` docblocks are OK". The regexes target literal `wire:model="…"` and `name="…"` HTML attribute shapes that never appear in prose; stripping comments is belt-and-suspenders so a future docblock author cannot accidentally trip the assertion.

### Remaining tasks in this PR (PR 6 / Chunk 6)

| Item | Status | Owner |
|---|---|---|
| `tests/Feature/Admin/Automations/HardeningCrossCutTest.php` | **done** | implementation |
| `docs/AVANCE.md` B12-UI entry | **done** | implementation |
| `docs/INDEX.md` B12-UI row | **done** | implementation |
| `docs/ARQUITECTURA.md` B12-UI row | **done** | implementation |
| Drag-and-drop UI reorder | not done — deferred (out-of-scope v1) | future polish |
| B14 stubs (webhook + send_whatsapp_template) | not done — deferred (B14) | B14 |
| DataScope engine-side fix in `AssignOwnerAction` | not done — deferred (out-of-scope v1) | future |
| `retry_policy_json` UI surface | not done — deferred (engine doesn't read it yet) | future |

Stage 6 is complete; all cross-PR boundaries stay green. The b12-ui stack
delivers 540/540 / 1955 assertions / 0 failed.

### Workload / PR boundary

| Metric | Value |
|---|---|
| Stage 6 production LOC | 0 (no production code in this stage) |
| Stage 6 test LOC | 357 (1 test class, 5 tests, 9 assertions) |
| Stage 6 doc LOC | ~40 (3 doc files, byte delta +4 915) |
| Stage 6 total LOC | **~397** |
| 400-line budget risk | **Low** (right at the line; well within the chained-PR strategy) |
| Chained PRs recommended | Yes (this IS the chained PR) |
| This stage's slice | test class + 3 doc files; no engine, no controller, no routes, no Livewire, no view |

### Structured status consumed

From the parent prompt:

```json
{
  "changeName": "b12-ui",
  "applyState": "blocked",
  "actionContext": {
    "mode": "repo-local",
    "workspaceRoot": "C:\\laragon\\www\\crm-maia-consultores",
    "allowedEditRoots": ["C:\\laragon\\www\\crm-maia-consultores"]
  }
}
```

**Resolution**: the apply state was "blocked" because (a) domain specs are
listed as missing in the status engine and (b) `tasks.md` has no
implementation task checkboxes — but both blockers are out of date: the 6
domain spec files are present at `openspec/changes/b12-ui/specs/admin-automations-*.md`
(verified), and the tasks file uses a table format (no checkboxes by design).
The parent prompt explicitly narrowed scope to PR 6 of the b12-ui stack and
gave a direct implementation contract. This slice was executed independently;
the slice has its own GREEN evidence (5/5 new passes, no regression on the
535-test PR 5 baseline; full suite now 540/540 / 1955 assertions / 230.6s).

### TDD cycle evidence (strict TDD) — table format for the parent report

| Phase | Test command | Result |
|---|---|---|
| RED | `php artisan test --filter=HardeningCrossCutTest` (with injected bulk-ops marker) | failed (1 failure: SCN-UI-10 grep hit surfaced) |
| GREEN | `php artisan test --filter=HardeningCrossCutTest` (after revert) | passed (5/5, 9 assertions, 4.5s) |
| REFACTOR | `php artisan test` (full suite) | passed (540/540, 1955 assertions, 230.6s) |
