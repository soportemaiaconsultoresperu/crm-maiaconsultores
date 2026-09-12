# Technical design: Cursos y charlas

## Executive decision

Implement a new bounded Laravel domain for **Cursos y charlas** under `App\Models\Courses`, `App\Services\Courses`, `App\Http\Controllers\CourseTalks`, and `resources/views/course-talks`. Reuse the repository's existing private `documents` storage, Spatie permissions, Spatie activity log, queued email pipeline, notification/outbound delivery ledger, and manual WhatsApp integration boundaries. Do not extend the existing CRM `activities` table for this feature; in this repository `Activity` already means CRM follow-up/task and would overload business language.

## Repository fit and integration points

| Existing evidence | Design decision |
|---|---|
| `documents` stores private file metadata polymorphically; `DocumentService` enforces private downloads. | Generated certificates and uploaded commercial documents attach to new course domain models through `documents`; `DocumentService::assertSubject()` must be expanded during implementation to allow these subjects. |
| `outbound_deliveries` is an append-only channel/status ledger keyed by related entity. | Use it for delivery attempts and retain a small domain-level `latest_sent_at`/status snapshot on certificate and commercial document rows. |
| `EmailService` persists outbound messages and queues `SendEmailMessage`. | `CourseDocumentDeliveryService` will call it for certificate/commercial-document emails; email success/failure listeners update delivery snapshots. |
| `WhatsAppService` currently sends approved templates, but scope requires v1 manual assisted WhatsApp. | Do not call automatic WhatsApp dispatch for v1. Generate a `wa.me`/WhatsApp Web URL with prepared text and secure document link, then require manual confirmation. |
| Spatie permission tables and `Gate::before` bridge exist in `AuthServiceProvider`. | Add granular permissions and policies; non-admin users need explicit abilities. |
| `activity_log` and models use `LogsActivity`/manual `activity()` calls. | Audit material state changes through Spatie activity log with event names under `course-*`. |
| `barryvdh/laravel-dompdf` exists; no QR package is present. | Use DomPDF for PDF generation. Add a QR generation dependency or generate QR server-side through a small adapter; dependency choice belongs to implementation tasks. |

## Domain model

### Tables and key columns

Use string columns for domain states instead of DB enums where possible, matching the existing newer `outbound_deliveries` convention and making transitions easier to evolve.

1. `course_activities`
   - `id`, `type` (`course`, `talk`), `code` unique, `name`, `slug`, `official_academic_hours decimal(8,2)`, `base_syllabus_json`, `reference_price decimal(14,2) nullable`, `talk_includes_certificate bool`, `talk_certificate_price decimal(14,2) default 0`, `is_active`, audit columns, timestamps, soft deletes.
   - Indexes: unique `code`; `type,is_active`; `name`.

2. `course_editions`
   - `id`, `course_activity_id`, `code` nullable unique per edition, `state`, `modality`, `starts_on`, `ends_on`, `address`, `access_url`, `price_amount decimal(14,2)`, `currency char(3) default PEN`, `syllabus_override_json nullable`, `responsible_user_id`, `delivery_due_days default 1`, `validations_completed_at nullable`, audit columns, timestamps, soft deletes.
   - Indexes: `activity,state`, `responsible_user_id,state`, `starts_on,ends_on`.

3. `course_edition_teachers`
   - `course_edition_id`, `user_id nullable`, `display_name`, `email nullable`, `sort_order`.
   - Allows internal teachers or external display-only teachers.

4. `course_sessions`
   - `id`, `course_edition_id`, `session_date`, `starts_at`, `ends_at`, `teacher_name`, `topic`, `sort_order`, audit columns, timestamps, soft deletes.
   - Unique-ish validation in service: no duplicate `edition + sort_order`; date/time conflicts warn, not block.

5. `course_participants`
   - `id`, optional `customer_id`, optional `contact_id`, `first_name`, `last_name`, `document_type`, `document_number`, `email`, `mobile`, normalized email/mobile/doc fields, audit columns, timestamps, soft deletes.
   - Unique recommended on `document_type, document_number_norm` when present; allow nullable docs for drafts only if enrollment validation blocks document generation.

