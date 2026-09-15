# Tasks: Course teacher identity

Change: `course-teacher-identity`  
Store: OpenSpec  
Task language: English

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | Full increment: 550–850 changed lines. PR 1 teacher identity/sync: ~280–390. PR 2 session assignment/certificates: ~270–460. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 teacher identity/sync → PR 2 session assignment/certificates |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

Forecast notes:

- Dependencies: PR 2 depends on PR 1 because `course_sessions.teacher_id`, removal blocking, and certificate precedence require stable `course_edition_teachers.id` and identity-preserving teacher sync.
- Next apply unit: after the parent obtains a chain-strategy decision, start with **PR 1 — teacher identity foundation and sync** only.
- Primary risks: cross-driver DDL for composite primary-key replacement, SQLite test compatibility, audit/soft-delete behavior changes, hidden ID trust boundaries, and production data unknowns.
- No `size:exception` is granted or assumed.

## Work Unit 0 — Delivery decision and operational prerequisite gates

- [x] Confirm with the owner whether the over-budget change will use a chained PR strategy before apply starts; do not proceed as one oversized PR without explicit `size:exception`. <!-- sdd-owner: parent -->

## PR 1 — Teacher identity foundation and identity-preserving sync

Goal: make edition teachers stable, auditable, soft-deletable entities and stop delete-all/reinsert sync. Out of scope for this PR: session teacher selection UI, certificate precedence, and production rollout execution.

### RED

- [x] Add failing migration/schema coverage in `tests/Feature/Courses/CourseDomainFoundationTest.php` or new `tests/Feature/Courses/CourseTeacherIdentityMigrationTest.php` for `course_edition_teachers.id`, audit columns, timestamps, `deleted_at`, nullable `sort_order` behavior, active ordering uniqueness, and SQLite `:memory:` migration success. <!-- sdd-owner: implementation -->
- [x] Add failing teacher identity tests in `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` and/or `tests/Feature/Courses/CourseActivityEditionServiceTest.php` for update-in-place, reorder-preserves-IDs, omission-does-not-remove, explicit-remove-only, external teacher without `user_id`, and cross-edition hidden ID rejection. <!-- sdd-owner: implementation -->
- [x] Add failing audit coverage in `tests/Feature/Courses/CourseAuditTest.php` or teacher sync tests proving `CourseEditionTeacher` follows course audit/soft-delete conventions after create/update/remove. <!-- sdd-owner: implementation -->

### GREEN

- [x] Create a new migration under `database/migrations/` that converts `course_edition_teachers` from composite primary key to stable `id`, preserves/backfills existing rows deterministically, adds nullable audit/timestamp/soft-delete columns, keeps active `(course_edition_id, sort_order)` ordering protection, and includes a SQLite rebuild branch plus MySQL-safe staged path. <!-- sdd-owner: implementation -->
- [x] Update `app/Models/Courses/CourseEditionTeacher.php` to extend `app/Models/Courses/CourseModel.php` conventions, remove `$timestamps = false`, keep passive `user()` and `edition()` relationships, and add a `sessions()` relationship target for later PR use. <!-- sdd-owner: implementation -->
- [x] Update `app/Http/Requests/CourseTalks/SyncEditionTeachersRequest.php` to accept hidden `teachers.*.id`, validate IDs within the route edition, require display data unless explicit remove is set, and continue ignoring client-controlled `sort_order`. <!-- sdd-owner: implementation -->
- [x] Update `app/Services/Courses/CourseEditionService.php::syncTeachers()` to use a transaction, mutate submitted teacher IDs in place, create new rows, soft-delete only explicit removals, preserve omitted active teachers, recompute deterministic active ordering, and continue dispatching `CourseEditionChanged($edition->id, 'course-edition-teachers-changed')` after success. <!-- sdd-owner: implementation -->
- [x] Update `resources/views/course-talks/editions/show.blade.php` teacher form to post hidden teacher IDs, preserve input on validation errors, keep explicit remove controls, and replace the current replacement warning with identity-preserving copy. <!-- sdd-owner: implementation -->

### TRIANGULATE

- [x] Add a seeded multi-teacher test proving partial payload omission preserves the omitted active teacher and deterministic ordering after submitted rows is stable. <!-- sdd-owner: implementation -->
- [x] Add a test proving a submitted hidden teacher ID from another edition cannot update, remove, or reorder that foreign teacher. <!-- sdd-owner: implementation -->
- [x] Add a migration/data test with pre-existing teacher-like rows where feasible to prove row counts are preserved and all migrated rows receive non-null unique IDs. <!-- sdd-owner: implementation -->

### REFACTOR

- [x] Refactor migration helper methods or service private methods only where they reduce duplicated driver-branch or sync logic, keeping tests green after each extraction. <!-- sdd-owner: implementation -->
- [x] Run focused verification for PR 1: `php artisan test --filter=CourseDomainFoundationTest`, `php artisan test --filter=CourseEditionTeachersHttpTest`, `php artisan test --filter=CourseActivityEditionServiceTest`, and `php artisan test --filter=CourseAuditTest`; record results in the apply summary. <!-- sdd-owner: implementation -->
- [x] Start or reuse bounded review for PR 1 before PR 2 implementation begins; verify changed lines remain near the forecast and review only teacher identity/sync scope. <!-- sdd-owner: parent -->

