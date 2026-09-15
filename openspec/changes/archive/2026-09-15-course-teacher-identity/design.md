# Design: Course teacher identity

Change: `course-teacher-identity`  
Phase: `sdd-design`  
Store: OpenSpec  
Scope: Increment 1 — edition-scoped teacher identity and nullable session assignment

## Executive decision

Implement edition teachers as stable, auditable, soft-deletable course-domain entities and let sessions optionally reference one teacher from the same edition. The business invariant is: **a session can only reference a teacher from the same edition**.

This design preserves external teachers without `user_id`, keeps legacy `course_sessions.teacher_name`, avoids delete-all/reinsert synchronization, blocks teacher removal while referenced by sessions, and uses certificate speaker precedence:

1. `course_sessions.teacher_id -> course_edition_teachers.display_name`
2. legacy `course_sessions.teacher_name`
3. `Maia Consultores`

No global teacher catalog, new `user_id` semantics, permissions, notifications, reporting, or production execution are introduced.

## Traceability to approved requirements

| Requirement / AC | Design coverage |
|---|---|
| Stable edition teacher identity / AC-1 | Add `course_edition_teachers.id`; model becomes identity-addressable; sync updates by id. |
| External teacher valid / AC-4 | Keep required `display_name`, optional `email`, nullable passive `user_id`. |
| Sort order is not identity | `sort_order` remains display order only; hidden `id` carries identity. |
| Identity-preserving sync / AC-2 | Service handles update/create/explicit remove; omission does not delete. |
| Removal blocked while assigned / AC-3 | Service rejects removal if sessions reference the teacher; FK restricts hard deletes. |
| Sessions nullable teacher FK / AC-5 | Add `course_sessions.teacher_id`, nullable. |
| Same-edition invariant / AC-6 | Validate and re-query by `(teacher_id, edition_id)` server-side; add composite DB FK when practical. |
| Legacy fallback / AC-7 | Keep `teacher_name` on writes/reads and display fallback. |
| Certificate precedence / AC-8 | Eager-load `edition.sessions.teacher`; compute FK display first, text second, company third. |
| Production prerequisites / AC-9 | Rollout blocked until backup and production counts are captured. |
| No global catalog / AC-10 | Teachers remain children of `course_editions`. |

## 1. Database migration strategy

Target classification: **unknown / production-unverified**. This is advisory design only. Production rollout remains blocked until the owner workflow verifies a current restorable backup and captures production counts for:

- `course_edition_teachers`
- `course_sessions`
- `course_sessions` with non-empty `teacher_name`
- unmatched and ambiguous normalized teacher-name candidates per edition

Local development has zero teacher/session rows, so local backfill is a no-op; this must not be used as production evidence.

### 1.1 Desired final schema

`course_edition_teachers` final shape:

- `id` bigint/integer primary key, auto-incrementing.
- `course_edition_id` FK to `course_editions`, cascade on edition delete remains acceptable because edition deletion owns its child teachers.
- `user_id` nullable FK to `users`, passive only.
- `display_name` required.
- `email` nullable.
- `sort_order` display order only; active teachers must have a unique order within their edition.
- `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` matching course audit conventions.
- Keep an ordering uniqueness guard. Preferred implementation: make `sort_order` nullable and keep/create unique index on `(course_edition_id, sort_order)`. Soft-deleted rows should have `sort_order = null` before or during removal, so active rows keep unique non-null order while deleted rows do not reserve order slots.
- Add supporting unique/index needed for same-edition FK enforcement, preferably `unique (id, course_edition_id)` if the DB composite FK path is used.

`course_sessions` final shape:

- Add nullable `teacher_id`.
- Keep legacy nullable `teacher_name`.
- Add FK from `teacher_id` to `course_edition_teachers.id` with restrict/no action on teacher delete.
- Stronger database invariant where feasible: composite FK `(teacher_id, course_edition_id)` references `(id, course_edition_id)` on `course_edition_teachers`, nullable on `teacher_id`. This complements server checks and prevents cross-edition assignments even outside the HTTP form. If a driver cannot add this in place, use the driver-specific rebuild described below.

### 1.2 Staged intent for MySQL 8

Do not try to add `id` as a primary auto-increment while the composite primary key exists. Use explicit stages:

1. **Preflight inside migration**
   - Assert the current table has no `id` column.
   - Assert existing `(course_edition_id, sort_order)` uniqueness holds. The current primary key should guarantee it, but the migration should fail loudly if not.

