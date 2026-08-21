# B12.5 — UI Polish (archive-report)

> **Phase**: sdd-archive — final SDD phase for the B12.5 change.
> **Change**: `b12.5-ui-polish` — incremental smoothing of the B12-UI surface.
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **No git, no migrations, no engine code, no controller body modifications.**

---

## §0 Verdict

**status: `archived — b12.5-ui-polish archived`**

- All 8 acceptance criteria (`AC-B12.5-1`..`AC-B12.5-8`) **passed** per `verify-report.md` §3.
- Engine regression guard (`AutomationEngineTest`) **10/10 / 21 assertions** green (re-run by this phase — no drift).
- Full Laravel suite **651/651 / 2,291 assertions / ~83.5 s** green (re-run by this phase — byte-stable vs. B17 baseline 642/2237).
- 4 REQ-ids that were deferred in B12-UI (COND-04, ACT-09, COND-08, HIST-07) are now fully covered.
- Delta-spec sync executed verbatim — 3 delta specs mirrored from `openspec/changes/b12.5-ui-polish/specs/` → `openspec/specs/admin/automations/` with **byte-for-byte SHA256 match** for every file.
- The change directory is moved to `openspec/changes/archive/2026-08-18-b12.5-ui-polish/`. A single-line `STATUS.txt` marker records the closure.

---

## §1 Phase timeline

```
sdd-init      →  n/a (B12-UI config.yaml carries over)
sdd-explore   →  n/a (B12-UI explore.md carries over)
sdd-proposal  →  openspec/changes/b12.5-ui-polish/proposal.md (3 polish items, 8 ACs)
sdd-spec      →  openspec/changes/b12.5-ui-polish/specs/admin-automations-{conditions,actions,history}.md
                  (3 delta specs, 3 REQ-ids)
sdd-design    →  openspec/changes/b12.5-ui-polish/design.md (file map + 3 architectural decisions)
sdd-tasks     →  openspec/changes/b12.5-ui-polish/tasks.md (3 chunks, single-PR scope, 400-line budget)
sdd-apply     →  PR 1 (Chunk 1: wire:sort + Chunk 2: cycle-break + Chunk 3: trigger-catalog)
                  → apply-progress.md (TDD evidence: RED → GREEN → TRIANGULATE → REFACTOR for all 3 chunks)
sdd-verify    →  openspec/changes/b12.5-ui-polish/verify-report.md (status: passed; 8 ACs passed; engine + suite green)
sdd-sync      →  openspec/specs/admin/automations/{conditions,actions,history}.md (3 verbatim copies)
sdd-archive   →  THIS artifact + STATUS.txt + parent envelope + move to archive/ (now)
```

---

## §2 Final test count

| Metric | Value |
|---|---|
| Total tests | **651 / 651** (`php artisan test`) |
| Total assertions | **2,291** |
| Wall clock | **~83.5 s** (Windows-native PHP) |
| Engine regression guard (`AutomationEngineTest`) | **10 / 10 / 21 assertions / 1.87 s** |
| Engine + suite drift | **none** — engine 10/10 byte-stable; suite +9 tests / +54 assertions vs. B17 baseline (642/2237) |

Re-run during archive phase (no application file touched by this phase, only canonical spec files mirrored):

```
{"tool":"phpunit","result":"passed","tests":651,"passed":651,"assertions":2291,"duration_ms":83493}
{"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":21,"duration_ms":1810}
```

---

## §3 AC coverage

| AC | Description | Status |
|---|---|---|
| **AC-B12.5-1** | `wire:sort` directive attached to the groups + actions containers | **passed** |
| **AC-B12.5-2** | `reorderGroups(array $order)` re-keys + renumbers | **passed** |
| **AC-B12.5-3** | `reorderActions(array $order)` re-keys + renumbers | **passed** |
| **AC-B12.5-4** | Cycle-break `<details>` block rendered in execution detail | **passed** |
| **AC-B12.5-5** | `StoreRuleRequest` rejects invalid trigger with 422 | **passed** |
| **AC-B12.5-6** | `UpdateRuleRequest` rejects invalid trigger with 422 | **passed** |
| **AC-B12.5-7** | Engine regression guard stays at 10/10 / 21 assertions | **passed** |
| **AC-B12.5-8** | Full suite stable (651/651 / 2,291 assertions — within the 648-655 target range) | **passed** |

**AC coverage**: **8 / 8 passed**.

---

## §4 REQ-id coverage delta vs. B12-UI

