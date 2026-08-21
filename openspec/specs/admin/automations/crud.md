# Admin Automations — Rule CRUD

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (engine surface, placeholder routes/views) and `openspec/changes/b12-ui/proposal.md` (PRD, §4 + §7 + §12).
> Scope: rule-level CRUD only. Condition authoring lives in `admin-automations-conditions.md`; action authoring in `admin-automations-actions.md`; history in `admin-automations-history.md`; permissions in `admin-automations-permissions.md`; UI hygiene in `admin-automations-ui-conventions.md`.

---

## Purpose

Define the contract for creating, reading, updating, soft-deleting, restoring, reordering, cloning, and toggling `AutomationRule` rows from the admin surface, so that the B12 automation engine — confirmed in `tests/Feature/AutomationEngineTest.php` (explore §1.5) — becomes operable without seeders or deploys.

---

## Requirements

### REQ-CRUD-01 — Index paginado

The system SHALL render `admin.automations.index` as a paginated list (20 per page, default) of `AutomationRule` rows where `deleted_at IS NULL` by default. Each row SHALL display `id`, `name`, `trigger_event` (rendered via `class_basename`), `mode` badge (green = `live`, purple = `test`), `is_active` toggle (gated `automations.manage`), `executions_count`, `updated_at` in `America/Lima`, and links to `show` and `edit`. A secondary tab "Papelera" SHALL list only `deleted_at IS NOT NULL` rows.

### REQ-CRUD-02 — Create rule

The system SHALL accept a POST to `admin.automations.store` that persists `name` (required, string ≤ 191), `description` (nullable text), `trigger_event` (required, FQCN ∈ `AutomationServiceProvider::TRIGGER_EVENTS`), `is_active` (bool, default `false`), `mode` (`live`|`test`, default `test`), `order` (unsigned int, default tail), `owner_id` (nullable fk `users`). The system SHALL auto-populate `created_by` with `auth()->id()` and SHALL reject unknown `trigger_event` values with a 422 + validation error keyed against a "missing in catalog" message.

### REQ-CRUD-03 — Edit rule

The system SHALL accept `PUT`/`PATCH` to `admin.automations.update` that updates any subset of `name`, `description`, `trigger_event`, `is_active`, `mode`, `order`, `owner_id`. Edits SHALL NOT modify `created_by` even if submitted. Saving with `trigger_event` removed from the current `AutomationServiceProvider::TRIGGER_EVENTS` list SHALL persist the edit AND surface a non-blocking `<x-alert type="warning">` warning above the form (proposal §9 edge case 9).

### REQ-CRUD-04 — Clone rule

The system SHALL duplicate the rule row, all `AutomationConditionGroup` rows, all `AutomationCondition` rows, and all `AutomationAction` rows attached to the source rule into a new rule owned by the current user. The clone SHALL receive a fresh `id` per row, the suffix " (copia)" appended to `name`, `created_by = auth()->id()`, `is_active = false`, `mode = test`, and `idempotency_key`-related rows untouched (none on `automation_rules`). Children MUST keep the order of their source rows.

### REQ-CRUD-05 — Toggle is_active inline

The system SHALL provide an inline toggle on the index that PATCHes only `is_active` against `automations.toggle` (alias of `update` restricted to that column). The endpoint SHALL validate `is_active ∈ {true, false}` and SHALL refuse the call with 403 when the user lacks `automations.manage`.

### REQ-CRUD-06 — Drag-to-reorder persistence

The system SHALL persist a batch `PATCH` to `automations.reorder` accepting `{order: [{id, order}, ...]}` and updating the `order` column of every listed row in one DB transaction. The endpoint SHALL re-normalize `order` to consecutive integers starting at 1 within the implicit "page" (or globally — must be documented in code). Concurrency is last-write-wins (proposal §11 risk R5); no optimistic-lock column is added.

### REQ-CRUD-07 — Soft-delete

The system SHALL soft-delete a rule via `DELETE` against `admin.automations.destroy`. The row SHALL disappear from the default `index`, SHALL appear under the "Papelera" tab, and SHALL be ignored by the listener (`DispatchAutomationRule`) regardless of `is_active` because `scopeActive` runs on the default query (explore §5.2). A delete SHALL NOT cascade — children rows stay attached so `restore()` can re-link them (proposal §9 edge case 6).

### REQ-CRUD-08 — Restore from papelera

