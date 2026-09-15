# course-talks-management Specification

> **Canonical spec.** Archived from the `course-talks-management` change on 2026-09-12.
> Verification: `openspec/changes/archive/2026-09-12-course-talks-management/verify-report.md` — 20 PASS, 2 PARTIAL, 0 FAIL across 22 requirements; 33 of 34 scenarios satisfied.
> Declared limits at archive time: `known-limitations.md` (14 items) and `suite-baseline.md` (11 pre-existing suite failures owned by OTHER in-flight changes).

## Purpose

The system MUST provide a unified **Cursos y charlas** module to manage reusable activities, scheduled editions, classes, participants, course grades, participation, certificates, externally issued receipts/invoices, delivery follow-up, revocation/regeneration, permissions, and auditability for the first operational version.

## Requirements

### Requirement: Unified activities module

The system MUST manage courses and talks in one module, with a visible `Tipo` value of `Curso` or `Charla`, shared filters, and shared operational tracking.

#### Scenario: Filter activities by type

- GIVEN activities of type `Curso` and `Charla` exist
- WHEN an authorized user opens the **Cursos y charlas** module
- THEN the system MUST show a unified list
- AND the user MUST be able to filter by `Curso`, `Charla`, or all activities.

### Requirement: Activity, edition, and class model

The system MUST distinguish reusable **activities**, concrete **editions**, and individual **classes/sessions**.

An activity MUST contain at least name, unique manual code, type, base syllabus, official academic hours, and reference data. An edition MUST contain dates, modality, price, stable edition-scoped teachers, participants, state, delivery settings, and edition-specific syllabus changes. A class/session MUST contain date, start time, end time, topic, attendance records, and teacher information through a nullable edition-teacher reference with legacy teacher-name fallback.

(Previously: editions stored teachers and classes contained a teacher, but the spec did not require stable edition teacher identity, same-edition teacher references, or legacy fallback behavior.)

#### Scenario: Create an edition from an activity

- GIVEN an authorized user has created an activity with a unique code and base syllabus
- WHEN the user creates an edition for that activity
- THEN the edition MUST inherit or reference the activity identity
- AND the edition MUST store its own dates, modality, price, stable edition-scoped teachers, participants, classes, and state.

#### Scenario: Reject duplicate activity code

- GIVEN an activity already uses code `CUR-SAN-001`
- WHEN a user attempts to save another activity with code `CUR-SAN-001`
- THEN the system MUST reject the duplicate code
- AND the original activity MUST remain unchanged.

### Requirement: Edition states and modality

The system MUST support edition states `Borrador`, `Programada`, `En curso`, `Finalizada`, and `Cancelada`. The system MUST support modalities `Presencial`, `Virtual`, and `Híbrida`, including address and/or access link according to modality.

#### Scenario: Configure hybrid edition

- GIVEN an authorized user creates an edition
- WHEN the user selects modality `Híbrida`
- THEN the system MUST allow storing both physical address and virtual access link.

### Requirement: Participants and enrollment

The system MUST enroll participants from existing contact/customer records or allow creation of a minimum participant record containing names, surnames, document type, document number, email, and mobile number with country code.

The system MUST support individual participant academic records even when a company pays for a group enrollment.

#### Scenario: Enroll participant with minimum data

- GIVEN an authorized user enrolls a new participant who is not yet in the CRM
- WHEN the user provides names, surnames, document type and number, email, and mobile number with country code
- THEN the system MUST create or link the participant record
- AND the system MUST create an enrollment for the selected edition.

#### Scenario: Company pays for multiple participants

- GIVEN a company pays one group purchase for multiple participants
- WHEN participants are enrolled in the edition
- THEN each participant MUST keep a separate academic result and certificate state
- AND the commercial document MAY cover multiple participants.

### Requirement: Enrollment and attendance states

The system MUST support enrollment states `Inscrito`, `Confirmado`, `En curso`, `Completó`, `Retirado`, and `No asistió`. The system MUST record attendance per class. In v1, attendance MUST be informative for courses and MUST determine valid participation for talks.

