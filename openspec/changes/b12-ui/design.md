# B12-UI — Automation Engine Administration UI (sdd-design)

> **Phase**: sdd-design (technical bridge between the 6 functional specs and the future sdd-tasks + sdd-apply phases).
> **Status**: design only — **no code, no migrations, no routes registered, no tests authored** in this artifact.
> **Upstream artifacts (authoritative)**:
>
> - `openspec/changes/b12-ui/explore.md` — engine + placeholder surface map.
> - `openspec/changes/b12-ui/proposal.md` — PRD + 12 locked decisions (§10) + AC-1..AC-12 (§12).
> - `openspec/changes/b12-ui/specs/admin-automations-crud.md` (CRUD-01..CRUD-08).
> - `openspec/changes/b12-ui/specs/admin-automations-conditions.md` (COND-01..COND-08).
> - `openspec/changes/b12-ui/specs/admin-automations-actions.md` (ACT-01..ACT-09).
> - `openspec/changes/b12-ui/specs/admin-automations-history.md` (HIST-01..HIST-10).
> - `openspec/changes/b12-ui/specs/admin-automations-permissions.md` (PERM-01..PERM-09).
> - `openspec/changes/b12-ui/specs/admin-automations-ui-conventions.md` (UI-01..UI-14).
> - `openspec/config.yaml` — Laravel 13.25, PHP 8.3.16, Livewire 4, Spatie Permission + Activitylog, AdminLTE/Bootstrap 5, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`.

This document resolves the 13 technical-shape decisions enumerated in the task brief. Every decision references a spec REQ-id, a proposal AC, or an explore § gotcha — none of them re-opens product scope. File paths are illustrative (sdd-apply owns the final wiring).

---

## §1 Decisiones de arquitectura (resumen)

| # | Decisión | Outcome (one-liner) | Trazabilidad |
|---|---|---|---|
| 1 | Routing shape | Hand-rolled `Route::controller(AutomationController::class)->group(...)` block extending the existing placeholder; `Route::resource()` rejected because the surface mixes CRUD + 5 verb-irregular actions. | CRUD affected-routes + proposal §7.1 |
| 2 | Livewire component tree | Stateful components as full PHP classes under `app/Livewire/Admin/Automations/`; tiny presentational widgets as anonymous Blade components under `resources/views/components/admin/automations/`. | UI-05 + REQ-CRUD-02..08, REQ-COND-01..04, REQ-ACT-01..07 |
| 3 | FormRequests | `StoreRuleRequest`, `UpdateRuleRequest`, `ReorderRequest`, `SimulateRequest` under `App\Http\Requests\Admin\Automations\`; per-class validation + REQ-id trace in §4. | CRUD-02, CRUD-03, CRUD-06, ACT-07 |
| 4 | Policies vs Gates | Stick with `Gate::authorize('automations.*')`; no `AutomationRulePolicy` — a Policy would only re-wrap the 5 named permissions and add indirection without changing semantics. | PERM-02..06 |
| 5 | payload_json validator | `App\Services\Automation\ActionPayloadValidator` — single service, per-type ruleset map keyed by `ActionRegistry::registered()`, throws `ValidationException`. | ACT-02, ACT-08 |
| 6 | History rendering | `paginate(20)` (mirroring placeholder), GET-param filter form (`status`, `date_from`, `date_to`, `subject`), single `_history_table` partial with `<x-table>` + empty-state. | HIST-01..03, UI-03 |
| 7 | Audit contextual | Blade partial `resources/views/views/admin/automations/partials/_audit_changes.blade.php`; query `Spatie\Activitylog\Models\Activity` filtered by `subject_type=AutomationRule`, `subject_id`, paginated 10/pg. | HIST-08, UI-02 |
| 8 | Drag-reorder | Livewire 4 `wire:sort` directive on rows + one `PATCH admin.automations.reorder` accepting `{order: [{id, order}, ...]}`; transactions per-kind. | CRUD-06, COND-04, ACT-09 |
| 9 | Clone semantics | `Rule::with('conditionGroups.conditions', 'actions')->find($id)->replicate()` chain + `created_by=auth()->id()`, `is_active=false`, `mode='test'`, `name+" (copia)"`. | CRUD-04 |
| 10 | Test seams | 21 test classes across Feature + Unit, mapped to REQ-ids in §11. | config.yaml `testing.conventions` |
| 11 | File map | Single ASCII tree in §12 — required vs optional marked. | (this doc) |
| 12 | `order` concurrency | Last-write-wins, no optimistic lock column added in v1; documented as known limitation tied to proposal R5. | proposal §11 R5 |
| 13 | `automations.webhook.execute` | Registered in `AutomationServiceProvider`, **zero** routes enforce it in v1; reserved per proposal decision 8. | PERM-06, PERM-07 (SCN-PERM-04) |

---

## §2 Routing shape (decision 1)

**Recommendation: hand-rolled**, extending the existing `Route::controller(\App\Http\Controllers\Admin\AutomationController::class)->group(...)` block at `routes/web.php:375`. The block stays in the same `auth` + `admin` middleware group (`explore.md` §2.1). `Route::resource('automations', AutomationController::class)` is rejected because:

1. It generates only the 7 REST verbs — B12-UI needs **11** named endpoints (CRUD-01..08 affected routes table) including `trash`, `clone`, `restore`, `reorder`, `toggle`, and the simulate sub-route.
2. Mixing the placeholder's existing `showExecution` nested route (`admin.automations.executions.show`) into a resource declaration would require an exception clause.
3. The placeholder's pattern (`Route::controller(...)->group(...)`) already exists; extending it is one continuous diff. Switching to `Route::resource()` mid-block forces splitting the controller declaration, which complicates the rollback path described in proposal §11.
4. Per-verb `Route::get/post/patch/delete(...)` lines give every endpoint an explicit grep handle — required by `strict_tdd: true` (config.yaml) so tests can target route names.

**Snippet pattern that sdd-apply will copy** (illustrative, non-runnable):

```php
Route::controller(\App\Http\Controllers\Admin\AutomationController::class)->group(function (): void {
    // read-only (kept)
    Route::get('automations', 'index')->name('automations.index');
    Route::get('automations/trash', 'trash')->name('automations.trash');
    Route::get('automations/{automation}', 'show')->name('automations.show');
    Route::get('automations/{automation}/executions/{execution}', 'showExecution')
        ->name('automations.executions.show');

    // rule CRUD (writes)
    Route::get('automations/create', 'create')->name('automations.create');
    Route::post('automations', 'store')->name('automations.store');
    Route::get('automations/{automation}/edit', 'edit')->name('automations.edit');
    Route::put('automations/{automation}', 'update')->name('automations.update');
    Route::patch('automations/{automation}', 'update')->name('automations.update'); // alias
    Route::delete('automations/{automation}', 'destroy')->name('automations.destroy');

    // irregular verbs (write)
    Route::patch('automations/{automation}/toggle', 'toggle')->name('automations.toggle');
    Route::post('automations/{automation}/clone', 'clone')->name('automations.clone');
    Route::post('automations/{automation}/restore', 'restore')->name('automations.restore');
    Route::patch('automations/reorder', 'reorder')->name('automations.reorder');

    // simulate sub-route
    Route::post('automations/{automation}/actions/{action}/simulate', 'simulateAction')
        ->name('automations.actions.simulate');

    // audit-only feed (history "Cambios" block)
    Route::get('automations/{automation}/audit', 'auditFeed')
        ->name('automations.audit.feed');
});
```

**Trace**: `admin-automations-crud.md` (affected-routes table) + `admin-automations-actions.md` (simulate sub-route) + `admin-automations-history.md` (audit-only feed) + `admin-automations-permissions.md` (gate matrix). **All 11 CRUD REQs and PERM-01..07 satisfied by the verbs above.**

> **Note on `Route::put`/`Route::patch`**: Livewire 4 + AdminLTE 5 form patterns emit `PATCH` for edit pages. Declaring both keeps the contract verbose but unambiguous (SCN-CRUD-03, SCN-CRUD-05).

---

## §3 Livewire components (decision 2)

### 3.1 Location pattern

Two locations, two roles — chosen to keep the diff readable and aligned with the conventions already in the B08 admin (explore §2.4):

- **Stateful components (PHP class)** → `app/Livewire/Admin/Automations/<Name>.php`. These need lifecycle (`mount`, `updating`, `updated`), cross-component listeners (`#[On]`), cached reads (`#[Computed]`), and optional full-page layouts (`#[Layout('layouts.app')]`). Livewire 4 attribute style (explore §6 + §8.15).
- **Presentational widgets (anonymous Blade component)** → `resources/views/components/admin/automations/<slug>.blade.php` (`<x-admin-automations.idempotency-key-copy>`). These have **no** reactive state and exist only to factor repeated markup.

