# course-teacher-identity — Stable edition teacher identity for course sessions and certificates

> **Phase**: sdd-proposal.
> **Artifact store**: OpenSpec.
> **Change**: `course-teacher-identity`.
> **Primary source**: `exploration.md` plus confirmed pre-proposal product decisions.
> **Increment**: 1 of 3 — edition-scoped teacher identity and session FK.

---

## 1. Executive summary

Increment 1 will turn `course_edition_teachers` from ordered value rows into stable edition-scoped teacher entities, while preserving support for external display-only teachers. Course sessions will gain a nullable `teacher_id` reference to an edition teacher, and certificate syllabus output will prefer the actual assigned teacher through that FK, with legacy text fallback retained.

The change solves operational drift between the edition teacher list, session teacher text, and official certificate speakers. It also prevents editing one teacher from deleting/recreating unrelated teacher rows, and blocks removing a teacher that is still assigned to sessions.

---

## 2. Why / problem statement

Today the course teacher list is not a stable identity surface:

- `course_edition_teachers` has composite primary key `(course_edition_id, sort_order)`, no `id`, and no audit/timestamps/soft-delete convention.
- `CourseEditionTeacher` is a plain `Model` with `$timestamps = false`.
- `CourseEditionService::syncTeachers()` deletes all teachers for an edition and reinserts the submitted list.
- Course sessions store `teacher_name` as free text, not a reference to an edition teacher.
- The official certificate syllabus uses session teacher text as `speaker`, so stale or mismatched names can reach generated documents.

Operationally, this means an edit to one teacher can destroy row identity for all teachers in the edition, sessions can drift away from the authoritative teacher list, and there is no server-side way to enforce that a session uses a teacher from its own edition.

---

## 3. Product outcome

After this increment:

1. Edition teachers have stable identities suitable for update, reference, audit, and future evolution.
2. Editing one teacher preserves other teacher records instead of replacing the whole list by delete/reinsert.
3. A session can reference a teacher belonging to the same edition.
4. Removing a teacher assigned to sessions is blocked server-side until sessions are reassigned.
5. Certificates display the assigned session teacher when available, while preserving legacy fallback behavior.
6. External teachers remain supported through `display_name` and optional `email` without requiring a CRM `user_id`.

---

## 4. Scope for Increment 1

Includes:

1. Add stable identity to `course_edition_teachers` while preserving edition ordering.
2. Preserve external/display-only teachers: `display_name` remains required, `email` remains optional, and `user_id` remains passive.
3. Replace delete-all/reinsert teacher sync with identity-preserving create/update/remove behavior.
4. Require explicit teacher removal and ensure editing one teacher does not mutate or delete unrelated teacher records.
5. Add nullable `course_sessions.teacher_id` FK to `course_edition_teachers`.
6. Enforce the invariant that a session may reference only a teacher from its own edition.
7. Retain `course_sessions.teacher_name` as a compatibility/display fallback.
8. Update certificate syllabus speaker selection to use `session.teacher_id -> teacher.display_name`, else `teacher_name`, else `Maia Consultores`.
9. Block teacher removal when assigned sessions exist; users must reassign sessions first.
10. Preserve `CourseEditionChanged('course-edition-teachers-changed')` as future-integration signaling unless design finds a safe reason to adjust it.

---

## 5. Non-goals

Increment 1 does **not** include:

- A global reusable teacher catalog.
- Changing the product meaning of `user_id`.
- Teacher permissions, notifications, availability, ownership, or CRM-user lifecycle rules.
- Dropping `course_sessions.teacher_name`.
- Automatic creation of global teacher records from names.
- Broad reporting, analytics, teacher utilization dashboards, or cross-edition teacher history.
- Production migration execution; this proposal only defines prerequisites and product intent.

---

## 6. Explicit decisions

| Topic | Decision |
|---|---|
| Teacher identity | Edition teachers become stable entities, not replaceable value rows. |
| Teacher sync | Use identity-preserving create/update/remove; no delete-all/reinsert. |
| Removal safety | Server-side block removal when assigned sessions exist. |
| External teachers | Keep `display_name` and optional `email`; no CRM user required. |
| `user_id` | Remains out of scope for Increment 1 beyond existing passive storage/validation. |
| Session teacher | Add `course_sessions.teacher_id` while keeping legacy `teacher_name`. |
| Edition invariant | A session may reference only a teacher from its own edition. |
| Certificate speaker | Prefer assigned teacher display name; fall back to `teacher_name`; then `Maia Consultores`. |
| Archived design relationship | Refines archived display-only edition teachers into stable edition-scoped entities; does not introduce a global catalog. |