#### Scenario: Confirm talk participation

- GIVEN a participant is enrolled in a talk edition
- WHEN attendance or participation is marked as confirmed
- THEN the participant MUST be eligible for a talk certificate if the talk includes certificates and all other validations pass.

### Requirement: Courses versus talks

The system MUST apply grades only to courses. Talks MUST NOT require or calculate grades in v1. A talk marked `Con certificado` MUST generate talk certificates for participants with confirmed participation after payment and data validations pass.

#### Scenario: Talk has no grades

- GIVEN an edition belongs to an activity of type `Charla`
- WHEN a user reviews participant results
- THEN the system MUST show participation status
- AND MUST NOT require grades or course averages.

### Requirement: Course grades and averaging

For course editions, the system MUST allow one principal grade per class per participant, with description, on a 0 to 20 scale, allowing up to two decimals. In v1, all grades MUST have equal weight.

The system MUST preserve the exact average and MUST display the average with two decimals. The result decision MUST use conventional half-up integer rounding: decimal values ending in `.50` or greater round up to the next integer.

A rounded result of 13 or higher MUST be `Aprobado`. A rounded result of 12 or lower MUST be `Participación`.

#### Scenario: 12.49 is participation

- GIVEN a course participant has class grades whose exact average is `12.49`
- WHEN the system calculates the final result
- THEN the system MUST preserve exact average `12.49`
- AND MUST round it to integer `12`
- AND MUST assign result `Participación`.

#### Scenario: 12.50 is approval

- GIVEN a course participant has class grades whose exact average is `12.50`
- WHEN the system calculates the final result
- THEN the system MUST preserve exact average `12.50`
- AND MUST round it to integer `13`
- AND MUST assign result `Aprobado`.

#### Scenario: Equal-weight average

- GIVEN a participant has valid grades for multiple course classes
- WHEN the final average is calculated
- THEN each class grade MUST contribute with equal weight in v1.

### Requirement: Payment completion before documents

The system MUST require payment completion before automatic certificate or constancy generation. Payment completion MUST be tracked independently from academic or participation status.

#### Scenario: Passing participant has unpaid balance

- GIVEN a course participant has rounded result `13`
- AND the participant payment is not complete
- WHEN automatic document generation is evaluated
- THEN the system MUST NOT generate the approval certificate
- AND the participant MUST remain pending for payment or validation.

### Requirement: Document types

The system MUST generate the correct academic document type:

- `Certificado de aprobación` for approved course participants.
- `Constancia de participación` for course participants who do not approve but participated.
- `Certificado de charla` for confirmed participants in talks marked `Con certificado`.

#### Scenario: Generate participation constancy for course non-approval

- GIVEN a course participant has payment complete, required data complete, edition validations complete, and rounded result `12`
- WHEN document generation runs
- THEN the system MUST generate `Constancia de participación`
- AND MUST NOT generate `Certificado de aprobación`.

#### Scenario: Generate talk certificate

- GIVEN a talk is marked `Con certificado`
- AND a participant has confirmed participation, complete payment, complete data, and valid edition checks
- WHEN document generation runs
- THEN the system MUST generate `Certificado de charla`.

### Requirement: Automatic certificate generation

The system MUST automatically generate the applicable academic document when all conditions are complete: payment complete, participant required data complete, valid edition data, and approved academic result or valid participation. The system MUST select the document type without requiring the user to manually choose the type.

#### Scenario: Conditions complete trigger generation

- GIVEN a participant has all required data
- AND payment is complete
- AND the edition validations pass
- AND the participant has an eligible result or participation
- WHEN the final missing condition becomes complete
- THEN the system MUST generate the corresponding PDF document automatically.

### Requirement: Configurable PDF template matching reference design

