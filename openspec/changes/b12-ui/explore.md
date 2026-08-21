# B12-UI — Engine & Placeholder Surface Map (sdd-explore)

> Read-only mapping of the B12 automation engine and the placeholder admin
> surface for the upcoming B12-UI proposal. No code or tests were added.
> All paths are relative to the project root unless explicitly absolute.
> Status legend: **ready** = the engine exposes a stable seam the UI may
> delegate to; **map** = the engine exposes the data but the UI must
> project it through a form/widget; **out** = out of scope for B12-UI.

---

## 1. Engine surface

### 1.1 Contracts

| Path | Kind | Status | Notes for B12-UI |
|---|---|---|---|
| `app/Contracts/Automation/ActionContract.php` | interface `ActionContract` | ready | Two methods: `execute(array $payload, AutomationExecutionStep $step)` and `simulate(array $payload): array`. Every concrete action under `app/Services/Automation/Actions/*` implements it. |
| `app/Contracts/Automation/AutomationTriggerEvent.php` | interface `AutomationTriggerEvent` | ready | Marker interface every trigger event implements (`subjectType`, `subjectId`, `actorId`, `payload`, `occurredAt`). `DispatchAutomationRule` filters on this marker. |

### 1.2 Enums (final classes with string constants)

| Path | Values |
|---|---|
| `app/Enums/AutomationMode.php` | `LIVE = 'live'`, `TEST = 'test'`. `values()` returns the canonical list. |
| `app/Enums/AutomationExecutionStatus.php` | `queued`, `running`, `success`, `partial`, `failed`, `skipped`, `circuit-broken`. Includes Spanish `label()` and `isTerminal()` helpers — reuse them. |
| `app/Enums/AutomationStepStatus.php` | `pending`, `simulated`, `running`, `success`, `failed`, `skipped`. Same `label()` / `isTerminal()` helpers. |
| `app/Enums/ConditionOperator.php` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `contains`, `starts_with`, `ends_with`, `is_null`, `is_not_null`, `before`, `after`, `between`. Authoritative list for V2 condition operators. |

### 1.3 Models

| Path | Status | Notes |
|---|---|---|
| `app/Models/AutomationRule.php` | ready | Fillable: `name, description, trigger_event, is_active, order, mode, created_by, owner_id`. Scopes: `active()`, `forTrigger($eventClass)`, `ordered()`. Methods: `isLiveMode()`, `conditionGroups()`, `actions()`, `executions()`, `cycleBreaks()`. Uses `SoftDeletes`. |
| `app/Models/AutomationConditionGroup.php` | ready | Fillable: `rule_id, logical_operator, position`. `logical_operator` is `AND`/`OR`. |
| `app/Models/AutomationCondition.php` | ready | Fillable: `group_id, rule_id, field, operator, value, value_type, position`. `value_type ∈ string\|int\|bool\|date\|datetime\|enum\|array`. The denormalized `rule_id` lets the engine avoid a join when listing conditions. |
| `app/Models/AutomationAction.php` | ready | Fillable: `rule_id, position, type, channel, recipient_strategy, payload_json, retry_policy_json, is_active`. `payload_json` and `retry_policy_json` cast to `array`. |
| `app/Models/AutomationExecution.php` | ready | Fillable: `rule_id, trigger_event, subject_type, subject_id, idempotency_key, status, attempt, started_at, finished_at, error_class, error_message`. No model scopes — read-only history model. |
| `app/Models/AutomationExecutionStep.php` | ready | Fillable: `execution_id, action_id, status, attempt, response_json, queued_at, started_at, finished_at, error_class, error_message`. `response_json` is the action's "what I would have done" payload in test mode. |
| `app/Models/AutomationCycleBreak.php` | map | Fillable: `rule_id, subject_type, subject_id, reason, detected_at`. Indexed `(rule_id, subject_type, subject_id)`. Only used for read-only surfacing in the execution history. |

### 1.4 Services