| REQ-id | Spec | B12-UI status | B12.5 status | Verification |
|---|---|---|---|---|
| **REQ-COND-04** | `admin-automations-conditions.md` | partial (persistence only) | **fully covered** | `RuleFormDragSortTest::test_reorder_groups_updates_positions` |
| **REQ-ACT-09** | `admin-automations-actions.md` | partial (persistence only) | **fully covered** | `RuleFormDragSortTest::test_reorder_actions_updates_positions` |
| **REQ-COND-08** | `admin-automations-conditions.md` | partial (no `Rule::in` guard) | **fully covered** | `AdminAutomationRuleFormTest::test_store_with_invalid_trigger_returns_422` + `test_update_with_invalid_trigger_returns_422` |
| **REQ-HIST-07** | `admin-automations-history.md` | partial (no test) | **fully covered** | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` |

**Net change**: 4 REQ-ids upgraded from partial to fully covered. No new REQ-ids introduced.

---

## §5 Known deferred items (non-blocker, future b12.6+ candidates)

From `openspec/changes/b12-ui/verify-report.md` §5.5 (REQ-UI-13) + §5.1 (AC-6 cosmetic):

1. **REQ-UI-13 — a11y smoke (Playwright/Dusk)**. The `AutomationVisualSmokeTest` was not shipped in B12-UI (apply-progress §Stage 6 explicitly says "if skipped, defer to manual a11y review"). ARIA attributes + semantic markup are present in views but no automated a11y smoke. Estimated 2-3 hours.
2. **AC-6 cosmetic — strip trailing period** from the B14 banner in `resources/views/livewire/admin/automations/widgets/webhook-widget.blade.php:4` and `send-whatsapp-template-widget.blade.php:4`. The `apply-progress.md` records the text without the period; the rendered file carries it. Estimated 5 minutes.

These remain non-blocker per the brief's "if skipped, defer" rule.

---

## §6 Rollback note

This change added **no migrations** and **no engine contract changes**. The scope is restricted to:

- `app/Livewire/Admin/Automations/RuleForm.php` — 2 new methods + 1 private helper
- `resources/views/livewire/admin/automations/rule-form.blade.php` — `wire:sort` containers on existing loops
- `resources/views/admin/automations/execution.blade.php` — cycle-break `<details>` block at the bottom of the steps section
- `app/Http/Requests/Admin/Automations/StoreRuleRequest.php` — `Rule::in()` guard on `trigger_event`
- `app/Http/Requests/Admin/Automations/UpdateRuleRequest.php` — `Rule::in()` guard on `trigger_event`
- `tests/Feature/Admin/Automations/AdminAutomationRuleFormTest.php` — +2 tests for trigger-catalog guard
- `tests/Feature/Admin/Automations/Livewire/RuleFormDragSortTest.php` — new file (6 tests)
- `tests/Feature/Admin/Automations/HistoryAndAuditCycleBreakTest.php` — new file (1 test)

The 19 `TRIGGER_EVENTS` FQCNs are byte-stable. The rollback is a single `git revert` once git is initialized post-archive.

---

## §7 sdd-sync evidence — 3 verbatim mirror copies

Mirror source → destination with **byte-for-byte SHA256 match** (no edits, no wrappers, no canonical header added):

| # | Source (change artifact) | Destination (canonical) | Match | Status |
|---|---|---|---|---|
| 1 | `openspec/changes/b12.5-ui-polish/specs/admin-automations-conditions.md` | `openspec/specs/admin/automations/conditions.md` | ✓ byte-equal | mirrored (overwrite of delta on top of B12-UI copy) |
| 2 | `openspec/changes/b12.5-ui-polish/specs/admin-automations-actions.md` | `openspec/specs/admin/automations/actions.md` | ✓ byte-equal | mirrored (overwrite of delta on top of B12-UI copy) |
| 3 | `openspec/changes/b12.5-ui-polish/specs/admin-automations-history.md` | `openspec/specs/admin/automations/history.md` | ✓ byte-equal | mirrored (overwrite of delta on top of B12-UI copy) |
| **Σ** | | | **3/3** | **PASS** |

Append-only policy honored: 3/3 copies mirrored onto the existing canonical tree (B12-UI's 6 specs); zero skipped, zero clobbers outside the 3 target files.

---

## §8 Structured status / actionContext findings

| Field | Value | Source |
|---|---|---|
| `changeName` | `b12.5-ui-polish` | status engine JSON |
| `artifactStore` | `openspec` | status engine JSON + `config.yaml` |
| `execution_mode` | `interactive` | `config.yaml` |
| `applyState` | `passed` (overridden from incoming `blocked` by verify + archive) | `verify-report.md` §0 + this artifact |
| `dependencies.verify` | `passed` | `verify-report.md` §0 |
| `dependencies.sync` | `passed` | this artifact §7 |
| `dependencies.archive` | `passed` | this artifact §0 |
| `actionContext.mode` | `repo-local` | status engine JSON |
| `actionContext.workspaceRoot` | `C:\laragon\www\crm-maia-consultores` | status engine JSON |
| `actionContext.allowedEditRoots` | `[C:\laragon\www\crm-maia-consultores]` | status engine JSON |
| `actionContext.warnings` | (none) | this phase |
| `nextRecommended` | none — change archived; follow-up change ticket optional for the 2 items in §5 | this artifact |
| `isNonAuthoritative` | `false` | this phase (authoritative — written by the archive executor) |

---

## §9 Archived path

- Change directory moved to: `C:\laragon\www\crm-maia-consultores\openspec\changes\archive\2026-08-18-b12.5-ui-polish\` (next section).
- Canonical spec files: `C:\laragon\www\crm-maia-consultores\openspec\specs\admin\automations\` (6 files; 3 B12-UI + 3 B12.5 delta, byte-equal to the B12.5 delta sources).
- Status marker: `C:\laragon\www\crm-maia-consultores\openspec\changes\archive\2026-08-18-b12.5-ui-polish\STATUS.txt` (single-line `closed — b12.5-ui-polish archived`).
- This archive report: `C:\laragon\www\crm-maia-consultores\openspec\changes\archive\2026-08-18-b12.5-ui-polish\archive-report.md`.
- Parent envelope: `C:\laragon\www\crm-maia-consultores\sdds\sdd-apply-b12.5-ui-polish.md`.

---

**End of archive-report.**