The system MUST generate certificate PDFs from an administrator-configurable template that reproduces the approved official reference design. The PDF MUST include the main certificate content, participant data, course or talk name, modality, delivery dates, academic hours, signatures, certificate code, unique QR, and syllabus/temario section.

The template configuration MUST allow authorized administrators to adjust approved visual/text fields without changing the required business data.

#### Scenario: PDF contains required reference sections

- GIVEN a participant document is generated
- WHEN the PDF is opened
- THEN it MUST follow the configured official reference design
- AND include participant name, activity name, modality, dates, academic hours, signatures, certificate code, QR, and temario section.

### Requirement: Certificate filename pattern

Generated academic PDFs MUST use this filename pattern:

`Certificado_{participante}_{curso}_{rango-fechas}_{empresa}.pdf`

The `{curso}` segment MUST contain the course or talk name. The filename MUST preserve the participant, activity, date range, and company identity in a human-readable form.

Sample filename:

`Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf`

#### Scenario: Generate expected filename

- GIVEN participant `Alvaro Segundo Alama Silva`
- AND activity `Curso Avanzado de Saneamiento Ambiental`
- AND date range `01-04.07.26`
- AND company `Maia Consultores`
- WHEN the certificate PDF is generated
- THEN the filename MUST be `Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf`.

### Requirement: Unique secure QR access

Each generated academic document MUST include a unique QR code. The QR MUST open only a secure, revocable link to the generated PDF. The QR landing behavior MUST NOT expose a public page with participant document number, email, phone, grades, or other additional personal data.

The QR link MUST remain valid while the certificate is current and MUST be revoked when the document is annulled or replaced.

#### Scenario: QR opens only secure PDF

- GIVEN a generated certificate is vigente
- WHEN a third party scans the QR code
- THEN the system MUST open or download only the secure PDF for that certificate
- AND MUST NOT display additional public personal-data fields outside the PDF.

#### Scenario: Revoked QR stops access

- GIVEN a certificate has been annulled or replaced
- WHEN its old QR link is opened
- THEN the system MUST deny access to the old PDF through that QR link
- AND indicate that the document is no longer vigente without exposing private data.

### Requirement: Revocation and regeneration

Authorized users MUST be able to annul and regenerate academic documents. Annulment MUST require a reason. Regeneration MUST preserve the previous document as annulled and create a new current document with its own code, PDF, QR, and delivery status.

#### Scenario: Regenerate corrected certificate

- GIVEN a generated certificate contains data that must be corrected
- WHEN an authorized user regenerates it with a correction reason
- THEN the previous certificate MUST be marked annulled
- AND its QR MUST be revoked
- AND a new current certificate MUST be generated with a new QR and traceable reason.

### Requirement: Receipts, invoices, and 18% IGV

The system MUST allow registration and upload of externally issued `Factura`, `Boleta`, or receipt/commercial-document files. The system MUST NOT generate tax documents in v1.

Prices MUST be registered without IGV. When a factura or boleta is requested, the system MUST calculate and show subtotal, IGV at 18%, and total in PEN. IGV MUST apply to the charged total: activity price plus certificate cost when a certificate charge applies.

#### Scenario: Calculate IGV for invoice

- GIVEN an enrollment charge has activity price `100.00` PEN and certificate charge `20.00` PEN
- WHEN a factura or boleta is requested
- THEN the subtotal MUST be `120.00` PEN
- AND IGV 18% MUST be `21.60` PEN
- AND total MUST be `141.60` PEN.

#### Scenario: Upload external invoice

- GIVEN an externally issued invoice exists
- WHEN an authorized user registers it
- THEN the system MUST store type, series, number, issue date, subtotal, IGV, total, currency, payer, payer document, attachment, and observations.

### Requirement: Email delivery

The system MUST deliver certificates and registered commercial documents by email as attachments or secure links according to document type. Email delivery MUST record success or error automatically and update pending status only when successful.

#### Scenario: Email success completes delivery

- GIVEN a certificate is pending delivery
- WHEN an authorized user sends it by email and the system records success
- THEN the certificate delivery state MUST become sent for the email channel
- AND the delivery history MUST record date/time, channel, recipient, responsible user, and result.

