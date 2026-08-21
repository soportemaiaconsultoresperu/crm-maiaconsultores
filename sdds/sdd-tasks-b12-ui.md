# sdd-tasks — b12-ui (envelope)

> Compact envelope for `openspec/changes/b12-ui/tasks.md`. The canonical
> artifact lives at `openspec/changes/b12-ui/tasks.md` (≈ 600 lines,
> sections A–G). This file is the parent-facing summary.

---

## status

- **phase**: sdd-tasks
- **artifact_store**: openspec (writes to `openspec/changes/b12-ui/tasks.md`)
- **execution_mode**: interactive
- **review_budget (parent preflight — config.yaml carries none)**: **600** LOC per PR
- **total estimated changed lines**: ≈ **5,500** (production ~3,200 + tests ~2,100 + docs ~150)
- **verdict**: **chained_prs_recommended**
- **stack order**: PR 1 → PR 2 → PR 3 → PR 4 → PR 5 → PR 6 → PR 7 (7 PRs, stacked-to-main)
- **next_recommended**: sdd-apply (after user approves the chain + answers the size-exception question for PRs 4 and 6)
- **skill_resolution**: paths-injected (skill paths were not pre-injected; this run executed under the SDD tasks executor's contracted behaviour — `Skill Resolution Contract` reports `paths-injected` because the parent session owns skill-path injection; if missing in a future run, the fallback registry is permitted per the contract)

## executive_summary

1. **Verdict**: **chained_prs_recommended** — total ~5,500 LOC vs 600 LOC budget (9×). Even the smallest chunk (Chunk 1) exceeds 600 by 23% once strict-TDD tests are counted.
2. **7 stacked PRs** in the order 1 → 2 → 3 → 4 → 5 → 6 → 7 (Chunk 4 split into 4a/4b per R-design-5). Each PR is independently buildable; `AutomationEngineTest` stays green at every PR boundary.
3. **Chunk 1 (Foundation)**: extend `routes/web.php` group; add 13 actions + 4 FormRequests + `AdminAutomationPermissionsTest` (PERM-01..09 + gate matrix). ~740 LOC.
4. **Chunk 2 (Index + Papelera + Toggle)**: replace 51-line index placeholder; trash tab; toggle/clone/restore buttons; CRUD + TrashRestore + Clone tests. ~660 LOC.
5. **Chunk 3 (Condition Builder)**: `RuleForm` + `ConditionGroupEditor` Livewire classes + `RulePayloadValidator`; condition persistence + drag-reorder; 4 new tests. ~890 LOC.
6. **Chunk 4a (ActionEditor + 9 write/stage widgets)**: `ActionEditor` Livewire + `ActionPayloadValidator` + 9 widget Blade components (`assign_owner`, `change_status`, `change_stage`, `add_tag`, `create_activity`, `create_follow_up_activity`, `add_note`, `webhook`, dispatcher). ~1,420 LOC — **over budget by 137%**.
7. **Chunk 4b (Notifications + Stubs + Simulate + Recipient sync + Retry hidden)**: 6 remaining widgets (`send_notification`, `send_email`, `send_whatsapp_template`, `b14-stub-banner`, `simulate-button`, `idempotency-key-copy`) + `simulateAction` controller body + 6 tests. ~670 LOC.
8. **Chunk 5 (History + Execution + Audit + Idempotency + Badge)**: replace 57/58-line show/execution placeholders; `_audit_changes.blade.php`; `_history_filter.blade.php`; `auditFeed` body + `HistoryFilter` (optional) + 5 tests. ~1,090 LOC — **over budget by 82%**.
9. **Chunk 6 (Hardening)**: `BulkOpsAbsentTest` final sweep + optional visual smoke + `docs/AVANCE.md` entry + `docs/v2/01-roadmap.md` note + autofix cleanup. ~150 LOC.
10. **Lens advisory (informational)**: 50 markdown lint warnings on the new `tasks.md` (top: MD060 long-lines ×30, MD036 first-line-heading ×12, MD040 fenced-code-language ×7). Same family as `R-design-4` from the design envelope — review-ready, no fix required this phase.

## artifacts

- **Canonical tasks doc**: `openspec/changes/b12-ui/tasks.md` (sections A–G, ≈ 600 lines)
  - A. Implementation chunks (7 chunks, each with LOC, files, TDD block, REQ/AC trace, dependency)
  - B. Cross-chunk dependency graph
  - C. TDD invariants enforced across every chunk
  - D. Tasks ↔ design cross-reference (all 58 REQ-ids → chunks)
  - E. AC ↔ chunks cross-reference (AC-1..AC-12 → primary PR)
  - F. Files explicitly NOT touched by B12-UI v1
  - G. Blockers / flags for parent
- **This envelope**: `sdds/sdd-tasks-b12-ui.md`

## next_recommended

- **sdd-apply** — but only after the user explicitly answers the size-exception question for PRs 4 and 6 (each exceeds 600 LOC by 82–137%). Two options the parent should present to the user:
  - **Option A — accept the chain as proposed**: 7 PRs, with PRs 4 and 6 carrying size exceptions. Reviewer is told explicitly via PR description that those two PRs are larger than budget.
  - **Option B — split PR 4 and PR 6 further**:
    - PR 4 → PR-4a-widgets (≈ 600 LOC) + PR-4b-ActionEditor+validator+tests (≈ 600 LOC)
    - PR 6 → PR-6a-history+exec-views (≈ 600 LOC) + PR-6b-audit+idempotency+badge (≈ 500 LOC)
  - Total PRs under Option B: 9.
- The parent owns the user-facing decision per the SDD preflight (`Delivery strategy: ask-on-risk`).

## risks

- **R-tasks-1**: total ~5,500 LOC vs 600 LOC budget — `chained_prs_recommended` is the honest verdict. Two PRs (4 and 6) exceed budget by 82–137% even after splitting Chunk 4 into 4a/4b per R-design-5.
- **R-tasks-2**: PR 4 owns the largest single-class expansion of v1 (the 9 widget Blade files + `ActionEditor` + `ActionPayloadValidator`). If a single widget balloons during `sdd-apply`, the parent may need to sub-split further inside PR 4.
- **R-tasks-3**: PR 6 mixes history views + audit partial + idempotency-key copy + test-mode badge wiring. The `HistoryFilter` Livewire component is marked **optional** in `design.md §7.2`; if it slips, PR 6 still meets AC-1..AC-9 via the mandatory GET-param filter form path.
- **R-tasks-4**: markdown lint warnings (MD060×30, MD036×12, MD040×7) on the new `tasks.md` — same family as `R-design-4` from the design envelope. Informational only; no fix this phase.
- **R-tasks-5**: no engine contracts were re-opened. The `automation_rules.order` last-write-wins tradeoff (proposal R5, design §13.1) is acknowledged in Chunk 3 (ReorderRequest) and Chunk 4a (ActionEditor wire:sort); engine tie-breaker is `scopeOrdered()` (`order ASC, id ASC`) so no rule is orphaned from the sequence. Optimistic-lock migration is explicitly out of scope for v1 (proposal §8).

## tasks ↔ design cross-reference (compact)

All 58 REQ-ids across the 6 spec files map to at least one chunk in `tasks.md` §D. Compact summary:

| REQ family | # REQs | Chunks / PRs | Notes |
|---|---|---|---|
| CRUD-* | 8 | Chunk 1 (routes/FormRequests), Chunk 2 (controller + views), Chunk 3 (RuleForm) | CRUD-04 clone lives in Chunk 2 (controller body) |
| COND-* | 8 | Chunk 3 (all — `RuleForm` + `ConditionGroupEditor` + `RulePayloadValidator`) | COND-08 trigger-catalog guard split between Chunk 1 (UpdateRuleRequest) and Chunk 3 |
| ACT-* | 9 | Chunk 4a (write/stage widgets + DataScope), Chunk 4b (notifications/stubs/simulate/retry hidden) | ACT-03 dual-write covered in both 4a (widget) and 4b (unit test) |
| HIST-* | 10 | Chunk 4b (idempotency copy + test-mode badge components), Chunk 5 (history + exec + audit views) | HIST-05/HIST-06 components in 4b; their integration in 5 |
| PERM-* | 9 | Chunk 1 (provider boot + gate matrix), every later PR (route-level coverage) | PERM-08 test-base convention applied in every Feature test |
| UI-* | 14 | Spread across all chunks (vocabulary), Chunk 6 (final sweep + a11y + Vite) | UI-04 sidebar regression guard in Chunk 6 |

**Coverage check**: every spec REQ-id appears in §D; every AC-1..AC-12 appears in §E with a primary PR; no orphan requirements.

---

End of envelope.