| Path | Status | Notes |
|---|---|---|
| `app/Services/Automation/ActionRegistry.php` | ready | Singleton registered in `AutomationServiceProvider::register()`. Exposes `register($type, $class)`, `resolve($type)`, `registered(): array`, `resolveForAction($action)`. UI may use `registered()` to render the action type dropdown and `resolveForAction()` only when needed for `simulate()` previews. |
| `app/Services/Automation/ConditionEvaluator.php` | ready | Singleton. `matches(AutomationRule, array $payload): bool`. Reads `conditionGroups` eager-loaded with `conditions`. Supports dot-paths in `field`. A rule with zero groups fires unconditionally; an empty group within AND-across-groups is a no-op. |
| `app/Services/Automation/CycleDetector.php` | ready | Singleton. `DEFAULT_WINDOW_SECONDS = 30`. Methods: `isCycling($ruleId, $subjectType, $subjectId)`, `recordBreak(...)`, `canMutateSubject($actionType)` (currently `change_stage`, `change_status`, `assign_owner`). |
| `app/Services/Automation/Exceptions/NotImplementedException.php` | out | Engine-internal; surfaced as `error_class` for stubs (e.g. WhatsApp). |
| `app/Services/Automation/Exceptions/WebhookNotAuthorizedException.php` | out | Engine-internal; surfaced as `error_class` for unauthorized URLs. |

### 1.5 Actions (`app/Services/Automation/Actions/*`)

11 action classes implement `ActionContract`. Each declares a payload schema in its PHPDoc:

| Type (slug) | Class | Payload (read → write) | Notes for UI |
|---|---|---|---|
| `create_activity` | `CreateActivityAction.php` | `type_id, title, description?, scheduled_at?, priority?, owner_id?` | needs `ActivityType` selector, optional datetime, default priority `'media'`. |
| `create_follow_up_activity` | `CreateFollowUpActivityAction.php` | `type_id, title, next_scheduled_at (required), description?, priority?, owner_id?` | `next_scheduled_at` is **required** here (unlike the general `create_activity`). |
| `assign_owner` | `AssignOwnerAction.php` | `recipient_strategy, user_id? (user), team_id? (team/round_robin)` | strategies: `user`, `team`, `round_robin`, `current`. Action also reads `recipient_strategy` from the column. Honors `DataScopeService::visibleOwnerIds()` for the rule creator. |
| `change_status` | `ChangeStatusAction.php` | `value (required), column?` | column defaults from subject type: `status_id` (Lead), `status` (Customer), `stage_id` (Opportunity). |
| `change_stage` | `ChangeStageAction.php` | `stage_slug (required), note?` | uses `OpportunityService::changeStage()`; subject must be `Opportunity`. |
| `add_tag` | `AddTagAction.php` | `tag_slug (required), tag_name?, color?` | auto-creates the tag if missing. UI needs `Tag` selector. |
| `send_notification` | `SendNotificationAction.php` | `user_id?, title, body, level?` | `level ∈ info\|warning\|error`. Falls back to subject owner, then first admin. |
| `send_email` | `SendEmailAction.php` | `to, subject, body, queue? (bool, default true)` | uses `Mail::raw` / `Mail::queue`; B13 introduces the email template catalog. |
| `send_whatsapp_template` | `SendWhatsAppTemplateAction.php` | `template_name, phone_number, language?, variables?, account_id?` | **stub** — throws `NotImplementedException` until B14. UI must surface a "no implementado" warning. |
| `add_note` | `AddNoteAction.php` | `body, priority?, owner_id?` | maps to `ActivityType.slug = 'nota'`; auto-creates the type if missing. |
| `webhook` | `WebhookAction.php` | `url (required, must be in allow-list), method? (GET\|POST\|PATCH, default POST), body?, headers?` | **stub** — does not actually fire until B14. Authorization consults `config('integrations.webhooks.allowed_destinations')`. |

Action slugs ↔ classes live in `AutomationServiceProvider::ACTION_TYPES` (canonical map; UI must read from there, not hardcode).

### 1.6 Jobs and listener

| Path | Status | Notes |
|---|---|---|
| `app/Jobs/V2/RunAutomationAction.php` | ready | `ShouldQueue` with `tries=3`, `backoff=[30,120,600]`. Receives `stepId`. Short-circuits when the step is already terminal. `failed()` hook flips execution to `failed` and dispatches `NotifyOnAutomationFailure`. |
| `app/Jobs/V2/NotifyOnAutomationFailure.php` | ready | `ShouldQueue` (`tries=2`, `backoff=[60,300]`). Notifies every active admin via `AutomationFailedPermanently`. |
| `app/Listeners/V2/DispatchAutomationRule.php` | ready | Single listener subscribed to **every** trigger event explicitly (`AutomationServiceProvider::boot()`). Creates the `AutomationExecution` row idempotently, consults `CycleDetector`, then either dispatches `RunAutomationAction` (live) or marks the step `simulated` (test). |
| `app/Notifications/Automation/AutomationNotification.php` | ready | Database channel only; emitted by `SendNotificationAction`. |
| `app/Notifications/Automation/AutomationFailedPermanently.php` | ready | Database channel only; payload includes `execution_id, rule_name, trigger_event, subject_type, subject_id, error_class, error_message, message`. |

