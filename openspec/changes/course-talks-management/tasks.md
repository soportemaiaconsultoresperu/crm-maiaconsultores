# Tasks: course-talks-management

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 3,000–5,500 across schema, domain, services, UI, integrations, and tests |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 domain foundation → PR 2 grading/eligibility → PR 3 certificates/PDF/QR → PR 4 commercial documents/IGV → PR 5 delivery/history → PR 6 UI workflows → PR 7 dashboards/alerts/audit/rollout |
| Delivery strategy | ask-on-risk → chained delivery approved |
| Chain strategy | stacked-to-main (approved) |

Decision needed before apply: No — chained delivery approved
Chained PRs recommended: Yes
Chain strategy: stacked-to-main (approved)
400-line budget risk: High

> The human approved chained delivery. Implement each slice as a separate, reviewable branch/PR targeting the immediately preceding slice (Slice 1 targets the base branch), with tests kept with the behavior. Do not combine later-slice surfaces into an earlier branch.

## Slice 0 — Apply planning gate and branch boundary

- [x] Confirm chain strategy: `stacked-to-main` approved for chained delivery. <!-- sdd-owner: parent -->
- [ ] Start or reuse a bounded review context for Slice 1 after implementation completes, verifying the slice diff excludes later-slice surfaces. <!-- sdd-owner: parent -->

## Slice 1 — Domain schema, enums, models, factories, permissions; no menu exposure

**Edit surfaces:** `database/migrations/*course*.php`, `database/seeders/*Permission*`, `app/Enums/Courses/*`, `app/Models/Courses/*`, `database/factories/Courses/*`, `app/Policies/Courses/*`, `app/Providers/AuthServiceProvider.php` or equivalent policy registration, `config/courses.php`, `tests/Feature/Courses/CourseDomainFoundationTest.php`, `tests/Unit/Courses/*Enum*Test.php`.

- [x] RED: add failing unit/feature coverage for activity code uniqueness, modality required fields, edition state transitions, enrollment uniqueness, permission denial stubs, and migration-level model relationships. <!-- sdd-owner: implementation -->
- [x] GREEN: create migrations for `course_activities`, `course_editions`, `course_edition_teachers`, `course_sessions`, `course_participants`, `course_enrollment_groups`, `course_enrollments`, `course_attendances`, `course_grades`, `course_certificate_templates`, `course_academic_documents`, and `course_commercial_documents` using string states, indexes, soft deletes where designed, and non-destructive rollbacks. <!-- sdd-owner: implementation -->
- [x] GREEN: add `App\Enums\Courses` backed enums for activity type, edition state, modality, enrollment state, payment status, final result, academic document type/status, delivery status, and commercial document type. <!-- sdd-owner: implementation -->
- [x] GREEN: add `App\Models\Courses` Eloquent models, relations, casts, `HasAuditColumns`, `LogsActivity`, factories, and basic policies for the permission names from `openspec/changes/course-talks-management/design.md`. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: add tests for duplicate enrollment per edition/participant, group payer with multiple academic records, and permission names seeded/assignable under Spatie permission. <!-- sdd-owner: implementation -->
- [x] REFACTOR: centralize constants/config defaults in `config/courses.php` for `igv_rate=0.18`, `delivery_due_days=1`, default currency `PEN`, and avoid leaking menu/routes before later UI slices. <!-- sdd-owner: implementation -->
- [x] Run focused verification with `php artisan test --filter=CourseDomainFoundationTest` plus affected unit enum tests. <!-- sdd-owner: implementation -->
- [ ] Review Slice 1 for migration safety, rollback behavior, policy registration, and absence of user-facing route/menu changes. <!-- sdd-owner: parent -->

## Slice 2 — Activity, edition, enrollment, attendance, grades, and eligibility services

**Depends on:** Slice 1. **Edit surfaces:** `app/Services/Courses/CourseActivityService.php`, `CourseEditionService.php`, `CourseEnrollmentService.php`, `CourseAttendanceService.php`, `CourseGradeService.php`, `CourseGradeCalculator.php`, `CourseEligibilityService.php`, `app/Events/Courses/*`, `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php`, `tests/Unit/Courses/CourseGradeCalculatorTest.php`, `tests/Feature/Courses/CourseEligibilityTest.php`, `tests/Feature/Courses/CourseAttendanceAndGradesTest.php`.