2. **Add temporary nullable identity column**
   - Add nullable unsigned big integer `id` without primary/autoincrement semantics.
   - Backfill deterministic ids ordered by `(course_edition_id, sort_order)`.
   - Verify every row has non-null unique `id`.

3. **Convert key shape**
   - Drop the composite primary key.
   - Make `id` the primary key and auto-incrementing.
   - Add or restore unique index for ordering, using nullable `sort_order` as described above.
   - Add index/unique support for `(id, course_edition_id)` if using composite FK enforcement.

4. **Add audit/timestamp/soft-delete columns**
   - Add nullable `created_by`, `updated_by`, nullable timestamps, and nullable `deleted_at`.
   - Do not invent historical actors; existing rows may have null audit users/timestamps.

5. **Add `course_sessions.teacher_id`**
   - Add nullable column.
   - Add regular FK and, if implemented, composite same-edition FK.
   - Add index for certificate/session eager loading.

6. **Backfill `teacher_id` conservatively**
   - See section 5. Never silently assign ambiguous or unmatched names.

### 1.3 Staged intent for SQLite `:memory:` tests

SQLite cannot freely alter primary keys in place. Do not depend on Laravel `dropPrimary()`/`change()` paths for the composite-PK replacement under SQLite.

Use a driver branch for SQLite that rebuilds the affected tables:

1. Create a replacement `course_edition_teachers_new` table with the final schema, including `id integer primary key autoincrement`.
2. Copy existing rows ordered by `(course_edition_id, sort_order)` into the new table. For existing rows, either preserve explicitly assigned ids from the deterministic backfill or let SQLite generate ids when local/test data is empty; production-like SQLite data should still be deterministic in tests.
3. Drop the old table and rename the replacement table.
4. Recreate indexes and FKs.
5. For `course_sessions.teacher_id` plus composite FK, rebuild `course_sessions` if SQLite cannot add the constraint with `ALTER TABLE ADD COLUMN ... REFERENCES ...` in the project’s test connection.
6. Keep foreign-key pragma handling explicit and restore it after rebuild.

The migration test suite must run under the existing SQLite `:memory:` connection and must not assume MySQL-only DDL succeeds in tests.

### 1.4 Rollback / forward-fix posture

Schema rollback is risky after sessions start referencing teacher ids. Prefer forward fixes over destructive rollback. If a rollback is unavoidable, first verify whether any `course_sessions.teacher_id` values exist; dropping them is destructive and needs explicit owner approval. Keep `teacher_name` throughout this increment so display/certificate fallback remains available during mitigation.

## 2. Model and relationship design

### 2.1 `CourseEditionTeacher`

Use the course-domain audit convention by making `CourseEditionTeacher` extend `CourseModel` once the schema has the required columns.

Decision: **extend `CourseModel`, not ad-hoc explicit traits**, because the course module already centralizes `HasAuditColumns`, `SoftDeletes`, `HasFactory`, and Spatie activity logging in `CourseModel`.

Model contract:

- Remove `$timestamps = false`.
- Keep fillable fields: `course_edition_id`, `user_id`, `display_name`, `email`, `sort_order`.
- Keep `edition()` belongs-to.
- Keep `user()` belongs-to as passive optional relationship.
- Add `sessions()` has-many from teacher to `CourseSession` through `teacher_id`.
- Active teacher queries should order by `sort_order`; default global scope is not required unless the project already uses explicit local scopes for ordered children.

Soft-delete note: removal is a soft delete. Before deleting, the service should release active ordering by setting `sort_order = null` or otherwise moving the row out of active order so future active rows can use contiguous positions.

### 2.2 `CourseSession`

Add:

- `teacher_id` to `$fillable`.
- `teacher()` belongs-to `CourseEditionTeacher::class`.

Read contract:

- Display speaker helper may be introduced on the model or kept in the document service. If introduced, it must return FK display name, then `teacher_name`, then `Maia Consultores`.
- Do not drop `teacher_name` or change its legacy meaning.

### 2.3 Same-edition integrity

The invariant must not trust client-submitted IDs. Enforcement layers:

1. Form request validation limits `teacher_id` to teachers for the route edition.
2. Service re-checks inside the transaction by querying `CourseEditionTeacher` with both `id` and `course_edition_id = $edition->id` before assigning.
3. Database composite FK `(teacher_id, course_edition_id) -> (course_edition_teachers.id, course_edition_teachers.course_edition_id)` should be added where the staged migration can support it for both MySQL and SQLite rebuild paths.