6. `course_enrollment_groups`
   - `id`, `course_edition_id`, `payer_customer_id nullable`, `payer_name`, `payer_document_type`, `payer_document_number`, `notes`, audit columns, timestamps.
   - Represents company/group purchase while academic records remain per participant.

7. `course_enrollments`
   - `id`, `course_edition_id`, `course_participant_id`, `course_enrollment_group_id nullable`, `state`, `payment_status`, `activity_price_amount decimal(14,2)`, `certificate_charge_amount decimal(14,2)`, `discount_amount decimal(14,2) default 0`, `subtotal_amount decimal(14,2)`, `currency`, `participation_confirmed_at nullable`, `exact_average decimal(8,4) nullable`, `display_average decimal(8,2) nullable`, `rounded_result unsignedTinyInteger nullable`, `final_result` (`approved`, `participation`, `not_applicable`, `pending`), `result_calculated_at`, audit columns, timestamps, soft deletes.
   - Unique: `course_edition_id, course_participant_id`.

8. `course_attendances`
   - `id`, `course_session_id`, `course_enrollment_id`, `status` (`present`, `absent`, `late`, `excused`, `unmarked`), `marked_by`, `marked_at`, timestamps.
   - Unique: `course_session_id, course_enrollment_id`.

9. `course_grades`
   - `id`, `course_session_id`, `course_enrollment_id`, `description`, `grade decimal(4,2)`, `entered_by`, `entered_at`, audit columns, timestamps, soft deletes.
   - Unique: `course_session_id, course_enrollment_id` for v1 principal grade. DB check or request validation: `grade >= 0 and <= 20`.

10. `course_certificate_templates`
    - `id`, `name`, `type_scope nullable`, `version`, `is_active`, `blade_view` or `html_template`, `settings_json` for reference-design assets, signatures, colors, layout toggles, required sections, audit columns, timestamps.
    - Keep business-required variables non-removable in validation: participant, activity, modality, dates, hours, signatures, code, QR, temario.

11. `course_academic_documents`
    - `id`, `course_enrollment_id`, `course_certificate_template_id`, `document_id` FK to `documents`, `type` (`approval_certificate`, `participation_constancy`, `talk_certificate`), `status` (`pending_generation`, `current`, `annulled`, `replaced`, `failed`), `code` unique, `issue_date`, `filename`, `qr_token_hash`, `qr_token_revoked_at`, `qr_token_last_used_at`, `replaced_by_id nullable`, `annulled_at`, `annulled_by`, `annul_reason`, `last_sent_at`, `delivery_status` (`pending`, `sent`, `failed`, `discarded`), `delivery_discard_reason`, timestamps.
    - Store only token hash; never store the raw QR token after PDF generation except in the PDF URL.

12. `course_commercial_documents`
    - `id`, `course_enrollment_group_id nullable`, `course_enrollment_id nullable`, `document_id` FK to `documents`, `type` (`factura`, `boleta`, `recibo`), `series`, `number`, `issue_date`, `currency default PEN`, `subtotal_amount decimal(14,2)`, `igv_rate decimal(5,4) default 0.1800`, `igv_amount decimal(14,2)`, `total_amount decimal(14,2)`, payer fields, `observations`, `status` (`pending_file`, `registered`, `sent`, `discarded`), `last_sent_at`, audit columns, timestamps, soft deletes.
    - Constraint: either group or enrollment must be present.

13. Optional `course_delivery_alerts`
    - Prefer computed pending/overdue queries from academic/commercial documents. Add this table only if product needs explicit alert close/discard independent from document status. If added: `alertable_type/id`, `state`, `due_at`, `closed_at`, `closed_by`, `close_reason`.

### Eloquent relations

- `CourseActivity hasMany CourseEdition`.
- `CourseEdition belongsTo CourseActivity`, `hasMany CourseSession`, `hasMany CourseEnrollment`, `belongsTo responsible User`, `belongsToMany/hasMany teachers`.
- `CourseParticipant belongsTo Customer?`, `belongsTo Contact?`, `hasMany CourseEnrollment`.
- `CourseEnrollment belongsTo CourseEdition`, `belongsTo CourseParticipant`, `belongsTo CourseEnrollmentGroup`, `hasMany CourseAttendance`, `hasMany CourseGrade`, `hasMany CourseAcademicDocument`.
- `CourseAcademicDocument belongsTo CourseEnrollment`, `belongsTo Document`, `belongsTo CourseCertificateTemplate`, `hasMany OutboundDelivery` through polymorphic `related_entity_type/id` query helper.
- `CourseCommercialDocument belongsTo Document`, belongs to group/enrollment, and uses polymorphic outbound delivery history.

