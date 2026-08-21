# Admin Automations — Actions (B12.5 delta)

> Module slice of `b12.5-ui-polish`. Upstream: `openspec/changes/b12-ui/specs/admin-automations-actions.md` (ACT-01..09) and `openspec/changes/b12-ui/verify-report.md` §5.2 (ACT-09 deferred).
> This spec delta settles the 1 deferred REQ-id for B12.5:
>
> - **REQ-ACT-09** — Drag-to-reorder actions (see below).

---

## REQ-ACT-09 — Drag-to-reorder actions (B12.5)

The system SHALL allow drag-to-reorder of `AutomationAction` rows attached to a rule via Livewire 4 `wire:sort` directive (see `openspec/changes/b12-ui/design.md` §9.1). The RuleForm parent component SHALL expose:

- A `reorderActions(int|string|array $itemOrOrder, ?int $position = null): void` method that re-keys `$this->actions` according to the new order and updates `position` to `1..count`. When called with an `array $order` (the test path), the method re-keys `$this->actions` by the new order. When called with `($item, $position)` (the wire:sort runtime path), the method rebuilds the order from the current state + the new position.

The `wire:sort` directive is attached to the actions container in `resources/views/livewire/admin/automations/rule-form.blade.php`. The method does NOT persist to DB; the persist is via the existing `save()` + controller endpoint, which already preserves the order in the form state.

---

## Scenarios

#### SCN-ACT-09-B12.5 — Drag reorder actions via RuleForm

- GIVEN the rule form open with 3 actions (positions 1, 2, 3)
- WHEN the admin drags the action at position 1 to position 3
- THEN `$this->actions` is re-keyed so position 1 holds the original action at index 2, position 2 holds the original action at index 0, position 3 holds the original action at index 1; positions are renumbered to 1..3.

---

## Cross-references

- Proposal: `openspec/changes/b12.5-ui-polish/proposal.md` B12.5-POL-01.
- Design: `openspec/changes/b12.5-ui-polish/design.md` §2.1.
- Tasks: `openspec/changes/b12.5-ui-polish/tasks.md` Chunk 1.
- Upstream: `openspec/changes/b12-ui/specs/admin-automations-actions.md` REQ-ACT-09.
