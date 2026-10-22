# Apply Progress: course-teacher-identity

## Scope applied

PR 1 only — teacher identity foundation and identity-preserving sync. Explicitly excluded PR 2 session `teacher_id`, session form/request/service changes, removal blocking by sessions, certificates, global catalog, `user_id` semantics, production rollout, and destructive operations.

## Structured status consumed

- Parent-provided native SDD status schema: `gentle-ai.sdd-status` v2
- Change: `course-teacher-identity`
- Artifact store: `openspec`
- Apply state: `ready`
- Workspace root: `C:\\laragon\\www\\crm-maia-consultores`
- Allowed edit roots: `C:\\laragon\\www\\crm-maia-consultores`
- Action context warnings: none
- Delivery path: parent assigned bounded PR 1 slice only; no `size:exception` assumed.

## Completed implementation tasks and checkbox evidence

Marked the following implementation-owned PR 1 task rows as complete in `openspec/changes/course-teacher-identity/tasks.md`:

- PR 1 RED migration/schema coverage.
- PR 1 RED teacher identity HTTP/service coverage.
- PR 1 RED audit coverage.
- PR 1 GREEN stable teacher identity migration.
- PR 1 GREEN `CourseEditionTeacher` CourseModel/audit convention update.
- PR 1 GREEN `SyncEditionTeachersRequest` hidden-id/explicit-remove validation update.
- PR 1 GREEN `CourseEditionService::syncTeachers()` identity-preserving sync update.
- PR 1 GREEN teacher form hidden-id/copy update.
- PR 1 TRIANGULATE omitted-teacher deterministic order test.
- PR 1 TRIANGULATE cross-edition hidden id rejection test.
- PR 1 TRIANGULATE pre-existing-row SQLite migration preservation test.
- PR 1 REFACTOR helper extraction/cleanup.
- PR 1 focused verification task.

Parent-owned rows were preserved unchecked.

## Files changed

- `database/migrations/2026_09_14_000001_convert_course_edition_teachers_to_stable_identity.php` — new stable identity migration with MySQL staged path and SQLite rebuild path.
- `app/Models/Courses/CourseEditionTeacher.php` — now extends `CourseModel`, uses timestamps/audit/soft deletes, keeps `edition()`/`user()`, adds `sessions()` for PR 2.
- `app/Http/Requests/CourseTalks/SyncEditionTeachersRequest.php` — validates hidden IDs within route edition, keeps explicit remove rows, appends `new_teacher`, ignores client `sort_order`.
- `app/Services/Courses/CourseEditionService.php` — teacher sync now updates by ID, creates new rows, soft-deletes explicit removals, preserves omitted active teachers, recomputes deterministic order, dispatches existing event.
- `resources/views/course-talks/editions/show.blade.php` — teacher form posts hidden IDs and replaces delete/reinsert warning with identity-preserving copy.
- `tests/Feature/Courses/CourseDomainFoundationTest.php` — schema and SQLite migration preservation coverage.
- `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` — identity-preserving sync, ordering, explicit remove, hidden foreign ID, and UI hidden-id coverage.
- `tests/Feature/Courses/CourseAuditTest.php` — teacher create/update/delete course audit coverage.
- `openspec/changes/course-teacher-identity/tasks.md` — PR 1 implementation checkboxes updated.
- `openspec/changes/course-teacher-identity/apply-progress.md` — this progress record.

## Changed-line / PR boundary

- Code changed-line calculation after compression: tracked insertions 311 + tracked deletions 39 + new migration 49 lines = **399 code changed lines**.
- This is within the parent-acquired 400-line bounded PR 1 attempt, but only barely.
- Artifact changes (`tasks.md`, `apply-progress.md`) are lifecycle evidence and should not be mixed into review of product code.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| Migration/schema identity | `tests/Feature/Courses/CourseDomainFoundationTest.php` | Feature/schema | ✅ `CourseDomainFoundationTest` 6/6 baseline | ✅ New schema tests failed on missing `id`/audit and order release | ✅ Migration passed under SQLite `:memory:` | ✅ Added pre-existing old-shape row preservation test | ✅ Kept driver branch helper inside migration |
| Teacher sync identity | `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` | Feature/HTTP | ✅ `CourseEditionTeachersHttpTest` 12/12 baseline | ✅ New tests failed for omission/update/reorder/foreign hidden ID | ✅ Service/request/view implementation passed | ✅ Multi-teacher omission + foreign ID tests added | ✅ Extracted `editionTeacherForUpdate()` |
| Audit convention | `tests/Feature/Courses/CourseAuditTest.php` | Feature/audit | ✅ Existing focused audit suite later passed | ✅ Teacher audit test failed before model extended `CourseModel` | ✅ Create/update/delete audit entries passed | ✅ Covers create, update, and soft delete paths | ➖ No further extraction needed |