This mirrors how `resources/views/components/{table,modal,alert}.blade.php` already wrap markup without owning state.

### 3.2 Tree

```
app/Livewire/Admin/Automations/
├── RuleForm.php                       # dual-purpose: create + edit. Hosts condition + action editors.
├── ConditionGroupEditor.php           # one instance per group; owns condition rows + AND/OR switch.
├── ActionEditor.php                   # one instance per action row; renders the type-specific widget.
└── HistoryFilter.php                  # Livewire form bound to ?status, ?date_from, ?date_to, ?subject.

resources/views/components/admin/automations/
├── idempotency-key-copy.blade.php     # <code class="font-monospace user-select-all"> + clipboard.
├── test-mode-badge.blade.php          # purple "Modo test" badge + tooltip.
├── delete-confirm.blade.php           # modal trigger for soft-delete.
├── restore-button.blade.php           # "Restaurar" form button.
├── simulate-button.blade.php          # "Simular ahora" button + payload textarea modal.
├── action-widget.blade.php            # <x-admin-automations.action-widget :action="$action" /> dispatcher.
├── assign-owner-widget.blade.php      # type-specific widget for assign_owner.
├── change-status-widget.blade.php     # subject-aware column + value selector.
├── change-stage-widget.blade.php      # PipelineStage selector + note.
├── add-tag-widget.blade.php           # tag picker + "crear si no existe" checkbox.
├── send-email-widget.blade.php        # recipient + subject + body + queue toggle.
├── send-notification-widget.blade.php # recipient + title + body + level.
├── send-whatsapp-template-widget.blade.php # B14 stub banner + variables editor.
├── create-activity-widget.blade.php   # ActivityType selector + scheduling.
├── create-follow-up-activity-widget.blade.php # same + required next_scheduled_at.
├── add-note-widget.blade.php          # body + priority + owner.
├── webhook-widget.blade.php           # B14 stub banner + URL select + method + headers.
└── b14-stub-banner.blade.php          # the literal banner fragment shared by webhook + whatsapp widgets.
```

**Why split widgets per type rather than one big `ActionWidget`?** Because each widget has a different payload schema (REQ-ACT-02 matrix). A single conditional switch inside `ActionWidget` becomes unreadable; per-type files keep each ~30–50 lines, are independently testable (`ActionWidgetTest` parameterised over `type`), and align with the B13/B14 strategy of "new action types auto-register through `ActionRegistry::registered()`" (explore §7 + proposal §11 dependencies).

`ActionEditor` resolves the right widget via `Blade::render(<x-admin-automations.{type}-widget :action="$action" />)` from a slug-to-blade-component map maintained in `ActionEditor::WIDGET_MAP` (defaults to `<x-admin-automations.{type}-widget>`).

### 3.3 Component responsibilities

| Component | Responsibility | REQ-ids |
|---|---|---|
| `RuleForm` | One component handles both `admin.automations.create` and `admin.automations.edit`. Loads the rule + condition groups + conditions + actions via `Rule::with(['conditionGroups.conditions', 'actions'])->find(...)`. Hosts `#[Computed]` props for `triggers`, `operators`, `actionTypes`, `visibleUsers`, `visibleTeams`. `save()` triggers `StoreRuleRequest`/`UpdateRuleRequest`. | CRUD-02, CRUD-03, ACT-01, COND-01 |
| `ConditionGroupEditor` | Renders N rows for `AutomationConditionGroup`. Exposes AND/OR switch (hidden on first group, COND-03). Drag-reorder via `wire:sort`. Emits `group-updated` `#[On]` event. | COND-01..04, COND-07 |
| `ActionEditor` | Renders a single action row with the type selector + the appropriate widget. Drag-reorder. Toggles `is_active`. "Eliminar" button (gated `automations.manage`). | ACT-01, ACT-02, ACT-09 |
| `HistoryFilter` | Reads `request()->query()` filters, renders the filter form, emits `filter-changed` event consumed by the controller-side paginator. Optional — see §7. | HIST-02 |
| `IdempotencyKeyCopy` (Blade) | `<code class="user-select-all font-monospace">` + "Copiar" button + 2s toast. | HIST-06, UI-07 |
| `TestModeBadge` (Blade) | `<span class="badge text-bg-purple" title="Modo test: simuló, no ejecutó acciones reales" data-bs-toggle="tooltip">Modo test</span>`. | HIST-05, UI-08 |
| `DeleteConfirm` (Blade) | Wraps `<x-modal>` + confirm form posting to `admin.automations.destroy`. | CRUD-07 |
| `RestoreButton` (Blade) | Inline form posting to `admin.automations.restore`. | CRUD-08 |
| `SimulateButton` (Blade) | "Simular ahora" + payload textarea + `<x-modal>` showing `response_json` monospace. | ACT-07 |

### 3.4 Composition