### 1.7 Console commands (already scheduled)

| Path | Signature | Schedule |
|---|---|---|
| `app/Console/Commands/EmitActivityOverdue.php` | `automation:emit-activity-overdue` | daily 02:00 `America/Lima` |
| `app/Console/Commands/EmitQuotationWillExpire.php` | `automation:emit-quotation-will-expire` (--look-ahead=7) | daily 02:30 `America/Lima` |
| `app/Console/Commands/EmitCustomerIdle.php` | `automation:emit-customer-idle` (--idle-days=30) | every 30 minutes `America/Lima` |
| `app/Console/Commands/DispatchDueAutomationSteps.php` | `automation:dispatch-due-steps` (--stuck-after=30) | every minute `America/Lima` |
| `app/Console/Commands/ReconcileFailedAutomationSteps.php` | `automation:reconcile-failed-steps` (--max-attempt=3) | every 5 minutes `America/Lima` |

These commands exist for **operational** use and are not user-facing; B12-UI does not need to surface them.

---

## 2. Placeholder admin surface

### 2.1 Routes (`routes/web.php:375-380`)

```
Route::controller(\App\Http\Controllers\Admin\AutomationController::class)->group(function (): void {
    Route::get('automations', 'index')->name('automations.index');
    Route::get('automations/{automation}', 'show')->name('automations.show');
    Route::get('automations/{automation}/executions/{execution}', 'showExecution')
        ->name('automations.executions.show');
});
```

Group parent at line 375 in `routes/web.php` literally says `// full UI in B12-UI`. All three routes live inside the existing `auth`/`admin` middleware group (the `Route::middleware([...])` block wrapping them also governs `audit.*`, `settings.*`, etc.).

Names: `admin.automations.index`, `admin.automations.show`, `admin.automations.executions.show` (parameter `automation` is route-model bound to `AutomationRule`; `execution` is route-model bound to `AutomationExecution`).

### 2.2 Controller

`app/Http\Controllers/Admin/AutomationController.php` (3 actions):

| Method | Gate | Behavior |
|---|---|---|
| `index(Request)` | `Gate::authorize('automations.view')` | Paginated list of rules with `withCount('executions')`; `paginate(20)`; ordered by `id desc`. |
| `show(AutomationRule $automation)` | `Gate::authorize('automations.view')` | Paginated executions for the rule; eager-loads `steps`. |
| `showExecution(AutomationRule $automation, AutomationExecution $execution)` | `Gate::authorize('automations.view')` | 404 unless `execution->rule_id === $automation->id`; eager-loads `steps.action`. |

The controller is read-only and does not gate `automations.manage`, `automations.test`, `automations.webhook.execute`, or `automations.audit` — those permissions are registered in `AutomationServiceProvider::boot()` but are not yet enforced.

### 2.3 Placeholder views (`resources/views/admin/automations/`)

All three views extend `layouts/app.blade.php` and yield `title`, `page-title`, `content`. The card/table combo comes from `<x-table>` + `<x-alert>`:

| File | Lines | Pattern |
|---|---|---|
| `index.blade.php` | 51 | `<x-table title="Reglas">` with `@slot('headers')` and a `@forelse` loop; badge for mode (`bg-{{ $rule->isLiveMode() ? 'success' : 'secondary' }}`); link `admin.automations.show` for history. |
| `show.blade.php` | 57 | `<dl class="row">` rule metadata + `<x-table title="Ejecuciones recientes">`; link to `admin.automations.executions.show`. |
| `execution.blade.php` | 58 | `<dl class="row">` execution metadata + `<x-table title="Pasos">` listing steps with `action->type`. |

`$rule->executions_count`, `$rule->isLiveMode()`, `$execution->steps`, and `$execution->subject_type` are the fields currently read.

### 2.4 Layout and AdminLTE/Bootstrap 5 conventions