## Test and diagnostic evidence

Safety/baseline:

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseDomainFoundationTest` — initial baseline passed, 6 tests / 28 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionTeachersHttpTest` — initial baseline passed, 12 tests / 71 assertions.

RED evidence:

- New migration/schema tests failed as expected: missing teacher `id`; active order slot was still reserved by old composite key.
- New teacher identity/audit tests failed as expected under delete-all/reinsert and non-audited model behavior.

Final focused verification:

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseDomainFoundationTest` — passed, 9 tests / 45 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionTeachersHttpTest` — passed, 14 tests / 83 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseActivityEditionServiceTest` — passed, 5 tests / 19 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseAuditTest` — passed, 25 tests / 110 assertions.
- PHP syntax diagnostics for changed PHP files passed.
- `git diff --check` — passed with no output.

Blind-write warning response / late advisories:

- Re-read `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` and `database/migrations/2026_09_14_000001_convert_course_edition_teachers_to_stable_identity.php` before further edits.
- Reviewed current diff before further edits.
- Fixed late Intelephense advisory at `app/Http/Requests/CourseTalks/SyncEditionTeachersRequest.php:90` by typing `withValidator(Validator $validator)` and closure parameter.
- Did not fix existing indentation typo at `app/Services/Courses/CourseEditionService.php:39`; it is pre-existing, outside teacher-sync-only scope, and not needed for PR 1 correctness.

## Deviations from design

- The migration `down()` is intentionally no-op/forward-only to avoid destructive loss of stable teacher IDs and audit history. This matches the design rollback posture, but it means rollback requires an explicit owner-approved recovery plan.
- PR 1 does not add `course_sessions.teacher_id` or session/certificate behavior; these remain PR 2.

## Remaining tasks / unchecked rows

Parent lifecycle / operational rows:

- [ ] Confirm with the owner whether the over-budget change will use a chained PR strategy before apply starts; do not proceed as one oversized PR without explicit `size:exception`. <!-- sdd-owner: parent -->
- [ ] Before staging or production execution, verify a current restorable database backup and capture target counts for `course_edition_teachers`, `course_sessions`, and non-empty `course_sessions.teacher_name`; record unmatched/ambiguous name candidates without running destructive actions. <!-- sdd-owner: parent -->
- [ ] Start or reuse bounded review for PR 1 before PR 2 implementation begins; verify changed lines remain near the forecast and review only teacher identity/sync scope. <!-- sdd-owner: parent -->

PR 2 implementation rows remain unchecked by design:

- [ ] Add failing schema/session tests in `tests/Feature/Courses/CourseDomainFoundationTest.php` or `tests/Feature/Courses/CourseEditionSessionsHttpTest.php` for nullable `course_sessions.teacher_id`, same-edition assignment acceptance, cross-edition rejection, clearing to null, and legacy `teacher_name` fallback preservation. <!-- sdd-owner: implementation -->
- [ ] Add failing teacher removal tests in `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` proving removal is blocked while active sessions reference the teacher and succeeds after reassignment or clearing. <!-- sdd-owner: implementation -->
- [ ] Add failing certificate regression tests in `tests/Feature/Courses/CourseAcademicDocumentGenerationTest.php` for speaker precedence: FK teacher display name, then legacy `teacher_name`, then `Maia Consultores`, with eager-loading/lazy-loading safety where supported. <!-- sdd-owner: implementation -->
- [ ] Add or extend a migration under `database/migrations/` to add nullable `course_sessions.teacher_id`, FK/index support, conservative same-edition composite FK where practical for both MySQL and SQLite, and non-destructive backfill that only assigns exact unambiguous same-edition name matches. <!-- sdd-owner: implementation -->
- [ ] Update `app/Models/Courses/CourseSession.php` to include `teacher_id` in fillable/casts as appropriate and add `teacher()` belongs-to `App\\Models\\Courses\\CourseEditionTeacher`. <!-- sdd-owner: implementation -->
- [ ] Update `app/Http/Requests/CourseTalks/SyncEditionSessionsRequest.php` to validate nullable `sessions.*.teacher_id` against active teachers belonging to the route edition while preserving `sessions.*.teacher_name` validation. <!-- sdd-owner: implementation -->
- [ ] Update `app/Services/Courses/CourseEditionService.php::syncSessions()` to re-query non-null teacher IDs by `(id, course_edition_id)` inside the transaction, reject cross-edition IDs with field-specific `InvalidCourseEditionData`, and persist both `teacher_id` and `teacher_name`. <!-- sdd-owner: implementation -->
- [ ] Update `app/Services/Courses/CourseEditionService.php::syncTeachers()` removal path to block soft deletion when active `course_sessions` in the same edition reference the teacher, leaving teacher and session rows unchanged on failure. <!-- sdd-owner: implementation -->
- [ ] Update `resources/views/course-talks/editions/show.blade.php` session section to load/render an active same-edition teacher select, include an empty clear option, preserve selected IDs after validation errors, and display FK teacher names with legacy text fallback. <!-- sdd-owner: implementation -->
- [ ] Update `app/Services/Courses/CourseDocumentGenerationService.php` to eager-load `edition.sessions.teacher` and compute syllabus speaker as teacher display name, else non-empty legacy `teacher_name`, else `Maia Consultores`. <!-- sdd-owner: implementation -->
- [ ] Add tests proving a teacher from edition E2 cannot be assigned to a session in edition E1 through HTTP payloads or direct service calls. <!-- sdd-owner: implementation -->
- [ ] Add tests proving clearing `teacher_id` does not erase legacy `teacher_name` and certificates still use the text fallback. <!-- sdd-owner: implementation -->
- [ ] Add tests for unmatched and ambiguous legacy session teacher names during any backfill path, proving they remain text-only and no teacher rows are invented. <!-- sdd-owner: implementation -->
- [ ] Extract a shared speaker-display helper only if it reduces duplication between session UI and certificate generation without changing precedence. <!-- sdd-owner: implementation -->
- [ ] Run focused verification for PR 2: `php artisan test --filter=CourseEditionSessionsHttpTest`, `php artisan test --filter=CourseEditionTeachersHttpTest`, `php artisan test --filter=CourseAcademicDocumentGenerationTest`, and `php artisan test --filter=CourseDomainFoundationTest`; record results in the apply summary. <!-- sdd-owner: implementation -->
- [ ] Start or reuse bounded review for PR 2; verify the diff contains only session assignment, removal blocking, certificate precedence, tests, and directly required migration/model changes. <!-- sdd-owner: parent -->

Final full-increment rows remain unchecked until PR 2 and lifecycle work complete.

## Risks / residual notes

- MySQL path is designed but not executed locally; staging/dry-run remains required before production.
- Production data counts and backup verification remain owner/lifecycle blockers.
- The PR 1 code diff is exactly at the 400-line budget boundary if counted as tracked insertions/deletions plus new migration lines; avoid adding product code to this PR before review.
- `course_sessions.teacher_id`, removal blocking while sessions reference a teacher, certificate precedence, and session same-edition validation are intentionally deferred to PR 2.

## Bounded PR 1 correction — first `new_teacher` row without `teachers`

Correction scope applied only to:

- `app/Http/Requests/CourseTalks/SyncEditionTeachersRequest.php`
- `tests/Feature/Courses/CourseEditionTeachersHttpTest.php`
- `openspec/changes/course-teacher-identity/apply-progress.md`