`admin/automations/create.blade.php` is a thin Blade view that renders `<livewire:admin.automations.rule-form :rule="null" />`. The Livewire class uses `#[Layout('layouts.app')]` (UI-01, UI-05) and yields `title`, `page-title`, `content` via `wire:full-page-component` semantics. This keeps the placeholder Blade thin and the form logic in the class — required by `strict_tdd: true` (Livewire::test pattern, config.yaml testing.conventions).

---

## §4 FormRequests (decision 3)

Four FormRequest classes under `App\Http\Requests\Admin\Automations\`. All extend `Illuminate\Foundation\Http\FormRequest` and use `authorize()` returning `$this->user()->can('automations.manage')` (for write classes) or `automations.test` (for `SimulateRequest`). The `authorize()` hook is the **first** statement checked (PERM-03, PERM-04).

### 4.1 `StoreRuleRequest`

| Field | Rules | REQ-id satisfied |
|---|---|---|
| `name` | `required`, `string`, `max:191` | CRUD-02 |
| `description` | `nullable`, `string` | CRUD-02 |
| `trigger_event` | `required`, `string`, `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` | CRUD-02, COND-08 |
| `is_active` | `nullable`, `boolean` (default false) | CRUD-02, CRUD-05 |
| `mode` | `required`, `Rule::in(['live','test'])` (default 'test') | CRUD-02 |
| `order` | `nullable`, `integer`, `min:1` (default tail = `max(order)+1`) | CRUD-02, CRUD-06 |
| `owner_id` | `nullable`, `integer`, `exists:users,id` | CRUD-02 |
| `condition_groups` | `nullable`, `array` | CRUD-02 |
| `condition_groups.*.logical_operator` | `required_with:condition_groups`, `Rule::in(['AND','OR'])` | COND-01, COND-03 |
| `condition_groups.*.conditions` | `nullable`, `array` | COND-02 |
| `condition_groups.*.conditions.*.field` | `required_with:condition_groups.*.conditions`, `string`, `max:191` | COND-02 |
| `condition_groups.*.conditions.*.operator` | `required_with:condition_groups.*.conditions`, `Rule::in(ConditionOperator::values())` | COND-02 |
| `condition_groups.*.conditions.*.value_type` | `required_with:condition_groups.*.conditions`, `Rule::in(['string','int','bool','date','datetime','enum','array'])` | COND-06 |
| `condition_groups.*.conditions.*.value` | `nullable`, mixed (validated by `RulePayloadValidator`, see §6) | COND-06, COND-07 |
| `actions` | `nullable`, `array` | ACT-01 |
| `actions.*.type` | `required_with:actions`, `Rule::in(array_keys(ActionRegistry::registered()))` | ACT-01 |
| `actions.*.is_active` | `nullable`, `boolean` | ACT-01 |
| `actions.*.recipient_strategy` | `nullable`, `Rule::in(['user','team','round_robin','current'])` (only when `type=assign_owner`) | ACT-03 |
| `actions.*.payload` | `nullable`, `array` (validated by `ActionPayloadValidator`, see §6) | ACT-02, ACT-08 |
| `actions.*.position` | `nullable`, `integer`, `min:1` | ACT-01, ACT-09 |

`created_by` is **not** accepted in the request body — it's hard-coded to `auth()->id()` in the controller (CRUD-02 + REQ-CRUD-03 "Edits SHALL NOT modify created_by"). The `withValidator()` hook calls `ActionPayloadValidator::validateMany()` and `RulePayloadValidator::validateMany()` to surface 422s with per-action key paths (REQs ACT-02, COND-07).

### 4.2 `UpdateRuleRequest`

Same field list as `StoreRuleRequest` plus:
- All scalar fields become `sometimes` instead of `required` (CRUD-03 — partial updates).
- `actions.*.id` is added (`nullable`, `integer`, `exists:automation_actions,id`) so the editor can distinguish new from edited rows.
- `condition_groups.*.id` ditto.
- A `withValidator()` hook rejects submissions referencing a `trigger_event` no longer in `AutomationServiceProvider::TRIGGER_EVENTS` with the message "Trigger no disponible en el catálogo actual" (COND-08, proposal §9.9).

### 4.3 `ReorderRequest`

| Field | Rules | REQ-id satisfied |
|---|---|---|
| `order` | `required`, `array`, `min:1` | CRUD-06 |
| `order.*.id` | `required`, `integer`, `exists:automation_rules,id` | CRUD-06 |
| `order.*.order` | `required`, `integer`, `min:1` | CRUD-06 |
| `kind` | `nullable`, `Rule::in(['rules','conditions','actions'])` (used to dispatch to the right persistence layer — see §9) | COND-04, ACT-09 |

Authorize hook: `automations.manage` (PERM-03). No optimistic-lock guard in v1 (decision 12, proposal R5).

### 4.4 `SimulateRequest`

| Field | Rules | REQ-id satisfied |
|---|---|---|
| `payload` | `required`, `array` | ACT-07 |
| `payload.*` | `nullable`, mixed (passed verbatim to `ActionContract::simulate`) | ACT-07 |

Authorize hook: `automations.test` (PERM-04). The authorize check runs **before** `ActionRegistry::resolveForAction()` is touched (defense in depth, REQ-PERM-04 SCN-PERM-02).

### 4.5 Why no `CloneRuleRequest`

Clone carries no payload — the controller takes the source rule's id and reuses `replicate()`. Authorization happens in the controller body (`$this->authorize('automations.manage')`) without a FormRequest (CRUD-04).

---

## §5 Policies & Gates (decision 4)

**Recommendation: do NOT ship an `AutomationRulePolicy`. Keep `Gate::authorize('automations.*')` calls at the top of each controller method.**

Justification:

1. The 5 permissions are already named (`automations.view|manage|test|audit|webhook.execute`) and registered by `AutomationServiceProvider::registerAutomationPermissions()` (PERM-01, explore §8.11). Adding a Policy would only re-wrap those names — `Policy::view()` would still resolve to `Gate::has('automations.view')` — adding indirection without changing semantics.
2. Spatie's `Gate::authorize()` is the convention used by the existing `AutomationController` (`app/Http/Controllers/Admin/AutomationController.php:24` for `index`). B12-UI extends that surface; switching to `Gate::authorize(...)` → `Policy` mid-block breaks the rollback path (proposal §11).
3. `FormRequest::authorize()` can return `false` instead of throwing — useful for `StoreRuleRequest` / `UpdateRuleRequest` / `ReorderRequest` / `SimulateRequest` because Laravel converts the `false` to a 403 *before* validation runs (PERM-04 SCN-PERM-02). A Policy class would have to override this behaviour or be bypassed by the FormRequest pattern.
4. The "audit" surface is gated by `@can('automations.audit')` in a Blade partial (HIST-08) — not by a controller method, since the audit block is part of `admin.automations.show`. A Policy class per model only handles controller-level checks.
5. The admin and supervisor role seeding (explore §8.11 + provider) is the single source of truth for "who has which permission" — a Policy would introduce a parallel ACL chain.

**Concretely** — `AutomationController::store()` (illustrative):

```php
public function store(StoreRuleRequest $request): RedirectResponse
{
    // FormRequest already authorized; double-check view (PERM-07).
    Gate::authorize('automations.view');   // defense in depth
    // ... persist ...
}
```

`#[Can('manage', 'automation')]` style attributes are rejected for the same reason. The PERM-07 contract ("server-side check is the source of truth") is satisfied by the `Gate::authorize()` call.