All domain models with business state should use `HasAuditColumns`, `LogsActivity`, and `SoftDeletes` where records are operational history.

## Enums and state transitions

Use PHP backed enums in `App\Enums\Courses` and validate transitions in services, not controllers.

| Enum | Values | Notes |
|---|---|---|
| `CourseActivityType` | `course`, `talk` | Drives grading/certificate rules. |
| `CourseEditionState` | `draft`, `scheduled`, `in_progress`, `finished`, `cancelled` | Allowed: draft→scheduled→in_progress→finished; draft/scheduled/in_progress→cancelled. Reopen only by explicit admin service if required later. |
| `CourseModality` | `presential`, `virtual`, `hybrid` | Presential requires address; virtual requires URL; hybrid requires both. |
| `CourseEnrollmentState` | `enrolled`, `confirmed`, `in_progress`, `completed`, `withdrawn`, `no_show` | Withdrawn/no_show are terminal for eligibility. |
| `PaymentStatus` | `pending`, `partial`, `paid`, `waived`, `refunded` | `paid` and authorized `waived` count as complete; partial/refunded do not. |
| `FinalResult` | `pending`, `approved`, `participation`, `not_applicable` | Talks are `not_applicable` academically but eligible by participation. |
| `AcademicDocumentType` | `approval_certificate`, `participation_constancy`, `talk_certificate` | Selected automatically. |
| `AcademicDocumentStatus` | `pending_generation`, `current`, `annulled`, `replaced`, `failed` | Annul/replaced revokes QR. |
| `DeliveryStatus` | `pending`, `sent`, `failed`, `discarded` | Snapshot only; append-only history is `outbound_deliveries` + audit. |
| `CommercialDocumentType` | `factura`, `boleta`, `recibo` | IGV applies to factura/boleta when requested; recibo follows configured tax policy. |

## Services, events, jobs

### Core services

- `CourseActivityService`: create/update activities, enforce code uniqueness and activity type rules.
- `CourseEditionService`: manage edition data, sessions, teachers, state transitions, modality validations, edition completion validations.
- `CourseEnrollmentService`: enroll participants, create/link minimal participants, handle group payer records, compute charges.
- `CourseAttendanceService`: mark attendance and emit eligibility evaluation triggers for talks.
- `CourseGradeService`: enter/correct grades, reject grades for talks, audit old/new values, call grade calculator.
- `CourseGradeCalculator`: pure deterministic class for exact arithmetic and result assignment.
- `CourseEligibilityService`: central predicate returning missing conditions (`payment`, `participant_data`, `edition_validations`, `academic_result`/`participation`).
- `CourseDocumentGenerationService`: selects template and document type, renders PDF, stores private file, creates `Document` and `CourseAcademicDocument`, generates QR token.
- `CourseDocumentDeliveryService`: email send, WhatsApp handoff URL, manual WhatsApp confirmation, delivery status snapshots, delivery history.
- `CourseCommercialDocumentService`: calculate IGV, register external factura/boleta/recibo, attach file, delivery status.
- `CourseAlertService`: pending/overdue dashboard queries using configurable `courses.delivery_due_days` default 1.

### Events and jobs

Emit small domain events after transaction commit:

- `CourseEnrollmentChanged`
- `CourseAttendanceMarked`
- `CourseGradeRecorded`
- `CoursePaymentStatusChanged`
- `CourseEditionValidationCompleted`
- `CourseAcademicDocumentGenerated`
- `CourseAcademicDocumentRevoked`
- `CourseDocumentDeliveryAttempted`

Queue jobs:

- `EvaluateCourseDocumentEligibility`: idempotent; receives enrollment id; locks enrollment row; no-op if current document already exists for the same eligibility version.
- `GenerateCourseAcademicDocument`: renders/stores PDF; unique by enrollment/document type/current generation key.
- `SendCourseDocumentEmail`: wraps `EmailService`, attaches or links private document through a controlled temporary/read route, updates `outbound_deliveries` and document snapshot.
- `RefreshCourseDeliveryAlerts`: optional scheduled job if alert rows are materialized; otherwise dashboards compute on read.