If the database composite FK proves impractical in Laravel’s SQLite test harness, the design still requires server-side re-query enforcement and test coverage for attempted cross-edition assignment.

## 3. Teacher sync request, service, and UI contract

### 3.1 Request payload

Existing rows must post hidden ids:

```text
teachers[0][id]
teachers[0][display_name]
teachers[0][email]
teachers[0][user_id]
teachers[0][remove]
```

New teacher row remains separate or may be appended by request preparation:

```text
new_teacher[display_name]
new_teacher[email]
new_teacher[user_id]
```

Validation rules:

- `teachers` nullable array.
- `teachers.*.id` nullable integer; when present, must exist for the current route edition, not just globally.
- `teachers.*.display_name` required unless the same row has explicit remove intent.
- `teachers.*.email` nullable email.
- `teachers.*.user_id` nullable existing user; no new product semantics.
- `teachers.*.remove` boolean affordance.
- `sort_order` stays absent from accepted user-controlled fields; service derives order from submitted active row order.

### 3.2 Service semantics

`CourseEditionService::syncTeachers($edition, $teachers)` becomes identity-preserving:

- Wrap in a transaction.
- Lock the edition’s current teachers or at least lock each submitted existing teacher row before mutation.
- For each submitted non-removed row with `id`: update that teacher only after confirming it belongs to the edition and is not soft-deleted.
- For each submitted non-removed row without `id`: create a new edition teacher.
- For each submitted row with `remove = true`: remove only that teacher after confirming it belongs to the edition.
- Omitted existing teachers remain active. Omission is not removal.
- Recompute `sort_order` for submitted active rows from array order. Existing active teachers omitted from the payload keep their relative order after the submitted rows or keep their current order; implementation must choose one deterministic behavior and test it. Recommended for the current full-page form: because it renders all rows, treat submitted active rows as the desired ordered list for those rows, and append omitted active rows afterward unchanged to satisfy “omission does not remove.”
- Dispatch `CourseEditionChanged($edition->id, 'course-edition-teachers-changed')` after successful transaction as today.

Removal block:

- Before soft-deleting a teacher, check active/non-deleted `course_sessions` in the same edition where `teacher_id = teacher.id`.
- If any exist, throw `InvalidCourseEditionData::forField('teachers', 'No se puede quitar el docente porque está asignado a una o más sesiones. Reasigne las sesiones antes de quitarlo.')` or equivalent clear Spanish UI message.
- Do not alter the session assignment or teacher row on failure.

### 3.3 UI contract

- Render hidden `id` for each existing teacher row.
- Replace the current warning “Guardar reemplaza toda la lista...” with copy explaining identity-preserving updates and explicit removal.
- Keep explicit “Quitar” checkbox.
- Reordering is by row order; client-submitted `sort_order` remains ignored.
- If a remove is blocked, show the server error in the existing error area and preserve input.

### 3.4 Concurrency / lost update posture

Do not invent versioning in this increment. The posture is best-effort last-write-wins for edits and ordering, with transactional row locks to avoid partial writes. Because no optimistic `lock_version`/ETag product requirement exists, the implementation should not add user-facing conflict resolution. Tests should assert atomicity and blocked removal, not new conflict UX.

## 4. Session form, service, and certificate contract

### 4.1 Session request payload

Each session row adds nullable `teacher_id` while preserving `teacher_name`:

```text
sessions[0][teacher_id]
sessions[0][teacher_name]
```

Validation:

- `sessions.*.teacher_id` nullable integer.
- If present, it must belong to the current route edition.
- `sessions.*.teacher_name` remains nullable string max 255.

### 4.2 Select / clear behavior

- The session form should render a select of active same-edition teachers ordered by `sort_order`.
- Include an empty option for clearing `teacher_id`.
- Clearing the select writes `teacher_id = null`.
- `teacher_name` remains editable or preserved as legacy fallback according to the current UI. If both are submitted, `teacher_id` controls authoritative teacher display/certificate output.
- New sessions can be saved with no `teacher_id` and optional `teacher_name`.

### 4.3 Service writes

`syncSessions()` must:

- Re-query any non-null `teacher_id` by `id` and `course_edition_id = $edition->id` inside the transaction.
- Reject cross-edition IDs with a field-specific `InvalidCourseEditionData` error.
- Fill both `teacher_id` and `teacher_name`.
- Keep existing positional upsert behavior unless separately changed by another approved requirement.