**Trace**: PERM-01..09, specifically SCN-PERM-01..06.

---

## §6 Validación temprana de payload_json (decision 5)

**Recommendation: `App\Services\Automation\ActionPayloadValidator`** — a single service, not a strategy map of 11 classes. The 11 schemas share enough shape (mostly "field X is required, field Y must be in {a,b,c}") that a switch on `$type` inside one method is shorter, easier to test, and the call site stays obvious.

### 6.1 Class layout

```
app/Services/Automation/ActionPayloadValidator.php
```

```php
final class ActionPayloadValidator
{
    /** @var array<string, list<array{0: string, 1: string|array, 2: string}>> */
    private const RULES = [
        'create_activity'             => [['type_id', 'required|integer|exists:activity_types,id', '...'], ...],
        'create_follow_up_activity'   => [['next_scheduled_at', 'required|date', '...'], ...],
        'assign_owner'                => [
            ['user_id', 'required_if:recipient_strategy,user|integer|exists:users,id', '...'],
            ['team_id', 'required_if:recipient_strategy,team,round_robin|integer|exists:teams,id', '...'],
        ],
        // ... 8 more rows
    ];

    public function validate(string $type, array $payload): array
    {
        $rules = self::RULES[$type] ?? [];
        // If unknown type, return $payload as-is (engine will throw later — UI defence = no-op).
        if ($rules === []) {
            return $payload;
        }

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $validator->validated();
    }

    public function validateMany(string $type, array $payloads): array { /* loop */ }
}
```

### 6.2 Call sites

- `StoreRuleRequest::passedValidation()` → `ActionPayloadValidator::validateMany($type, $payloads)` per action row.
- `UpdateRuleRequest::passedValidation()` → same, for each action in the batch.
- `SimulateRequest` **does NOT** call this — `simulate()` is the engine's own preview, payload is opaque (ACT-07). The simulate handler catches `Throwable` from the engine and renders the exception in the modal (HIST-09).

### 6.3 Companion `RulePayloadValidator`

Conditions use a similar inline service — `App\Services\Automation\RulePayloadValidator` — to enforce the COND-06 / COND-07 rules (int coercion for `value_type=int|bool`, `Carbon::parse` for `date|datetime`, array required for `in|not_in|between`). Lives next to `ActionPayloadValidator` to keep validation adjacent.

### 6.4 Trace

ACT-02 (per-type widget matrix), ACT-07 (simulate), ACT-08 (retry hidden), COND-06, COND-07. The validator throws `Illuminate\Validation\ValidationException` which Livewire 4 + AdminLTE 5 surface as `<x-validation-error>` automatically (UI-02, UI-13).

---

## §7 Historia (decision 6)

### 7.1 Paginator size

`paginate(20)` — matches the placeholder (`AutomationController::index()` line 36) and the executions query in `AutomationController::show()` (line 50). No change in v1.

### 7.2 Filter form

`HistoryFilter` Livewire component is the **optional** path. The **mandatory** path is a plain Blade form posting via GET so the URL stays shareable (HIST-02 "Filters SHALL persist in pagination links"). The Livewire component is only used when the admin needs the date pickers to refresh the table without a full page reload — both flows end at the same controller route `admin.automations.show` with the same query string.