- [x] RED: add grade calculator boundary tests for `12.49 -> 12 Participación`, `12.50 -> 13 Aprobado`, `12.60 -> 13 Aprobado`, equal weights, two-decimal display, and no float drift. <!-- sdd-owner: implementation -->
- [x] RED: add feature tests proving talks reject grades, course attendance is informative, talk participation gates eligibility, unpaid/invalid participants do not generate eligibility, and completed courses select approval vs participation result. <!-- sdd-owner: implementation -->
- [x] GREEN: implement activity/edition services with code uniqueness, modality validation, teacher/session management, state transition guards, and audit events under `course-*`. <!-- sdd-owner: implementation -->
- [x] GREEN: implement enrollment and attendance services for participant linking/creation, group payer records, per-participant academic records, payment status changes, attendance marking, and eligibility trigger events after commit. <!-- sdd-owner: implementation -->
- [x] GREEN: implement grade service and pure grade calculator using integer/decimal-string arithmetic or `bc*` functions; persist exact average, display average, rounded result, final result, and correction audit. <!-- sdd-owner: implementation -->
- [x] GREEN: implement eligibility service returning explicit missing conditions for payment, participant data, edition validations, academic result, and participation; implement idempotent `EvaluateCourseDocumentEligibility` job as a no-op until document generation exists. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: add tests for payment `paid`/authorized `waived` complete, `partial`/`refunded` incomplete, withdrawn/no-show ineligible, and validation completion as the final trigger. <!-- sdd-owner: implementation -->
- [x] REFACTOR: keep controllers absent/thin in this slice; ensure services own transitions and can be called from future UI/jobs without duplicated rules. <!-- sdd-owner: implementation -->
- [x] Run focused verification with `php artisan test --filter=CourseGradeCalculatorTest` and `php artisan test --filter=CourseEligibilityTest`. <!-- sdd-owner: implementation -->
- [x] Review Slice 2 for strict TDD evidence, arithmetic correctness, event-after-commit behavior, and no premature PDF/delivery/UI implementation. <!-- sdd-owner: parent -->

## Slice 3 — Certificate template, reference PDF, QR security, revocation/regeneration

**Depends on:** Slices 1–2. **Edit surfaces:** `composer.json`/`composer.lock` if a QR package is chosen, `app/Services/Courses/CourseDocumentGenerationService.php`, `app/Services/Courses/CertificateQrTokenService.php`, `app/ViewModels/Courses/CourseCertificateViewModel.php`, `resources/views/course-talks/certificates/reference.blade.php`, `routes/web.php`, `app/Http/Controllers/CourseTalks/PublicCertificateQrController.php`, `app/Services/DocumentService.php`, `tests/Feature/Courses/CourseAcademicDocumentGenerationTest.php`, `tests/Feature/Courses/CourseCertificateQrSecurityTest.php`, `tests/Unit/Courses/CourseCertificateFilenameTest.php`.

- [x] RED: add failing tests for automatic document type selection, filename pattern, PDF required reference sections including temario/signatures/QR/code, private document storage, unique token hashing, QR streams only current PDF, revoked/replaced token denied with no identity data, and regeneration requiring a reason. <!-- sdd-owner: implementation -->
- [x] GREEN: add or adapter-wrap a QR generator dependency, keeping the implementation behind `CertificateQrTokenService`/renderer interfaces so future package changes do not affect domain services. <!-- sdd-owner: implementation -->
- [x] GREEN: implement template model/service defaults and `resources/views/course-talks/certificates/reference.blade.php` to reproduce the approved reference-template structure with configurable admin fields but mandatory business data. <!-- sdd-owner: implementation -->
- [x] GREEN: implement `CourseDocumentGenerationService` to choose approval/participation/talk document type, generate code/token, render DomPDF, store private PDFs under `course-academic-documents/{enrollment_id}/{code}.pdf`, register `documents`, and create `course_academic_documents`. <!-- sdd-owner: implementation -->
- [x] GREEN: expand `app/Services/DocumentService.php` subject allow-list/assertion to support `CourseAcademicDocument` and later `CourseCommercialDocument` without weakening private-document checks. <!-- sdd-owner: implementation -->
- [x] GREEN: add public `GET /certificate/qr/{token}` route/controller that hashes the token with app key, streams only current non-revoked PDFs, rate-limits if existing route middleware supports it, and returns generic invalid/not-current responses. <!-- sdd-owner: implementation -->
- [x] GREEN: implement annul/regenerate service paths requiring permission/reason, preserving old private PDF for audit, revoking QR, marking old rows annulled/replaced, and creating a new current document with new code/token/status. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: test multiple certificates for one enrollment across regeneration, token uniqueness, raw token not persisted, and no public exposure of document number/email/phone/grades in invalid QR responses. <!-- sdd-owner: implementation -->
- [x] REFACTOR: isolate PDF/QR adapters for fakes in tests and keep generated assets out of public storage/symlinks. <!-- sdd-owner: implementation -->
- [x] Run focused verification with `php artisan test --filter=CourseAcademicDocumentGenerationTest` and `php artisan test --filter=CourseCertificateQrSecurityTest`. <!-- sdd-owner: implementation -->
- [x] Review Slice 3 for privacy, QR revocation, private storage, filename compliance, and reference-template completeness. <!-- sdd-owner: parent -->

