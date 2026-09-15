# Course teacher identity - archive report

> **Phase**: sdd-archive.
> **Change**: `course-teacher-identity`.
> **Workspace**: `C:/laragon/www/crm-maia-consultores`.
> **Archived path**: `openspec/changes/archive/2026-09-15-course-teacher-identity/`.
> **Canonical spec synced**: `openspec/specs/course-talks/course-talks-management.md`.

## Archive status

**PASS with warnings.** The OpenSpec change is closed and archived after syncing its course-talks-management delta into the canonical course-talks spec.

Nothing is committed. The implementation, OpenSpec sync, and archive move remain working-tree changes until a human creates commits/PRs.

## Artifacts read

- `openspec/changes/course-teacher-identity/proposal.md`
- `openspec/changes/course-teacher-identity/specs/course-talks-management/spec.md`
- `openspec/changes/course-teacher-identity/design.md`
- `openspec/changes/course-teacher-identity/tasks.md`
- `openspec/changes/course-teacher-identity/apply-progress.md`
- `openspec/changes/course-teacher-identity/verify-report.md`
- `openspec/config.yaml`
- Existing archive convention: `openspec/changes/archive/2026-09-12-course-talks-management/`
- Canonical spec: `openspec/specs/course-talks/course-talks-management.md`

## Structured status and action context

| Field | Value |
|---|---|
| Artifact store | `openspec` |
| Parent status | `dependencies.archive: ready`; `nextRecommended: archive`; no blockers |
| Task progress | 35 total / 35 complete / 0 pending |
| Action context | repo-local, workspace `C:/laragon/www/crm-maia-consultores`, edits confined to workspace |
| Verification validator | `gentle-ai sdd-verify-validate --input openspec/changes/course-teacher-identity/verify-report.md --requirements 8 --scenarios 20` returned valid pass-with-warnings |

Final task completion gate re-read `tasks.md` immediately before sync/archive and found no unchecked implementation task boxes (`- [ ]`).

## Verification close state

Verdict: **pass_with_warnings**.

Evidence recorded in `verify-report.md`:

- 8/8 requirements satisfied.
- 20/20 scenarios satisfied.
- `php artisan test --filter=Course` passed: 554 tests / 554 passed / 3997 assertions, exit 0.
- `npm run build` passed, exit 0.
- Critical findings at archive time: 0 unresolved.
- Blockers at archive time: 0.

Warnings carried forward:

1. MySQL DDL path was never executed locally; SQLite cannot prove it.
2. Verification had no independent verifier after three delegated verifier attempts stalled; owner authorized inline verification.
3. Production counts are unknown; local counts are 0 teachers and 0 sessions.
4. Human acceptance has never been performed.
5. Production rollout gate remains outstanding: backup, production counts, unmatched/ambiguous name candidates, and MySQL dry-run.

## Review lineage close state

| Lineage | Final state | Archive interpretation |
|---|---|---|
| `review-f4a496f2c334a0cf` | approved after correction | Real CRITICAL MySQL `DROP PRIMARY KEY` / FK index defect fixed and validated. |
| `review-046e09047f9b45c8` | approved | No correction required. |
| `review-6befb52527bc835e` | correction_required on false positive | `R3-session-teacher-select-not-selected` was refuted with three independent proofs and deliberately not fixed. Not an unresolved product defect. |
| `review-d6eb68fe9b75d84f` | approved after correction | Real CRITICAL single-column migration guard defect fixed and validated. |

## Canonical spec sync

Synced domain: `course-talks-management` delta into canonical domain `course-talks` at `openspec/specs/course-talks/course-talks-management.md`.

### ADDED requirements

- Edition teachers have stable identity
- Teacher synchronization preserves identity
- Teacher removal is blocked while assigned to sessions
- Sessions may reference an edition teacher
- Certificate speaker uses assigned teacher before legacy fallback
- Production rollout prerequisites for teacher identity changes
- Increment scope boundaries

### MODIFIED requirements

- Activity, edition, and class model

### REMOVED requirements

- None

### Destructive merge guard

No REMOVED requirements were present. One canonical requirement was replaced by a full MODIFIED requirement block: `Activity, edition, and class model`. This is an approved archive-time sync per the parent instruction to sync the 7 ADDED / 1 MODIFIED delta. No scenarios were silently dropped from the modified block; the replacement includes both scenarios from the canonical requirement and adds the stable-teacher/session-reference wording.

### Active same-domain change warnings

No other active change under `openspec/changes/*/specs/course-talks-management/spec.md` touches this exact domain after excluding this archived change. The prior course-talks-management change is already archived.

## Task ledger

- Final persisted ledger: 35 / 35 complete.
- Unchecked implementation task lines: none.
- Stale-checkbox reconciliation: not performed and not needed.
- The production backup/count prerequisite is intentionally not a `tasks.md` deliverable; it remains tracked under the verify-report production rollout gate.

## Residual risks after archive

1. Do not run staging/production migrations until a restorable backup, production counts, unmatched/ambiguous name report, and MySQL dry-run are complete.
2. The MySQL DDL path remains review-validated but not execution-validated here.
3. Human acceptance/browser verification remains pending.
4. The chosen chained-PR delivery still has no commit boundary; nothing is committed.

## Archive result

- Archive report written before move.
- Canonical spec synced.
- Change moved to `openspec/changes/archive/2026-09-15-course-teacher-identity/`.

## Key Learnings

- Green SQLite course tests did not catch the two real severe migration risks; adversarial migration review caught both.
- Task ledgers should not include production rollout gates as implementation deliverables when they are intentionally out of reach for local archive closure.
- Archive reports must preserve warnings plainly: pass-with-warnings is a valid close state only when the remaining warnings are operationally owned and non-blocking for code archive.