Filter form layout (placed inside `<x-table>`'s `filters` slot, UI-02):

```
form(method="GET" action="admin.automations.show")  -> 4 columns:
  col-auto  [Status   select  AutomationExecutionStatus::values() + labels()]
  col-auto  [Desde    input   type=date  name=date_from]
  col-auto  [Hasta    input   type=date  name=date_to]
  col-auto  [Sujeto   input   type=text  name=subject  (matches subject_type or subject_id)]
  col-auto  [Filtrar  submit][Limpiar  link -> route without query]
```

### 7.3 Steps table layout

The existing `resources/views/admin/automations/execution.blade.php` (58 lines, explore §2.3) is replaced by a refactored version that:

- Loads `$execution->load(['steps.action', 'cycleBreaks'])` (already in the placeholder).
- Renders the steps table with columns: position, action type (`class_basename($step->action->type)`), status badge (`AutomationStepStatus::label()`), attempt, started_at + finished_at (TZ `America/Lima`, HIST-10).
- Each row expands via `<details>` to show `response_json` in `<pre><code class="font-monospace">`.
- When `status ∈ {failed, partial, circuit-broken}`, the expanded row also renders `<x-alert type="error">{{ $step->error_class }}: {{ $step->error_message }}</x-alert>` (HIST-04, HIST-09).
- Cycle breaks appear below in a collapsed `<details>` with rows `reason` + `detected_at` (HIST-07).

### 7.4 Where the copy button lives

The `<x-admin::automations.idempotency-key-copy :value="$execution->idempotency_key" />` Blade component sits inside the alert summary block at the top of `admin.automations.executions.show`, immediately under the `subject_type` + `subject_id` row. This is the "diagnostic" surface (HIST-06, UI-07).

---

## §8 Audit contextual (decision 7)

### 8.1 Partial path

`resources/views/admin/automations/partials/_audit_changes.blade.php`.

Rendered from `admin.automations.show` inside an `@can('automations.audit')` guard (HIST-08, PERM-05, SCN-PERM-03). The partial renders an `<x-table title="Cambios">` with paginated `Activity` rows.

### 8.2 Query (illustrative)

```php
Activity::query()
    ->where('subject_type', AutomationRule::class)
    ->where('subject_id', $automation->id)
    ->orderByDesc('created_at')
    ->paginate(10);
```

`paginate(10)` matches the proposal's "paginated at 10 per page" (HIST-08). No eager loading — the row payload (causer, subject) is light.

### 8.3 Display

Each row: `created_at` (`America/Lima`, HIST-10), `causer` name (`User::find($activity->causer_id)->name`), description (`$activity->description`), and a small `<pre>` with the diff if `$activity->properties['attributes']` is present (Spatie's default `changes` array). The query lives inside `AutomationController::auditFeed(AutomationRule $automation)` and the partial pulls `activities` from a view variable — pagination reuses `<x-table>`'s `pagination` slot.

### 8.4 Trace

HIST-08, PERM-05, AC-9. Note: this is a **dedicated** route (`admin.automations.audit.feed`, §2) — the global `audit.view` surface (`audit.index`) is NOT reused (proposal §10 #5, AC-9).

---

## §9 Drag-reorder (decision 8)

**Recommendation: Livewire 4 `wire:sort` directive + one PATCH endpoint `admin.automations.reorder`.**

### 9.1 Mechanism

Livewire 4 ships `wire:sort` (composer pin `livewire/livewire: ^4.4` — explore §8.15). The pattern:

```blade
<ul wire:sort="updateRuleOrder">
    @foreach ($rules as $rule)
        <li wire:key="rule-{{ $rule->id }}" wire:sort:item="{{ $rule->id }}">…</li>
    @endforeach
</ul>
```

`updateRuleOrder($orderedIds)` is a Livewire method on the host component (`RuleForm` for the editor, a dedicated `RuleIndexList` for the index). It computes the new `order` per id (1-indexed), persists via the controller endpoint, and re-renders the list. Same shape for `ConditionGroupEditor` and `ActionEditor` (COND-04, ACT-09).

### 9.2 Route handler

`PATCH admin.automations.reorder` → `AutomationController::reorder(ReorderRequest $request)`. Single transactional handler that:

- Receives `{kind: 'rules'|'conditions'|'actions', order: [{id, order}, ...]}` (CRUD-06 + ReorderRequest above).
- Dispatches to the right persistence layer based on `kind`.
- Re-normalizes `order` to consecutive integers starting at 1 (CRUD-06) within the implicit "page" (for v1 the page = the whole list returned by `AutomationRule::ordered()` for rules; per-group for conditions; per-rule for actions).

### 9.3 Why not sortable.js?

The composer pin locks `livewire/livewire: ^4.4` — using `sortable.js` would add a JS dependency, conflict with UI-14 ("Vite-asset alignment"), and require a parallel bundle. `wire:sort` is the idiomatic Livewire 4 path.

### 9.4 Why not per-drop AJAX?

Per-drop POSTs amplify the race window (R5) and require idempotency keys on the wire. One batch PATCH keeps the transaction atomic and the round-trip count low.

### 9.5 Trace

CRUD-06, COND-04, ACT-09, UI-14. Concurrency tradeoff documented in §13.

---

## §10 Clone semantics (decision 9)

`POST admin.automations.clone` → `AutomationController::clone(AutomationRule $source)`. Inside the controller (illustrative — not runnable):

```php
$clone = DB::transaction(function () use ($source, $request) {
    $rule = $source->replicate(['created_by', 'is_active', 'mode', 'name']);
    $rule->name       = $source->name . ' (copia)';
    $rule->created_by = $request->user()->id;
    $rule->is_active  = false;
    $rule->mode       = 'test';
    $rule->save();

    foreach ($source->conditionGroups as $group) {
        $newGroup = $group->replicate();
        $newGroup->rule_id = $rule->id;
        $newGroup->save();

        foreach ($group->conditions as $cond) {
            $newCond = $cond->replicate();
            $newCond->rule_id = $rule->id;   // denormalized (COND-08)
            $newCond->group_id = $newGroup->id;
            $newCond->save();
        }
    }

    foreach ($source->actions as $action) {
        $newAction = $action->replicate();
        $newAction->rule_id = $rule->id;
        $newAction->save();
    }

    return $rule;
});
```

The `replicate(['created_by', ...])` exclusion list (CRUD-04) guarantees that only the explicit assignments stick. Children keep their source order because `replicate()` carries `position` columns intact. After the save the new ids are auto-assigned by Eloquent, so no FK rework is needed. FK violations from a manual delete (proposal §9.6) surface as a `QueryException` caught in the controller and rendered through `<x-alert type="error">` (SCN-CRUD-05).

**Trace**: CRUD-04 (REQ-CRUD-04 + SCN-CRUD-04), AC-1, AC-4 (papelera restoration shares the same children-replication pattern via Eloquent's restore() — different code path).

---

## §11 Test seams (decision 10)

Test classes that sdd-apply will author, mapped to REQ-ids. All extend `Tests\TestCase` (Feature) or PHPUnit's `TestCase` (Unit). All Feature tests use `RefreshDatabase` + boot the provider explicitly in `setUp()` (PERM-08).

### 11.1 Feature tests — `tests/Feature/Admin/Automations/`

| Class | Covers | REQ-ids |
|---|---|---|
| `AdminAutomationCrudTest` | index, create, store, edit, update, destroy, toggle, papelera; asserts gate matrix; `assertForbidden()` on writes without `manage`. | CRUD-01..08, PERM-02..03 |
| `AdminAutomationCloneTest` | SCN-CRUD-04 verbatim; checks `created_by`, `is_active`, `mode`, name suffix, child row counts. | CRUD-04 |
| `AdminAutomationReorderTest` | SCN-CRUD-03 last-write-wins; checks `kind=rules|conditions|actions` dispatch; concurrent reorder behaviour. | CRUD-06, COND-04, ACT-09 |
| `AdminAutomationTrashRestoreTest` | SCN-CRUD-05, SCN-CRUD-06; FK break error rendering. | CRUD-07, CRUD-08 |
| `AdminAutomationHistoryTest` | SCN-HIST-01, 02, 03, 07; filter form URL persistence; empty-state. | HIST-01..03, HIST-10 |
| `AdminAutomationExecutionDetailTest` | SCN-HIST-04..08; idempotency_key copy button DOM; cycle-break badge. | HIST-04..07, HIST-09 |
| `AdminAutomationAuditBlockTest` | SCN-HIST-05, SCN-PERM-03; `automations.audit` gates the partial; without perm → DOM has no `#audit-changes-block`. | HIST-08, PERM-05 |
| `AdminAutomationPermissionsTest` | SCN-PERM-01..06; provider boot in setUp; every route's gate matrix. | PERM-01..09 |
| `WebhookAllowListSurfaceTest` | SCN-ACT-05; empty config disables save; URL outside list renders red alert. | ACT-05 |
| `B14StubBannerTest` | SCN-ACT-04; banner + index pill present. | ACT-06, UI-09 |
| `TestModeBadgeComponentTest` | SCN-HIST-04; renders exact copy "Modo test: simuló, no ejecutó acciones reales" + `bg-purple` + tooltip. | HIST-05, UI-08 |
| `RecipientStrategyUnifiedControlTest` | SCN-ACT-01; column + payload_json stay in lockstep after save. | ACT-03 |
| `RetryPolicyHiddenTest` | SCN-UI-06; grep-equivalent assertion over rendered views. | ACT-08, UI-11 |
| `BulkOpsAbsentTest` | SCN-UI-05; grep-equivalent assertion over rendered index. | UI-10 |

### 11.2 Livewire tests — `tests/Feature/Admin/Automations/Livewire/`

| Class | Covers | REQ-ids |
|---|---|---|
| `RuleFormLivewireTest` | create + edit dual purpose; Livewire::test + set/call/assertSet + assertHasErrors on validation. | CRUD-02, CRUD-03 |
| `ConditionGroupEditorLivewireTest` | AND/OR semantics, COND-03 (first-group fixed); drag reorder; empty group auto-remove. | COND-01..04, COND-07 |
| `ActionEditorLivewireTest` | type swap; per-widget rendering; ACT-03 unified control; ACT-09 drag reorder. | ACT-01..04, ACT-09 |
| `ActionWidgetTypeTest` | parameterised over the 11 types; ensures each renders the right payload keys. | ACT-02 |
| `HistoryFilterLivewireTest` | URL persistence; clear filter; pagination link retention. | HIST-02 |
| `SimulateNowLivewireTest` | SCN-ACT-02, SCN-ACT-03; calls ActionRegistry::resolveForAction()->simulate(); modal renders response_json. | ACT-07, PERM-04 |
| `IdempotencyKeyCopyComponentTest` | clipboard write (jsdom), toast 2s; literal value rendered. | HIST-06, UI-07 |

### 11.3 Unit tests — `tests/Unit/Admin/Automations/`

| Class | Covers | REQ-ids |
|---|---|---|
| `ActionPayloadValidatorTest` | every row in the RULES map; missing type → no-op; rejects with 422 + bag. | ACT-02, ACT-08 |
| `RulePayloadValidatorTest` | value_type coercion; `is_null` strips value; array required for in/not_in/between. | COND-06, COND-07 |
| `ConditionOperatorValuesTest` | exhaustiveness vs. the 16 declared constants (regression guard). | COND-02 |
| `TriggerCatalogTest` | `AutomationServiceProvider::TRIGGER_EVENTS` size = 19, no duplicates. | CRUD-02 (catalog source of truth) |
| `RecipientStrategyDualWriteTest` | invariant: setting `recipient_strategy` on column keeps payload_json key in sync. | ACT-03 |
| `WebhookAllowListConfigTest` | reads `config('integrations.webhooks.allowed_destinations')`; empty config = deny. | ACT-05 |

### 11.4 Total

≈ 21 classes. Each File test ≤ ~150 lines per `strict_tdd` discipline (config.yaml `delivery.tdd_cycle`). All Feature tests use `Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Event::fake()` when exercising Livewire write paths to avoid hidden side effects (config.yaml testing.conventions).

---

## §12 File map (decision 11)

ASCII tree of every new file B12-UI v1 will add. `*` = required, `(o)` = optional / may be deferred if a simpler shape satisfies the spec.

```
app/
├── Http/
│   ├── Controllers/Admin/
│   │   └── AutomationController.php            *  (extend existing 3 actions to 13)
│   └── Requests/Admin/Automations/
│       ├── StoreRuleRequest.php                *
│       ├── UpdateRuleRequest.php               *
│       ├── ReorderRequest.php                  *
│       └── SimulateRequest.php                 *
├── Livewire/Admin/Automations/
│   ├── RuleForm.php                            *
│   ├── ConditionGroupEditor.php                *
│   ├── ActionEditor.php                        *
│   └── HistoryFilter.php                       (o)  (see §7.2)
└── Services/Automation/
    ├── ActionPayloadValidator.php              *
    └── RulePayloadValidator.php                *

resources/views/
├── admin/automations/
│   ├── index.blade.php                         *  (replace placeholder)
│   ├── trash.blade.php                         *  (papelera tab — same controller, ?trash=1)
│   ├── show.blade.php                          *  (replace placeholder; adds filter form + audit block)
│   ├── execution.blade.php                     *  (replace placeholder; adds steps expansion + cycle breaks)
│   ├── partials/
│   │   ├── _audit_changes.blade.php            *
│   │   ├── _history_filter.blade.php           *
│   │   ├── _rule_form.blade.php                *  (Livewire host stub)
│   │   └── _test_mode_badge.blade.php          (o)  (only if not factored as Blade component)
│   └── ...
├── components/admin/automations/
│   ├── idempotency-key-copy.blade.php          *
│   ├── test-mode-badge.blade.php               *
│   ├── delete-confirm.blade.php                *
│   ├── restore-button.blade.php                *
│   ├── simulate-button.blade.php               *
│   ├── action-widget.blade.php                 *  (dispatcher)
│   ├── assign-owner-widget.blade.php           *
│   ├── change-status-widget.blade.php          *
│   ├── change-stage-widget.blade.php           *
│   ├── add-tag-widget.blade.php                *
│   ├── send-email-widget.blade.php             *
│   ├── send-notification-widget.blade.php      *
│   ├── send-whatsapp-template-widget.blade.php *
│   ├── create-activity-widget.blade.php        *
│   ├── create-follow-up-activity-widget.blade.php *
│   ├── add-note-widget.blade.php               *
│   ├── webhook-widget.blade.php                *
│   └── b14-stub-banner.blade.php               *
└── livewire/admin/automations/
    ├── rule-form.blade.php                     *  (Livewire full-page view)
    ├── condition-group-editor.blade.php        *
    ├── action-editor.blade.php                 *
    └── history-filter.blade.php                (o)

routes/web.php                                  *  (extend the existing group — see §2)

tests/
├── Feature/Admin/Automations/
│   ├── AdminAutomationCrudTest.php             *
│   ├── AdminAutomationCloneTest.php            *
│   ├── AdminAutomationReorderTest.php          *
│   ├── AdminAutomationTrashRestoreTest.php     *
│   ├── AdminAutomationHistoryTest.php          *
│   ├── AdminAutomationExecutionDetailTest.php  *
│   ├── AdminAutomationAuditBlockTest.php       *
│   ├── AdminAutomationPermissionsTest.php      *
│   ├── WebhookAllowListSurfaceTest.php         *
│   ├── B14StubBannerTest.php                   *
│   ├── TestModeBadgeComponentTest.php          *
│   ├── RecipientStrategyUnifiedControlTest.php*
│   ├── RetryPolicyHiddenTest.php               *
│   ├── BulkOpsAbsentTest.php                   *
│   └── Livewire/
│       ├── RuleFormLivewireTest.php            *
│       ├── ConditionGroupEditorLivewireTest.php*
│       ├── ActionEditorLivewireTest.php        *
│       ├── ActionWidgetTypeTest.php            *
│       ├── HistoryFilterLivewireTest.php       (o)
│       ├── SimulateNowLivewireTest.php         *
│       └── IdempotencyKeyCopyComponentTest.php *
└── Unit/Admin/Automations/
    ├── ActionPayloadValidatorTest.php          *
    ├── RulePayloadValidatorTest.php            *
    ├── ConditionOperatorValuesTest.php         *
    ├── TriggerCatalogTest.php                  *
    ├── RecipientStrategyDualWriteTest.php      *
    └── WebhookAllowListConfigTest.php          *
```

**Files NOT modified by B12-UI v1** (proposal §11 rollback path):

- `database/migrations/2026_08_18_0100{00..60}_*.php` — engine schema is authoritative.
- `app/Providers/AutomationServiceProvider.php` — 5 permissions + ACTION_TYPES + TRIGGER_EVENTS already wired.
- `app/Models/AutomationRule.php`, `AutomationConditionGroup.php`, `AutomationCondition.php`, `AutomationAction.php`, `AutomationExecution.php`, `AutomationExecutionStep.php`, `AutomationCycleBreak.php` — fillable + casts + relations stay as-is.
- `app/Services/Automation/ActionRegistry.php`, `ConditionEvaluator.php`, `CycleDetector.php` — engine services unchanged.
- `resources/views/layouts/partials/sidebar.blade.php` — UI-04, sidebar already wired at line ~92.

---

## §13 Trade-offs y notas (decisions 12, 13)

### 13.1 `automation_rules.order` concurrency (decision 12)

**v1 tradeoff**: drag-to-reorder persists `order` via a single batch PATCH (§9). Two admins reordering the same `order` simultaneously will last-write-wins (proposal R5). **No optimistic-lock column (`version`, `updated_at` compare-and-swap, or `lockForUpdate()`) is added in v1.**

Justification:

- The engine uses `scopeOrdered()` which sorts by `order ASC, id ASC` (`app/Models/AutomationRule.php:81`). The `id` tie-breaker guarantees that no rule is orphaned from the sequence even under concurrent writes (explore §5.2, §8.3).
- Adding a `version` column would require a migration — proposal §8 explicitly forbids migrations in v1 ("No migrations / no schema changes").
- The "compare-and-swap on `updated_at`" approach would only catch drift for one writer at a time and still doesn't guarantee ordering.
- Operational blast radius is low: rules rarely reorder; admins rarely collide on the same page.

**Future work** (out of scope for v1, link back to proposal §11 R5): if reorder collisions become observable, B12.x can either (a) add an optimistic-lock migration, or (b) wrap the reorder handler in `Cache::lock('automation_rules.reorder', 5)` per roadmap §1.3. Neither lands in v1.

### 13.2 `automations.webhook.execute` registered but unused (decision 13)

`automations.webhook.execute` is registered by `AutomationServiceProvider::registerAutomationPermissions()` (PERM-01, explore §8.11) and **no v1 route enforces it** (PERM-06). The permission exists for a future "manual replay" feature (proposal §10 #8) and is explicitly out of scope for v1 (proposal §8 "NO replay, NO manual trigger", §11 R3 / Decision 8).

The test suite ships `SCN-PERM-04`: a user with **only** `automations.webhook.execute` who tries every v1 route receives 403 from every route (because no route enforces that permission). The permission exists but is unreachable. Any code that mentions `automations.webhook.execute` outside the provider registration or this test is flagged as a dead branch during PR review.

### 13.3 Operator-precedence bug in `AssignOwnerAction` (proposal R3)

The engine-side DataScope guard in `AssignOwnerAction::execute` is dead code (explore §8.5 — `! $this->dataScope->visibleOwnerIds($creator) === false` is always `false`). B12-UI compensates by **pre-filtering the user/team pickers** in `assign-owner-widget.blade.php` via `DataScopeService::visibleOwnerIds($creator)` (ACT-04). The engine fix is **not** part of v1; flagged for B12.x / engine maintenance.

### 13.4 Retry policy hidden (UI-11, ACT-08)

`automation_actions.retry_policy_json` column exists (explore §4) but the engine hard-codes `tries=3, backoff=[30,120,600]` in `RunAutomationAction`. No v1 input writes to that column. `RetryPolicyHiddenTest` greps `retry_policy` across all `resources/views/admin/automations/` and `resources/views/livewire/admin/automations/` and asserts zero matches (AC-10, SCN-UI-06).

### 13.5 No breadcrumbs (UI-04)

`resources/views/layouts/partials/breadcrumbs.blade.php` is unused. The rule's `show` page navigates via the page-header back-link + the table link to executions — no breadcrumb driver. This is consistent with the placeholder (explore §8.14).

---

## §14 Quick cross-reference (spec ↔ design ↔ sdd-tasks-ready)

| REQ-id | Spec section | Design section | Test class |
|---|---|---|---|
| CRUD-01 | admin-automations-crud.md §REQ-CRUD-01 | §3.4, §12 file map (`index.blade.php`) | `AdminAutomationCrudTest` |
| CRUD-02 | admin-automations-crud.md §REQ-CRUD-02 | §4.1 `StoreRuleRequest` | `RuleFormLivewireTest` |
| CRUD-03 | admin-automations-crud.md §REQ-CRUD-03 | §4.2 `UpdateRuleRequest` | `AdminAutomationCrudTest` |
| CRUD-04 | admin-automations-crud.md §REQ-CRUD-04 | §10 clone sketch | `AdminAutomationCloneTest` |
| CRUD-05 | admin-automations-crud.md §REQ-CRUD-05 | §2 routes, §4.1 toggle gate | `AdminAutomationPermissionsTest` |
| CRUD-06 | admin-automations-crud.md §REQ-CRUD-06 | §9 drag-reorder, §13.1 | `AdminAutomationReorderTest` |
| CRUD-07 | admin-automations-crud.md §REQ-CRUD-07 | §2 routes, §4.1 store gate | `AdminAutomationTrashRestoreTest` |
| CRUD-08 | admin-automations-crud.md §REQ-CRUD-08 | §2 routes, §3.3 `RestoreButton` | `AdminAutomationTrashRestoreTest` |
| COND-01 | admin-automations-conditions.md §REQ-COND-01 | §3.2 `ConditionGroupEditor` | `ConditionGroupEditorLivewireTest` |
| COND-02 | admin-automations-conditions.md §REQ-COND-02 | §3.2 + §6.3 | `ConditionGroupEditorLivewireTest` |
| COND-03 | admin-automations-conditions.md §REQ-COND-03 | §3.3 `ConditionGroupEditor` | `ConditionGroupEditorLivewireTest` |
| COND-04 | admin-automations-conditions.md §REQ-COND-04 | §9 wire:sort | `ConditionGroupEditorLivewireTest` |
| COND-05 | admin-automations-conditions.md §REQ-COND-05 | §3.2 (datalist on field) | `ConditionGroupEditorLivewireTest` |
| COND-06 | admin-automations-conditions.md §REQ-COND-06 | §6.3 `RulePayloadValidator` | `RulePayloadValidatorTest` |
| COND-07 | admin-automations-conditions.md §REQ-COND-07 | §6.3 + §4.1 | `RulePayloadValidatorTest` |
| COND-08 | admin-automations-conditions.md §REQ-COND-08 | §4.2 (`UpdateRuleRequest` guard) | `ConditionGroupEditorLivewireTest` |
| ACT-01 | admin-automations-actions.md §REQ-ACT-01 | §3.2 `ActionEditor` | `ActionEditorLivewireTest` |
| ACT-02 | admin-automations-actions.md §REQ-ACT-02 | §6 `ActionPayloadValidator` + §3.2 widgets | `ActionWidgetTypeTest`, `ActionPayloadValidatorTest` |
| ACT-03 | admin-automations-actions.md §REQ-ACT-03 | §3.2 `assign-owner-widget` | `RecipientStrategyUnifiedControlTest` |
| ACT-04 | admin-automations-actions.md §REQ-ACT-04 | §3.2 `assign-owner-widget` + §13.3 | `ActionEditorLivewireTest` |
| ACT-05 | admin-automations-actions.md §REQ-ACT-05 | §3.2 `webhook-widget` + §6 | `WebhookAllowListSurfaceTest`, `WebhookAllowListConfigTest` |
| ACT-06 | admin-automations-actions.md §REQ-ACT-06 | §3.2 `b14-stub-banner` | `B14StubBannerTest` |
| ACT-07 | admin-automations-actions.md §REQ-ACT-07 | §3.2 `simulate-button` + §4.4 | `SimulateNowLivewireTest` |
| ACT-08 | admin-automations-actions.md §REQ-ACT-08 | §13.4 + §12 file map (no retry field) | `RetryPolicyHiddenTest` |
| ACT-09 | admin-automations-actions.md §REQ-ACT-09 | §9 wire:sort | `ActionEditorLivewireTest` |
| HIST-01 | admin-automations-history.md §REQ-HIST-01 | §7.3 + §3.4 | `AdminAutomationHistoryTest` |
| HIST-02 | admin-automations-history.md §REQ-HIST-02 | §7.2 | `HistoryFilterLivewireTest` |
| HIST-03 | admin-automations-history.md §REQ-HIST-03 | §7.3 + UI-03 | `AdminAutomationHistoryTest` |
| HIST-04 | admin-automations-history.md §REQ-HIST-04 | §7.3 + §13.5 | `AdminAutomationExecutionDetailTest` |
| HIST-05 | admin-automations-history.md §REQ-HIST-05 | §3.2 `test-mode-badge` | `TestModeBadgeComponentTest` |
| HIST-06 | admin-automations-history.md §REQ-HIST-06 | §3.2 `idempotency-key-copy` | `IdempotencyKeyCopyComponentTest` |
| HIST-07 | admin-automations-history.md §REQ-HIST-07 | §7.3 cycle-break `<details>` | `AdminAutomationExecutionDetailTest` |
| HIST-08 | admin-automations-history.md §REQ-HIST-08 | §8 audit partial | `AdminAutomationAuditBlockTest` |
| HIST-09 | admin-automations-history.md §REQ-HIST-09 | §7.3 error alert + §6.2 | `AdminAutomationExecutionDetailTest` |
| HIST-10 | admin-automations-history.md §REQ-HIST-10 | §7.3 TZ directive | (covered by every test rendering a timestamp) |
| PERM-01 | admin-automations-permissions.md §REQ-PERM-01 | §5 (no Policy), §2 routes | `AdminAutomationPermissionsTest` |
| PERM-02 | admin-automations-permissions.md §REQ-PERM-02 | §5 + §2 | `AdminAutomationPermissionsTest` |
| PERM-03 | admin-automations-permissions.md §REQ-PERM-03 | §5 + §4.1-§4.3 `authorize()` | `AdminAutomationPermissionsTest` |
| PERM-04 | admin-automations-permissions.md §REQ-PERM-04 | §4.4 `SimulateRequest` | `AdminAutomationPermissionsTest` |
| PERM-05 | admin-automations-permissions.md §REQ-PERM-05 | §8 audit partial | `AdminAutomationAuditBlockTest` |
| PERM-06 | admin-automations-permissions.md §REQ-PERM-06 | §13.2 | `AdminAutomationPermissionsTest` (SCN-PERM-04) |
| PERM-07 | admin-automations-permissions.md §REQ-PERM-07 | §5 + every controller action | `AdminAutomationPermissionsTest` |
| PERM-08 | admin-automations-permissions.md §REQ-PERM-08 | §11 test seams convention | n/a (test base convention) |
| PERM-09 | admin-automations-permissions.md §REQ-PERM-09 | §11 test seams | n/a (test base convention) |
| UI-01 | admin-automations-ui-conventions.md §REQ-UI-01 | §3.4 + §12 file map | (covered by every view render test) |
| UI-02 | admin-automations-ui-conventions.md §REQ-UI-02 | §3.2 + §6.4 + §12 | (covered by every view render test) |
| UI-03 | admin-automations-ui-conventions.md §REQ-UI-03 | §7.3 empty states | (covered by history/trash tests) |
| UI-04 | admin-automations-ui-conventions.md §REQ-UI-04 | §13.5 sidebar unchanged | (regression test: git diff) |
| UI-05 | admin-automations-ui-conventions.md §REQ-UI-05 | §3.1, §3.4 | `RuleFormLivewireTest` |
| UI-06 | admin-automations-ui-conventions.md §REQ-UI-06 | §7.3 | (TZ directive covered by HIST tests) |
| UI-07 | admin-automations-ui-conventions.md §REQ-UI-07 | §3.2 `idempotency-key-copy` | `IdempotencyKeyCopyComponentTest` |
| UI-08 | admin-automations-ui-conventions.md §REQ-UI-08 | §3.2 `test-mode-badge` | `TestModeBadgeComponentTest` |
| UI-09 | admin-automations-ui-conventions.md §REQ-UI-09 | §3.2 `b14-stub-banner` | `B14StubBannerTest` |
| UI-10 | admin-automations-ui-conventions.md §REQ-UI-10 | §12 file map (no bulk buttons) | `BulkOpsAbsentTest` |
| UI-11 | admin-automations-ui-conventions.md §REQ-UI-11 | §13.4 | `RetryPolicyHiddenTest` |
| UI-12 | admin-automations-ui-conventions.md §REQ-UI-12 | (copy strings hard-coded in widgets) | (covered by every view render test) |
| UI-13 | admin-automations-ui-conventions.md §REQ-UI-13 | §3.4 + §12 file map | (manual a11y review) |
| UI-14 | admin-automations-ui-conventions.md §REQ-UI-14 | §9.3 (no sortable.js) | (covered by Vite build) |

**Coverage check**: all 6 spec REQ-id families (`CRUD-*`, `COND-*`, `ACT-*`, `HIST-*`, `PERM-*`, `UI-*`) appear at least once in the table. No REQ-id is left without a design section + test mapping.

---

**End of design.**