## Slice 4 — Commercial documents, IGV, uploads, and no-tax-generation boundary

**Depends on:** Slices 1–3 for document integration. **Edit surfaces:** `app/Services/Courses/CourseCommercialDocumentService.php`, `app/Http/Requests/CourseTalks/*CommercialDocument*Request.php`, `app/Models/Courses/CourseCommercialDocument.php`, `app/Services/DocumentService.php`, `tests/Unit/Courses/CourseCommercialDocumentMoneyTest.php`, `tests/Feature/Courses/CourseCommercialDocumentRegistrationTest.php`.

- [x] RED: add tests for IGV calculation `100.00 + 20.00 = subtotal 120.00, IGV 21.60, total 141.60`, factura/boleta vs recibo policy, stored used rate, PEN default, group-or-enrollment constraint, metadata fields, attachment storage, and no SUNAT/accounting side effects. <!-- sdd-owner: implementation -->
- [x] GREEN: implement money calculation using decimal-safe half-up arithmetic from `config/courses.php` rate and persist subtotal, IGV rate/amount, total, currency, payer, series/number, issue date, observations, and `document_id`. <!-- sdd-owner: implementation -->
- [x] GREEN: implement external factura/boleta/recibo registration and upload integration via existing private `documents` storage, supporting group purchase or one enrollment while preserving per-participant academic records. <!-- sdd-owner: implementation -->
- [x] GREEN: add request validation classes for commercial document registration/upload surfaces to be used by later controllers. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: add tests for discounts/certificate charge included in taxable subtotal, invalid negative totals, missing file when status requires file, and document replacement audit. <!-- sdd-owner: implementation -->
- [x] REFACTOR: keep commercial document delivery status independent from payment completion and academic document validity. <!-- sdd-owner: implementation -->
- [x] Run focused verification with `php artisan test --filter=CourseCommercialDocument`. <!-- sdd-owner: implementation -->
- [ ] Review Slice 4 for IGV/commercial document correctness, private upload safety, and explicit v1 non-goal of tax-document generation. <!-- sdd-owner: parent -->

## Slice 5 — Email delivery, WhatsApp assisted handoff, delivery history, snapshots

**Depends on:** Slices 3–4. **Edit surfaces:** `app/Services/Courses/CourseDocumentDeliveryService.php`, `app/Jobs/Courses/SendCourseDocumentEmail.php`, `app/Events/Courses/CourseDocumentDeliveryAttempted.php`, `app/Listeners/Courses/*Delivery*`, `app/Models/Notification/OutboundDelivery.php` helpers if needed, `tests/Feature/Courses/CourseDocumentEmailDeliveryTest.php`, `tests/Feature/Courses/CourseDocumentWhatsAppDeliveryTest.php`.

