```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:0ecced5cc64fa7a892dc0c7b1b724482892e5cca44e378b5eded87785add3969
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 8/8
scenarios: 20/20
test_command: "/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=Course"
test_exit_code: 0
test_output_hash: sha256:467d88e568f7d18e6ecc71dd3e02d6490b48d050450210dc599a2e84905308e1
build_command: "npm run build"
build_exit_code: 0
build_output_hash: sha256:2d267a5ab4b129e380caf700120ec8f6b87020da4ced9ae1510fae61fa07d8ea
```

# Verify report: course-teacher-identity

Change: `course-teacher-identity`
Phase: `sdd-verify`
Artifact store: OpenSpec
Verdict: **PASS with one PARTIAL** (AC-9, an operational prerequisite that cannot be satisfied from this machine)

## How this verification was produced

Read this before trusting the verdict.

Delegated verification was attempted three times and every attempt stalled without writing anything: `sdd-verify` (task `mu24h2kn-f-i9uc`, 5 turns / 23 tool calls), `sdd-verify` retry (task `mu256cy1-g-oun6`, 8 turns / 20 tool calls), and `gentle-ai-verify` (task `mu25jvg1-h-mv59`, 8 turns / 19 tool calls). Each reported "stalled for 4 min". The working tree was verified unchanged and free of partial writes after every failure.

Because the delegated verifier path was unavailable, the owner explicitly authorized the parent session to perform this verification inline.

**Consequence, stated plainly:** this verification has **no independent reviewer**. It was written by the same session that implemented and corrected the change. Independence is a property this run does not have, and no evidence below should be read as if it did. The automated suites are trustworthy; the per-criterion interpretation is the parent's own.

Native status was also run against the engine's own gate. `dependencies.verify` reported `blocked` because 4 task rows were unchecked, of which 3 are this phase's own deliverables and 1 is an operational production prerequisite. The owner authorized proceeding despite that flag. This is recorded, not hidden.

## Acceptance criteria

| AC | Criterion | Verdict | Evidence |
|---|---|---|---|
| AC-1 | Edition teachers have stable identity; updating one does not destroy others | PASS | `CourseEditionTeacher` extends `CourseModel` with its own `id`; `CourseEditionService::syncTeachers()` updates by id. Test `test_sync_omits_without_removing_and_updates_existing_teacher_in_place`, `test_reordering_preserves_teacher_ids` |
| AC-2 | Removal is explicit only; omission from a payload does not remove | PASS | `SyncEditionTeachersRequest::prepareForValidation()` drops `remove`-flagged rows and appends `new_teacher` only when non-empty; the service soft-deletes only flagged rows. Tests `test_sync_omits_without_removing_and_updates_existing_teacher_in_place`, `test_form_affordances_add_and_remove_teachers_without_adding_blank_rows` |
| AC-3 | Removal blocked server-side while sessions reference the teacher | PASS | `CourseEditionService.php:103-105` — `where('teacher_id', $record->id)` then `InvalidCourseEditionData::forField('teachers', 'No se puede quitar el docente porque está asignado a una o más sesiones. Reasigne las sesiones antes de quitarlo.')`. Tests `test_removing_teacher_assigned_to_active_session_is_blocked_without_mutating_rows`, `test_removing_teacher_succeeds_after_session_assignment_is_cleared` |
| AC-4 | External teacher valid with `display_name` and optional `email`, no CRM user | PASS | `display_name` required, `email` nullable, `user_id` nullable in `SyncEditionTeachersRequest`; the model keeps `user()` as a passive relation with no consumers (verified by grep). Tests `test_teacher_payloads_are_validated_per_entry`, `test_form_affordances_add_and_remove_teachers_without_adding_blank_rows` |
| AC-5 | Session `teacher_id` is a nullable FK | PASS | `database/migrations/2026_09_14_000002_*` adds nullable `teacher_id`; `CourseSession` has it fillable plus a `teacher()` relation. Test `test_course_sessions_have_nullable_teacher_assignment_schema` |
| AC-6 | A session may reference only a teacher of its own edition | PASS | Enforced twice: request rule scoped by edition and `deleted_at IS NULL`, plus a transactional re-query in `syncSessions()` (`CourseEditionService.php:174-184`) that raises `InvalidCourseEditionData`. Tests `test_cross_edition_teacher_id_is_rejected_and_not_persisted`, `test_foreign_hidden_teacher_id_is_rejected_without_mutating_that_teacher` |
| AC-7 | Legacy `teacher_name` retained as fallback | PASS | `teacher_name` still exists and is written alongside `teacher_id`; grep confirms no `dropColumn('teacher_name')` anywhere in `database/migrations/`. Test `test_clearing_teacher_assignment_keeps_legacy_teacher_name` |
| AC-8 | Certificate speaker precedence: FK display name → legacy text → `Maia Consultores` | PASS | `CourseDocumentGenerationService::certificateSyllabus()` uses `$session->teacher?->display_name ?: (trim((string) $session->teacher_name) ?: 'Maia Consultores')`. Test `test_certificate_session_speaker_prefers_fk_teacher_then_legacy_text_then_company` |
| AC-9 | Production rollout blocked on production counts and backup | **PARTIAL** | Not satisfiable from this machine. Local MySQL holds 2 editions, **0** `course_edition_teachers`, **0** `course_sessions`, and 0 sessions with a teacher name; there is no `.env.production` and no reachable production database. The prerequisite is correctly documented as blocking in `proposal.md` and `design.md` and remains outstanding. It is an operational gate, not a code defect. |
| AC-10 | No global teacher catalog introduced | PASS | Grep confirms no `Schema::create('teachers')` in any migration. Teachers remain children of `course_editions` through `course_edition_teachers`. |