- Layout: `resources/views/layouts/app.blade.php` (AdminLTE 4 + Bootstrap 5, `@vite` of `resources/css/app.css` + `resources/js/app.js`). Yields `title`, `page-title`, `content`; `@stack('scripts')` at the end.
- Sidebar: `resources/views/layouts/partials/sidebar.blade.php`. The `Automatizaciones` link is already present at line ~92 (`@can('automations.view')` inside the `$adminPerms` block at line 65), pointing to `admin.automations.index` and using `bi-lightning-charge`. No breadcrumb driver is set in `app.blade.php`; `partials/breadcrumbs.blade.php` reads `$breadcrumbs ?? []` (currently unused everywhere) and the rest of the B08 admin views do **not** push breadcrumbs either.
- Components (AdminLTE/Bootstrap 5, all `resources/views/components/*.blade.php`):
  - `<x-table>` with `@slot('headers'|'rows'|'filters'|'pagination')` and `@props(['id', 'title'])`.
  - `<x-text-input>` for `<input>` with `label, type, value, required, autocomplete, placeholder, help`.
  - `<x-select>` for `<select>` with `options` (associative) and the same `placeholder`/`help` props.
  - `<x-modal>` (no jQuery; `data-bs-*` toggles) with `@slot('trigger'|'content'|'footer')`.
  - `<x-alert type="success|error|warning|info">` (no jQuery).
  - `<x-label>`, `<x-badge-status>`, `<x-validation-error>`.
  - `@include('layouts.partials.empty-state', ['message' => '...'])` for empty lists.

Reference admin CRUD pages that B12-UI is expected to mirror stylistically:

- `resources/views/admin/roles/index.blade.php` (index with search filter and `@can('roles.manage')` gates).
- `resources/views/admin/roles/create.blade.php` (form card with `<x-text-input>` and grouped permission checkboxes).
- `resources/views/admin/roles/edit.blade.php` (edit form pattern).

No breadcrumb convention is currently active in the admin views; the sidebar is the only navigation backbone for B08 modules. The placeholder automation views also use no breadcrumbs.

---

## 3. Data model

Schema authoritative under `database/migrations/2026_08_18_0100{00..60}_*.php`. All seven tables are confirmed-migrated and tested by `AutomationEngineTest`. Column types:

| Table | Column | Type | UI read | UI write | UI hide |
|---|---|---|---|---|---|
| `automation_rules` | `id` | bigint pk | yes | — | — |
| | `name` | string(191) | yes | yes | — |
| | `description` | text nullable | yes | yes | — |
| | `trigger_event` | string(191) | yes | yes | — |
| | `is_active` | bool | yes | yes | — |
| | `order` | unsigned int | yes | yes | — |
| | `mode` | string(16) — `live`/`test` | yes | yes | — |
| | `created_by` | fk users nullable | yes (via `creator()`) | set on create from `auth()->id()` | — |
| | `owner_id` | fk users nullable | yes | yes | — |
| | `timestamps`, `deleted_at` | datetime | yes | — | — |
| `automation_condition_groups` | `id, rule_id, logical_operator` (`AND`/`OR`), `position`, `timestamps` | — | yes | yes (driven from the rule form) | — |
| `automation_conditions` | `id, group_id, rule_id, field, operator, value, value_type, position, timestamps` | — | yes | yes (operator list from `ConditionOperator::values()`) | — |
| `automation_actions` | `id, rule_id, position, type, channel, recipient_strategy, payload_json, retry_policy_json, is_active, timestamps` | — | yes | yes (type from `ACTION_TYPES`, `payload_json` per action) | — |
| `automation_executions` | `id, rule_id, trigger_event, subject_type, subject_id, idempotency_key (UNIQUE), status, attempt, started_at, finished_at, error_class, error_message, timestamps` | — | yes (history list + drill-down) | — | `idempotency_key` should be **visible** to admins as diagnostic; do not edit. |
| `automation_execution_steps` | `id, execution_id, action_id, status, attempt, response_json, queued_at, started_at, finished_at, error_class, error_message, timestamps` | — | yes (drill-down) | — | — |
| `automation_cycle_breaks` | `id, rule_id, subject_type, subject_id, reason, detected_at, timestamps` | — | yes (drill-down) | — | — |

Indexes already present:

- `automation_rules`: `(trigger_event, is_active)`, `owner_id`.
- `automation_executions`: `(rule_id, status)`, `(subject_type, subject_id)`, `(status, created_at)`, **UNIQUE `idempotency_key`**.
- `automation_cycle_breaks`: `(rule_id, subject_type, subject_id)`.

`mode` semantics (`app/Enums/AutomationMode.php` + `DispatchAutomationRule::processRule`):

- `live` → steps are queued via `RunAutomationAction::dispatch($step->id)` and the execution stays in `running` until the Job finalises it.
- `test` → the listener records each step as `AutomationStepStatus::SIMULATED` synchronously, stores the would-be payload in `response_json`, and the execution flips to `success`. **No external call** is issued.

Idempotency key (engine-side, see `DispatchAutomationRule::processRule`):