- [x] RED: add tests for email success marking sent and `last_sent_at`, email failure keeping pending/failed with visible error, resend appending history, WhatsApp open creating a handoff entry but keeping pending, manual `Marcar como enviado` marking sent, and recipient override persistence. <!-- sdd-owner: implementation -->
- [x] GREEN: implement delivery service for academic and commercial documents using `outbound_deliveries` append-only rows keyed to related entity, operation idempotency keys, status snapshots, responsible user, recipient, channel, attempts, and error fields. <!-- sdd-owner: implementation -->
- [x] GREEN: implement `SendCourseDocumentEmail` job wrapping existing `App\Services\Email\EmailService` with attachments or secure links as designed; update snapshots only on recorded success. <!-- sdd-owner: implementation -->
- [x] GREEN: implement WhatsApp assisted URL builder using `wa.me`/WhatsApp Web prepared text plus secure document link, explicitly avoiding automatic `WhatsAppService` dispatch in v1. <!-- sdd-owner: implementation -->
- [x] GREEN: implement manual WhatsApp confirmation path requiring actor, recipient, timestamp, and delivery-history append before status changes to sent. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: test commercial documents and academic documents share delivery behavior, failed resend history remains intact, and WhatsApp cannot be auto-marked sent merely by opening the handoff. <!-- sdd-owner: implementation -->
- [x] REFACTOR: extract channel-neutral delivery snapshot helpers and keep raw QR tokens/secrets out of logs and outbound-delivery payloads. <!-- sdd-owner: implementation -->
- [x] Run focused verification with `php artisan test --filter=CourseDocumentEmailDeliveryTest` and `php artisan test --filter=CourseDocumentWhatsAppDeliveryTest`. <!-- sdd-owner: implementation -->
- [ ] Review Slice 5 for email/WhatsApp v1 boundary, delivery history append-only behavior, and pending-state correctness. <!-- sdd-owner: parent -->

## Slice 6 — Authenticated routes, controllers, requests, and Blade UI workflows

**Depends on:** Slices 1–5. **Edit surfaces:** `routes/web.php`, `app/Http/Controllers/CourseTalks/*Controller.php`, `app/Http/Requests/CourseTalks/*Request.php`, `resources/views/course-talks/**/*`, `resources/views/layouts/*` or existing navigation partials for menu exposure, `tests/Feature/CourseTalks/*HttpTest.php`.

### Slice 6 units

The slice-level rows above are aggregate and cannot be checked until every workflow is delivered. Track progress through these units instead.

- [x] 6.a Activities and editions HTTP surfaces: authenticated `course-talks` route group, activity index/show/create/store, edition create/store/show with teacher and session management, form requests, and Blade views. Evidence: `CourseActivityCreateHttpTest`, `CourseEditionCreateHttpTest`, `CourseEditionSessionsHttpTest`, `CourseEditionTeachersHttpTest`, `CourseTalksReadOnlyHttpTest` — 63 tests / 370 assertions passing. <!-- sdd-owner: implementation -->
- [ ] 6.b Enrollments and participants UI: per-edition enrollment list, participant linking and creation, group payer enrollment, and payment status display. <!-- sdd-owner: implementation -->
- [ ] 6.c Attendance matrix: per-session attendance marking for courses and informational attendance for talks. <!-- sdd-owner: implementation -->
- [ ] 6.d Grade matrix: course grade recording and correction, with grades blocked for talks. <!-- sdd-owner: implementation -->
- [ ] 6.e Academic document actions: generate, regenerate, annul, email, WhatsApp handoff, confirm sent, and discard. <!-- sdd-owner: implementation -->
- [ ] 6.f Commercial document actions: register, upload, send, and discard, plus certificate template settings. <!-- sdd-owner: implementation -->
- [ ] 6.g Navigation exposure for authorized users only. <!-- sdd-owner: implementation -->

- [ ] RED: add HTTP feature tests for module index filters, activity CRUD, edition CRUD/state transitions, sessions/teachers, enrollment, attendance matrix, course grade matrix, talks hiding/blocking grades, document generate/regenerate/annul/send/open WhatsApp/confirm/discard, commercial document register/upload/send/discard, and template settings permissions. <!-- sdd-owner: implementation -->
- [ ] GREEN: add authenticated `course-talks` route group and public `/certificate/qr/{token}` route with named routes, middleware, authorization calls, and no route exposure for unauthorized users. <!-- sdd-owner: implementation -->
- [ ] GREEN: implement thin controllers and form requests delegating to services for activities, editions, sessions, participants/enrollments, attendance, grades, academic documents, commercial documents, deliveries, and templates. <!-- sdd-owner: implementation -->
- [ ] GREEN: implement server-rendered Blade views under `resources/views/course-talks` using existing table/badge/alert patterns for lists, forms, details, matrices, delivery modals/actions, and template configuration. <!-- sdd-owner: implementation -->
- [ ] GREEN: add menu/navigation entry for authorized users only after policies and permissions are effective; keep unauthorized users unable to discover or access restricted workflows. <!-- sdd-owner: implementation -->
- [ ] TRIANGULATE: add tests for validation errors, duplicate activity codes, hybrid address+URL requirements, grade permission denial, revocation permission denial, commercial-document permission denial, and template-management permission denial. <!-- sdd-owner: implementation -->
- [ ] REFACTOR: split oversized views into partials such as `resources/views/course-talks/editions/_form.blade.php`, `_sessions.blade.php`, `_participants.blade.php`, `_delivery-history.blade.php`, and keep each controller action thin. <!-- sdd-owner: implementation -->
- [ ] Run focused verification with `php artisan test --filter=CourseTalks` and any affected controller tests. <!-- sdd-owner: implementation -->
- [ ] Review Slice 6 for UI completeness, authorization coverage, route naming, and adherence to existing Laravel/AdminLTE/Bootstrap patterns. <!-- sdd-owner: parent -->

