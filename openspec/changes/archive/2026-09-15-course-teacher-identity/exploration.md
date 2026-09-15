# Exploration: Course teacher identity

Change: `course-teacher-identity`  
Phase: `sdd-explore`  
Artifact store: OpenSpec  
Increment: 1 of 3 — identity only

## Scope boundary

This exploration covers only making `course_edition_teachers` an identifiable course-domain entity and preparing `course_sessions` to reference it. It intentionally does not decide Increment 2 (`user_id` semantics) or Increment 3 (global reusable teacher catalog + pivot), but notes where those later increments would attach.

This change contradicts the archived course-talks design: `openspec/changes/archive/2026-09-12-course-talks-management/design.md` defines `course_edition_teachers` as `course_edition_id`, nullable `user_id`, `display_name`, `email`, `sort_order` and explicitly says it "Allows internal teachers or external display-only teachers." The canonical spec, `openspec/specs/course-talks/course-talks-management.md`, requires an edition to store teachers and a class/session to contain a teacher, but does not require the teacher to be a reusable entity.

## Evidence verified

- `database/migrations/2026_08_26_000001_create_course_domain_foundation_tables.php` creates `course_edition_teachers` without `id`, timestamps, audit columns, or soft deletes, and with primary key `(course_edition_id, sort_order)`. The same migration applies its `audit()` helper to every other course-domain table except `course_edition_teachers` and `course_attendances` has a narrower timestamp-only convention.
- `course_sessions` already has `id`, `teacher_name` nullable text, unique `(course_edition_id, sort_order)`, audit columns, timestamps, and soft deletes.
- `app/Services/Courses/CourseEditionService.php::syncTeachers()` deletes all edition teachers and reinserts the submitted list with `sort_order = index + 1`, then dispatches `CourseEditionChanged($edition->id, 'course-edition-teachers-changed')`.
- `SyncEditionTeachersRequest` validates a full-list payload, drops rows marked `remove`, appends `new_teacher` when non-empty, requires `teachers.*.display_name`, validates nullable `email`, and validates nullable `user_id` as an existing user.
- `CourseEditionTeacher` extends plain `Model`, uses `HasFactory`, has `$timestamps = false`, and defines `edition()` and `user()` relationships. The `user()` relation has no confirmed runtime caller; the only observed `user_id` reads are display/form reads in the teachers UI.
- `resources/views/course-talks/editions/show.blade.php` renders the Docentes card/form only when `$teachers` is passed, and renders the sessions teacher input as free text `sessions[*][teacher_name]`.
- `CourseDocumentGenerationService::certificateSyllabus()` uses session `teacher_name` as the certificate syllabus row `speaker`, falling back to `Maia Consultores` when blank.

## Writer/reader map

### `course_edition_teachers`

| Area | Reads | Writes | Notes |
|---|---:|---:|---|
| Migration | Defines table | Creates/drops table | Composite primary key `(course_edition_id, sort_order)`, FK to editions cascades on delete, nullable FK to users. |
| `CourseEdition` model | Defines `teachers()` has-many | No direct write | Relation is ordered by callers, not by relation definition. |
| `CourseEditionController::teachers()` | Reads ordered teachers | No write | Passes `$teachers` explicitly to shared edition view. |
| `CourseEditionController::syncTeachers()` | No direct read | Delegates write | Uses FormRequest payload and service. |
| `CourseEditionService::syncTeachers()` | Deletes through relation | Delete-all + insert | Primary writer. Silent partial payload risk is real because service treats payload as complete replacement. |
| `SyncEditionTeachersRequest` | Reads request only | No DB write | Validates `user_id` but does not attach semantics beyond existence. |
| `show.blade.php` teachers block | Displays `display_name`, `email`, `user_id` badge and form values | HTML form posts full list | No `id` in form today; rows are position/value-based. |
| Tests | Assert DB rows/counts | Seed through service | `CourseEditionTeachersHttpTest` and `CourseActivityEditionServiceTest` lock delete+reinsert/full-list behavior. |
| Factory/seeders/demo data | No factory found | No seed/demo writer found | There is no `CourseEditionTeacherFactory.php`; `HasFactory` would not resolve a backing factory today. |
| Exports/CSV/reports/dashboards | No consumer found | No writer found | Searches found no course teacher export or dashboard use. |
| Certificate/document generation | No consumer found | No writer found | Certificates currently read sessions, not edition teacher rows. |