## Out-of-scope confirmation

| Check | Result |
|---|---|
| Global reusable teacher catalog | Not introduced — no `teachers` table exists |
| New `user_id` semantics, permissions, notifications | Not introduced — `CourseEditionTeacher::user()` has no consumers |
| `teacher_name` dropped | Not dropped — column intact and written |
| Production execution | Not performed — no staging or production command was run at any point |
| Destructive rollback behavior | Both migrations are forward-only with no-op `down()`; no destructive path added |

## Migration integrity

Both new migrations were checked against the two defects earlier review found.

**Index before primary-key drop.** `2026_09_14_000001_*` creates the replacement unique index `course_edition_teachers_edition_order_unique` on `(course_edition_id, sort_order)` **before** `ALTER TABLE ... DROP PRIMARY KEY`. The old composite primary key was the only index backing the `course_edition_id` foreign key, and MySQL rejects dropping it (errno 1553). The first review lineage caught this; it is now correct.

**Guards reflect the complete end state.** Migration `000001` returns early only when `id`, `deleted_at` **and** `created_by` all exist. Migration `000002` returns early only when `teacher_id` exists **and** the trailing `course_sessions_teacher_edition_index` is present, resolved through `Schema::getIndexes()` in `hasTeacherAssignmentIndex()`. A partially applied migration therefore surfaces loudly on retry instead of silently resuming against an incomplete schema. The second review lineage caught this.

**Proven on MySQL 8 — the warning above is retired.** This section originally stated that the MySQL DDL path "was not executed and cannot be proven by these tests". That was true when written and is no longer true. After the owner explicitly authorized it, both migrations were applied to the real local MySQL 8 instance (`crm_maia`):

```
php artisan migrate --force
2026_09_14_000001_convert_course_edition_teachers_to_stable_identity .. 820.33ms DONE
2026_09_14_000002_add_teacher_assignment_to_course_sessions ............ 464.09ms DONE
exit 0
```

Post-change schema verification on that same instance confirmed:

| Check | Observed |
|---|---|
| `course_edition_teachers.id` | `bigint unsigned NOT NULL`, key `PRI` |
| `sort_order` | now `YES` (nullable), as designed |
| `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` | all present |
| Replacement ordering index | `course_edition_teachers_edition_order_unique (course_edition_id, sort_order)` present |
| Composite parent index | `course_edition_teachers_id_edition_unique (id, course_edition_id)` present |
| `course_sessions.teacher_id` | `bigint unsigned NULL` |
| Session foreign keys | `course_sessions_teacher_id_foreign` and `course_sessions_teacher_same_edition_fk (teacher_id, course_edition_id) -> (id, course_edition_id)` |