#### Scenario: Email error keeps pending

- GIVEN a receipt/invoice is pending delivery
- WHEN email delivery fails
- THEN the document MUST remain pending
- AND the error MUST be visible in delivery history.

### Requirement: Assisted WhatsApp delivery

The system MUST support assisted WhatsApp delivery in v1. The system MUST prepare a message and open the WhatsApp chat with a secure document link or attachable file when the channel permits. WhatsApp delivery MUST remain pending until the user explicitly selects `Marcar como enviado`.

#### Scenario: WhatsApp handoff remains pending until confirmation

- GIVEN a certificate is pending delivery
- WHEN the user opens assisted WhatsApp with the prepared message
- THEN the system MUST keep the certificate pending
- UNTIL the user confirms `Marcar como enviado`.

#### Scenario: User confirms WhatsApp sent

- GIVEN assisted WhatsApp was opened for a document
- WHEN the responsible user selects `Marcar como enviado`
- THEN the system MUST mark the WhatsApp delivery as sent
- AND record the confirmation in delivery history.

### Requirement: Delivery history and last sent date

The system MUST maintain delivery history for certificates and commercial documents, including date/time, channel, recipient, responsible user, result, error if any, resend attempts, and latest sent date.

#### Scenario: Resend document

- GIVEN a document has already been sent once
- WHEN an authorized user sends it again
- THEN the system MUST append a new delivery-history entry
- AND update the latest sent date without deleting prior entries.

### Requirement: Pending and one-day configurable alerts

The system MUST show pending and overdue certificates and commercial documents on the main dashboard and inside the **Cursos y charlas** module. A document MUST be overdue when it remains pending beyond one calendar day by default. The overdue threshold MUST be configurable.

Alerts MUST be closable only by sending the document or discarding it with a reason.

#### Scenario: Pending document becomes overdue after one day

- GIVEN the overdue threshold is configured as one calendar day
- AND a generated certificate has not been sent
- WHEN more than one calendar day passes after it became pending
- THEN the system MUST mark it as overdue
- AND show it in pending/overdue lists.

#### Scenario: Discard alert with reason

- GIVEN a commercial document delivery alert is pending
- WHEN an authorized user discards the alert with a reason
- THEN the system MUST close the alert
- AND preserve the reason and responsible user in audit or history.

### Requirement: Permissions

The system MUST enforce separate permissions for at least: viewing the module, managing activities/editions, managing classes/attendance, entering grades, managing participants, generating documents, annulling/regenerating documents, registering receipts/invoices, sending documents, managing template configuration, and viewing audit/history.

Unauthorized users MUST NOT perform restricted actions.

#### Scenario: User without grade permission cannot grade

- GIVEN a user can view a course edition but lacks grade permission
- WHEN the user attempts to enter or change grades
- THEN the system MUST deny the action
- AND grades MUST remain unchanged.

#### Scenario: User without revocation permission cannot annul certificate

- GIVEN a certificate is vigente
- WHEN a user without annul/regenerate permission attempts to annul it
- THEN the system MUST deny the action
- AND the certificate MUST remain vigente.

### Requirement: Auditability

The system MUST audit material changes, including activity code changes, edition state changes, participant enrollment changes, attendance changes, grade changes, result recalculations, payment-completion changes, document generation, document annulment, document regeneration, template changes, receipt/invoice registration or attachment changes, delivery attempts, manual WhatsApp confirmations, alert closures, and permission-sensitive actions.

#### Scenario: Grade correction is audited

- GIVEN a participant grade has already been saved
- WHEN an authorized user changes the grade
- THEN the system MUST record who changed it, when, previous value, new value, and affected participant/edition.

### Requirement: v1 non-goals