```
sha1($rule->id . '|' . $eventClass . '|' . $subjectType . '|' . $subjectId . '|' . $payloadHash)
```

where `$payloadHash = substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 32)`. This is enforced by the **UNIQUE** column. The listener catches duplicate-key `QueryException` and silently returns.

Cycle key (engine-side, see `CycleDetector`):

- Window: 30 s (`DEFAULT_WINDOW_SECONDS`).
- Cycle test: an `AutomationExecution` row exists for `(rule_id, subject_type, subject_id)` with `created_at >= now() - 30s`.
- The listener still creates the row (or reuses the latest one) and calls `recordBreak()` which writes to `automation_cycle_breaks` and marks the execution `circuit-broken`.

Race window: the cycle check, idempotency insert, and step dispatch happen sequentially in the same listener invocation. Two concurrent events racing the same `(rule, subject)` may both pass the `isCycling()` check; the second one fails the UNIQUE on `idempotency_key` and returns. Three-step race risk: `event → cycle_check → insert → dispatch`. The only realistic re-entry is when an action's side-effect re-emits the same event within 30 s, which is exactly what the cycle window covers.

---

## 4. Action configuration surface

The 11 action classes declare their payload shapes in PHPDoc. `automation_actions` columns available to the UI form layer: `type, channel, recipient_strategy, payload_json (JSON), retry_policy_json (JSON), position, is_active`.

Per-type UI mapping (UI surfaces that benefit from a dedicated form/section):

| Action | Columns worth a dedicated form | Payload JSON fields the UI must render | Hidden engine fields |
|---|---|---|---|
| `webhook` | URL (must be in `config('integrations.webhooks.allowed_destinations')`), method, secret/HMAC (future), is_active | `url, method, body, headers` | — |
| `assign_owner` | recipient_strategy (radio/select), user picker, team picker | `user_id, team_id` | — |
| `send_email` | recipient picker, template selector (B13), subject, body (textarea/Markdown), `queue` toggle | `to, subject, body, queue` | — |
| `send_whatsapp_template` | template selector (B14), phone_number, language, variables editor | `template_name, phone_number, language, variables, account_id` | B14 credentials |
| `send_notification` | user picker, title, body, level | `user_id, title, body, level` | — |
| `change_status` | subject-type-aware column dropdown, value (status id / stage id / enum string) | `value, column` | — |
| `change_stage` | `PipelineStage` selector (slug), note | `stage_slug, note` | — |
| `add_tag` | tag picker (slug + optional color), `tag_name` for auto-create | `tag_slug, tag_name, color` | — |
| `create_activity` | `ActivityType` selector, title, description, scheduled_at, priority, owner | `type_id, title, description, scheduled_at, priority, owner_id` | — |
| `create_follow_up_activity` | same as `create_activity` + **required** `next_scheduled_at` | `type_id, title, next_scheduled_at, description, priority, owner_id` | — |
| `add_note` | body (textarea), priority, owner | `body, priority, owner_id` | — |

Notes for the UI form layer:

- `recipient_strategy` and `channel` are **columns** on `automation_actions`, not inside `payload_json`. Treat them as first-class fields.
- `retry_policy_json` is a column but no action class currently reads it; the run/retry policy is hard-coded in `RunAutomationAction` (`tries=3`, `backoff=[30,120,600]`). The column is reserved for future per-action overrides and the UI may surface it as read-only or hide it.
- `payload_json` is opaque to the engine; the UI should validate JSON shape per selected action type before saving.
- Several actions resolve their target via `User::query()->first()` as a fallback actor (see `ChangeStatusAction`, `ChangeStageAction`, `AddNoteAction`, `CreateActivityAction`, `CreateFollowUpActivityAction`). This is an engine limitation that affects audit trail fidelity but not the UI shape.

---

## 5. Triggers and events

### 5.1 Trigger catalog (19 events)