Trigger eligibility from the last condition changing: payment status, grade result, attendance/participation, required-data correction, edition validation completion, and manual regeneration.

## Exact grade arithmetic

Use decimal strings and integer cents-like scaling; do not use floats.

- Store each grade as `decimal(4,2)` and cast/access as string or use a Decimal value object.
- Convert grade to integer hundredths: `12.50 -> 1250`.
- Exact average basis points: sum hundredths / count. Preserve as `decimal(8,4)` using arbitrary precision (`bcdiv`) or integer division plus remainder; configure `ext-bcmath` if not already enabled.
- Display average: half-up to two decimals for display only.
- Decision result: half-up to integer from the exact average: `floor(avg + 0.5)`, implemented in integer space as `intdiv(sumHundredths * 2 + count * 100, count * 200)` or via `bcadd($avg, '0.5')` then floor. Test boundary examples explicitly.
- Approved when rounded integer `>= 13`; otherwise `participation` for completed course participants.

Recommended pure API:

```php
GradeResult CourseGradeCalculator::calculate(Collection $gradeDecimalStrings)
// returns exactAverage: string(4 dp), displayAverage: string(2 dp), roundedResult: int, finalResult: FinalResult
```

## PDF template, temario, QR, and revocation

- Render with DomPDF from a versioned Blade template (`resources/views/course-talks/certificates/reference.blade.php`) populated by `CourseCertificateViewModel`.
- Admin configuration controls assets/signatures/text blocks/colors but cannot remove required business fields or the `temario` section.
- Store generated PDFs on the private `docs` disk under `course-academic-documents/{enrollment_id}/{code}.pdf` and register metadata in `documents`.
- PDF filename follows `Certificado_{participant}_{activity}_{date-range}_{company}.pdf`; sanitize only forbidden filesystem characters while preserving human-readable spaces.
- QR URL: public route like `GET /certificate/qr/{token}`. The token is random 32+ bytes URL-safe; database stores `hash_hmac('sha256', token, app_key)`.
- QR route finds current non-revoked document by token hash, streams only the PDF; no public personal-data page. For revoked/missing tokens, return a minimal invalid/not-current message without identity data.
- Annul/regenerate requires permission and reason, marks previous row `annulled` or `replaced`, sets `qr_token_revoked_at`, creates a new document/code/token, and preserves old private PDF for audit while blocking QR access.

## Payment and commercial documents

- Enrollment charges are stored without IGV: `activity_price + certificate_charge - discount = subtotal`.
- When factura/boleta is requested: `igv_amount = subtotal * 0.18` rounded half-up to 2 decimals; `total = subtotal + igv` in PEN by default.
- Make `courses.igv_rate` configurable in settings with default `0.18`; persist the rate used on each document for audit.
- `CourseCommercialDocumentService` stores externally issued metadata and uses `DocumentService` for upload. It must not create SUNAT/accounting-provider side effects.
- A commercial document can attach to a group purchase or one enrollment. Delivery status is independent from payment completion.

## Delivery history, email, WhatsApp, alerts

- Email: create an outbound delivery row with channel `mail`, related entity `CourseAcademicDocument` or `CourseCommercialDocument`, idempotency key based on document id + recipient + operation UUID. Call `EmailService`; when send succeeds mark snapshot `sent` and `last_sent_at`. On failure keep pending/failed and expose error.
- WhatsApp assisted: build prepared message with secure QR/document link and recipient mobile. Record a `whatsapp_handoff_opened` audit/delivery entry but keep `delivery_status=pending`. Only `confirmWhatsAppSent()` marks sent and appends history with actor/time/recipient.
- Re-sends append history and update `last_sent_at`; never overwrite old delivery rows.
- Pending dashboards query documents where `delivery_status in (pending, failed)` and not discarded. Overdue when `now() > generated_or_registered_at + configured days` (default one calendar day).
- Discarding an alert/document delivery requires permission and reason; it changes only delivery follow-up, not document validity.

## Policies and permissions

Register policies for main models and seed these permission names:

