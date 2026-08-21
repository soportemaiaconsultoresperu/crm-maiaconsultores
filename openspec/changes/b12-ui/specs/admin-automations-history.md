# Admin Automations — Execution History

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (§1.3 read-only models, §1.4 services, §3 data model + indexes, §8 gotchas 1 + 2 + 7 + 8) and `openspec/changes/b12-ui/proposal.md` (§4 reglas 1–2, §7.11 historial filtrable, §7.12 detalle de ejecución, §7.13 audit contextual, §10 #1 + #5 + #6 + #8).
> Pair with: `admin-automations-crud.md` (rule host), `admin-automations-permissions.md` (gate for audit + test), `admin-automations-ui-conventions.md` (badges + timeline).

---

## Purpose

Specify the read-only execution history surface for `AutomationRule` rows: a paginated + filtered executions list, a per-execution detail page with steps and `idempotency_key`, a contextual `Spatie\Activitylog` audit block, and the test-mode badge contract. The history layer MUST NOT mutate any engine data — it is a projection only (proposal §10 #1, "NO replay, NO manual trigger").

---

## Requirements

### REQ-HIST-01 — Executions list (per rule)

The system SHALL render `admin.automations.show` as: a header with rule metadata + clone/edit/toggle/trash buttons, followed by an `<x-table title="Ejecuciones recientes">` listing `AutomationExecution` rows paginated at 20 per page with columns `id`, `status` (badge via `AutomationExecutionStatus::label()`), `trigger_event` (FQCN), `subject_type` + `subject_id` (combined cell), `started_at` + `finished_at` (TZ `America/Lima`), `attempt`, and a link to `admin.automations.executions.show`. Each row SHALL carry a purple "test-mode" pill when the parent rule's `mode='test'` (REQ-HIST-05).

### REQ-HIST-02 — Filters

The system SHALL expose a `?status=`, `?date_from=`, `?date_to=` (both `Y-m-d`), and `?subject=` (free text matched against `subject_type` or `subject_id`) filter row above the executions table. All filters are `AND`-combined. Resetting the filter clears the URL query. Filters SHALL persist in pagination links.

### REQ-HIST-03 — Empty state

The system SHALL render `@include('layouts.partials.empty-state', ['message' => 'Aún no hay ejecuciones registradas para esta regla'])` when the executions list is empty after filters (proposal §9.2).

### REQ-HIST-04 — Execution detail

The system SHALL render `admin.automations.executions.show` with: rule breadcrumb, an `<x-alert>` summary block with `status` + `trigger_event` + `subject_type` + `subject_id` + `idempotency_key`, plus a `<x-table title="Pasos">` listing `AutomationExecutionStep` rows eager-loaded with `action` (one-to-one) showing `position`, action `type` (rendered via `class_basename`), `status` (badge via `AutomationStepStatus::label()`), `attempt`, `started_at`/`finished_at`, and an expanded row showing `response_json` in `<pre><code class="font-monospace">`. When `status ∈ {failed, partial, circuit-broken}`, the same expanded row ALSO shows `error_class` + `error_message` inside a red `<x-alert type="error">`.

### REQ-HIST-05 — Test-mode badge contract

The system SHALL render a purple (Bootstrap `bg-purple`/`text-bg-purple`) badge reading `Modo test` next to any `AutomationRule` with `mode='test'` in (a) the index, (b) the rule's `show` page header, (c) each execution row, and (d) the execution detail header. The badge SHALL carry `title="Modo test: simuló, no ejecutó acciones reales"` and `data-bs-toggle="tooltip"` so admins see the literal tooltip text on hover (proposal §10 #6 + AC-7).

### REQ-HIST-06 — `idempotency_key` visibility + copy

The system SHALL display `AutomationExecution::idempotency_key` in a `<code class="user-select-all font-monospace">` element on the execution detail page with a sibling "Copiar" button that calls `navigator.clipboard.writeText(...)` and shows a 2-second `<x-badge-status>` toast "Copiado". The value MUST be the literal stored string; the field is read-only (explore §8.2 + AC-8).

### REQ-HIST-07 — Cycle-break surfacing

When an execution row is linked to one or more `AutomationCycleBreak` rows (explore §1.3, indexed by `(rule_id, subject_type, subject_id)`), the system SHALL render an extra badge "Re-entry detectado dentro de 30 s" (`title="Cycle window: 30 s"`) and SHALL list the break rows in a collapsed `<details>` block below the steps table, displaying each `reason` + `detected_at` (proposal §9.8).

### REQ-HIST-08 — Audit contextual block

The system SHALL render a "Cambios" section on `admin.automations.show` listing `Spatie\Activitylog` entries recorded against the `AutomationRule` model, newest-first, paginated at 10 per page. The block MUST be wrapped in `@can('automations.audit')`. Without the permission, the section is not rendered at all (proposal §4 + AC-9). The global `audit.view` page is NOT reused.

### REQ-HIST-09 — Runtime exception surfacing

The system SHALL surface engine-side exceptions (explore §1.5 + §8.4 + §8.5) by passing `error_class` through `<x-alert>` with the exception short-name (`NotImplementedException`, `WebhookNotAuthorizedException`, `InvalidArgumentException`, `Throwable`). The text SHALL be the literal `error_message` from the row, escaped.

### REQ-HIST-10 — Time zone normalization

All timestamps SHALL be formatted with `America/Lima` (explore §8.12) via a dedicated Blade directive or a single helper so the controller and view never drift.

---

## Scenarios

#### SCN-HIST-01 — Real execution appears in history

- GIVEN a `live` rule with `is_active=true`, an event is dispatched, `DispatchAutomationRule` creates an `AutomationExecution` (explore §5.2)
- WHEN the admin opens `admin.automations.show`
- THEN the new row appears with status reflecting the listener outcome (AC-2); `response_json` for live steps is empty until the action writes it.

#### SCN-HIST-02 — Filter narrows results

- GIVEN 50 executions with mixed statuses
- WHEN the admin filters `status=failed&date_from=2026-01-01`
- THEN only failed executions on or after the date are paginated; clicking pagination retains the filters in the query string.

#### SCN-HIST-03 — Idempotency key visible and copyable

- GIVEN an execution with `idempotency_key="abc123…"`
- WHEN the admin opens detail
- THEN the key shows monospace + "Copiar" button; clicking it places the literal in the clipboard (AC-8). The field is NOT editable.

#### SCN-HIST-04 — Test-mode badge with exact tooltip

- GIVEN a rule with `mode='test'`
- WHEN the admin views the index, the rule's `show`, or any execution row/detail
- THEN a purple badge "Modo test" is rendered with `title="Modo test: simuló, no ejecutó acciones reales"` (AC-7, exact copy).

#### SCN-HIST-05 — Audit section gated

- GIVEN a user with `automations.view` + `automations.manage` but no `automations.audit`
- WHEN they open a rule's `show`
- THEN the "Cambios" block is absent (AC-9); adding the permission makes it reappear on next request.

#### SCN-HIST-06 — Cycle-break surfaces

- GIVEN an execution with two events entering inside 30s (explore §8.3 + explore §3)
- WHEN the admin opens the detail
- THEN the second execution (status=`circuit-broken`) carries the badge and a collapsed list of `AutomationCycleBreak` rows from `automation_cycle_breaks`.

#### SCN-HIST-07 — Empty execution list

- GIVEN a rule with `executions_count === 0`
- WHEN the admin opens `show`
- THEN the table is replaced by the empty-state partial.

#### SCN-HIST-08 — Stub action failure surfaced

- GIVEN a rule with `mode='live'` and a `webhook` action with `is_active=true`
- WHEN the listener fires
- THEN the execution lands with `status=failed`, and the detail page renders a red `<x-alert>` quoting `error_class=NotImplementedException` verbatim.

---

## Affected routes

| Method | URI | Name | Permission |
|---|---|---|---|
| GET | `/admin/automations/{automation}` | `admin.automations.show` | `automations.view` |
| GET | `/admin/automations/{automation}/executions/{execution}` | `admin.automations.executions.show` | `automations.view` |

These already exist on `routes/web.php` lines 375–380 (explore §2.1) and stay read-only with the existing `Gate::authorize('automations.view')` enforcement.

---

## Cross-references

- Proposal: §4 reglas 1–2, §7.11 historial filtrable, §7.12 detalle de ejecución, §7.13 audit contextual, §9.2 + §9.7 + §9.8 + §9.12 edge cases, §10 #1 + #5 + #6 + #8 locked, AC-2 / AC-7 / AC-8 / AC-9.
- Explore: §1.3 (`AutomationExecution` + `AutomationExecutionStep` fillable, no scopes), §1.4 (CycleDetector + exceptions), §2.1 (existing routes), §3 (UNIQUE `idempotency_key`, indexes), §8.1 (mode semantics), §8.2 (idempotency formula), §8.3 (cycle window), §8.4 (webhook allow-list), §8.5 (AssignOwner dead code), §8.6 (stubs), §8.12 (America/Lima).
- Config: `openspec/config.yaml` — Spatie Activitylog contract.
