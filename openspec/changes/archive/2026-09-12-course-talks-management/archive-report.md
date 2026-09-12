# Cursos y charlas — `course-talks-management` (archive-report)

> **Phase**: sdd-archive — the final SDD phase for this change.
> **Change**: `course-talks-management`.
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **Branch**: `feat/course-talks-slice-6-ui`, 34 commits over `main`.
> **Canonical spec**: `openspec/specs/course-talks/course-talks-management.md` (22 requirements, carried over verbatim from the verified delta).
> **Verification**: 20 PASS, 2 PARTIAL, 0 FAIL across the 22 delta requirements; 33 of 34 scenarios satisfied.

This archive closes the change. It does NOT close the debt, and the sections below say exactly
what remains — an archive that reads as "everything is done" would be a false closure.

## What shipped

Seven slices plus the units and corrective work that followed them:

| Surface | Delivered |
|---|---|
| Domain foundation | 12 tables, 10 enums, 12 models, 6 policies, 13 permissions, factories, config |
| Services | activity, edition, enrollment, attendance, grade (integer-hundredths calculator), eligibility, document generation, QR tokens, delivery, alerts, commercial documents |
| Academic documents | lifecycle (generate / regenerate / annul), private storage, signed QR streaming, revocation |
| Commercial documents | registration with IGV, group purchase with charge aggregation, private attachments, idempotent registration |
| Delivery | queued email with a real message carrying the document link, assisted WhatsApp handoff, manual confirmation, append-only ledger, per-document history |
| UI | activities (with the type filter), editions, enrollments, attendance matrix, grade matrix, document lifecycle and delivery actions, commercial documents, certificate template administration, navigation, alerts dashboard with eight filters |
| Automatic generation | the eligibility job generates when the last condition completes, attributed to a SYSTEM author, with a config stop switch |
| Audit | `course-*` entries on every material change, attributed to the ACTOR rather than the session user, with no raw QR token anywhere |
| Rollout | permission seeding wired into `DatabaseSeeder`, with proven rollback controls |

## Verification verdict

The final report is `verify-report.md` in this archive. It records 20 PASS / 2 PARTIAL / 0 FAIL
and names the two PARTIALs:

- **Unified activities module** — the must-level type filter was missing and is now implemented and
  test-visible; the requirement is PASS in the final report.
- **Permissions** — every clause is enforced except "viewing audit/history", whose permission
  (`course-talks.audit.view`) has no surface. Declared, not hidden.
- The fourth scenario-level gap was the automatic generation, which the remediation closed.

Six defects were found and fixed AFTER the units that introduced them, four of them by independent
review rather than by the suite: an email that carried no document, a commercial delivery cycle
that never reached a terminal state, a certificate generation that could duplicate, a document
deletion that destroyed a referenced private file, a wrong responsible actor across the whole
audit trail, and a rollout seed that was never wired. **Every one of them was green in the test
suite at the moment it existed** — the lesson this change leaves behind is that a passing test
proves only the edge of what it asserts.

## Declared limits carried into the archive

1. **11 pre-existing suite failures** owned by OTHER in-flight changes (B12/B14 automations,
   settings, email/calendar), proven against `main`. See `suite-baseline.md`.
2. **Six course migrations are NOT applied to any real database.** The module has no tables on the
   dev database until `php artisan migrate` runs. This is a deploy prerequisite, not a code issue.
3. **14 items in `known-limitations.md`**, including: the academic/commercial duplication; the
   `mailOperation` stub that would mark a document sent without sending; the queued-email
   transition recorded without a causer (deliberate, with the reasoning); `course_edition_teachers`
   being unauditable without a migration; the two raw status columns lacking enums; the deferred
   view split; the participant-data dead end where an enrollment without email or phone is
   permanently ineligible; and the alerts screen keeping the same collation-sensitive filter shape.
4. **Two product decisions left to the owner**: `course-talks.view` grants participant-PII reads
   across all editions with no team data-scope, and the mutation policies take no model instance,
   so one permission mutates any resource by id.
5. **A recorded TDD deviation**: the automatic-generation remediation's RED was reproduced rather
   than observed in order, because the original died with a subagent timeout. The owner accepted
   this explicitly; it is item 14 in `known-limitations.md`.
6. **No human acceptance was performed.** Every UI claim rests on automated tests: no browser,
   keyboard, screen-reader, contrast or visual check was run at any point.

## Review workload

Thirteen units exceeded the 400-line review budget (the largest at about 4.9x, always
tests-driven). The per-unit table is in `tasks.md`. The approved `stacked-to-main` chain was never
instantiated as separate pull requests: the whole change lives as ONE branch over `main`, so the
budget was applied to per-unit commits instead.

## Final measured evidence

- Module suite: **484 tests / 3,689 assertions** passing.
- Full suite: **1,286 tests, 1,263 passed, 11 failed, 12 errors** — exactly the documented
  external baseline, so this change introduced no new failure.