---

## 7. Verified rationale evidence

The proposal is grounded in these verified repository facts:

- `course_edition_teachers` currently has composite primary key `(course_edition_id, sort_order)`, no `id`, no audit columns, no timestamps, and no soft-delete convention.
- `CourseEditionTeacher` is a plain Eloquent `Model` with `$timestamps = false`.
- `CourseEditionService::syncTeachers()` currently performs delete-all + reinsert behavior.
- `course_sessions.teacher_name` is free text.
- Session teacher text is rendered as `speaker` in official certificate output through `CourseDocumentGenerationService::certificateSyllabus()` and `resources/views/course-talks/certificates/reference.blade.php`.
- `CourseEditionChanged('course-edition-teachers-changed')` has no current listener/consumer found in the explored code paths.
- Local read-only development counts are: 2 editions, 0 `course_edition_teachers`, 0 `course_sessions`, and 0 sessions with teacher name. Therefore local backfill is zero.
- Production counts are unknown and must not be inferred from local development data.
- The archived design at `openspec/changes/archive/2026-09-12-course-talks-management/design.md` allowed internal or external display-only teachers. This proposal preserves external teachers while refining the table from ordered value rows into stable edition-scoped entities.

---

## 8. Affected behavior

### Teachers

- Existing teacher rows become addressable by stable identity.
- Updating one teacher changes that teacher only.
- Reordering remains possible without treating all teachers as new records.
- Removing a teacher requires an explicit removal action.
- Removing a teacher with assigned sessions fails server-side with a clear operational message.

### Sessions

- Session forms and services should support selecting an edition teacher by identity.
- Legacy `teacher_name` remains available for old records and fallback display.
- Session save behavior must reject a `teacher_id` that belongs to another edition.

### Certificates

- Certificate syllabus speaker display becomes more authoritative when a session has `teacher_id`.
- Existing certificates/session rows without `teacher_id` continue to render through the legacy `teacher_name` fallback, then `Maia Consultores`.

---

## 9. Migration and data-safety prerequisites

Target classification: **unknown / production-unverified**. This proposal is advisory; production execution requires the owner workflow.

### Local facts

Local development read-only counts show zero teacher and session backfill work:

| Dataset | Local count |
|---|---:|
| Course editions | 2 |
| `course_edition_teachers` | 0 |
| `course_sessions` | 0 |
| Sessions with teacher name | 0 |

These local facts do **not** prove production safety.

### Blocking operational prerequisites before migration

Before applying schema/data changes to staging or production:

1. Verify a current restorable database backup exists.
2. Capture production counts for `course_edition_teachers`, `course_sessions`, and sessions with non-empty `teacher_name`.
3. Identify session teacher names that match no edition teacher.
4. Identify ambiguous normalized matches within the same edition.
5. Confirm whether unmatched/ambiguous sessions remain text-only or require manual mapping before enabling strict selection.
6. Dry-run or otherwise verify the primary-key conversion path for the target DB engine.
7. Verify SQLite test compatibility if repository tests use SQLite for migrations.

### Data-safety posture

- Existing teacher rows must be preserved and assigned stable IDs, not recreated wholesale.
- `(course_edition_id, sort_order)` ordering uniqueness should remain protected unless design explicitly replaces it with an equivalent invariant.
- `course_sessions.teacher_id` should be nullable so unmatched legacy session text remains valid.
- Automatic backfill from names is best-effort and must not silently choose among ambiguous matches.
- `teacher_name` must remain available as compatibility/display fallback in this increment.

---

## 10. Rollback posture

Rollback should be treated conservatively because a primary-key conversion and new FK can become referenced by application data.

If the feature must be backed out shortly after deployment:

1. Stop using the new teacher assignment UI/service path.
2. Preserve teacher rows, audit history, and session text data.
3. Prefer disabling/hiding FK-driven selection over destructive data rollback.
4. Keep `course_sessions.teacher_name` available for display and certificate fallback.
5. If schema rollback is required, first verify whether any sessions reference `teacher_id`; dropping those references is destructive unless explicitly approved.
6. After production use, a forward fix is likely safer than reverting the teacher identity model.