- `course-talks.view`
- `course-talks.activities.manage`
- `course-talks.editions.manage`
- `course-talks.sessions.manage`
- `course-talks.attendance.manage`
- `course-talks.grades.manage`
- `course-talks.participants.manage`
- `course-talks.documents.generate`
- `course-talks.documents.revoke`
- `course-talks.commercial-documents.manage`
- `course-talks.documents.send`
- `course-talks.templates.manage`
- `course-talks.audit.view`

Policies combine permissions with ownership/scope: responsible users can view their editions and pending lists; sensitive actions require explicit permissions. Controllers must call `$this->authorize()` or `Gate::authorize()` before service calls.

## Routes and UI boundaries

Add authenticated/active routes in `routes/web.php` under `course-talks`:

- Activities: index/create/store/show/edit/update.
- Editions: create/store/show/edit/update, state transition actions, sessions, teachers.
- Participants/enrollments: enroll/update state/group/payment.
- Attendance: per-session matrix form.
- Grades: per-course grade matrix; hidden/blocked for talks.
- Documents: generate/regenerate/annul/send email/open WhatsApp/confirm WhatsApp/discard pending.
- Commercial documents: register/upload/send/discard.
- Templates: admin-only template settings/versioning.
- Public QR route outside auth: `/certificate/qr/{token}` only.

Blade UI should follow existing server-rendered patterns with reusable components (`components/table`, `badge-status`, alerts). Main dashboard gets counts and links; module dashboard gets filters by activity type, edition, participant, responsible, document type, status, channel, and date.

## Testing strategy

- Unit: enum transition guards, `CourseGradeCalculator` boundary cases (`12.49`, `12.50`, `12.60`), IGV half-up money math, filename/date-range formatting, token hash/revocation lookup.
- Feature: permission denials for grades/revocation/commercial docs/send/template management; duplicate activity code; modality required fields; no grades for talks; automatic generation after final condition; no generation before payment/data/edition validation.
- Integration with fakes: `Storage::fake('docs')`, `Queue::fake()`, mail/email service fakes, PDF renderer adapter fake, QR adapter fake.
- Delivery: email success updates sent snapshot and history; email failure remains pending; WhatsApp open remains pending; manual confirmation marks sent; resend appends history.
- Security/privacy: QR route streams only current PDF, denies revoked/replaced token, does not require auth, does not expose ID/email/phone/grade in invalid responses.
- Regression: audit entries for grade correction, document regeneration, template change, manual WhatsApp confirmation, alert discard.

## Rollout plan

1. Land schema/enums/models/factories/permissions behind no menu exposure.
2. Add services and pure tests for grading, eligibility, money, QR tokens.
3. Add PDF/template generation and private document integration.
4. Add UI routes/views for activities/editions/enrollments/sessions/grades.
5. Add delivery workflows and pending dashboards.
6. Enable menu for authorized roles only after seed permissions are assigned.
7. Keep rollback non-destructive: hide routes/menu, stop eligibility jobs, revoke QR links only if required by business decision, preserve generated/uploaded files and audit rows.

## Data, privacy, and security considerations

- Participant document numbers, email, phone, grades, and PDFs are personal data; expose only authenticated UI except QR PDF link.
- QR tokens must be high entropy, hashed at rest, revocable, and rate-limited. Invalid responses must be generic.
- Generated PDFs remain private files; no `storage:link` public paths for academic or commercial documents.
- Audit logs may contain old/new grades and delivery recipients; respect existing activity-log retention policy and avoid logging raw QR tokens.
- Historical Excel import remains out of v1; future imports need source-quality review, consent/data-minimization mapping, duplicate strategy, and dry-run reports.

## Assumptions and open integration points

- The participant is modeled as a new domain record linked optionally to `customers`/`contacts` because current contacts lack document fields and customers can be companies.
- Payment completion source is a v1 domain field unless the business later integrates with customer invoices/payments.
- Commercial factura/boleta upload is separate from existing `customer_invoices`, which appears to model receivables by customer and lacks series/type/file/IGV fields. A later bridge may link both if accounting workflow requires it.
- A QR generation package/adapter is needed; keep it behind an interface so implementation can choose package without changing domain services.
- Exact decimal arithmetic should require/verify `ext-bcmath` or a small integer-money/grade value object to avoid floats.
