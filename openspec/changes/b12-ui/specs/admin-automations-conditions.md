# Admin Automations — Condition Builder

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (§1.3 models, §1.2 enums, §5.3 event payload shapes) and `openspec/changes/b12-ui/proposal.md` (§4 regla 4, §7.6 builder visual, §7 tareas de validación).
> Pair with: `admin-automations-crud.md` (rule form host), `admin-automations-ui-conventions.md` (Livewire + components).

---

## Purpose

Specify the visual builder for `AutomationConditionGroup` + `AutomationCondition` rows attached to an `AutomationRule`, so an admin with `automations.manage` can express AND/OR logic in the UI without writing SQL or JSON, matching the engine semantics in `DispatchAutomationRule::handle()` (explore §5.2) and `ConditionEvaluator::matches()`.

---

## Requirements

### REQ-COND-01 — Groups editor

The system SHALL render N condition groups (`AutomationConditionGroup`) per rule in the Livewire rule form. Each group SHALL expose: a header that displays the group's `logical_operator` (AND/OR), a position number, an "Eliminar grupo" button (gated `automations.manage`), and an "Agregar condición" button. The first group's logical operator SHALL be implicitly AND AND SHALL persist as `AND` in DB. The engine treats zero groups as "fire unconditionally" (explore §1.4); the editor SHALL NOT send an empty rule silently — when zero groups exist, an inline `<x-alert type="info">` SHALL explain the engine semantics above the form.

### REQ-COND-02 — Conditions per group

The system SHALL render a list of `AutomationCondition` rows inside each group. Each row SHALL expose four fields: `field` (text input, required), `operator` (select populated from `ConditionOperator::values()` — 16 entries, explore §1.2), `value_type` (select ∈ `string|int|bool|date|datetime|enum|array`, default inferred), and `value` (text/number/date input depending on `value_type`). A "Eliminar condición" button is gated `automations.manage`.

### REQ-COND-03 — Logical operator switch

The system SHALL render an AND/OR switch on every group except the first (whose operator is "between groups" by definition). Selecting OR SHALL persist `logical_operator='OR'` AND SHALL visually de-emphasize conditions matching all-of-AND siblings so users do not confuse intra-group vs inter-group semantics.

### REQ-COND-04 — Drag-to-reorder within a group

The system SHALL allow per-group drag-to-reorder of `AutomationCondition` rows via Livewire 4 `wire:sort` (or `#[On]` listener + JS hook, per project convention). On drop, the system SHALL persist the new `position` column for the affected rows in a single transaction. Cross-group drag is OUT of scope for v1 (reassignment is done via a per-row "Mover a grupo" dropdown).

### REQ-COND-05 — Field autocompletion

The system SHALL provide an autocomplete-style datalist on the `field` input sourced from the static reference `$triggerEvent::payload()` (explore §5.3) of the `trigger_event` selected in the parent rule form. For time-driven events (`App\Events\V2\TimeDriven\*`), the system SHALL merge the standard payload with the contextual fields (`days_until_expiry`, `look_ahead_days`, `idle_days`, `days_overdue`, explore §5.3). Free-typing is allowed; unmatched fields persist literally and rely on `ConditionEvaluator`'s dot-path fallback (explore §1.4).

### REQ-COND-06 — Value type inference

The system SHALL infer `value_type` from the entered `value` (string, int, float, date string parseable by `Carbon::parse`) but SHALL always expose the override dropdown so users can force `enum` or `array`. When `operator` is `in`/`not_in`/`between`, the system SHALL force `value_type` to `array` and render the value as a repeating key/value row pair.

### REQ-COND-07 — Validation of payload-shape invariants

The system SHALL validate on save: (a) every group's conditions count > 0 — empty groups SHALL be auto-removed before persistence with a `<x-alert type="warning">` toast, AND-explained; (b) when `operator` is `is_null` or `is_not_null`, `value` SHALL be nullable and stored as `null`; (c) when `value_type = date|datetime`, `value` SHALL parse as `Carbon::parse` or fail with a 422 + `<x-validation-error>`; (d) when `value_type = bool`, `value` SHALL match `^(true|false|1|0|yes|no)$/i` and be canonicalized to `'1'`/`'0'`.