## PR 2 — Session assignment, removal blocking, and certificates

Goal: add nullable session teacher assignment, enforce same-edition validation, block removal while referenced, and update certificate speaker precedence. Depends on PR 1 stable teacher identity.

### RED

- [x] Add failing schema/session tests in `tests/Feature/Courses/CourseDomainFoundationTest.php` or `tests/Feature/Courses/CourseEditionSessionsHttpTest.php` for nullable `course_sessions.teacher_id`, same-edition assignment acceptance, cross-edition rejection, clearing to null, and legacy `teacher_name` fallback preservation. <!-- sdd-owner: implementation -->
- [x] Add failing teacher removal tests in `tests/Feature/Courses/CourseEditionTeachersHttpTest.php` proving removal is blocked while active sessions reference the teacher and succeeds after reassignment or clearing. <!-- sdd-owner: implementation -->
- [x] Add failing certificate regression tests in `tests/Feature/Courses/CourseAcademicDocumentGenerationTest.php` for speaker precedence: FK teacher display name, then legacy `teacher_name`, then `Maia Consultores`, with eager-loading/lazy-loading safety where supported. <!-- sdd-owner: implementation -->

### GREEN

- [x] Add or extend a migration under `database/migrations/` to add nullable `course_sessions.teacher_id`, FK/index support, conservative same-edition composite FK where practical for both MySQL and SQLite, and non-destructive backfill that only assigns exact unambiguous same-edition name matches. <!-- sdd-owner: implementation -->
- [x] Update `app/Models/Courses/CourseSession.php` to include `teacher_id` in fillable/casts as appropriate and add `teacher()` belongs-to `App\Models\Courses\CourseEditionTeacher`. <!-- sdd-owner: implementation -->
- [x] Update `app/Http/Requests/CourseTalks/SyncEditionSessionsRequest.php` to validate nullable `sessions.*.teacher_id` against active teachers belonging to the route edition while preserving `sessions.*.teacher_name` validation. <!-- sdd-owner: implementation -->
- [x] Update `app/Services/Courses/CourseEditionService.php::syncSessions()` to re-query non-null teacher IDs by `(id, course_edition_id)` inside the transaction, reject cross-edition IDs with field-specific `InvalidCourseEditionData`, and persist both `teacher_id` and `teacher_name`. <!-- sdd-owner: implementation -->
- [x] Update `app/Services/Courses/CourseEditionService.php::syncTeachers()` removal path to block soft deletion when active `course_sessions` in the same edition reference the teacher, leaving teacher and session rows unchanged on failure. <!-- sdd-owner: implementation -->
- [x] Update `resources/views/course-talks/editions/show.blade.php` session section to load/render an active same-edition teacher select, include an empty clear option, preserve selected IDs after validation errors, and display FK teacher names with legacy text fallback. <!-- sdd-owner: implementation -->
- [x] Update `app/Services/Courses/CourseDocumentGenerationService.php` to eager-load `edition.sessions.teacher` and compute syllabus speaker as teacher display name, else non-empty legacy `teacher_name`, else `Maia Consultores`. <!-- sdd-owner: implementation -->

### TRIANGULATE

- [x] Add tests proving a teacher from edition E2 cannot be assigned to a session in edition E1 through HTTP payloads or direct service calls. <!-- sdd-owner: implementation -->
- [x] Add tests proving clearing `teacher_id` does not erase legacy `teacher_name` and certificates still use the text fallback. <!-- sdd-owner: implementation -->
- [x] Add tests for unmatched and ambiguous legacy session teacher names during any backfill path, proving they remain text-only and no teacher rows are invented. <!-- sdd-owner: implementation -->

### REFACTOR

- [x] Extract a shared speaker-display helper only if it reduces duplication between session UI and certificate generation without changing precedence. <!-- sdd-owner: implementation -->
- [x] Run focused verification for PR 2: `php artisan test --filter=CourseEditionSessionsHttpTest`, `php artisan test --filter=CourseEditionTeachersHttpTest`, `php artisan test --filter=CourseAcademicDocumentGenerationTest`, and `php artisan test --filter=CourseDomainFoundationTest`; record results in the apply summary. <!-- sdd-owner: implementation -->
- [x] Start or reuse bounded review for PR 2; verify the diff contains only session assignment, removal blocking, certificate precedence, tests, and directly required migration/model changes. <!-- sdd-owner: parent -->

## Final verification before completion

- [x] Run the full course-focused test set or full project test command `php artisan test` after both PR slices are integrated locally; investigate any failures before completion. <!-- sdd-owner: implementation -->
- [x] Confirm final behavior against acceptance criteria AC-1 through AC-10 from `openspec/changes/course-teacher-identity/proposal.md` and scenarios in `openspec/changes/course-teacher-identity/specs/course-talks-management/spec.md`. <!-- sdd-owner: implementation -->
- [x] Confirm no implementation introduced a global teacher catalog, new `user_id` semantics, production execution, or destructive rollback behavior. <!-- sdd-owner: implementation -->
- [x] Perform final bounded review/lifecycle gate after implementation verification and before marking the OpenSpec change ready for archive. <!-- sdd-owner: parent -->