---

## 11. High-level affected files/domains

Implementation design belongs to `sdd-design`; expected affected areas are:

| Domain | Expected impact |
|---|---|
| Database migrations | Teacher primary-key conversion, stable IDs, audit/timestamp/soft-delete alignment, nullable session FK. |
| Course models | `CourseEditionTeacher`, `CourseSession`, and relationships to support identity and session assignment. |
| Course services | Identity-preserving teacher sync, removal safety, session teacher assignment invariant. |
| Form requests | Validation for teacher IDs, explicit removal, and edition-scoped session teacher references. |
| Course edition UI | Preserve teacher row IDs; allow explicit removal and session teacher selection. |
| Certificate generation | Prefer FK teacher display name with legacy fallbacks. |
| Tests | Update replacement-behavior tests and add acceptance/regression coverage for identity, removal blocking, FK invariant, and certificate fallback. |

---

## 12. Risks and tradeoffs

| Risk | Why it matters | Mitigation |
|---|---|---|
| Production data unknown | Local backfill is zero, but production may contain many teacher/session rows. | Make production counts and backup verification blocking prerequisites. |
| Primary-key conversion | `course_edition_teachers` currently has a composite PK; adding `id` changes table shape. | Use a staged migration path verified against target DB engines. |
| Ambiguous name backfill | Existing session text may not map cleanly to edition teachers. | Keep `teacher_id` nullable and avoid silent ambiguous matches. |
| Certificate regression | Certificates are official output and currently depend on `teacher_name`. | Preserve exact fallback chain: FK display name, legacy text, `Maia Consultores`. |
| Audit noise | Current delete/reinsert behavior would be misleading once teacher rows have identity/audit. | Replace sync with identity-preserving updates before relying on audit history. |
| Review workload | Increment 1 may approach the 400-line review budget. | Exact forecast and delivery slicing belong to `sdd-tasks`; no exception is assumed here. |

---

## 13. Future increments out of scope

### Increment 2 — `user_id` meaning, permissions, notifications

Future work may define what it means for an edition teacher to link to a CRM user, including permissions, notifications, ownership, profile display, and lifecycle behavior. Increment 1 intentionally avoids assigning those semantics.

### Increment 3 — global reusable catalog and reporting

Future work may introduce a reusable teacher catalog, cross-edition reuse, reporting, teacher utilization metrics, or global deduplication. Increment 1 intentionally keeps teachers scoped to an edition.

---

## 14. Acceptance-level success criteria

- **AC-1 — Stable teacher identity**: An existing edition teacher has a stable ID and can be updated without deleting/recreating other teachers in the same edition.
- **AC-2 — Explicit removal only**: Omitting a teacher from an edit payload does not silently remove it; removal requires explicit intent.
- **AC-3 — Removal blocked when assigned**: A teacher assigned to one or more sessions cannot be removed; the server returns a clear failure and the user must reassign sessions first.
- **AC-4 — External teacher preserved**: A teacher with `display_name` and optional `email`, but no CRM `user_id`, remains valid.
- **AC-5 — Session teacher FK**: A session can reference an edition teacher by `teacher_id`.
- **AC-6 — Edition invariant enforced**: A session cannot reference a teacher from another edition, even if the client submits such an ID.
- **AC-7 — Legacy fallback retained**: A session without `teacher_id` still displays and renders using `teacher_name` when present.
- **AC-8 — Certificate speaker precedence**: Certificate syllabus speaker uses `session.teacher_id -> teacher.display_name`, else `teacher_name`, else `Maia Consultores`.
- **AC-9 — Local zero backfill acknowledged**: Local dev migration/backfill has zero existing teacher/session rows, but production rollout remains blocked on production counts and backup verification.
- **AC-10 — No global catalog introduced**: Teachers remain edition-scoped entities in Increment 1.

---

## Quick cross-reference

- **Change root**: `openspec/changes/course-teacher-identity/`.
- **Proposal artifact**: `openspec/changes/course-teacher-identity/proposal.md`.
- **Exploration artifact**: `openspec/changes/course-teacher-identity/exploration.md`.
- **Archived design reference**: `openspec/changes/archive/2026-09-12-course-talks-management/design.md`.
- **Artifact language**: English.
