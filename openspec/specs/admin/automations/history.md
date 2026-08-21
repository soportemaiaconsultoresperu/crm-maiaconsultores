# Admin Automations — History (B12.5 delta)

> Module slice of `b12.5-ui-polish`. Upstream: `openspec/changes/b12-ui/specs/admin-automations-history.md` (HIST-01..10) and `openspec/changes/b12-ui/verify-report.md` §5.4 (HIST-07 deferred).
> This spec delta settles the 1 deferred REQ-id for B12.5:
>
> - **REQ-HIST-07** — Cycle-break rendering (see below).

---

## REQ-HIST-07 — Cycle-break rendering (B12.5)

The system SHALL render a `<details>` block in `resources/views/admin/automations/execution.blade.php` that lists the `AutomationCycleBreak` rows attached to the rule. The block SHALL:

- Render a `<summary>` with the cycle-break count (e.g. "Cycle breaks (2)").
- For each row, render the `reason` field and the `detected_at` timestamp (timezone `America/Lima`).
- Render an empty-state message ("No hay cycle breaks.") when the relation is empty.

The block is rendered at the bottom of the steps section, after the steps table. The relation is lazy-loaded via `$rule->cycleBreaks` (no explicit `load()` needed — the relation is already on the `AutomationRule` model).

---

## Scenarios

#### SCN-HIST-07-B12.5 — Execution detail renders cycle-break `<details>` block

- GIVEN an `AutomationRule` + `AutomationExecution` + 2 `AutomationCycleBreak` rows
- WHEN the admin GETs `admin.automations.executions.show` for the execution
- THEN the response body contains the cycle-break count (2), the rule name, the `<details>` block, and a literal substring of the `reason` field.

---

## Cross-references

- Proposal: `openspec/changes/b12.5-ui-polish/proposal.md` B12.5-POL-02.
- Design: `openspec/changes/b12.5-ui-polish/design.md` §2.2.
- Tasks: `openspec/changes/b12.5-ui-polish/tasks.md` Chunk 2.
- Upstream: `openspec/changes/b12-ui/specs/admin-automations-history.md` REQ-HIST-07.