The system MUST NOT include the following in v1: automatic tax-document generation, SUNAT/accounting-provider integration, bank/payment-gateway integration, complex installments/refunds/accounting reconciliation, automatic WhatsApp API document delivery, weighted or multiple grading formulas, student self-service portal, public enrollment, online checkout, learning-content platform, videoconference integration, automatic attendance collection, unreviewed mass historical migration, or third-party digital-signature provider integration.

#### Scenario: No automatic tax generation

- GIVEN a user registers a factura or boleta
- WHEN the user completes the commercial-document record
- THEN the system MUST store the externally issued document and attachment
- AND MUST NOT issue or submit a tax document to SUNAT or a tax provider.

#### Scenario: No automatic WhatsApp send

- GIVEN a user chooses WhatsApp delivery in v1
- WHEN the system opens WhatsApp with a prepared message
- THEN the system MUST NOT assume the document was automatically sent
- AND MUST require manual confirmation before marking delivery as sent.

### Requirement: Edition teachers have stable identity

The system MUST treat each edition teacher as a stable edition-scoped entity. `sort_order` SHALL define display/order position only and MUST NOT be used as teacher identity.

External/display-only teachers MUST remain valid with a required `display_name`, optional `email`, and no required CRM `user_id`.

#### Scenario: Teacher identity survives reordering

- GIVEN an edition has teachers `T1` and `T2` with stable teacher identities
- WHEN an authorized user changes their order
- THEN each teacher MUST keep its original identity
- AND only ordering semantics MAY change.

#### Scenario: External teacher without CRM user remains valid

- GIVEN an authorized user adds an edition teacher with `display_name` `External Speaker`, no `user_id`, and no `email`
- WHEN the teacher is saved
- THEN the system MUST accept the teacher as a valid edition teacher.

#### Scenario: Sort order is not identity

- GIVEN an edition teacher has stable identity `T1` and sort order `1`
- WHEN another teacher is inserted before `T1`
- THEN `T1` MUST remain the same teacher entity
- AND `T1` MAY receive a different sort order.

### Requirement: Teacher synchronization preserves identity

The system MUST synchronize edition teachers through identity-preserving create, update, and explicit remove operations. The system MUST NOT delete all teachers and reinsert the submitted list as a normal synchronization strategy.

Omitting an existing teacher from a partial payload MUST NOT by itself remove that teacher. Teacher removal MUST require explicit removal intent.

#### Scenario: Updating one teacher preserves unrelated teachers

- GIVEN an edition has teachers `T1` and `T2`
- WHEN an authorized user updates the display name for `T1`
- THEN `T1` MUST be updated in place
- AND `T2` MUST remain present with its identity unchanged.

#### Scenario: Partial payload omission does not remove a teacher

- GIVEN an edition has teachers `T1` and `T2`
- WHEN a teacher synchronization request updates `T1` and omits `T2` without explicit removal intent
- THEN `T2` MUST remain assigned to the edition.

#### Scenario: Explicit removal removes only the selected teacher

- GIVEN an edition has teachers `T1`, `T2`, and `T3`
- WHEN an authorized user explicitly removes `T2`
- THEN `T2` MUST no longer be active for that edition
- AND `T1` and `T3` MUST remain present with their identities unchanged.

### Requirement: Teacher removal is blocked while assigned to sessions

The system MUST block server-side removal of an edition teacher when one or more sessions in that edition are assigned to that teacher. The user MUST reassign affected sessions before the teacher can be removed.

#### Scenario: Removal with assigned sessions is rejected

- GIVEN edition teacher `T1` is assigned to a session in the same edition
- WHEN an authorized user attempts to remove `T1`
- THEN the system MUST reject the removal
- AND the teacher MUST remain available
- AND the session assignment MUST remain unchanged.

#### Scenario: Removal succeeds after reassignment

- GIVEN edition teacher `T1` was assigned to a session
- AND the session has been reassigned to another valid teacher or cleared according to allowed session rules
- WHEN an authorized user explicitly removes `T1`
- THEN the system MUST allow the removal.

### Requirement: Sessions may reference an edition teacher

