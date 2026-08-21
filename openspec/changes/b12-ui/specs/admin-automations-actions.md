# Admin Automations — Action Forms

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (§1.5 action classes + PHPDoc payload schemas, §1.4 `ActionRegistry`, §7 reusable services, §8 gotchas 4–6 + 11–13) and `openspec/changes/b12-ui/proposal.md` (§4 reglas 3–4, §7.7 forms per-type, §7.8 stubs, §7.9 simulate-now, §7.14 retry_policy hidden).
> Pair with: `admin-automations-crud.md` (rule form host), `admin-automations-permissions.md` (gate mapping), `admin-automations-ui-conventions.md` (Livewire + components).

---

## Purpose

Specify the per-type action editor for the 11 `ActionContract` implementations under `app/Services/Automation/Actions/*`, the `payload_json` shape contract for each, the B14 stub banner, the unified `recipient_strategy` control for `assign_owner`, the `DataScope` pre-filter, the webhook allow-list surface, the simulate-now wiring, and the intentional omission of `retry_policy_json`.

---

## Requirements

### REQ-ACT-01 — Action list editor

The system SHALL render an ordered list of `AutomationAction` rows inside the Livewire rule form, with per-row `position`, `type` (select populated from `ActionRegistry::registered()` — explore §1.4), `is_active` toggle, drag-to-reorder handle, "Eliminar" button (gated `automations.manage`), and a per-type widget area below that swaps based on `type`. New rows default to `type='assign_owner'` (the engine's most-tested path) and `is_active=true`.

### REQ-ACT-02 — Per-type widget matrix

The system SHALL render the type-specific widgets below. Each widget SHALL populate `payload_json` (and the first-class columns `channel`, `recipient_strategy` where applicable) per the engine PHPDoc (explore §1.5). The matrix is the source of truth for sdd-apply.

| Type slug | First-class columns | Required `payload_json` keys | Widget summary |
|---|---|---|---|
| `create_activity` | — | `type_id, title, next? scheduled_at, next? priority (default 'media'), next? owner_id` | `ActivityType` selector, title text, description textarea, datetime input (optional), priority select, owner picker. |
| `create_follow_up_activity` | — | `type_id, title, next_scheduled_at, next? description, next? priority, next? owner_id` | Same as `create_activity` but `next_scheduled_at` is **required**. Inline `<x-validation-error>` if missing. |
| `assign_owner` | `recipient_strategy` | `user_id? (when strategy=user), team_id? (when strategy in [team, round_robin])` | `recipient_strategy` segmented control (user/team/round_robin/current); user picker filtered by `DataScopeService::visibleOwnerIds($creator)`; team picker similarly filtered. The form SHALL keep `column.recipient_strategy` and `payload_json.recipient_strategy` in lockstep (explore §8.13). |
| `change_status` | — | `value (required), column?` | Column selector — default per subject_type (`status_id` for `Lead`, `status` for `Customer`, `stage_id` for `Opportunity`); value selector from the matching status model. Free-text override only when the rule form opens a "custom column" advanced toggle, gated behind a warning. |
| `change_stage` | — | `stage_slug (required), next? note` | `PipelineStage` selector (slug); note textarea. Subject is implicit (`Opportunity`). |
| `add_tag` | — | `tag_slug (required), next? tag_name, next? color` | Tag selector, optional "crear si no existe" checkbox that flips `tag_name` editable (explore §8.8). |
| `send_notification` | — | `user_id?, title, body, next? level (info|warning|error)` | User picker, title, body textarea, level select (default `info`). |
| `send_email` | — | `to, subject, body, next? queue (bool, default true)` | Recipient, subject, body (textarea/Markdown), queue toggle. B13 introduces a real template catalog; B12-UI v1 leaves `subject`/`body` as literals. |
| `send_whatsapp_template` | — | `template_name, phone_number, next? language, next? variables, next? account_id` | B14 stub banner; `account_id` text input; `variables` rendered as a repeating key/value row pair. |
| `add_note` | — | `body, next? priority, next? owner_id` | Body textarea (required), priority select, owner picker. Engine auto-creates the `ActivityType` slug `'nota'` on first run (explore §8.9); a non-blocking info note explains that. |
| `webhook` | — | `url (required, must be in allow-list), next? method (GET|POST|PATCH, default POST), next? body, next? headers` | B14 stub banner; `url` rendered as a `<x-select>` of `config('integrations.webhooks.allowed_destinations')` (explore §8.4); method + body + headers. Header is a repeating key/value pair. |

### REQ-ACT-03 — `recipient_strategy` unified control

The system SHALL treat `recipient_strategy` as a single widget. On change, the form SHALL write the chosen value to BOTH `automation_actions.recipient_strategy` AND `automation_actions.payload_json['recipient_strategy']` so `AssignOwnerAction::execute` reads consistently (explore §8.13). The selected strategy SHALL hide unapplicable pickers (e.g. choosing `team` hides the user picker; choosing `current` hides both).

### REQ-ACT-04 — `DataScope` pre-filter for assign_owner

The system SHALL build the user and team picker options using `DataScopeService::visibleOwnerIds($rule->created_by)` (when the rule exists) or `DataScopeService::visibleOwnerIds(auth()->id())` (when authoring a new rule). Empty result SHALL show an info alert "No hay usuarios visibles según el DataScope del creador". This compensates the operator-precedence bug in `AssignOwnerAction::execute` (explore §8.5) — the engine-side check is currently dead code and not relied on.

### REQ-ACT-05 — Webhook allow-list surface

The system SHALL read `config('integrations.webhooks.allowed_destinations')` (explore §8.4) and render the values as `<x-select>` options for the `webhook` action. When the config is empty, the system SHALL show a red `<x-alert type="warning">` "Sin URLs autorizadas" and disable the save action for that row. Saved actions whose `url` later disappears from config SHALL NOT be auto-mutated; they fail at execute time as `WebhookNotAuthorizedException`.

### REQ-ACT-06 — B14 stub banners

The system SHALL render the banner "Pendiente (B14) — la acción fallará con `NotImplementedException` hasta B14" inside both `webhook` and `send_whatsapp_template` form widgets. The banner SHALL render BEFORE the widget inputs. The form SHALL still save normally; the runtime behaviour is documented (proposal §9.12) and the index SHALL show a small gray "B14 stub" pill next to any rule that contains one of those action types.

### REQ-ACT-07 — Simulate-now per action

The system SHALL render a "Simular ahora" button inside each action's widget, gated `@can('automations.test')`. Clicking dispatches a `POST admin.automations.{automation}.actions.{action}.simulate` carrying a `payload` array the admin authors in a textarea. The system SHALL call `ActionRegistry::resolveForAction($action)->simulate($payload)` (explore §1.4) and SHALL render the returned array inside a `<x-modal>` in monospace. If the action throws (`NotImplementedException`, `WebhookNotAuthorizedException`, `InvalidArgumentException`), the modal SHALL display the exception class + message in a red `<x-alert type="error">` and SHALL NOT mark the action as executed (no `AutomationExecution` row is written).

### REQ-ACT-08 — `retry_policy_json` hidden

The system SHALL NOT render any field that writes to `automation_actions.retry_policy_json` (proposal §10 #7). Grep across the B12-UI views MUST return zero matches for `retry_policy`. The column exists (explore §4) but the engine hard-codes `tries=3, backoff=[30,120,600]` and ignores the field.

### REQ-ACT-09 — Drag-to-reorder of actions

The system SHALL allow drag-to-reorder of `AutomationAction` rows via Livewire 4 (same pattern as conditions, REQ-COND-04). Positions are persisted atomically.

---

## Scenarios

#### SCN-ACT-01 — Add an `assign_owner` action to a new rule

- GIVEN the Livewire rule form, the admin picks `type=assign_owner`
- WHEN they pick `recipient_strategy=user`, choose a user from the DataScope-filtered picker, then save
- THEN the row has `type='assign_owner'`, `recipient_strategy='user'` on the column AND inside `payload_json`, plus `payload_json.user_id = <id>`; no `team_id`; AC-5 verified because the picker excludes users outside `visibleOwnerIds($creator)`.

#### SCN-ACT-02 — Simulate-now returns would-be payload

- GIVEN a `assign_owner` action row
- WHEN an admin with `automations.test` clicks "Simular ahora" with `payload={"subject_type": "Lead", "subject_id": 42}`
- THEN the endpoint calls `AssignOwnerAction::simulate(...)`, returns the array, and the modal renders it monospace (AC-3 satisfied).

#### SCN-ACT-03 — Simulate blocked when permission missing

- GIVEN a user with `automations.manage` only (no `automations.test`)
- WHEN they load the edit page
- THEN the "Simular ahora" button is not rendered; a direct POST to the simulate endpoint returns 403 (proposal §4 + edge case §9.5).

#### SCN-ACT-04 — Stub banner persists even after save

- GIVEN a saved rule with a `webhook` action
- WHEN the admin re-opens edit
- THEN the B14 banner still shows above the widget; the index pill "B14 stub" still renders next to the rule (AC-6 satisfied).

#### SCN-ACT-05 — Webhook allow-list empty disables save

- GIVEN `config('integrations.webhooks.allowed_destinations') = []`
- WHEN the admin opens a `webhook` action widget
- THEN a red warning shows; the action's save button is disabled; submitting still returns 422 with a clear message.

#### SCN-ACT-06 — Retry override is hidden

- GIVEN any action form
- WHEN the admin inspects the DOM
- THEN zero inputs reference `retry_policy_json`; the column keeps its default null (AC-10 satisfied).

#### SCN-ACT-07 — `change_status` picks subject-aware column

- GIVEN `trigger_event` = `App\Events\V2\LeadCreated`
- WHEN the admin adds a `change_status` action
- THEN the column dropdown defaults to `status_id`; the value dropdown lists `LeadStatus` rows only.

#### SCN-ACT-08 — `create_follow_up_activity` requires `next_scheduled_at`

- GIVEN `type='create_follow_up_activity'`
- WHEN the admin tries to save without `next_scheduled_at`
- THEN a validation error is shown: "next_scheduled_at es obligatorio para esta acción".

---

## Affected routes

| Method | URI | Name | Permission |
|---|---|---|---|
| POST | `/admin/automations/{automation}/actions/{action}/simulate` | `admin.automations.actions.simulate` | `automations.test` |
| (sub-route) | in `admin.automations.reorder` | `admin.automations.reorder` | `automations.manage` |

Action create / update / delete are sub-routes of `store` / `update` for the host rule; v1 has no standalone `automations.actions.update` route.

---

## Cross-references

- Proposal: §4 reglas 3–4 (recipient_strategy sync + payload validation), §7.7 forms per-type, §7.8 stubs banner, §7.9 simulate-now, §7.14 retry hidden, §9.4 stub + DataScope edge cases, §10 #3 + #4 + #7 + #8 locked, AC-3 / AC-5 / AC-6 / AC-10.
- Explore: §1.5 (action classes + PHPDoc payload schemas), §1.4 (ActionRegistry + ConditionOperator), §4 (action configuration surface), §7 (ActionRegistry + ConditionEvaluator), §8.4 (webhook allow-list), §8.5 (DataScope bug), §8.6 (stubs), §8.8 (tag auto-create), §8.9 (activity auto-create), §8.11 (permissions registered at boot), §8.13 (recipient_strategy dual write).
- Config: `openspec/config.yaml` — Livewire 4 `strict_tdd`; Spatie Permission contract.