Defect fixed: `prepareForValidation()` no longer returns early when `teachers` is absent. Missing `teachers` is treated as an empty list so a visible `new_teacher[...]` row can create the first edition teacher. A present malformed non-array `teachers` payload is still left untouched and rejected by the existing `teachers` array rule.

### Correction TDD evidence

| Cycle | Evidence |
|---|---|
| RED | Modified the existing `test_form_affordances_add_and_remove_teachers_without_adding_blank_rows` to submit only `new_teacher[...]` with no `teachers` input. `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionTeachersHttpTest` failed: expected 1 teacher row, found 0. |
| GREEN | Updated `SyncEditionTeachersRequest::prepareForValidation()` to allow absent `teachers` while preserving rejection for present non-array `teachers`. Re-ran the same test filter; first GREEN attempt exposed a null `foreach`, then final focused run passed. |
| REFACTOR / final focused verification | `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionTeachersHttpTest` — passed, 14 tests / 81 assertions. `git diff --check` — passed with no output. |

### Correction changed-line total

- Current PR 1 product diff count: tracked product files 306 insertions + 47 deletions, plus 49-line untracked migration = **402 changed lines**.
- This matches the parent-authorized `size:exception` ceiling for this bounded correction attempt.

### Human acceptance checklist (not run)

Feature: first edition teacher via the visible "new teacher" form row.

| Scenario | Status | Expected visible result | Failure evidence to capture |
|---|---|---|---|
| Add the first teacher on an edition with no existing teachers using only the `new_teacher` row | not run | The request succeeds, the teacher appears as the first row, and no validation error says `teachers` is required. | Screenshot of form before/after and request payload if it fails. |
| Submit malformed `teachers` as a non-array payload | not run | Validation rejects the malformed `teachers` value; it is not coerced into an empty list. | Validation error response/session errors. |

Human acceptance remains pending until a human records results.

## Key Learnings

- SQLite requires a rebuild path for replacing the old composite-key teacher table; testing an old-shape table catches identity preservation without touching MySQL.
- To soft-delete teachers while preserving active order uniqueness, release active `sort_order` before deletion and recompute active rows deterministically.
- Hidden teacher IDs must be validated against the route edition and re-queried in the service; the UI hidden field is only an affordance, not authority.
- Missing `teachers` means an empty existing-teacher list, but only when the key is absent/null; a present non-array value must remain available for the `array` rule to reject.

## Native review outcome for PR 1

Lineage `review-f4a496f2c334a0cf` closed as **approved** and its authority was burned (`gentle-ai.review-acknowledged/v1`). Risk tier medium, lens `review-reliability`, correction budget 200; the correction used 4 of it.

### Root cause of the earlier review blocker

The intended-untracked selection never persisted, so START could not proceed. The migration was untracked and therefore part of the eligible untracked inventory. Staging the changed files removed that inventory entirely and the review started immediately. **Lesson: keep new files tracked (staged) so review never depends on intended-untracked selection.**

### Critical finding raised and corrected

`R3-mysql-primary-key-drop-before-replacement-index` (CRITICAL, inferential, causal disposition `introduced`) at `database/migrations/2026_09_14_000001_convert_course_edition_teachers_to_stable_identity.php:19`.

The MySQL branch dropped the composite primary key while it was still the only index backing the `course_edition_id` foreign key to `course_editions`. MySQL/InnoDB rejects that with errno 1553, so the migration would have failed in production before applying anything. SQLite tests could not detect it because the SQLite path rebuilds the table instead.

Correction (declared 4 correction lines, actual diff 3 insertions + 1 deletion = 4): create the replacement unique index `course_edition_teachers_edition_order_unique` on `(course_edition_id, sort_order)` immediately before `DROP PRIMARY KEY`, and remove the now-duplicate creation from the later `Schema::table` block.

Verification after correction: `php -l` clean and `CourseDomainFoundationTest` passed 9 tests / 45 assertions.

### Residual operational risk

The MySQL DDL path is corrected but still not executed against a real MySQL instance, because no MySQL migration run was authorized. Staging/dry-run plus the production backup and count prerequisites remain outstanding.

## PR 2 — session teacher assignment, removal blocking, certificates