The system SHALL restore a soft-deleted rule via `POST admin.automations.restore` calling `restore()` on the model. If a manual FK break happened between delete and restore, the system SHALL surface the FK violation in a red `<x-alert type="error">` without crashing the rest of the index (proposal §9 edge case 6). Restored rules SHALL appear in `index` at the tail and SHALL never auto-reactivate (`is_active` keeps the deleted-time value).

---

## Scenarios

#### SCN-CRUD-01 — Author creates a minimal rule

- GIVEN an admin with `automations.manage`
- WHEN they POST to `automations.store` with `name="Lead nuevo auto-asignar"`, `trigger_event="App\Events\V2\LeadCreated"`, `mode="test"`, `is_active=false`
- THEN the rule exists with `created_by = auth()->id()`, `is_active=false`, `mode='test'`, and the listener does not dispatch (because `mode=test`).

#### SCN-CRUD-02 — Toggle fails when permission missing

- GIVEN a user with `automations.view` only
- WHEN they PATCH `automations.toggle` to flip `is_active`
- THEN the response is 403 and no row is updated.

#### SCN-CRUD-03 — Drag reorder persists

- GIVEN two admin sessions reorder the same rule (id 5) to position 3
- WHEN both submit within seconds
- THEN the last-write-wins (DB `updated_at` records the survivor); the engine tie-breaks by `id asc` (`scopeOrdered`, explore §5.2) so no rule is orphaned from the list.

#### SCN-CRUD-04 — Clone carries conditions + actions

- GIVEN a rule R with 2 groups (1 condition each) and 2 actions
- WHEN an admin POSTs `automations.clone`
- THEN the cloned rule R' has `created_by=actor.id`, name=R.name + " (copia)", `is_active=false`, `mode='test'`, and 2 + 2 child rows with fresh `id`s.

#### SCN-CRUD-05 — Restore after accidental soft-delete

- GIVEN a rule R with children, `deleted_at` set 5 minutes ago
- WHEN an admin POSTs `automations.restore`
- THEN R is visible in `index`, children are re-fetched (FK reattached), and the rule's previous `is_active` value is preserved.

#### SCN-CRUD-06 — Trash tab hides non-deleted rules

- GIVEN 3 active rules and 1 soft-deleted rule
- WHEN the admin opens the "Papelera" tab
- THEN only the soft-deleted row appears; "Activas" shows the 3 others.

#### SCN-CRUD-07 — Empty states are gated

- GIVEN a database with zero rules
- WHEN the admin opens `index`
- THEN an empty-state is rendered with a CTA "Crear primera regla" only if `automations.manage` is granted.

---

## Affected routes

| Method | URI | Name | Permission |
|---|---|---|---|
| GET | `/admin/automations` | `admin.automations.index` | `automations.view` |
| GET | `/admin/automations/trash` | `admin.automations.trash` | `automations.view` |
| GET | `/admin/automations/create` | `admin.automations.create` | `automations.manage` |
| POST | `/admin/automations` | `admin.automations.store` | `automations.manage` |
| GET | `/admin/automations/{automation}/edit` | `admin.automations.edit` | `automations.manage` |
| PUT/PATCH | `/admin/automations/{automation}` | `admin.automations.update` | `automations.manage` |
| PATCH | `/admin/automations/{automation}/toggle` | `admin.automations.toggle` | `automations.manage` |
| POST | `/admin/automations/{automation}/clone` | `admin.automations.clone` | `automations.manage` |
| PATCH | `/admin/automations/reorder` | `admin.automations.reorder` | `automations.manage` |
| DELETE | `/admin/automations/{automation}` | `admin.automations.destroy` | `automations.manage` |
| POST | `/admin/automations/{automation}/restore` | `admin.automations.restore` | `automations.manage` |

All routes register inside the existing `auth` + `admin` middleware group on `routes/web.php` next to the placeholder block at lines 375–380 (explore §2.1).

---

## Cross-references

- Proposal: §4 (permissions), §7.1 (CRUD + clone + toggle + reorder), §7.3 (drag-to-reorder), §7.5 (papelera), §9.1 / §9.3 (empty states), §9.6 (restore cycle break), §10 #1 (CRUD locked), AC-1 / AC-4 / AC-11 / AC-12.
- Explore: §1.3 (model fillable + scopes), §2.1 (existing routes), §5.2 (active scope on listener), §8.11 (permissions registered at boot), §8.15 (Livewire 4 available).
- Config: `openspec/config.yaml` — `strict_tdd: true` (test seams must be authored before controller methods).