### 4.4 Reads and display

- Session list should display FK teacher display name when present; otherwise legacy `teacher_name`; otherwise blank/placeholder.
- Session edit rows must preserve the selected `teacher_id` on validation errors.
- The sessions management view should load active edition teachers for the select without creating N+1 queries.

### 4.5 Certificate generation and N+1 avoidance

Update document generation load from:

```php
$enrollment->loadMissing('edition.activity', 'edition.sessions', 'participant', 'group')
```

to include session teachers:

```php
$enrollment->loadMissing('edition.activity', 'edition.sessions.teacher', 'participant', 'group')
```

`certificateSyllabus()` speaker expression becomes:

```text
session.teacher.display_name if teacher exists
else session.teacher_name if non-empty
else Maia Consultores
```

This avoids per-session lazy loading and preserves certificate fallback behavior for legacy rows.

## 5. Migration and data backfill

### 5.1 Production preflight

Before staging/production execution, capture and retain:

- Restorable backup evidence.
- Count of teacher rows.
- Count of session rows.
- Count of sessions with non-empty `teacher_name`.
- Per-edition duplicate normalized teacher display names.
- Per-edition session teacher names with no normalized teacher match.
- Per-edition ambiguous normalized matches.

Production remains blocked until these exist. Local zero-row behavior: local has zero teacher/session rows, so no local data assignment occurs; this does not reduce production checks.

### 5.2 Name matching normalization

For optional automatic `teacher_id` backfill from legacy `teacher_name`, normalize only for candidate detection:

- trim
- collapse internal whitespace
- case-fold/lowercase
- remove accents if the project has a safe helper; otherwise document accent sensitivity

Do not normalize by dropping meaningful punctuation/titles unless explicitly approved. The purpose is conservative matching, not identity invention.

### 5.3 Backfill rules

- If session `teacher_name` is null/blank: leave `teacher_id = null`.
- If exactly one active teacher in the same edition matches the normalized name: assign that teacher id.
- If no teacher matches: leave `teacher_id = null`; keep `teacher_name`.
- If more than one teacher matches: leave `teacher_id = null`; keep `teacher_name`; report ambiguity.
- Never create teacher rows from session names during this increment.
- Never choose among ambiguous teachers based on `user_id`, email, or order without explicit approval.

### 5.4 Rollback / recovery

After `teacher_id` is used, rollback is primarily forward-fix. Because `teacher_name` remains, an emergency UI rollback can hide FK selection and continue displaying/certifying from legacy text. Destructive removal of `teacher_id` values or teacher audit history requires separate approval.

## 6. Test strategy with strict TDD seams

Use RED/GREEN/TRIANGULATE/REFACTOR for each seam. Required tests are feature/integration-heavy because this change crosses migrations, services, requests, views, and certificate generation.

### 6.1 Migration tests — `CourseTeacherIdentityMigrationTest` or extend `CourseDomainFoundationTest`

RED:

- Assert teachers have an `id` primary key, timestamps/audit/soft-delete columns.
- Assert ordering uniqueness exists and local zero-row migration succeeds under SQLite `:memory:`.
- Assert `course_sessions.teacher_id` exists and is nullable.

GREEN:

- Implement migration branch/rebuild.

TRIANGULATE:

- Add a seeded pre-migration-like fixture test if the project has migration test support; otherwise assert final schema and FK behavior through models.

REFACTOR:

- Extract migration helper methods only if they reduce driver-branch duplication.

MySQL-specific DDL cannot be fully proven by SQLite tests. The PR must document that MySQL 8 path requires staging/dry-run verification before production.

### 6.2 Teacher sync tests — `CourseEditionTeachersHttpTest` and/or service test

Required coverage:

- Updating one teacher preserves unrelated teacher identity.
- Reordering preserves ids.
- Omitted teacher is not removed.
- Explicit remove removes only selected teacher.
- External teacher without `user_id` remains valid.
- Hidden id from another edition is rejected / ignored with an error, never reassigned.
- Removing a teacher assigned to a session is rejected with clear error and leaves teacher/session unchanged.
- Removing after reassignment/clear succeeds.
- Existing permission tests remain valid.

### 6.3 Session tests — `CourseEditionSessionsHttpTest`

Required coverage:

- Same-edition teacher can be assigned to a session.
- Cross-edition teacher id is rejected.
- Clearing select stores `teacher_id = null` and keeps/uses `teacher_name` fallback.
- Existing `teacher_name` validation and positional upsert behavior continue.
- View renders teacher select without losing legacy text.

### 6.4 Certificate regression — `CourseAcademicDocumentGenerationTest`

Required coverage:

- FK teacher display name takes precedence over legacy `teacher_name`.
- Legacy `teacher_name` is used when `teacher_id` is null.
- `Maia Consultores` is used when neither FK nor text exists.
- Generation eager-loads `edition.sessions.teacher`; add query-count protection only if the project already has stable query-count testing. Otherwise assert no lazy-loading exception if lazy loading prevention is available in tests.

### 6.5 Audit/security tests — `CourseAuditTest` or teacher tests

Required coverage:

- `CourseEditionTeacher` creates/updates/deletes produce expected course audit behavior if existing audit tests cover course models.
- Authorization remains unchanged: teacher management uses edition manage ability; session management uses existing manage sessions ability.

## 7. Changed surface and PR boundary

Estimated changed lines for the full increment: **550–850 lines**, mainly because it crosses DDL, model conventions, two request/service/view surfaces, and certificate tests. This likely exceeds the 400-line review budget.

Minimum safe split without granting a size exception:

1. **PR 1 — teacher identity foundation and sync**
   - Migration converts `course_edition_teachers` to stable id/audit/soft-delete.
   - `CourseEditionTeacher` extends `CourseModel`.
   - Teacher sync/UI uses hidden ids, identity-preserving update/create/remove, omission safety.
   - No session `teacher_id` UI yet unless required by migration sequencing.

2. **PR 2 — session assignment and certificates**
   - Add/use `course_sessions.teacher_id` if not already added.
   - Enforce same-edition invariant in request/service/DB where practical.
   - Block teacher removal while referenced.
   - Update session UI select and certificate precedence/eager loading.
   - Add migration/backfill reporting and certificate regression tests.

If a single PR is still requested later, it requires an explicit `size:exception` approval; this design does not grant one.

## 8. Security, authorization, and audit

- Existing route authorization remains the authority:
  - Teacher management: edition manage/update ability.
  - Session management: existing `manageSessions` behavior.
- Hidden ids are untrusted. Every id is validated and re-queried by route edition.
- Cross-edition assignment is forbidden even if a valid teacher id is submitted.
- Removal block is server-side and cannot rely on UI disabling.
- `user_id` remains passive; it grants no permissions, notifications, ownership, or CRM-user lifecycle behavior.
- Extending `CourseModel` makes teacher rows auditable with created/updated users, timestamps, soft deletes, and activity log entries.
- Soft deletion preserves history; hard deletes should remain constrained by FK behavior and not be normal application flow.

## 9. Intentionally out of scope

- Global reusable teacher catalog.
- Teacher deduplication across editions.
- New meaning for `user_id`.
- Teacher permissions, notifications, availability, lifecycle, or ownership rules.
- Dropping `teacher_name`.
- Automatically creating teachers from session names.
- Production migration execution.
- New optimistic locking or user-facing conflict resolution.

## 10. Database safety summary

| Topic | Decision |
|---|---|
| Target | Unknown / production-unverified. Advisory only. |
| Schema/data change | Primary-key conversion, audit/timestamps/soft deletes, nullable session FK, conservative backfill. |
| Compatibility | Local zero rows are easy; production unknown until counts are captured. |
| Backup status | Required before staging/production; currently not verified in this phase. |
| Forward migration | Driver-specific staged migration with MySQL alter path and SQLite rebuild path. |
| Rollback reality | Forward-fix preferred once sessions reference teacher ids. |
| Post-change verification | Row counts preserved, ids non-null/unique, active order unique, no cross-edition session references, certificate speaker precedence passes. |

## Key Learnings

- The safest identity conversion is staged: backfill ids before replacing the composite primary key, and rebuild tables under SQLite.
- Soft-deleted teachers should not reserve active `sort_order` slots; release/null the order on removal while preserving audit history.
- Same-edition teacher assignment must be enforced by server re-query and should be reinforced by a composite FK where the driver path supports it.
- Certificate generation needs `edition.sessions.teacher` eager loading to avoid N+1 while changing speaker precedence.
- The full increment is likely over the 400-line review budget; split teacher identity/sync from session assignment/certificates unless a later explicit size exception is approved.