## Slice 7 — Pending dashboards, alerts, audit reports, rollout controls, full verification

**Depends on:** Slices 1–6. **Edit surfaces:** `app/Services/Courses/CourseAlertService.php`, `app/Jobs/Courses/RefreshCourseDeliveryAlerts.php` if materialized, dashboard controllers/views such as `resources/views/dashboard*.blade.php` or existing admin dashboard paths, `config/courses.php`, `database/seeders/*`, `docs/` only if existing rollout docs require update, `tests/Feature/Courses/CourseDeliveryAlertsTest.php`, `tests/Feature/Courses/CourseAuditTest.php`, `tests/Feature/Courses/CourseRolloutTest.php`.

- [ ] RED: add tests for pending and overdue counts after one calendar day, configurable due days, module-dashboard filters by activity type/edition/participant/responsible/document type/status/channel/date, alert discard with reason, and close only by send/discard. <!-- sdd-owner: implementation -->
- [ ] RED: add audit regression tests for activity code changes, edition state changes, enrollment changes, attendance changes, grade corrections, result recalculations, payment changes, document generation/annul/regeneration, template changes, commercial document changes, delivery attempts, WhatsApp confirmations, and alert discard. <!-- sdd-owner: implementation -->
- [ ] GREEN: implement `CourseAlertService` computed pending/overdue queries or materialized alerts only if required, using default `delivery_due_days=1` from `config/courses.php` and preserving document validity when delivery follow-up is discarded. <!-- sdd-owner: implementation -->
- [ ] GREEN: wire main dashboard and module dashboard counters/lists with filters and links to pending certificates/commercial documents without exposing private data to unauthorized roles. <!-- sdd-owner: implementation -->
- [ ] GREEN: ensure all material changes emit Spatie activity log entries with `course-*` event names, old/new values where required, responsible actor, and no raw QR tokens. <!-- sdd-owner: implementation -->
- [ ] GREEN: add rollout seeding/assignment path for permissions and menu enablement, leaving safe rollback guidance in code comments/config only where existing project conventions support it. <!-- sdd-owner: implementation -->
- [ ] TRIANGULATE: test rollback controls: hiding route/menu by permissions, stopping eligibility jobs through queue/config if introduced, preserving rows/files, and revoking QR links only through explicit document annul/replacement paths. <!-- sdd-owner: implementation -->
- [ ] REFACTOR: consolidate dashboard query scopes on models/services and remove duplication between main dashboard and module dashboard. <!-- sdd-owner: implementation -->
- [ ] Run full verification with `php artisan test`, then rerun focused failing tests if any. <!-- sdd-owner: implementation -->
- [ ] Review Slice 7 for alerts, audit completeness, rollout safety, and full-test evidence before archive/next SDD gate. <!-- sdd-owner: parent -->

## Cross-slice guardrails

- [ ] Keep historical Excel import, SUNAT/accounting-provider integration, payment gateway, automatic WhatsApp API document delivery, public enrollment/student portal, weighted formulas, videoconference/content platform, and third-party digital signature integration out of v1. <!-- sdd-owner: implementation -->
- [ ] Keep tests with the slice that implements the behavior; do not create a separate tests-only PR unless it is a deliberate RED-only review unit approved by the parent. <!-- sdd-owner: implementation -->
- [ ] Preserve private storage for generated/uploaded documents and never expose `storage:link` public paths for academic or commercial documents. <!-- sdd-owner: implementation -->
- [ ] Track approximate changed lines after each slice with `git diff --stat` if the repository is initialized; pause when a slice approaches the selected review-budget policy. <!-- sdd-owner: implementation -->