### `course_sessions.teacher_name`

| Area | Reads | Writes | Notes |
|---|---:|---:|---|
| Migration | Defines nullable text column | Creates/drops column with table | No FK; no index. |
| `CourseSession` model | Fillable/cast context | Eloquent writes allowed | Model extends `CourseModel`, so session changes are audited and soft-deleted. |
| `CourseEditionService::syncSessions()` | No read before fill except `firstOrNew` by position | Upserts `teacher_name` from payload | Upserts by `(course_edition_id, sort_order)` and restores soft-deleted rows. It does not delete omitted sessions. |
| `SyncEditionSessionsRequest` | Reads request | No DB write | Validates nullable string `teacher_name`; no FK/name-list validation. |
| `show.blade.php` sessions block | Displays session teacher text; pre-fills form | HTML form posts text | User-visible drift point from edition teacher list. |
| `CourseDocumentGenerationService::certificateSyllabus()` | Reads `teacher_name` | No write | Certificate syllabus `speaker` is `(string) ($session->teacher_name ?: 'Maia Consultores')`. |
| Certificate Blade | Renders `speaker` | No write | `resources/views/course-talks/certificates/reference.blade.php` prints syllabus speaker. |
| `CourseSessionFactory` | Seeds fake teacher name | Writes factory default | Tests using factory can create teacher text unrelated to edition teachers. |
| Tests | Many assertions | Seed through service/factory | `CourseEditionSessionsHttpTest`, `CourseActivityEditionServiceTest`, `CourseAcademicDocumentGenerationTest`, `CourseDomainFoundationTest`. |
| Exports/CSV/reports/dashboards | No direct course export found | No writer found | Generic dashboards and alert dashboards do not read session teacher names. |

### `CourseEditionChanged('course-edition-teachers-changed')`

No consumer/listener was found in `app/`, `resources/`, `routes/`, `database/`, or course tests. The event class is a simple after-commit event with `editionId` and `event`; all observed dispatches are from `CourseEditionService`. Current practical effect appears to be only test/future integration signaling, not application behavior.

## Migration path for adding identity

Target classification: unknown. This is advisory only; no migration or destructive database operation was run.

Current schema concern: `course_edition_teachers` has a composite primary key. A new auto-increment `id` primary key cannot coexist with that primary key, so the migration must change key shape, not merely add a nullable column.

Safe cross-database path to evaluate for MySQL 8 and SQLite:

1. Add nullable `id` column first, without primary/autoincrement semantics.
2. Backfill every existing row with a unique integer value in a deterministic order, e.g. ordered by `(course_edition_id, sort_order)`. This avoids requiring the database to synthesize auto-increment values while the composite PK still exists.
3. Ensure every row has a unique non-null `id` and there are no duplicate `(course_edition_id, sort_order)` values beyond the existing PK invariant.
4. Rebuild/change table key shape:
   - MySQL 8: drop the composite primary key, make `id` the primary auto-increment key, and add a separate unique index on `(course_edition_id, sort_order)` if the ordering invariant must remain unique.
   - SQLite: prefer Laravel's table rebuild path or an explicit create-copy-rename strategy, because SQLite cannot freely alter primary keys in place. The migration must be tested under the project's SQLite test connection.
5. Add audit columns in a step that is compatible with existing rows: nullable `created_by`, nullable `updated_by`, nullable timestamps, nullable `deleted_at`.
6. Only after code reads by `id`, add optional `course_sessions.teacher_id` as nullable FK in a later migration step. Do not drop `teacher_name` until product decides text fallback/legacy display rules.

Row counts requested:

- `course_edition_teachers`: not inspected.
- non-null `course_sessions.teacher_name`: not inspected.