| FQCN | Subject | Source |
|---|---|---|
| `App\Events\V2\LeadCreated` | `Lead` | service: `LeadService::create()` |
| `App\Events\V2\LeadAssigned` | `Lead` | service: `LeadService::assign()` |
| `App\Events\V2\LeadStatusChanged` | `Lead` | service: `LeadService::update()` |
| `App\Events\V2\LeadDeactivated` | `Lead` | service: `LeadService::deactivate()` |
| `App\Events\V2\LeadConverted` | `Lead` | service: `LeadConversionService::convert()` |
| `App\Events\V2\OpportunityCreated` | `Opportunity` | service: `OpportunityService` |
| `App\Events\V2\OpportunityStageChanged` | `Opportunity` | service: `OpportunityService::changeStage()` |
| `App\Events\V2\OpportunityWon` | `Opportunity` | service: `OpportunityService::markAsWon()` |
| `App\Events\V2\OpportunityLost` | `Opportunity` | service: `OpportunityService::markAsLost()` |
| `App\Events\V2\QuotationCreated` | `Quotation` | service: `QuotationService` |
| `App\Events\V2\QuotationSent` | `Quotation` | service: `QuotationService::send()` |
| `App\Events\V2\QuotationAccepted` | `Quotation` | service: `QuotationService::accept()` |
| `App\Events\V2\ActivityCompleted` | `Activity` | service: `ActivityService::markAsCompleted()` |
| `App\Events\V2\ContactPrimaryChanged` | `Contact` | service: `ContactService` |
| `App\Events\V2\ContactDeactivated` | `Contact` | service: `ContactService::deactivate()` |
| `App\Events\V2\CustomerDeactivated` | `Customer` | service: `CustomerService::deactivate()` |
| `App\Events\V2\TimeDriven\QuotationWillExpire` | `Quotation` | time-driven: `automation:emit-quotation-will-expire` |
| `App\Events\V2\TimeDriven\ActivityOverdue` | `Activity` | time-driven: `automation:emit-activity-overdue` |
| `App\Events\V2\TimeDriven\CustomerIdle` | `Customer` | time-driven: `automation:emit-customer-idle` |

### 5.2 How the listener matches a trigger

`DispatchAutomationRule::handle()` does:

```
AutomationRule::query()
    ->active()
    ->forTrigger($event::class)   // matches `trigger_event` VARCHAR(191)
    ->ordered()
    ->with(['conditionGroups.conditions', 'actions'])
    ->get();
```

`trigger_event` is therefore the **literal FQCN** of the event class (`App\Events\V2\LeadCreated`, `App\Events\V2\TimeDriven\QuotationWillExpire`, …). The UI dropdown must offer those exact strings; the proposal may want to expose them with a friendlier label keyed off `class_basename($fqcn)`.

### 5.3 Domain-event payload shape

`LeadCreated::payload()` (representative):

```php
[
    'code', 'person_type', 'interest_level', 'status_id', 'owner_id',
    'source_id', 'ubigeo_code', 'email', 'phone', 'company_name',
]
```

`QuotationCreated::payload()` exposes `number, status, owner_id, lead_id, customer_id, opportunity_id, total, currency_code, expires_at`. Time-driven payloads add context fields (`days_until_expiry`, `look_ahead_days`, `idle_days`, `days_overdue`). `payload()` is what `ConditionEvaluator::matches()` reads through dot-path resolution.

---

## 6. Livewire conventions

- **Version**: `composer.json` pins `livewire/livewire: ^4.4` (Livewire 4).
- **No current Livewire components**: `app/Livewire` does not exist; `resources/views/livewire` does not exist. No `use Livewire`, `@livewire`, or `livewire:` reference anywhere in `app/` or `resources/views/` (only vendor source).
- **No Livewire tests** under `tests/`. `composer.json` test runner is `php artisan test`; `phpunit.xml` configures `Unit` and `Feature` suites against `sqlite :memory:` with `QUEUE_CONNECTION=sync`. B12-UI is the first block to introduce Livewire components and their tests; the config-recorded convention is `Livewire::test(Component::class)` with `set()`, `call()`, `assertSet()`, `assertHasErrors()`, `assertDispatched()`, plus HTTP route/gate coverage around the host.
- **Shared testing conventions** (already in place; reusable):
  - `Tests\TestCase` (extends `Illuminate\Foundation\Testing\TestCase`) — abstract.
  - `RefreshDatabase` trait, `Database\Seeders\RolesAndPermissionsSeeder`, `Database\Seeders\SettingsSeeder`, `User::factory()`, explicit `assignRole('admin')`, `actingAs()`.
  - `Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Event::fake()` are available per the recorded conventions.

---

## 7. Reusable services for the UI