Implemented after the PR 1 review closed.

### Files

- `database/migrations/2026_09_14_000002_add_teacher_assignment_to_course_sessions.php` (new, staged)
- `app/Models/Courses/CourseSession.php` — `teacher_id` fillable and `teacher()` relation
- `app/Http/Requests/CourseTalks/SyncEditionSessionsRequest.php` — same-edition `teacher_id` validation
- `app/Services/Courses/CourseEditionService.php` — `syncSessions()` re-query and removal blocking
- `app/Services/Courses/CourseDocumentGenerationService.php` — eager load and speaker precedence
- `resources/views/course-talks/editions/show.blade.php` — teacher select with clear option
- tests: `CourseEditionSessionsHttpTest`, `CourseAcademicDocumentGenerationTest`, `CourseEditionTeachersHttpTest`, `CourseDomainFoundationTest`

### Behavior verified by reading the implementation

- Removal blocked server-side with the message "No se puede quitar el docente porque está asignado a una o más sesiones. Reasigne las sesiones antes de quitarlo." (`CourseEditionService.php:103-105`).
- Cross-edition assignment rejected with "El docente seleccionado no pertenece a este dictado." (`CourseEditionService.php:184`).
- Certificate speaker precedence is `teacher?->display_name` → non-empty `teacher_name` → `Maia Consultores` (`CourseDocumentGenerationService.php`).
- N+1 avoided through `loadMissing('edition.activity', 'edition.sessions.teacher', ...)` (`CourseDocumentGenerationService.php:141`).

### MySQL safety of the new migration

Single transaction-free `up()` guarded by `Schema::hasColumn`. It creates the parent unique index `(id, course_edition_id)` on `course_edition_teachers` **before** declaring any foreign key that depends on it, adds the nullable `course_sessions.teacher_id`, and prefers `restrictOnDelete`. This respects the rule that PR 1 violated: never drop or depend on a key change before its replacement index exists. SQLite takes the plain-column branch and enforces the invariant in the request and service layers.

Residual: MySQL path still not executed here.

### Focused verification after PR 2

| Suite | Result |
|---|---|
| `CourseEditionSessionsHttpTest` | passed, 18 tests / 101 assertions |
| `CourseAcademicDocumentGenerationTest` | passed, 21 tests / 109 assertions |
| `CourseEditionTeachersHttpTest` | passed, 16 tests / 90 assertions |
| `CourseDomainFoundationTest` | passed, 10 tests / 49 assertions |

`git diff --check` clean. No untracked source files. Code changed lines across both PR slices: 791, with PR 1 independently measured at 404; PR 2 therefore accounts for roughly 387.

### Notable process learning

Untracked source files break the native review's intended-untracked selection. New files created during PR 2 were staged immediately, and the review/attempt inventories stayed empty as a result.

## Native review outcome for PR 2

Lineage `review-046e09047f9b45c8` closed as **approved** and its authority was burned (`gentle-ai.review-acknowledged/v1`). Risk tier medium, lens `review-reliability`, correction budget 200, of which **0 was used**: the lens review was admitted and approved on its first event, with no refuter round and no correction required. This differs from the PR 1 review, which had to correct a CRITICAL MySQL index-ordering defect first.

The reviewed candidate covered both PR slices (20 files, 2192 counted lines including the staged OpenSpec planning documents).

### Delivery boundary note

PR 1 and PR 2 are both implemented, reviewed, and approved, but neither is committed and they share no separate review boundary. The user chose chained PRs targeting `main`. Splitting them into two reviewable PRs now requires freezing PR 1 as its own commit before PR 2 is staged for delivery, and committing is an explicit human decision under ordinary repository policy.

### Remaining scope

Production rollout still requires a restorable backup plus production row counts; the local database proves nothing about production.

## Refuted CRITICAL finding from a later review candidate

After the documentation update, lineage `review-6befb52527bc835e` re-reviewed the same code (candidate differing only by this file and `tasks.md`) and demanded a correction for `R3-session-teacher-select-not-selected`, severity CRITICAL, evidence class `deterministic`, causal disposition `introduced`.