Reason: this executor had only read/write/search file tools, not a safe database query tool or command runner. Running migrations/destructive operations was prohibited, and no DB read was performed. Open question: obtain read-only counts from the target database before proposal, including duplicate normalized names per edition and session teacher names that do not match an edition teacher.

## Backfill considerations for `course_sessions.teacher_id`

Name matching is best-effort and should not be silently authoritative. Orphan/ambiguous cases to enumerate before deciding:

- Session has `teacher_name = null` or blank.
- Session has a name not present in the edition teacher list.
- Session name differs by case, accents, punctuation, extra spaces, titles, or abbreviations.
- More than one edition teacher has the same normalized display name.
- A teacher row was deleted/reinserted by sync, so historical identity is unavailable before `id` exists.
- A session references a teacher name from an old edition teacher list that no longer exists.
- A session's `teacher_name` is a company/house speaker fallback rather than a person.
- `user_id` exists on one teacher but not another with the same display name.
- Soft-deleted future teacher rows may or may not count after audit/soft delete is added.

Decision points for the orchestrator/user:

- Leave unmatched sessions as text only, or require manual mapping before save?
- Store both `teacher_id` and legacy `teacher_name`, or derive the display name from the teacher entity after migration?
- For ambiguous normalized matches, block automatic backfill, pick none, or require manual selection?
- Should future session forms restrict teacher selection to the edition teacher list, allow free-text external teachers, or support both?
- Should deleting/removing an edition teacher be blocked when sessions reference it, soft-delete only, or leave historical session references intact?

## Blast radius of adding `id`

Likely affected files/areas for Increment 1:

- Migration: new migration to add/backfill `id`, convert primary key, preserve unique `(course_edition_id, sort_order)`, add audit/timestamps/soft deletes, and likely add nullable `course_sessions.teacher_id` only if proposal includes the session FK in increment 1.
- Model: `CourseEditionTeacher` would likely extend `CourseModel` instead of plain `Model`, remove `$timestamps = false`, add casts/relations as needed, and set identity assumptions explicitly if not using defaults.
- Service: `syncTeachers()` currently relies on delete+reinsert and array position. Adding stable identity conflicts with that behavior unless the service changes to update existing rows by `id` and explicitly remove selected rows.
- FormRequest/view: current teachers form posts no `id`; it cannot preserve row identity. It would need hidden `teachers[*][id]` and validation constrained to the edition if identity-preserving sync is desired.
- Sessions form: current teacher field is text. If `teacher_id` is introduced, view/request/service/tests must change to select or submit IDs while preserving legacy text decisions.
- Tests: `CourseEditionTeachersHttpTest` currently asserts full replacement, removal by omission, zero rows after removing all teachers, and client `sort_order` ignored. Those tests either change or get complemented with identity-preserving tests. `CourseEditionSessionsHttpTest` and `CourseAcademicDocumentGenerationTest` currently assert/use `teacher_name` text. `CourseDomainFoundationTest` comments explicitly call out the no-`id` teacher table and would need updating.
- Factories: there is no teacher factory today; adding one would support entity tests. `CourseSessionFactory` currently generates arbitrary `teacher_name` text and may need a `forTeacher()` state later.
- Activity logs: changing the model to `CourseModel` will start Spatie activity logging and audit column filling for teacher creates/updates/deletes. Existing delete+reinsert would create noisy delete/create audit histories instead of stable update diffs.
- Composite key reliance: no application code was found that directly addresses a teacher row by composite key, but the database and tests rely on `(edition, sort_order)` uniqueness and replacement semantics.
- `updateOrCreate` reliance: no `updateOrCreate` against `course_edition_teachers` was found. Sessions, not teachers, use `firstOrNew` by `(edition, sort_order)`.

## Audit-column implications

Other course-domain models mostly get audit behavior by extending `App\Models\Courses\CourseModel`, which uses:

- `HasAuditColumns`: fills `created_by` on create and `updated_by` on update from the authenticated user when present.
- `HasFactory`.
- `LogsActivity` with `course-*` descriptions, dirty-only, log-all.
- `SoftDeletes`.