| Service / class | What the UI can reuse |
|---|---|
| `App\Services\Automation\ActionRegistry` | `registered()` returns the canonical `[type => class]` map — use it to populate the action-type selector and to gate which actions are user-creatable. |
| `App\Services\Automation\ConditionEvaluator` | The evaluator itself is engine-internal; the UI should not call it. But its operator + value-type lists come from `App\Enums\ConditionOperator::values()` and the `coerce()` mapping — surface those as the operator/value-type dropdowns. |
| `App\Services\Automation\CycleDetector` | Engine-internal; expose `canMutateSubject($type)` and `DEFAULT_WINDOW_SECONDS` (as a label) in the UI to warn users about re-entry risk. |
| `App\Models\AutomationRule` | `scopeActive`, `scopeForTrigger`, `scopeOrdered`, `isLiveMode()`. The UI list query in `AutomationController::index()` already uses `withCount('executions')` — replicate that pattern. |
| `App\Models\AutomationExecution` / `AutomationExecutionStep` / `AutomationCycleBreak` | Read-only for the UI; use the existing eager-load patterns from the placeholder controller (`$execution->load(['steps.action'])`). |
| `App\Services\DataScopeService` | **MUST be honored** by the assignment action form — the UI should pre-filter the user/team pickers using `visibleOwnerIds($user)` so the action never raises a permission error at runtime. |
| `App\Enums\AutomationExecutionStatus::label()` / `AutomationStepStatus::label()` | Reuse for badges. |
| `App\Providers\AutomationServiceProvider::ACTION_TYPES` | Single source of truth for action slugs and classes; expose as a static array, do not duplicate. |
| `App\Providers\AutomationServiceProvider::TRIGGER_EVENTS` | Canonical list of trigger FQCNs; use it to drive the trigger selector. |

UI logic that **must** live outside the engine (no engine coupling):

- Form rendering and Livewire reactive state for rule/condition/action editing.
- Validation of `payload_json` shape per action type (the engine only enforces per-class `InvalidArgumentException`s at execute time).
- Diffing the rule form before save (engine does not preserve history; UI may want a "draft" notion, but B12 engine has no draft state).
- Localised labels for trigger events and action types — the engine carries no UI metadata beyond `AutomationExecutionStatus::label()` and `AutomationStepStatus::label()`.

---

## 8. Constraints and gotchas

1. **Mode semantics** (`app/Enums/AutomationMode.php`, `DispatchAutomationRule::processRule`): a rule with `mode='test'` **never** enqueues a real `RunAutomationAction` job; every step is stored with `status=simulated` and the execution finishes synchronously as `success`. The UI must clearly distinguish the two modes when listing or editing rules and must not pretend a `test` execution produced real side effects.
2. **Idempotency key** (`DispatchAutomationRule::processRule`): `sha1(rule_id|event_class|subject_type|subject_id|payload_hash)` where `payload_hash = substr(sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 0, 32)`. The `automation_executions.idempotency_key` column is **UNIQUE**; duplicate inserts raise `QueryException` which the listener swallows. UI must not edit this column.
3. **Three-step race window**: `event → cycle_check (30 s) → idempotency insert → step dispatch`. Two workers can pass the cycle check on the same `(rule, subject)` tuple; the second worker is resolved by the UNIQUE on `idempotency_key`. There is no `Cache::lock` (the roadmap §1.3 mentions it as a follow-up; the current engine relies on the DB UNIQUE only).
4. **Webhook allow-list** (`config/integrations.php:212`, `WebhookAction::isAuthorized`): `INTEGRATIONS_WEBHOOK_ALLOWED` env var, comma-separated full URLs, default empty = deny. The UI must surface this allow-list in the webhook form (a select or readonly display) because URLs not in it will throw `WebhookNotAuthorizedException` at runtime.
5. **AssignOwnerAction** (`AssignOwnerAction::execute`): the action reads `DataScopeService::visibleOwnerIds($rule->created_by)` and rejects the target user if not in the visible set. UI user/team pickers must be pre-filtered by the same scope to avoid surfacing unattainable choices. Note: there is also a known **operator-precedence bug** in `AssignOwnerAction::execute` — the guard reads `! $this->dataScope->visibleOwnerIds($creator) === false` which is `! (true) === false === false`. The condition is always false, so the visibility check is **dead code** today. B12-UI does not have to fix it but must not rely on the engine-side check; do the filtering in the UI.
6. **WhatsApp and Webhook stubs**: both throw rather than run. The UI should label them "Pendiente (B14)" in the action type list and warn admins that the action will produce a `NotImplementedException` step error.
7. **No rules engine for `subject_type`-aware column resolution**: `ChangeStatusAction` infers the column from `subject_type` but also accepts an explicit `column` override. The UI must offer a per-subject-type selector that matches `LeadStatus`, `Customer.status` (enum), or `PipelineStage`. Letting users pass an arbitrary column opens up data corruption.
8. **Tags auto-create**: `AddTagAction` creates a `Tag` row if `tag_slug` is missing. The UI may want a checkbox "crear si no existe" to make that explicit.
9. **Activity auto-create**: `AddNoteAction` creates the `ActivityType` row `'nota'` if missing. Same caveat.
10. **No event bus for inbound webhooks**: the engine has no inbound webhook listener — only outbound `webhook` actions. UI must not offer a "trigger on incoming webhook" option.
11. **Permissions in service provider, not in DB seeders**: `AutomationServiceProvider::registerAutomationPermissions()` registers the 5 B12 permissions at boot, but only if the Spatie permissions table exists. The seeders only assert 84 permissions exist (the B12 ones are added at runtime). Tests therefore must boot the provider before asserting; the existing `AutomationEngineTest` works because `RefreshDatabase` runs the migrations and the provider's `boot()` runs during `setUp()` indirectly. The UI test base class must do the same.
12. **Scheduler timezone**: every scheduled command is `America/Lima`. The UI history list should display timestamps in this timezone (or normalise to user TZ).
13. **`recipient_strategy` is both a column and a payload key** in `AssignOwnerAction`. The UI form should keep them in sync.
14. **No breadcrumbs or page-header pattern** is used by any current B08 admin view; the automation placeholder also skips them. The proposal can choose to introduce one without breaking convention, but it is not a constraint.
15. **Composer json constraint**: `livewire/livewire: ^4.4` — Livewire 4 component class style (`#[Layout]`, `#[Computed]`, `#[On]`) is available.