The system MUST allow a course session to store a nullable `teacher_id` reference to an edition teacher. A session `teacher_id` MUST reference only a teacher that belongs to the same edition as the session.

The system MUST retain legacy `teacher_name` as a compatibility and display fallback in this increment.

#### Scenario: Session references teacher from same edition

- GIVEN edition `E1` has teacher `T1`
- AND a session belongs to `E1`
- WHEN an authorized user assigns `T1` to the session
- THEN the session MUST store the teacher assignment as valid.

#### Scenario: Cross-edition teacher ID is rejected

- GIVEN edition `E1` has a session
- AND edition `E2` has teacher `T2`
- WHEN a request attempts to assign `T2` to the `E1` session
- THEN the system MUST reject the assignment
- AND the session MUST NOT reference `T2`.

#### Scenario: Session without teacher ID can use legacy teacher name

- GIVEN a session has no `teacher_id`
- AND the session has legacy `teacher_name` `Legacy Speaker`
- WHEN the session is displayed or used for certificate speaker data
- THEN the system MUST keep `Legacy Speaker` available as fallback display text.

### Requirement: Certificate speaker uses assigned teacher before legacy fallback

The system MUST choose certificate syllabus speaker text using this precedence: session `teacher_id` teacher `display_name`, then legacy session `teacher_name`, then `Maia Consultores`.

#### Scenario: Assigned teacher takes precedence over legacy text

- GIVEN a session references teacher `T1` with display name `Assigned Teacher`
- AND the same session has legacy `teacher_name` `Old Teacher Text`
- WHEN certificate syllabus speaker data is generated
- THEN the speaker MUST be `Assigned Teacher`.

#### Scenario: Legacy teacher name is used when no teacher is assigned

- GIVEN a session has no `teacher_id`
- AND the session has legacy `teacher_name` `Legacy Speaker`
- WHEN certificate syllabus speaker data is generated
- THEN the speaker MUST be `Legacy Speaker`.

#### Scenario: Company fallback is used when no teacher text exists

- GIVEN a session has no `teacher_id`
- AND the session has no non-empty `teacher_name`
- WHEN certificate syllabus speaker data is generated
- THEN the speaker MUST be `Maia Consultores`.

### Requirement: Production rollout prerequisites for teacher identity changes

Before applying teacher identity schema or data changes to staging or production, the owner workflow MUST verify a current restorable database backup and MUST capture target-environment counts for `course_edition_teachers`, `course_sessions`, and sessions with non-empty legacy `teacher_name`.

Production safety MUST NOT be inferred from local development counts. Local verification of zero teachers and zero sessions MAY reduce local backfill work only.

#### Scenario: Local zero counts do not authorize production rollout

- GIVEN local development has 0 edition teachers and 0 sessions
- WHEN production backup status and production row counts are unknown
- THEN production rollout MUST remain blocked.

#### Scenario: Production prerequisites are captured before rollout

- GIVEN the owner intends to apply the change to production
- WHEN a current restorable backup is verified
- AND production counts for edition teachers, sessions, and sessions with non-empty `teacher_name` are captured
- THEN the production-safety prerequisite MAY proceed to design/runbook validation.

### Requirement: Increment scope boundaries

The system MUST keep teacher identity behavior edition-scoped in this increment. This increment MUST NOT introduce a global teacher catalog, teacher permissions, teacher notifications, CRM-user lifecycle semantics, global teacher reporting, or production execution.

#### Scenario: No global teacher catalog is introduced

- GIVEN the change is implemented for an edition teacher
- WHEN the teacher is saved
- THEN the teacher MUST belong to the edition scope
- AND the system MUST NOT require or create a global reusable teacher record for this increment.

#### Scenario: CRM user link remains passive

- GIVEN an edition teacher has a nullable `user_id` field available
- WHEN the teacher is created or updated in this increment
- THEN the system MUST NOT assign new permissions, notifications, ownership, or CRM-user lifecycle behavior based on that value.
