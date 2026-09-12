# Verify report — `course-talks-management`

- **Phase**: sdd-verify, re-verification after remediation (independent judgement; read-only on the product).
- **Artifact store**: OpenSpec (`openspec/changes/course-talks-management/`). Read: `spec.md`, `tasks.md`, `apply-progress.md`, `known-limitations.md`, `suite-baseline.md`, the code and the tests.
- **Repo**: `C:/laragon/www/crm-maia-consultores`, branch `feat/course-talks-slice-6-ui`, HEAD `35c95fa`, working tree clean, nothing staged. The report was first written at `ce53b54`; the bounded blocker-closure round (§9, §10) was measured at `35c95fa`, two commits later (`03ae9b9` the activity-type filter, `35c95fa` the docs).
- **Runner**: `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test` (bare `php` is not on PATH). Every run sequential, one command per shell block.
- **Skills loaded**: `acceptance-checklist`, `project-discovery` (injected paths).
- **Strict TDD**: active (`openspec/config.yaml` `delivery.strict_tdd: true`). The note that `config.yaml` nominally documents the unrelated `b12-ui` change stands; the strict-TDD requirement was supplied for this phase and is honoured.
- **Structured status / actionContext**: no native `sdd-status` JSON was supplied for this re-verification. Readiness was resolved from the artifacts directly: the change is `openspec`-backed, all three required artifacts exist and are non-empty (`spec.md` 22 requirements / 34 scenarios, `tasks.md` **117 checked, 0 unchecked**, `apply-progress.md` with a remediation section at the end). No `blockedReasons` and no `actionContext` blocker. The previous report is REPLACED by this one and this report stands on its own.
- **Closure round (`35c95fa`)**: no native status JSON was supplied for this bounded re-check either. Readiness was again resolved from the artifacts, which are all present and non-empty (`spec.md` 22 requirements / 34 scenarios; `tasks.md` **118 checked, 0 unchecked** — my `grep`, not the artifact's claim; `apply-progress.md` with the remediation RED block and the filter unit). No `blockedReasons`, no `actionContext` blocker, every artifact inside the workspace. Two checks needed a modified tree, so they ran against a copy outside the repository (`C:/tmp/cta-verify`) and the product tree was never written to — `git status --porcelain` empty, `git diff --cached --name-only` empty, HEAD `35c95fa` (§9, method note).

**What changed since this report was written:** two commits landed after it — `03ae9b9` (`feat(courses): filter the unified activity list by type`, 3 code files, +361/−6) and `35c95fa` (docs: the retro-recorded RED, the review-budget table in `tasks.md`, and a rewrite of this report). **§9 below is the bounded confirmation of the three blockers this report raised, measured by me at `35c95fa`; §10 replaces the archive-gate verdict accordingly.** Nothing else was re-verified.

**What changed since the previous report:** the previous verification returned 19 PASS / 2 PARTIAL / 1 FAIL. Its FAIL (automatic certificate generation) was remediated in `ce53b54`; that requirement is now **PASS**. Its WARNING-2 (migration gap understated) is now **resolved** — `known-limitations.md` item 3 states all six pending migrations. Its WARNING-1 (activity-type filter) is **re-classified from WARNING to FAIL**, because the scenario carries a normative MUST. One new CRITICAL (strict-TDD evidence for the remediation unit) and two new WARNINGs are recorded below. Everything else in the previous report still holds and was re-confirmed by my own runs.

---

## 1. Verdict summary

| # | Requirement (delta spec) | Verdict |
|---|---|---|
| 1 | Unified activities module | **PASS** (was PARTIAL with the MUST-level FAIL-1; implemented in `03ae9b9` and re-verified by me in §9.1) |
| 2 | Activity, edition, and class model | PASS |
| 3 | Edition states and modality | PASS |
| 4 | Participants and enrollment | PASS |
| 5 | Enrollment and attendance states | PASS |
| 6 | Courses versus talks | PASS |
| 7 | Course grades and averaging | PASS |
| 8 | Payment completion before documents | PASS |
| 9 | Document types | PASS |
| 10 | **Automatic certificate generation** | **PASS** (was FAIL; remediated in `ce53b54`) — see §3.1 |
| 11 | Configurable PDF template matching reference design | PASS |
| 12 | Certificate filename pattern | PASS |
| 13 | Unique secure QR access | PASS |
| 14 | Revocation and regeneration | PASS |
| 15 | Receipts, invoices, and 18% IGV | PASS |
| 16 | Email delivery | PASS |
| 17 | Assisted WhatsApp delivery | PASS |
| 18 | Delivery history and last sent date | PASS |
| 19 | Pending and one-day configurable alerts | PASS |
| 20 | Permissions | **PARTIAL** — every clause enforced except “viewing audit/history”, whose permission has no surface (WARNING-1) |
| 21 | Auditability | PASS |
| 22 | v1 non-goals | PASS (structural absence; no dedicated test for the tax-generation non-goal — SUGGESTION-4) |

**Counts as first written: 22 requirements — 20 PASS, 2 PARTIAL, 0 FAIL at requirement level; the “Unified activities module” PARTIAL contained a MUST-level scenario gap (FAIL-1) that blocked a clean archive on its own.**
**Scenarios: 34 measured (`grep -c '^#### Scenario:' spec.md`). 33 satisfied, 1 unmet (`Filter activities by type`, `Conditions complete trigger generation` satisfied).**
**Counts after the closure round (§9, measured at `35c95fa`): 22 requirements — 21 PASS, 1 PARTIAL (requirement 20, the `audit.view` clause, WARNING-1), 0 FAIL; 34 of 34 scenarios satisfied; 0 unmet.**

Findings: **1 CRITICAL, 4 WARNING, 4 SUGGESTION.** One previous WARNING is resolved, one is elevated to FAIL. After the closure round: CRITICAL-1 is closed as a bookkeeping gap with a permanently unprovable ordering component, FAIL-1 is closed, WARNING-2 is closed for everything I flagged, and three new findings are added (WARNING-5, WARNING-6, WARNING-7) plus one new suggestion (SUGGESTION-5) — see §9.

---

## 2. Verification performed (exact commands and measured results)

All runs at HEAD `ce53b54`, sequential, one command per shell block.

1. **Module regression**
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=Course`
   → `{"tool":"phpunit","result":"passed","tests":472,"passed":472,"assertions":3599,"duration_ms":125500}`
   Previous verification measured **463 / 3,527**. Delta **+9 tests / +72 assertions**, exactly the remediation's new cases.

2. **Full suite**
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test`
   → `{"tool":"phpunit","result":"failed","tests":1276,"passed":1253,"assertions":6689,"duration_ms":544377,"failed":11,"errors":12}`
   Previous verification measured **1267 tests / 11 failures / 12 errors**. Delta **+9 tests**, and the **same 11 failures and the same 12 pre-existing errors**, verified by name against `suite-baseline.md`:

   | # | Failure | Baseline group |
   |---|---|---|
   | 1 | `AdminHttpTest::test_settings_update_round_trip_through_admin_form` | B |
   | 2 | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` | A |
   | 3 | `HistoryAndAuditTest::test_show_filters_by_subject_type_query` | A |
   | 4 | `ActionEditorLivewireTest::test_webhook_action_renders_b14_banner` | A |
   | 5 | `ActionEditorLivewireTest::test_send_whatsapp_template_action_renders_b14_banner` | A |
   | 6 | `SendWhatsAppTemplateWidgetLivewireTest::test_b14_banner_is_present` | A |
   | 7 | `WebhookWidgetLivewireTest::test_b14_banner_is_present` | A |
   | 8 | `WebhookWidgetLivewireTest::test_empty_allow_list_shows_warning_message` | A |
   | 9 | `SettingsServiceTest::test_set_persists_typed_values_and_audits` | B |
   | 10 | `GmailProviderTest::test_send_returns_documented_error_envelope_when_credentials_missing` | C |
   | 11 | `GoogleCalendarWebhookTest::test_remote_edit_for_crm_origin_link_is_overwritten_by_crm_projection` | C |

   The **12 errors** are the pre-existing Campaign/Livewire ones (`CampaignMetricsServiceTest` ×4, `CampaignItemActionHttpTest` ×3, `CampaignRunLifecycleTest` ×2, `CampaignTemplateHttpTest` ×3), all `User::where('email', env('ADMIN_EMAIL'))->first()` returning null. **No new failure appeared and no baseline failure disappeared.**

3. **Focused remediation suite**
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CourseEligibilityAutomationTest`
   → `{"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":68,"duration_ms":2000}` — matches the remediation's recorded 12 / 68 exactly.

4. **Migration state (read-only, real MySQL from `.env`)**
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan migrate:status`
   → all six course migrations `Pending` (`grep -c Pending` → `6`): `2026_08_26_000001_create_course_domain_foundation_tables`, `…_000002_add_course_academic_document_qr_token_hash_index`, `…_000003_add_email_message_id_to_outbound_deliveries`, `…_000004_add_delivery_status_to_course_commercial_documents`, `…_000005_add_course_commercial_document_idempotency_key`, `…_000006_add_delivery_discard_reason_to_course_commercial_documents`. The remediation added no migration, so six remains correct.

**Interpretation of the previous report's §2 (untouched requirements).** The previous report's per-requirement evidence for requirements 2–9, 11–19, 21, 22 was based on tests that are all inside the module suite I re-ran green (472 / 3,599). I re-read the specific code and test files it cites for the remediated surface and spot-checked the rest; nothing contradicted it, so those verdicts are kept and not re-narrated here. The requirements re-examined in depth for this re-verification are §3.1 (automatic generation), §3.2 (system author), §3.3 (stop switch), §3.4 (no regressions), §3.5 (re-judged warnings), §3.6 (declared residual).

---

## 3. Re-verification of the remediated surface

### 3.1 Automatic certificate generation — now PASS

**Requirement:** *“The system MUST automatically generate the applicable academic document when all conditions are complete… WHEN the final missing condition becomes complete THEN the system MUST generate the corresponding PDF document automatically.”*

**From the code, not from a test name:**

- `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php:48-125` now, inside a single `DB::transaction`: locks the enrollment (`lockForUpdate`), evaluates eligibility through the production `CourseEligibilityService`, returns when ineligible or when a `Current` document of the required type already exists (idempotency under the lock), and otherwise calls `CourseDocumentGenerationService::generateAutomatically($enrollment, $systemAuthor, $this->reason)`.
- `CourseDocumentGenerationService::generateAutomatically()` (`app/Services/Courses/CourseDocumentGenerationService.php:80-131`) delegates to the SAME private `generateDocument()` the operator path uses: it authorizes `Gate::forUser($systemAuthor)->authorize('generate', CourseAcademicDocument::class)`, evaluates eligibility again, selects the document type from eligibility, builds the filename/code, mints the QR token, renders the configured template and registers a private `documents` row. No eligibility, type, filename, code, QR or storage rule is reimplemented in the job. The automatic entry passes `deferStorageUntilOuterCommit: false`, so the PDF is stored inside the caller's transaction.
- It then writes an explicit service-level audit entry `course-academic-document-auto-generated` naming the SYSTEM author and the trigger reason, with no private path, no signed link and no raw QR token.
- The **stop switch** is the first statement of `handle()` (see §3.3). The **missing-author fail-closed** path is `courses.system_author_email` resolution returning `null` → log `error` and return without generating (see §3.2 and WARNING-3). The **failure path** catches `Throwable`, logs with queue-missing context, and re-throws so the queue owns retry; the transaction rolls the rows back and `storeAndRegisterDocument()` deletes the file on any post-write exception.

**Proof of the required end state, not of a job detail.** `tests/Feature/Courses/CourseEligibilityAutomationTest.php::test_completing_the_final_condition_generates_the_certificate_automatically_and_the_qr_route_streams_it` binds the real generation service with only the two outside-world drivers faked (PDF renderer, QR renderer), asserts the probe enrollment is really eligible *before* acting, then asserts after the job: exactly 1 `course_academic_documents` row, `type = ApprovalCertificate`, `status = Current`, a non-null `document_id`, a recorded `course_certificate_template_id`, a `qr_token_hash`, a `documents` row whose `docable_type` is `CourseAcademicDocument`, the named template title present in the **stored private file**, and finally `GET /certificate/qr/{token}` (built from `basename($payloads[0])`, i.e. the raw token actually handed to the QR renderer) returning `200` with `content-type: application/pdf`. That is a stored, registered, current document reachable through the public QR route.

**The old camouflage test is gone, in every form I could find.** `grep -rn "does_not_create_documents\|but_does_not_create\|evaluates_eligibility_but" tests/ app/` → no match; `git show ce53b54` shows `test_job_evaluates_eligibility_but_does_not_create_documents_or_mutate_enrollment_data` removed, and its replacement `test_job_generates_the_current_document_without_mutating_enrollment_data` asserts the same non-mutation claim while asserting **1** document instead of pinning 0. `grep -rn "no-op" tests/` finds no surviving assertion that the eligibility job is a no-op; the only remaining `assertDatabaseCount('course_academic_documents', 0)` occurrences in the module are the ineligible/duplicate-refusal/queued cases (`CourseEligibilityAutomationTest:57`, `CourseAcademicDocumentGenerationTest:179,236`, `CourseAcademicDocumentHttpTest:244,305`), none of which pins the job's absence of generation. The `// Future slice: dispatch document generation here.` comment no longer exists anywhere.

**Verdict: PASS.** The two `Cache`/queue drivers are faked exactly as the pre-existing generation suite does; every domain rule is the production one and the assertion is on observable state (row + private file + QR response), not on internals.

### 3.2 The SYSTEM author decision — adversarially

`database/seeders/CourseSystemAuthorSeeder.php` + `app/Services/Courses/CourseAuditActor::systemAuthor()` + `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php:59-67`.

| Claim | Evidence | Verified? |
|---|---|---|
| Genuinely non-human | name `Sistema (generación automática de certificados)`; email default `sistema.certificados@crm-maia.invalid` — RFC 2606 `.invalid` can never resolve | yes (code + test assertion on the name) |
| No role | seeder `syncRoles([])`; rollout test asserts `getRoleNames() === []` | yes |
| Exactly one ability | seeder `syncPermissions(['course-talks.documents.generate'])`; rollout test asserts `getPermissionNames() === ['course-talks.documents.generate']` | yes |
| Cannot authenticate | `is_active = false`; **two** independent guards: `LoginRequest::authenticate()` (`app/Http/Requests/Auth/LoginRequest.php:71` — inactive users get the generic `auth.failed` and the session is destroyed) and `EnsureUserIsActive` middleware (logs out and redirects every authenticated request); random 64-char password never disclosed | yes (code); the test asserts only the `is_active` flag, not an actual login attempt — see SUGGESTION-1 |
| Named in the audit trail | the model's `course-created` entry and the explicit `course-academic-document-auto-generated` entry both carry `causer_id = author->id` (asserted), plus `actor_type = system`, `system_action = course-eligibility-job`, `trigger_reason` | yes |
| Could it bypass anything else? | the single ability is consumed only by `CourseAcademicDocumentPolicy::generate` (and therefore the one `documents.generate` route/action). The `Gate::before` admin bypass in `app/Providers/AuthServiceProvider.php:93` fires **only** on the `admin` role, which this account does not hold (asserted), so there is no broad bypass. The rollout test asserts `can('generate', CourseAcademicDocument::class) === true` and `can('viewAny', CourseActivity::class) === false`, `can('viewAny', CourseEdition::class) === false`, `can('create', CourseAcademicDocument::class) === false`. | yes |
| Does any other surface admit it? | it holds no module-read ability, so it cannot open the module; it cannot hold a session; the seeder runs after `CoursePermissionsSeeder` (so its one permission exists) and after `AdminUserSeeder` (so the bootstrap admin stays the first user row, which `SeedersTest` and the automation actions rely on) | yes |
| Does its absence fail closed? | `systemAuthor() === null` → `logger()->error(...)` and `return`; nothing is generated and no anonymous `documents` row can exist (`uploaded_by` is NOT NULL) | yes in code, **NO TEST** — WARNING-3 |

`documents.uploaded_by` being a NOT NULL FK to `users`, and `generateDocument()` authorizing through `Gate::forUser($actor)`, are both confirmed in the code, so a userless job genuinely cannot register the private PDF without an account and the generation gate is genuinely exercised (not bypassed). The decision is sound and honestly justified.

### 3.3 The stop switch

- **Checked before any side effect:** it is the first statement in `handle()` (`if (! (bool) config('courses.automatic_document_generation_enabled')) { return; }`), before the author lookup, before the transaction and before any file or row.
- **Default matches the spec:** `config/courses.php` → `env('COURSES_AUTOMATIC_DOCUMENT_GENERATION_ENABLED', true)` — the requirement is the default state, and a deployment must opt out explicitly. The config block documents exactly why the switch exists.
- **The rewritten rollback test proves the new truth**, not the old one. `CourseRolloutTest::test_automatic_generation_is_stopped_by_the_config_switch_and_not_by_the_permission_rollback` runs the REAL `DatabaseSeeder`, asserts both probe enrollments are eligible first, then **actually revokes every `CoursePermissionsSeeder::PERMISSIONS` entry from every role** and asserts each role now holds zero of them, then handles the job and asserts **1** current document and a non-empty private disk — i.e. revoking permissions alone does **not** stop asynchronous generation — and only then sets the config flag to `false` on a second eligible enrollment and asserts nothing more is generated and no further file is written. The previous test (`test_the_only_eligibility_job_is_a_documented_no_op_so_a_rollback_has_no_job_to_stop`) asserted the opposite and was correctly replaced, not quietly kept.

### 3.4 No regressions

See §2. Full suite 1276 tests, **the same 11 documented failures and the same 12 pre-existing errors** as `suite-baseline.md` and as the previous measurement. Module suite 472 / 3,599, green (+9 tests over the 463/3,527 previous measurement, exactly the new cases). No new failure, none removed. The remediation also touched `tests/Feature/SeedersTest.php` (its `User::count() === 1` pin became an assertion that a full seed creates exactly the bootstrap admin and the system author once each, identified by role and by the seeder constant rather than a direct `env()` read) and `tests/Feature/Courses/CourseRolloutTest.php`. I read both diffs: they are honest adaptations to a genuinely new seeded row and to the corrected job behaviour — the replacement assertions are **stronger**, not weaker (the `SeedersTest` assertion now also proves no other non-admin user is seeded; the rollout test now exercises the real rollback rather than asserting the defect).

### 3.5 The previously reported WARNINGs, re-judged

- **WARNING-1 (activity-type filter) → now FAIL-1.** See §7. The spec scenario `Filter activities by type` contains a normative **MUST** (“the user MUST be able to filter by `Curso`, `Charla`, or all activities”), so its absence is a spec contradiction, not advice. Re-confirmed in the current tree: `app/Http/Controllers/CourseTalks/CourseActivityReadController.php::index()` loads every activity with no query-parameter handling, `resources/views/course-talks/activities/index.blade.php` renders no filter control (the `filters` slot holds only action buttons), and `grep` over the `CourseTalks` controllers and the activities view finds no `type` filter. The alerts screen’s own activity-type filter filters **delivery follow-ups**, not the unified activities list, so it does not satisfy the scenario.
- **WARNING-3 previously (`course-talks.audit.view`) → carried as WARNING-1.** Still true and still documented (`known-limitations.md` item 9): the permission is seeded, `CourseActivityPolicy::viewAudit` consumes it, and nothing reachable invokes it (the generic audit viewer uses the unrelated `audit.view`). The `Permissions` requirement’s *viewing audit/history* clause is therefore not enforced. Requirement verdict: PARTIAL (11 of 12 clauses enforced).
- **WARNING-4 previously (review budget) → carried as WARNING-2.** See §6. Two units do record in prose that the maintainer accepted a `size:exception` (`apply-progress.md:1248`, `:1323`), but there is still **no `size:exception` token anywhere in `tasks.md`**, the largest overruns (7.c at ~1,043 lines, the foundation corrective at 616) carry only recommendations, and the remediation unit (1,001 insertions / 59 deletions) declares no changed-line count at all.
- **WARNING-2 previously (migration gap understated) → RESOLVED.** `known-limitations.md` item 3 now states “ALL SIX course migrations are NOT applied”, tabulates all six, and says explicitly that the previous “TWO” was measured and corrected. My own `migrate:status` run confirms six pending.

### 3.6 The declared residual

**Judgement: the residual does NOT make automatic generation PARTIAL. The requirement is PASS.**

The spec’s scenario is: *GIVEN a participant has all required data AND payment is complete AND the edition validations pass AND the participant has an eligible result or participation. WHEN the final missing condition becomes complete THEN the system MUST generate…*. The GIVEN already assumes complete participant data, so the “final missing condition” that can complete is always payment, edition validations, result or participation — and all four are dispatched after commit by `CourseEligibilityTriggerService` (which has exactly `paymentChanged`, `gradeChanged`, `participationChanged`, `editionValidationChanged`) and all four reach the job and generate. I also checked the one structural hole that would break this reasoning: could enrollment creation itself complete the final condition without a trigger? No — the enrollment HTTP surface (`StoreCourseEnrollmentRequest`) cannot set `payment_status` or `final_result`, and the schema defaults them to `pending`, so a newly created enrollment is never eligible; through every reachable surface the last completed condition is one of the four triggered ones.

The residual is real but lies outside the scenario: a participant datum can never *become* complete after enrollment because the module exposes no participant-edit surface at all. That is why no trigger exists — and it is also a sharper consequence than the artifact states (see WARNING-4). The honest summary is: **the automatic-generation mechanism is complete and proven for every condition-completion path the system offers; it has no path to fire for a participant-data completion because no path exists to complete participant data.** PASS, with the gap recorded as a WARNING, not as a partial implementation.

---

## 4. Task completion

- `openspec/changes/course-talks-management/tasks.md`: **117 checked, 0 unchecked** when first written; at `35c95fa` my own `grep` measures **118 checked, 0 unchecked** (`grep -c '^\s*- \[x\]'` → `118`; `grep -n '^\s*- \[ \]'` → no output). The 118th row is the bounded filter unit's implementation-owned row. **No unchecked implementation task remains.** The exact set of unchecked lines is empty.
- The ledger's own reconciliation section was read before judging and its claims were spot-checked rather than trusted: the per-unit `--filter=Course` counts and focused-suite counts it cites exist in `apply-progress.md`, and the ones I re-ran match (the module run is now 472/3,599). The two REFORMULATED rows (view split, dashboard-scope refactor) state their residual and are recorded in `known-limitations.md`. `CourseRolloutTest` does run the real full seed (`$this->seed(DatabaseSeeder::class)`).
- **Caveat:** every row can be `[x]` while a delta-spec MUST is unmet; the ledger tracks the planned slices, not spec conformance. When this section was written the ledger was complete but FAIL-1 remained an unmet MUST; FAIL-1 is now closed and verified (§9.1), and the one remaining MUST-adjacent gap is the `audit.view` clause (WARNING-1), which the ledger never tracked as a task. One new ledger observation is recorded in §9.4 (WARNING-7): the parent-owned review row for the new unit exists only in `apply-progress.md`, not in `tasks.md`.

---

## 5. Strict-TDD verification

- `apply-progress.md` contains a `TDD Cycle Evidence` heading **45 times** (`grep -c`), for the slices and corrective units. The document-level gate is therefore satisfied.
- **But the remediation unit has NONE.** The last TDD Cycle Evidence table is at line 3399 (unit 7.d); the remediation section at line 4351 has “What and why / Where / Decisions / Authorized surface expansion / Evidence / Declared residual / Process note” and **no TDD cycle table**, no RED run for the replacement tests, and no RED→GREEN→TRIANGULATE→REFACTOR record. The record itself explains why: “the subagent timed out at 30 minutes while applying the last assertion fix, so the parent verified the resulting tree.” The fix for this change’s only FAIL is therefore shipped without the RED evidence strict TDD requires. **CRITICAL-1** (it does not mean the implementation is wrong — the replacement tests are correct and green — but the process evidence is missing for the most critical unit of the change).
- **Reported test files cross-referenced against the codebase:** every class named in the evidence exists; no ghost file. The remediation's new/modified test files all exist: `tests/Feature/Courses/CourseEligibilityAutomationTest.php` (12 tests / 68 assertions, re-run green), `tests/Feature/Courses/CourseRolloutTest.php` (10 tests / 110 assertions, inside the green module run), `tests/Feature/SeedersTest.php` (2 / 35).
- **GREEN still true:** confirmed by my own runs — module 472/3,599 green; focused automation 12/68 green; full suite with only the documented baseline failures.
- **Assertion-quality audit (remediation tests):** no tautologies, no ghost loops, no type-only assertions, no smoke-only tests, no `markTestSkipped`/`expectNotToPerformAssertions`/`assertTrue(true)`. The assertions are behavioural and value-bearing: exact document counts, `status === Current`, non-null `document_id`/`qr_token_hash`, the template title **inside the stored bytes**, `content-type: application/pdf` off the real public route, the exact causer id and property bag of the audit row, exactly one role / exactly one permission name, `Hash::check` against the admin password and against `password`. The one carried-over implementation-detail assertion (`page-break-after: always`) is SUGGESTION-2.
- **Note on a test that the remediation modified to keep the suite green (`SeedersTest`).** Its `User::count() === 1` pin was replaced because the SYSTEM author is a real new seeded row. I verified the replacement is not a weakening: it asserts exactly one admin (by role), exactly one SYSTEM author (by the seeder constant, not `env()`), and zero users that are neither — which is a stronger claim than the original integer.

- **Status of CRITICAL-1 after the closure round (§9.2, measured by me at `35c95fa`):** closed as a bookkeeping gap. A RED for the pre-fix revision now exists and I reproduced it first-hand — `--filter=CourseEligibilityAutomationTest` against `ce53b54^` returns `{"tests":12,"passed":5,"failed":6,"errors":1,"assertions":32}` with the same six assertion failures the record quotes, plus **one error the record omits**. The ordering guarantee strict TDD actually asks for (RED *before* the fix) is permanently unprovable and needs an explicit owner-accepted deviation; see §9.2.

---

## 6. Review-workload / PR-boundary verification

- `tasks.md` `Review Workload Forecast`: `Chained PRs recommended: Yes`, `400-line budget risk: High`, `Chain strategy: stacked-to-main (approved)`, `Decision needed before apply: No`.
- Overruns are recorded honestly in `apply-progress.md` and, for two units, an accepted `size:exception` is stated in prose (lines 1248, 1323). But there is **no `size:exception` token in `tasks.md`**, the largest units (7.c ~1,043 lines ≈ 2.6×; foundation corrective 616 / 831 with bookkeeping) carry recommendations only, and the remediation unit itself (1,001 insertions / 59 deletions) declares no changed-line count and no exception. → WARNING-2.
- **PR boundary:** relative to `main` the change is still a single branch (`feat/course-talks-slice-6-ui`, 31 commits, **207 files, +34,240 / −190**), so the approved stacked chain of separate branches/PRs was never materialised at branch level. I can verify the branch/commit structure only; whether separate PRs exist outside git is not observable here. The remediation is one commit, `ce53b54`.

- **After the closure round (§9.3):** the review-budget table now exists in `tasks.md` and every number I checked is traceable to a recorded per-unit measurement in `apply-progress.md`, including all three units this section flagged. The residual is that the table is not the full set. The branch is now 33 commits; the filter unit is the single commit `03ae9b9` (3 code files, +361/−6, inside the 400-line budget).

---

## 7. Findings

### CRITICAL-1 — The remediation unit ships without strict-TDD evidence

- **What:** the fix for this change’s only FAIL has no `TDD Cycle Evidence` table, no recorded RED run for the twelve replacement tests, and no RED→GREEN→TRIANGULATE→REFACTOR cycle in `apply-progress.md`. The record states the subagent timed out before its final step and the parent ran the verification.
- **Evidence:** last `TDD Cycle Evidence` heading at `apply-progress.md:3399`; remediation section begins at `:4351` with no such table; `awk` over lines 4351-4382 finds no RED/GREEN/failure record.
- **Attribution:** the remediation unit (parent-run after subagent timeout). Under the strict-TDD contract the phase is instructed to flag missing or incomplete TDD evidence as CRITICAL.
- **Impact:** process evidence only. The implementation and its tests are correct and green; this does not change any requirement verdict.
- **Status after the closure round (§9.2, my measurement):** closed as a bookkeeping gap. I reproduced the pre-fix RED first-hand — `--filter=CourseEligibilityAutomationTest` against `ce53b54^` → `{"tests":12,"passed":5,"failed":6,"errors":1,"assertions":32}`, the same six assertion failures the record quotes plus **one error the record does not mention** (`test_the_automatic_generation_names_the_system_author_and_states_that_it_was_automatic` → `No query results for model [App\Models\Courses\CourseAcademicDocument]`). So seven of twelve tests are red without the fix, not six, and the recorded envelope's own arithmetic (`5 + 6 = 11` of 12) betrays the dropped `"errors":1`. Two record corrections are owed. The part that cannot be closed is the ordering: a RED obtained after the fix proves the tests are regression-protective, never that they came first — that needs an explicit owner-accepted deviation.

### FAIL-1 — The activity-type filter required by the spec is missing (MUST unmet)

- **Requirement:** `Unified activities module` — *“…with a visible `Tipo` value of `Curso` or `Charla`, shared filters, and shared operational tracking.”* Scenario `Filter activities by type`: *“…AND the user MUST be able to filter by `Curso`, `Charla`, or all activities.”*
- **Why FAIL and not WARNING:** the scenario contains a normative **MUST**, so the absence is a contradiction of the delta spec, which is a FAIL by this contract, not advisory advice. (The previous report classified it WARNING and left the requirement PARTIAL; on re-reading the spec text the classification is wrong.)
- **Evidence:** `app/Http/Controllers/CourseTalks/CourseActivityReadController.php:14-24` loads every activity (`orderBy('type')->orderBy('name')`) with no query-parameter handling; `resources/views/course-talks/activities/index.blade.php` renders no filter form or control (the `filters` slot contains only the create, templates and alerts links); `grep` over `app/Http/Controllers/CourseTalks/` and `resources/views/course-talks/activities/` finds no `type` filter. `CourseTalksReadOnlyHttpTest::test_authorized_user_can_view_all_activity_types_and_activity_detail` asserts the unified list only and never exercises a filter. The alerts screen’s eight filters (unit 7.b) filter delivery follow-ups, not the activities list.
- **Attribution:** this change. Untouched by the remediation.
- **Requirement verdict:** PARTIAL.
- **Status after the closure round (§9.1, my measurement): CLOSED — requirement verdict PASS.** The filter exists (`03ae9b9`), `--filter=CourseTalksReadOnlyHttpTest` is 19/19 (126 assertions) and `--filter=Course` is 482/482 (3,668 assertions) in my own runs, the vocabulary comes from `CourseActivityType::cases()` with no literal list anywhere, an unknown scalar narrows to nothing and says so, an array is dropped before the query (200, never 500), and the `viewAny` gate is untouched (403 asserted). My mutation run — commenting out the single `where('type', …)` clause in a copy — kills exactly the four list-narrowing tests and leaves every control/vocabulary test green.

### WARNING-1 — `course-talks.audit.view` is seeded but enforces nothing

- As in `known-limitations.md` item 9: `CourseActivityPolicy::viewAudit` (`app/Policies/Courses/CourseActivityPolicy.php:33-36`) is the only consumer and nothing reachable invokes it; the module exposes no audit surface and the generic viewer uses the unrelated `audit.view`. The `Permissions` requirement’s *viewing audit/history* clause is not enforced in the running system. Documented open product decision; report, not fix. Requirement verdict: PARTIAL.

### WARNING-2 — Review budget repeatedly exceeded and no `size:exception` was recorded

- See §6. Two units state in prose that a `size:exception` was accepted; no exception token exists in `tasks.md`; the largest two units and the remediation unit itself record no exception and the remediation records no changed-line count. Attribution: this change.
- **Status after the closure round (§9.3, my measurement): CLOSED for everything this finding asked for.** The table exists, and all three units flagged here (7.c 1,043; foundation corrective 616 / 831; remediation 1,001) are in it with line counts that trace to recorded per-unit measurements in `apply-progress.md` and are plausible against the commits. Residual (reported as WARNING-6 in §9.4): the table is presented as the recorded exceptions but omits five further units that `apply-progress.md` itself records over the 400-line budget, and the literal `size:exception` token is still absent (accepted here, because the approved strategy was chained delivery and never `single-pr`).

### WARNING-3 — The fail-closed path for a missing SYSTEM author has no test

- **What:** `EvaluateCourseDocumentEligibility::handle()` (`app/Jobs/Courses/EvaluateCourseDocumentEligibility.php:59-72`) returns without generating and logs an error when `CourseAuditActor::systemAuthor()` is null. This is the fail-closed guarantee that the module never generates anonymously — and nothing tests it.
- **Evidence:** `grep -rn "systemAuthor() === null\|nothing was generated\|fail-closed" tests/` → no match; `CourseEligibilityAutomationTest` always seeds its own author fixture.
- **Attribution:** the remediation unit. The behaviour is correct in code; the guarantee is unproven by a test. The task explicitly asked to check this path, so it is recorded rather than passed silently.

### WARNING-4 — A participant-data dead-end, and the declared residual is imprecise

- **What:** `known-limitations.md` item 13 says automatic generation does not fire for a participant-data correction and that “the certificate waits for an operator”. That is inaccurate: the operator path is gated by the same `CourseEligibilityService`, so an enrollment with incomplete participant data is refused by the operator too. Worse, the module has **no participant-edit surface** (`grep` finds only `CourseParticipant::firstOrCreate` inside `CourseEnrollmentService`), so the data can never be completed.
- **Reachable consequence:** resolving a participant from an existing contact (`CourseEnrollmentService::contactAttributes()`) copies `email` from `$contact->email` and `mobile` from `$contact->phone ?? $contact->whatsapp`; a contact without email or phone yields a participant that fails `hasCompleteParticipantData()`, which makes the enrollment permanently ineligible for **any** document, automatic or manual, with no remediation path in v1.
- **Not a spec violation:** the spec requires enrolling from contacts or creating a minimum record, and the automatic requirement only fires when “participant required data is complete”. Both hold. It is a workflow gap that should be stated accurately before archive.
- **Attribution:** this change (the eligibility design), surfaced by the re-verification of the remediation’s declared residual.

### SUGGESTION-1 — `systemAuthor()` trusts the email; the account’s invariants are unguarded and login is untested

- `CourseAuditActor::systemAuthor()` resolves `User::where('email', $email)->first()` with no check that the row is the seeded non-human account (no assertion that it lacks roles or holds only the generation ability). Because `generateDocument()` authorizes *as that user*, a human row that happened to occupy `courses.system_author_email` would be both the author and the authorizing actor (and, if it carried the `admin` role, would pass through the `Gate::before` bypass). Nothing today can produce that row — the seeder is absolute and idempotent — but there is no invariant enforcing it. Separately, the rollout test asserts `is_active === false` but never drives a login attempt with the SYSTEM credentials; the two guards (`LoginRequest`, `EnsureUserIsActive`) are verified by code reading only.

### SUGGESTION-2 — Carried over: one implementation-detail assertion

- `tests/Feature/Courses/CourseCertificateTemplateTest.php:53` asserts `page-break-after: always`, a CSS declaration rather than rendered structure. Paired with content assertions, so not smoke-only, but it is the one presentation-detail assertion in the audited surface.

### SUGGESTION-3 — Stale docblock left by the remediation

- `tests/Feature/Courses/CourseRolloutTest.php:53` (class docblock) still claims “the eligibility job is a documented no-op (nothing to stop)”, which the remediation made false. The individual test method’s docblock was corrected; the class-level one was not.

### SUGGESTION-4 — Carried over: the tax-generation non-goal has no dedicated test

- “No automatic tax generation” is satisfied by structural absence, but unlike the WhatsApp non-goal it has no test asserting the absence of tax side effects.

### Accepted open product decisions (documented; do not contradict any spec requirement)

1. **`course-talks.view` grants participant-PII read access across all editions with no team data-scope** — the spec asks for permission separation and denial of restricted actions, which hold; it does not require ownership scoping.
2. **Mutation policies take no model instance, so one permission mutates any resource by id** — permission-gated and denied for unauthorized users; cross-resource scoping is a product decision.

### Documented limitations reviewed — none contradicts a spec requirement

`known-limitations.md` items 1, 2, 4, 5, 6, 7, 8, 10, 11, 12 remain genuine documented limitations with no delta-spec requirement behind them. Item 3 is now correct (resolved). Item 9 (WARNING-1) and item 13 (WARNING-4) are the two that interact with a requirement, and both are recorded above.

---

## 8. What is NOT verified (no coverage claimed)

- **Deployment / migrations.** Nothing was applied and nothing will be. All six course migrations are **Pending** on the real dev MySQL database, so the module has **no tables** there and every module screen would fail; `php artisan migrate` is a pending owner action and deploy prerequisite. No real-database schema, unique index, seeding or rollback was exercised.
- **Browser / human acceptance.** Nothing was run in a browser. Every human scenario, including the rollout drills and the duplicate-registration double-click, remains `not run`. Human acceptance is pending until a human records results.
- **PDF rendering fidelity.** The PDF assertions are Blade-render assertions with the PDF renderer faked at the service boundary; the visual output against the approved reference design was not inspected.
- **Real email/WhatsApp transport.** Exercised with fakes and queued jobs only; no real message was sent and no real `wa.me` handoff was opened.
- **The audit trail in a real queue worker.** The remediation’s tests invoke `handle()` synchronously; no real queue worker processed the job, so the queue’s retry/ownership behaviour after the escaping exception is asserted from code and the `Log::spy()` call, not observed in a worker.
- **The SYSTEM author’s login refusal.** Verified by reading `LoginRequest` and `EnsureUserIsActive`, not by driving a login attempt.
- **Secondary baseline claims.** The baseline’s “fails identically on `main`” was corroborated by the empty `git log main..HEAD -- <files>` over the failing tests’ surfaces but I did not check out `main` or run the suite there — a read-only verify must not mutate the worktree.
- **PR structure.** Whether the approved chained PRs exist outside this repository is not observable; only branch/commit structure vs `main` is verified.

---

## 9. Blocker-closure confirmation (bounded re-check, read-only on the product)

This round answers one question only: are the three archive blockers this report raised genuinely closed, and did closing them introduce anything? Nothing else was re-verified. Every number below is my own measurement at HEAD `35c95fa`. I did **not** take the parent's, the apply unit's or any subagent's summary as evidence; where a claim was checkable I measured it, and where I could not measure it I say so.

**Method note — how I measured without touching the product.** Two of the checks need a modified tree (an independent RED and a mutation proof). Rather than editing the product I copied `app/ tests/ database/ resources/ routes/ config/ bootstrap/ public/ vendor/ storage/ lang/ artisan composer.json composer.lock phpunit.xml .env` into `C:/tmp/cta-verify` (outside the repository), cleared `bootstrap/cache` there, and performed every mutation in that copy. The copy was proven equivalent to the product before each mutation (its `--filter=CourseTalksReadOnlyHttpTest` run returns the same `19 / 19 / 126` as the product's) and its `app/` was diffed `diff -r --brief` back to identical afterwards. The product tree was never written to: `git status --porcelain` empty, `git diff --cached --name-only` empty, `git rev-parse --short HEAD` → `35c95fa`.

### 9.1 FAIL-1 — the activity-type filter MUST → **CLOSED** (requirement verdict PASS)

Commands, all with `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe`, sequential, one per shell block:

| # | Command | Result I measured |
|---|---|---|
| 1 | `artisan test --filter=CourseTalksReadOnlyHttpTest` (product) | `{"tool":"phpunit","result":"passed","tests":19,"passed":19,"assertions":126,"duration_ms":2634}` |
| 2 | `artisan test --filter=Course` (product) | `{"tool":"phpunit","result":"passed","tests":482,"passed":482,"assertions":3668,"duration_ms":47341}` |
| 3 | copy: the single clause `$activities->where('type', $typeFilter['value']);` commented out | `{"result":"failed","tests":19,"passed":15,"failed":4,"assertions":112}` |
| 4 | copy: controller **and** view reverted to `03ae9b9^` (pre-filter revision) | `{"result":"failed","tests":19,"passed":12,"failed":7,"assertions":96,"errors":none}` |

Measurement 3 kills exactly four tests — `test_filtering_the_activity_list_by_course_hides_talks`, `test_filtering_the_activity_list_by_talk_hides_courses`, `test_an_unknown_activity_type_filter_narrows_to_nothing_and_is_reported`, `test_the_type_filter_narrows_each_type_independently_when_both_types_repeat` — and leaves every control/vocabulary assertion green. Its failure output shows the "course"-filtered response containing **both** `CUR-FILT-001` and `CHA-FILT-001`, and the `webinar`-filtered response rendering the amber "no es un valor válido y no encontró ninguna actividad" message over the **full** list. Measurement 4 is the mirror image: without the implementation the new suite is red (7 failures, no errors), and measurement 1 says it is green with it.

What the task asked me to confirm, each answered by measurement rather than by a test name:

- **It really narrows by type.** `CourseActivityReadController::index()` applies `where('type', $typeFilter['value'])` only when a value survives normalization; measurement 3 proves the tests pin the QUERY and not the markup.
- **The vocabulary is the enum's, not a parallel literal list.** `typeOptions()` iterates `CourseActivityType::cases()` and takes labels from `$type->label()`; the view only iterates the array the controller hands it; the validity check is `CourseActivityType::tryFrom($value)`. A `grep` over both files finds no literal `'course'` / `'talk'` / `Curso` / `Charla` value list — only route and view names.
- **An unknown value cannot silently show the full list.** It is passed to the query as it arrived, matches no row, and the screen names the rejected value in a rendered `x-alert`; the test asserts the message present **and** every activity absent. Measurement 3 shows why that assertion is the load-bearing one: without the `where` the message still renders while the full list returns.
- **Malformed input cannot 500.** An array — which is how PHP parses `?activity_type[]=…`, a repeated parameter or a nested one — is dropped before any query sees it, and the screen says it was discarded while the complete list stays; the test asserts `200` + full list + discard message. Scalars are bound parameters, so no scalar reaches SQL unbound.
- **Authorization is unchanged.** `Gate::authorize('viewAny', CourseActivity::class)` is still the first statement of `index()`; `git show --stat 03ae9b9` is exactly three files (controller +91/−6, view +39, test +237) with no route, policy, permission, enum, model, migration, service or config touched; the 403 assertion with the parameter present passes.

**Scope creep: none.** 367 changed lines (361 added / 6 deleted) across three code files plus six bookkeeping lines — inside the 400-line budget, so no `size:exception` is owed for it. The unit declares the other findings as out of scope and the diff confirms it.

### 9.2 CRITICAL-1 — strict-TDD evidence for the remediation → **CLOSED as a bookkeeping gap; the ordering component is permanently unprovable**

I reproduced the recorded RED rather than reading it. In the copy, with `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php` restored to `ce53b54^` (55 lines — the eligibility-only version whose body still ended with `// Future slice: dispatch document generation here.`) and nothing else changed:

`artisan test --filter=CourseEligibilityAutomationTest` → `{"result":"failed","tests":12,"passed":5,"failed":6,"errors":1,"assertions":32}`

- **Six behavioural failures** with the messages the record quotes: `test_completing_the_final_condition_generates_the_certificate_automatically_and_the_qr_route_streams_it` (*"Completing the last missing condition must generate the corresponding document automatically. Failed asserting that 0 is identical to 1."*), `test_job_generates_the_current_document_without_mutating_enrollment_data`, `test_a_re_trigger_cannot_mint_a_second_certificate`, `test_a_talk_trigger_generates_the_talk_certificate_through_the_eligibility_service`, `test_a_failed_automatic_generation_escapes_the_job_and_leaves_nothing_behind`, `test_a_failed_generation_does_not_leave_a_failed_document_row_blocking_the_next_trigger`.
- **One error the record does not mention:** `test_the_automatic_generation_names_the_system_author_and_states_that_it_was_automatic` → `No query results for model [App\Models\Courses\CourseAcademicDocument]` (a `firstOrFail()` on a document that was never generated). So **seven** of the twelve tests are red against the pre-fix revision, not six, and the recorded envelope's own arithmetic betrays the omission — `5 passed + 6 failed = 11` of the `12` it reports, because the `"errors":1` field is missing. Its prose ("each on a BEHAVIOURAL assertion… never on a fatal") is likewise inexact for that seventh test: it is an error, not a failure.
- Restoring the current job in the copy returns `{"result":"passed","tests":12,"passed":12,"assertions":68}` for the same filter, and `diff -r --brief` of the copy's `app/` against the product's `app/` reports no differences — the one swapped file was the only variable.

**Verdict.** The substance of CRITICAL-1 is closed: an executable RED for the pre-fix revision now exists, is reproducible, and is real — I reproduced it first-hand and found *more* red than the record claims. What cannot be repaired is the ordering: a RED obtained after the fix is a reconstruction, so it proves the tests are regression-protective; it does not prove they were written before the code, which is the one thing strict TDD exists to guarantee. Two corrections would make the record accurate (add `"errors":1` and the seventh affected test to the retro-RED block; drop "never on a fatal"), and the deviation itself should be recorded as explicitly accepted by the owner in `known-limitations.md` or the archive report. If the owner wants the letter of the cycle instead, the only honest remedy is reverting `ce53b54` and re-running RED→GREEN — which I do not recommend, because it re-derives paperwork from a fix that is already correct, green and now RED-proven in the other direction.

### 9.3 WARNING-2 — review budget → **CLOSED for everything this finding asked for** (table not exhaustive — see WARNING-6)

The table added to `tasks.md` accounts for all three units I flagged, and every number in it traces to a recorded per-unit measurement:

| Table row | Table | What I measured | Plausible against the commit? |
|---|---|---|---|
| 6.c attendance matrix | 780 | `apply-progress.md:1775` records "780 added / 0 deleted"; commit `aceca73` totals +873/−1 | yes (same unit, wider commit total) |
| 6.e-1 academic document lifecycle | 944 | `apply-progress.md:2071` records exactly 944; commit `cc74cb0` +1,057 | yes |
| 6.e-2 academic delivery actions UI | 1,026 | `apply-progress.md:2278` records exactly 1,026; commit `c0059d3` +1,150 | yes |
| 6.f-2b commercial delivery actions UI | 1,200 | `apply-progress.md:2865` records exactly "1,196 added / 4 deleted = 1,200"; commit `c8a1739` +1,298/−4 | yes |
| 7.b alerts dashboards and filter list | 1,962 | the 7.b section totals "1,960 added lines … roughly 4.9× the 400-line budget"; commit `be1b793` +2,099/−4 | yes |
| 7.c audit regression suite | 1,043 | `apply-progress.md:3944` = 122 tracked + 921 untracked = 1,043; commit `a0165df` +1,192/−32 | yes |
| foundation corrective | 616 (831 with bookkeeping) | `apply-progress.md:4311–4312` records exactly "568 + 48 = 616" and "783 + 48 = 831"; commit `1eb1260` +784/−48 | yes |
| automatic generation remediation | 1,001 | commit `ce53b54` = +1,001/−59 | yes — the line count this finding said was missing is now recorded |

**Any missing?** Yes, five units that `apply-progress.md` itself records above the 400-line budget are absent from the table, and the accompanying commit message's "eight units that landed above the 400-line budget" is therefore an undercount: 6.b enrollments and participants UI (1,371, `:1683`), 6.d grade matrix (827, `:1977`), 6.f-1 commercial documents UI (1,083, `:2532`), 6.t1 certificate templates made real (1,032, `:3309`), 6.t2 certificate template management UI (1,312, `:3457`) — see WARNING-6. Also, the literal `size:exception` token is still absent everywhere: the table records a prose disposition per unit instead. I accept that as sufficient here, because the approved strategy was chained delivery and never `single-pr` (the token exists for the single-PR case) and the table's own note records that the chain was never materialised as PRs at all.

### 9.4 New findings from this round (reported, not silently traded for a verdict)

**WARNING-5 (new) — the "unknown value" message can contradict the list it stands next to, on MySQL only.** Measured: the product's connection compares case-insensitively (`artisan tinker --execute="dump(DB::selectOne('select (? = ?) …', ['course','Course']));"` → `matches: 1`, `@@collation_connection: utf8mb4_unicode_ci`), while the test connection does not (the same statement under SQLite `:memory:`, the phpunit connection → `0`). The controller decides validity in PHP with an exact-case `CourseActivityType::tryFrom()` but narrows in SQL with `where('type', $value)`. Consequence (inferred, not directly measured — the dev database has no course tables, so `course_activities.type`'s collation cannot be read; it is a plain `string(20)` in the migration and inherits the connection default): on MySQL, a hand-edited `?activity_type=Course` would list the course rows **while** the screen renders "El tipo de actividad Course no es un valor válido y no encontró ninguna actividad" — the exact false statement this unit exists to prevent, invisible to every test because SQLite is case-sensitive. The control never emits such a value, so it is reachable only by editing the URL: a WARNING, not a spec break, and not a gate-closer. One-place fix that keeps the vocabulary single-sourced: resolve with `CourseActivityType::tryFrom(strtolower(trim($value)))` and, when it resolves, put the enum's own `->value` into the query and report nothing; only a value that resolves to nothing reaches the "unknown" branch.

**WARNING-6 (new) — the review-budget table is not the full set of exceptions** (the five units listed in §9.3, all of them already recorded in `apply-progress.md` with line counts). Bookkeeping completeness only: no requirement, test or product line is affected. Cheapest fix: add the five rows, or retitle the table so it reads as the units the verification flagged rather than as the change's complete exception list.

**WARNING-7 (new) — the review row for the new unit is not in the ledger.** `apply-progress.md:4474` lists `- [ ] Review the bounded activity-type-filter unit: … <!-- sdd-owner: parent -->` under "Remaining tasks (exact unchecked lines)", but `grep` for that row in `tasks.md` finds nothing: the ledger carries only the unit's implementation-owned `[x]` row. So `tasks.md` reports 118 checked / 0 unchecked while a review action recorded in the progress artifact is untracked in the ledger — a reviewer reading only `tasks.md` would not know the new unit still awaits review. It does not make any implementation task incomplete (the row is parent-owned), and it does not block archive on its own.

**SUGGESTION-5 (new) — stale text in the new unit's record.** `apply-progress.md`'s bounded-unit section still describes its state as "HEAD `ce53b54` plus this unit's uncommitted edits. Nothing staged, nothing committed", although the unit is committed as `03ae9b9` and the ledger row is committed too.

**What this round did not verify:** anything outside the three blockers. The 21 previously verified requirements, the migration state, the human-acceptance gaps and everything listed in §8 stand exactly as written. I also did not re-run the `pint --test` check the unit reports, so its formatting-clean claim is unverified by me (it is not part of any requirement or gate).

---

## 10. Archive-gate recommendation (updated after the blocker-closure round)

### 10.1 Verdict: **ARCHIVE**

All three blockers this report raised are answered on measured evidence, and closing them changed no requirement verdict:

1. **FAIL-1 → closed.** The spec scenario `Filter activities by type` is now met: the list is narrowed by the enum's own vocabulary, an unknown value narrows to nothing *and says so*, malformed input answers 200, authorization is unchanged, and the tests that prove it die when the query clause is removed (§9.1, measurements 1–4). 34 of 34 scenarios satisfied; requirement 1 flips to PASS.
2. **CRITICAL-1 → closed as a bookkeeping gap.** A RED for the pre-fix revision now exists and I reproduced it first-hand, finding seven of twelve tests red where the record claims six (§9.2). Two corrections are owed to the record, and the ordering guarantee needs an explicit, owner-accepted deviation — a documentation act, not a code risk.
3. **WARNING-2 → closed for the flagged units.** The table exists, covers all three, and every number is traceable and plausible (§9.3).

Nothing else moved: module suite 482 / 3,668 green; full suite `{"tests":1286,"passed":1263,"failed":11,"errors":12,"assertions":6758}` — the identical 11 baseline failures by name and the identical 12 pre-existing campaign/Livewire errors documented in `suite-baseline.md`, with **+10 tests / +69 assertions** over my previous measurement, exactly the new unit's cases and nothing else; `tasks.md` 118 checked / 0 unchecked; the closure touched 3 code files, 367 lines, inside budget.

**Record these in the archive report (none of them blocks archiving):** (a) the retro-RED corrections — the omitted `"errors":1` and the seventh affected test, and the "never on a fatal" wording (§9.2); (b) the owner's explicit acceptance of the strict-TDD ordering deviation, in `known-limitations.md` or the archive report (§9.2); (c) WARNING-5, WARNING-6, WARNING-7 and SUGGESTION-5 as carried residuals (§9.4).

**Still open and still not blocking** (unchanged from the previous revision of this section): WARNING-1 (`course-talks.audit.view` — decide the surface or drop the permission), WARNING-3 (no test for the missing-SYSTEM-author fail-closed path), WARNING-4 (`known-limitations.md` item 13 and the participant-data dead-end), SUGGESTIONs 1–4, the six unapplied migrations and every unperformed human-acceptance scenario. None of these is a MUST-level gap, and the two open product decisions recorded above are unaffected.

### 10.2 Superseded verdict, retained for the record

The previous revision of this section, written at `ce53b54`, is preserved verbatim below; its three blockers are answered in §9.1–§9.3 and the verdict above replaces it.

**Do not archive yet.** The remediation did what the previous report required: the delta spec’s automatic-generation requirement is now genuinely implemented — a stored, registered, current document reachable through the public QR route — and the change adds **no new failure** (full suite 1276 tests, the same 11 baseline failures and 12 pre-existing errors; module 472/3,599 green). Tasks are 117/117. The system-author design is sound and adversarially verified, and the stop switch both defaults correctly and is proven by a rewritten rollback test that asserts the new truth.

Three things stand between this and a clean archive:

1. **FAIL-1 — the activity-type filter.** The spec scenario `Filter activities by type` is a MUST and is unmet. Either implement the filter on the unified activities list (a bounded unit: query parameter + control + a focused test that actually exercises `Curso`, `Charla` and “all”), **or** obtain an explicit owner decision to re-scope that clause and record it in `known-limitations.md`. It was not touched by the remediation and cannot be waved through as a WARNING: a normative MUST that the running system does not honour is a spec contradiction.
2. **CRITICAL-1 — strict-TDD evidence for the remediation unit.** The change’s most important fix ships without a RED→GREEN record. This is cheap to close: run the twelve replacement tests against the pre-`ce53b54` job and record the RED, or record an explicit, owner-approved TDD deviation for the parent-run remediation. Under `delivery.strict_tdd: true` this is not optional bookkeeping.
3. **WARNING-2 — record the `size:exception` (and the remediation’s line count) in `tasks.md`.** The largest units and the remediation itself are well over the 400-line budget with no formal exception.

**Recommended (not blocking by themselves):** WARNING-1 (`audit.view` — decide surface or drop), WARNING-3 (add the missing-author fail-closed test), WARNING-4 (correct `known-limitations.md` item 13 and decide whether to add a participant-edit path), and the four suggestions.

Once FAIL-1 is implemented or explicitly re-scoped by the owner and CRITICAL-1 is closed, this change is archive-ready on the evidence above.