### REQ-COND-08 — Persistence denormalized `rule_id`

The system SHALL write each new `AutomationCondition` with both `group_id` and `rule_id` populated (the denormalization in `automation_conditions`, explore §1.3) so the engine avoids an extra join when matching. The form MUST always re-hydrate both columns on save.

---

## Scenarios

#### SCN-COND-01 — Authoring a 2-group OR-of-ANDs rule

- GIVEN the rule form open with rule R
- WHEN the admin adds Group 1 (AND) with conditions `status_id eq 2`, `owner_id eq 5`, then Group 2 (OR) with `interest_level in [hot, warm]`
- THEN the saved rows reflect: G1 logical_operator=AND, G2=OR; condition positions are contiguous per group; `rule_id` is denormalized on every child row.

#### SCN-COND-02 — Operator-switch visibility for first group

- GIVEN Group 1 exists
- WHEN the admin inspects the form
- THEN no AND/OR switch is rendered on Group 1's header (engine semantics fixed).

#### SCN-COND-03 — Drag reorder inside a group

- GIVEN Group 1 has 3 conditions in positions 1, 2, 3
- WHEN the admin drags condition at position 1 to position 3
- THEN positions are persisted as 3, 1, 2 (or 2, 3, 1 — equivalent contiguous re-numbering); the new order is visible on the next Livewire tick without a page reload.

#### SCN-COND-04 — Field autocomplete from trigger payload

- GIVEN `trigger_event = App\Events\V2\LeadCreated`
- WHEN the admin clicks the field input
- THEN the datalist shows `code, person_type, interest_level, status_id, owner_id, source_id, ubigeo_code, email, phone, company_name` (explore §5.3).

#### SCN-COND-05 — Operator `is_null` strips value

- GIVEN the admin picks operator `is_null`
- WHEN they save without entering value
- THEN `value = null`, `value_type = 'string'` (or empty), and `ConditionEvaluator` accepts the row.

#### SCN-COND-06 — `value_type=array` required for in/not_in

- GIVEN operator `in`
- WHEN the admin enters a single scalar
- THEN the system rejects with 422 + "Los operadores in/not_in/between requieren múltiples valores" pointing at `value_type`.

#### SCN-COND-07 — Empty group is auto-removed

- GIVEN Group 1 with 0 conditions
- WHEN the admin tries to save
- THEN the empty group is dropped, an info toast says "Grupo vacío eliminado", and the rule saves cleanly without a dangling group row.

#### SCN-COND-08 — Trigger removed from catalog

- GIVEN a rule whose saved `trigger_event` no longer exists in `AutomationServiceProvider::TRIGGER_EVENTS`
- WHEN the form is re-opened
- THEN the field shows the literal stored value (no select option), the entire form submission is BLOCKED with a 422 error "Trigger no disponible en el catálogo actual" on save, and a non-blocking `<x-alert type="warning">` explains the situation (proposal §9.9).

---

## Affected routes

All condition rows are persisted as children of the rule form (`admin.automations.store` / `update`). Reordering issues a single `PATCH admin.automations.reorder` payload that the host route dispatches into condition/condition-group re-numbering. No standalone condition route exists in v1.

| Method | URI | Name | Permission |
|---|---|---|---|
| (sub-route) | in `admin.automations.reorder` | `admin.automations.reorder` | `automations.manage` |

---

## Cross-references

- Proposal: §4 regla 4 (value_type + payload validation), §7.6 (builder visual AND/OR, autocompletado del field), §9 edge case 9 (trigger eliminado), §10 #2 (builder locked), AC-1.
- Explore: §1.2 (16 operators), §1.3 (fillable on condition + denormalized `rule_id`), §1.4 (ConditionEvaluator dot-path + zero groups semantics), §5.3 (event payload reference).
- Config: `openspec/config.yaml` — Livewire 4 in stack; `strict_tdd` requires test seams.