**The finding is false.** It was refuted on three independent grounds and no correction was fabricated:

1. The claim states the session teacher `<select>` compares a stored integer `teacher_id` with a string under strict equality. The helper at `resources/views/course-talks/editions/show.blade.php:224` is declared `fn ($row, string $key): string` and returns `(string) $row[$key]`. It cannot return an integer, so line 268 compares string to string and selects the persisted option correctly.
2. An existing test asserts the opposite of the claim and passes: `test_authorized_sync_assigns_same_edition_teacher_and_renders_teacher_select` asserts `value="<id>" selected` is rendered. It passes with 1 test and 6 assertions.
3. The identical source was already APPROVED minutes earlier by lineage `review-046e09047f9b45c8` with zero corrections. The same lens reached opposite verdicts on unchanged code, so the lens verdict is not deterministic.

Decision taken by the owner: report the false positive instead of inventing a fix. The review for this candidate therefore remains incomplete, and delivery follows ordinary repository policy. No edit was made to satisfy an uncorroborated finding, and no correction budget was spent.

## Migration guard correction (second real review finding)

A later lineage, `review-d6eb68fe9b75d84f`, raised `R3-migration-single-column-idempotency-guard` (CRITICAL, inferential, `introduced`) against `database/migrations/2026_09_14_000001_...php:12`. This finding was **legitimate** and was corrected.

**The defect.** Both new migrations guarded themselves with a single-column existence check:

```php
if (Schema::hasColumn('course_edition_teachers', 'id')) return;
if (Schema::hasColumn('course_sessions', 'teacher_id')) return;
```

MySQL DDL is not transactional. If `000001` failed after adding `id` but before the primary-key conversion, the replacement index, or the audit columns, a retry would see `id`, return early, and leave the application running against a schema with no `deleted_at` and no `created_by` while `CourseEditionTeacher` already used soft deletes and audit columns. `000002` had the same shape: a failure after `teacher_id` but before the foreign keys and index would silently skip them on retry. That is a silent failure of exactly the kind this change exists to remove.

**The correction.** Each guard now requires the migration's complete intended end state:

- `000001` returns only when `id`, `deleted_at` and `created_by` all exist.
- `000002` returns only when `teacher_id` exists **and** the trailing `course_sessions_teacher_edition_index` index is present, resolved through `Schema::getIndexes()` in a private `hasTeacherAssignmentIndex()` helper.

A partially applied migration now surfaces loudly on retry instead of silently resuming against an incomplete schema. Correction size: about 32 diff lines against the 40 declared and the 200 available budget.

**Verification.** `php -l` clean on both migrations; `CourseDomainFoundationTest` 10 tests / 49 assertions, `CourseEditionSessionsHttpTest` 18 / 101, `CourseEditionTeachersHttpTest` 16 / 90 — all passed. The targeted validator accepted the correction and the lineage closed `approved`, after which its authority was burned.

## Review ledger for this change

| Lineage | Outcome | Finding |
|---|---|---|
| `review-f4a496f2c334a0cf` | approved | CRITICAL and real: MySQL would reject `DROP PRIMARY KEY` while it backed the `course_edition_id` foreign key |
| `review-046e09047f9b45c8` | approved, no correction | none |
| `review-6befb52527bc835e` | correction_required | false positive, refuted with three independent proofs, no fix fabricated |
| `review-d6eb68fe9b75d84f` | approved after one correction | CRITICAL and real: silent schema skip after a partial migration failure |

Every automated test was green throughout. Both real defects lived in migration mechanics that SQLite test runs cannot exercise, which is why adversarial review of the migrations mattered more than the test suite here.

### Contract hazard observed

After an acknowledgement burns authority, the review authority inventory holds no entry for that lineage. `review status --lineage=<burned>` fails with "inventory review authority: review authority inventory contains no entries", and `inspect` reports the same target as `applicability: unrelated` / `fresh_target_ready`, offering a fresh start. A post-burn reminder is therefore stale when the `target_identity` returned by the acknowledgement equals `current_snapshot_identity` and no tracked file changed. Reminders can also be generated mid-review when a correction changes the candidate, then delivered after that review closes.
