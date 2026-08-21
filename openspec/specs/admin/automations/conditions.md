# Admin Automations — Condition Builder (B12.5 delta)

> Module slice of `b12.5-ui-polish`. Upstream: `openspec/changes/b12-ui/specs/admin-automations-conditions.md` (COND-01..08) and `openspec/changes/b12-ui/verify-report.md` §5.2 (COND-04 deferred).
> This spec delta settles the 1 deferred REQ-id for B12.5:
>
> - **REQ-COND-04** — Drag-to-reorder within a group (see below).

---

## REQ-COND-04 — Drag-to-reorder within a group (B12.5)

The system SHALL allow per-group drag-to-reorder of `AutomationCondition` rows via Livewire 4 `wire:sort` directive (see `openspec/changes/b12-ui/design.md` §9.1). The RuleForm parent component SHALL expose:

- A `reorderGroups(int|string|array $itemOrOrder, ?int $position = null): void` method that re-keys `$this->groups` according to the new order and updates `position` to `1..count`. When called with an `array $order` (the test path), the method re-keys `$this->groups` by the new order. When called with `($item, $position)` (the wire:sort runtime path), the method rebuilds the order from the current state + the new position.
- The matching `reorderActions(...)` method for the actions list.

The `wire:sort` directive is attached to the groups container + the actions container in `resources/views/livewire/admin/automations/rule-form.blade.php`. The methods do NOT persist to DB; the persist is via the existing `save()` + controller endpoint, which already preserves the order in the form state.

---

## Affected routes

No new routes. The wire:sort dispatches into the RuleForm Livewire component methods; the persistence half is already covered by `admin.automations.reorder` (CRUD-06).

---

## Scenarios

#### SCN-COND-04-B12.5 — Drag reorder groups via RuleForm

- GIVEN the rule form open with 3 groups (positions 1, 2, 3)
- WHEN the admin drags the group at position 1 to position 3
- THEN `$this->groups` is re-keyed so position 1 holds the original group at index 2, position 2 holds the original group at index 0, position 3 holds the original group at index 1; positions are renumbered to 1..3.

#### SCN-COND-04-B12.5-2 — Drag reorder actions via RuleForm

- Same as SCN-COND-04-B12.5 but for `$this->actions`.

---

## Cross-references

- Proposal: `openspec/changes/b12.5-ui-polish/proposal.md` B12.5-POL-01.
- Design: `openspec/changes/b12.5-ui-polish/design.md` §2.1.
- Tasks: `openspec/changes/b12.5-ui-polish/tasks.md` Chunk 1.
- Upstream: `openspec/changes/b12-ui/specs/admin-automations-conditions.md` REQ-COND-04.