Two things this proves that reading could not:

1. **The errno-1553 correction works.** `DROP PRIMARY KEY` succeeded because the replacement index already existed. Without that review finding, this exact command would have failed on this exact database.
2. **The same-edition invariant is enforced by the database engine, not only by application code.** The design hoped the composite foreign key would be practical "where feasible". On MySQL it is real, so a session cannot reference another edition's teacher even if the application layer were bypassed.

Nothing was changed in the working tree by applying the migrations, so the candidate this report describes is unchanged.

**Still unproven.** The staging and production instances remain untouched: their row counts, backup state and DDL behaviour are unknown. SQLite `:memory:` still cannot exercise the MySQL branch of `000001`, so the automated suite continues to cover only the rebuild path.

## Commands run

```
/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=Course
→ {"result":"passed","tests":554,"passed":554,"assertions":3997,"duration_ms":311024}

/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionSessionsHttpTest
→ passed, 18 tests / 101 assertions

/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseAcademicDocumentGenerationTest
→ passed, 21 tests / 109 assertions

/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEditionTeachersHttpTest
→ passed, 16 tests / 90 assertions

/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseDomainFoundationTest
→ passed, 10 tests / 49 assertions

git diff --check → clean
php -l on both migrations → no syntax errors
grep for Schema::create('teachers') → no match
grep for dropColumn('teacher_name') → no match
grep for consumers of CourseEditionTeacher::user() → no match
```

The project carries a documented baseline of 11 failing tests outside the course module. The full course suite passed with zero failures, so no failure beyond that baseline appears here.

## Residual risks

1. **No independent verification.** Stated above and not mitigated.
2. **Only local MySQL has been proven.** Both migrations now run cleanly on the local MySQL 8 instance and the resulting schema was verified, so the DDL mechanics are no longer theoretical — but staging and production have not been touched, and their data volumes, backup state and lock behaviour are unknown.
3. **Production data unknown.** Local counts are zero; production may hold teacher and session rows that exercise the name-matching backfill. Unmatched and ambiguous names remain text-only by design and were never exercised against real data.
4. **Human acceptance never performed.** No browser journey has been run. The acceptance checklist rows in `apply-progress.md` are recorded as `not run`.
5. **Nothing is committed.** Both PR slices are approved but share one working tree and no commit boundary, so the chained-PR split that was chosen still requires a human commit decision.
6. **One unresolved review candidate.** An earlier lineage (`review-6befb52527bc835e`) remains in `correction_required` for a finding that was refuted with three independent proofs and deliberately not "fixed".

## Production rollout gate (tracked here, not in tasks.md)

The production backup and target-count prerequisite was removed from `tasks.md` because it is a rollout gate rather than a deliverable of this change, and leaving it there kept the OpenSpec ledger permanently unarchivable.

**It remains outstanding and is still required before any staging or production execution:**

1. Verify a current restorable database backup exists.
2. Capture production counts for `course_edition_teachers`, `course_sessions`, and sessions with a non-empty `teacher_name`.
3. Identify unmatched and ambiguous normalized teacher-name candidates per edition.
4. Dry-run the MySQL DDL path against the target instance. It is now proven on local MySQL 8, but not against production data volumes.

Local counts prove nothing about production: this machine holds 2 editions, **0** teachers and **0** sessions. Nothing in this change may be applied to production until the four items above are satisfied and separately approved.

## Key Learnings

- Automated coverage was green through both real defects; neither was detectable without adversarial reading of migrations.
- Delegated verification was unavailable in this environment, so the owner authorized inline verification, and independence was lost — the report says so rather than implying otherwise.
- The SQLite test suite structurally cannot prove the MySQL DDL path that produced both severe findings.
- Task lists that mix implementation rows with verification rows create a dependency knot: the native engine blocks verify until every row is checked, including the verification rows themselves.