`CourseEditionTeacher` currently extends plain `Model`, uses `HasFactory`, disables timestamps, and has no soft deletes or activity logging. To join the course audit convention it would need schema columns (`created_by`, `updated_by`, timestamps, `deleted_at`) and model inheritance/traits compatible with those columns. If it extends `CourseModel`, delete-all + reinsert becomes materially more visible: removals become soft deletes/activity entries, new rows get new identities, and audit history may become noisy unless sync becomes update-by-id.

## Review-budget estimate

Estimated Increment 1 changed lines: approximately 300–430 lines, depending on whether `course_sessions.teacher_id` is included in this increment or deferred.

Rough breakdown:

- Migration/backfill/key conversion/audit columns: 80–140 lines.
- `CourseEditionTeacher` model and optional factory: 25–60 lines.
- `CourseEditionService::syncTeachers()` identity-preserving update path: 60–100 lines.
- `SyncEditionTeachersRequest` and view hidden IDs/removal validation: 50–90 lines.
- Session teacher FK prep/form/request/service changes, if included: 60–120 lines.
- Tests/spec delta: 100–180 lines.

Budget risk: medium. The 400-line budget is plausible if Increment 1 is strictly "teacher row identity + audit + identity-preserving sync" and defers session FK/backfill mechanics. It likely exceeds 400 if it also implements `course_sessions.teacher_id`, automatic matching, certificate display changes, and broad test rewrites in the same PR.

## Open decision gaps before proposal

1. Is Increment 1 limited to adding stable IDs/audit to `course_edition_teachers`, or must it also add `course_sessions.teacher_id` now?
2. Should the teacher sync UI remain a full-list replacement surface, or become identity-preserving with explicit remove/update/create actions?
3. When a teacher is removed from an edition but sessions reference it, should removal be blocked, soft-deleted but retained for history, or allowed with sessions preserving historical text?
4. For certificate syllabus speaker display, should future output read from `course_sessions.teacher_id -> display_name`, keep `teacher_name`, or prefer FK with text fallback?
5. How should unmatched/ambiguous session teacher names be handled during backfill: leave unmapped, mark for manual mapping, block future saves, or create new edition teacher rows?
6. Should `user_id` remain a passive optional link in Increment 1, or should the proposal explicitly state that semantics remain out of scope until Increment 2?
7. Does the archived design decision allowing display-only teachers need formal reversal, or only refinement from "value row" to "edition-scoped entity" while still allowing external display-only teachers?

## Database safety notes

- Advisory target: unknown database/environment.
- Schema/data change: primary-key conversion, audit/timestamp/soft-delete additions, possible nullable FK addition from sessions to edition teachers.
- Existing-data compatibility depends on row counts, matched/unmatched teacher names, and whether production has rows not represented in local tests.
- Backup status: unknown; must be verified before applying to staging/production.
- Rollback reality: reverting a primary-key conversion is possible in schema terms but risky after other tables begin referencing `course_edition_teachers.id`; once `course_sessions.teacher_id` exists and is used, rollback must preserve or intentionally remove those references. Soft-deleted/audit history should not be casually destroyed.
- Post-change verification should include row count preservation, unique `(course_edition_id, sort_order)`, all teacher rows with non-null unique `id`, audit columns present, session display unchanged, and certificate syllabus output unchanged unless explicitly changed.

## Key Learnings

- `course_edition_teachers` is the only course teacher list and is currently modeled as ordered values, not stable entities.
- The existing teacher form and service intentionally implement full replacement; adding identity without changing the form would not preserve identity.
- `course_sessions.teacher_name` has real downstream impact because certificate syllabus rows render it as `speaker`.
- The `course-edition-teachers-changed` event currently has no found consumer, so changing it is low runtime risk but should preserve future-integration intent.
- Adding audit behavior makes the current delete+reinsert sync noisy and potentially misleading; identity-preserving sync is the main design pressure of Increment 1.