---

## 9. Open questions for the product round

> The product-round questions below are framed for the sdd-proposal stage.
> B12-UI does **not** answer them in this exploration.

1. **CRUD surface** — which rule fields must be editable in the admin UI (just `name/description/is_active/order/mode`, or also `trigger_event`, `created_by`, `owner_id`)? Must `trigger_event` itself be selectable per rule, or only configured at seed time?
2. **Condition authoring** — does the admin need to author `AutomationConditionGroup`/`AutomationCondition` rows from the UI, or is it acceptable to seed conditions through fixtures? If authored, which subset of `ConditionOperator` should the UI expose by default? How should `value_type` be inferred vs. forced?
3. **Action authoring** — same question for `AutomationAction`: full per-type form, a JSON textarea, or a hybrid (type-specific UI for the high-volume actions, JSON for the rest)? Which of the 11 actions should be admin-creatable vs. reserved for power users (or even read-only)?
4. **Test-mode preview** — when an admin opens an action form, should the UI offer a "simulate now" button that calls `ActionContract::simulate()` and shows the would-be payload? Permission gate (`automations.test`) is registered but unused.
5. **History controls** — is read-only history sufficient, or should admins be able to filter executions by status, replay failed executions, or manually trigger a run (forcing a new event with a synthetic payload)? Permission gate (`automations.webhook.execute`) is registered but unused.
6. **Bulk operations** — should the UI allow enabling/disabling or re-ordering rules in bulk, or always one rule at a time?
7. **Soft-delete UX** — `AutomationRule` uses `SoftDeletes`. Should deleted rules be visible in a "Papelera" tab, or hidden entirely?
8. **Manual event emission** — should the UI expose any of the `automation:emit-*` commands (ActivityOverdue / QuotationWillExpire / CustomerIdle) as admin-triggered actions, or are they strictly scheduled?
9. **Audit visibility** — `automations.audit` is registered but unused. Should the B12-UI history view surface Spatie Activitylog entries for rule changes, or rely on the existing `audit.view` viewer?
10. **Test-mode behaviour in the history view** — when a `mode='test'` execution shows `status=success` but `response_json` only describes what would have happened, what badge/icon does the UI use to communicate that to admins?
11. **Per-rule retry override** — `retry_policy_json` is reserved but unread by the engine. Should the UI still let admins set it (knowing the engine ignores it), or hide it until the engine reads it?
12. **Idempotency key visibility** — column is `UNIQUE` but the engine never lets admins trigger duplicate events. Should the UI show the key in the execution detail view (helpful for debugging), or hide it as an implementation detail?

---

## Quick cross-reference

- Authoritative V2 context: `docs/v2/01-roadmap.md` (C-01..C-06, D-21), §2.1 = B12 schema source of truth.
- Service provider that wires the engine: `app/Providers/AutomationServiceProvider.php`.
- Engine test: `tests/Feature/AutomationEngineTest.php` (10 tests, 21 assertions; passes under `php artisan test --filter=AutomationEngineTest`).
- Routes: `routes/web.php` lines 375-380.
- Sidebar entry already wired: `resources/views/layouts/partials/sidebar.blade.php` line ~92 (`@can('automations.view')`).
