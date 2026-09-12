# Known limitations and open debts — `course-talks-management`

Snapshot taken at the end of Slice 7.D plus the corrective unit that closed the
foundation review (document deletion, schema rollback, orphan permissions). Each item
states what is limited, why it was accepted, and what closing it would take. Nothing here
is a hidden defect: everything listed was either deliberately deferred or surfaced by a
review and consciously left open.

## 1. The queued-email transition is recorded without a causer

`app/Jobs/V2/SendEmailMessage::syncCourseDelivery()` is the only writer of the terminal
`delivery_status` / `last_sent_at` for the wired queued-email channel. It runs with no
session and no actor, so its `course-updated` entry carries `causer_id = NULL`.

**Why it is accepted**: the HUMAN act — enqueuing the send — IS audited with its actor
(`queueAcademicEmail()` / `queueCommercialEmail()` run inside `CourseAuditActor`). What
stays unattributed is the technical transition the SYSTEM performed. A null causer is the
honest representation of "no person did this"; inventing a system user would attribute a
human-shaped row to nobody real.

**What closing it would take**: `outbound_deliveries` has no actor column, so the job has
nobody to attribute to. Closing it means adding that column, propagating the actor from the
delivery services, and having the job set the causer — one more migration plus a change to
shared email infrastructure.

## 2. `course_edition_teachers` has no audit trace

The table has a composite primary key with no `id` and no timestamps, and `syncTeachers()`
mass-deletes through the query builder, so there is nothing for `LogsActivity` to key on.
Teacher assignment changes are therefore invisible in the audit trail.

**What closing it would take**: a migration adding an `id` and timestamps (or replacing the
composite key), plus rewriting the sync so it goes through the models instead of a bulk
delete. That is the most invasive of the open items and belongs with its own review.

Related: `CourseEditionChanged` exists but no listener consumes it anywhere.

## 3. ALL SIX course migrations are NOT applied — the module has no tables on a real database

`php artisan migrate:status` against the real MySQL database reports every course migration
`Pending`:

| Migration | What it creates / adds |
|---|---|
| `2026_08_26_000001_create_course_domain_foundation_tables` | ALL 12 domain tables (activities, editions, teachers, sessions, participants, groups, enrollments, attendances, grades, templates, academic and commercial documents) |
| `2026_08_26_000002_add_course_academic_document_qr_token_hash_index` | the public QR lookup index |
| `2026_08_26_000003_add_email_message_id_to_outbound_deliveries` | the delivery-to-email correlation column |
| `2026_08_26_000004_add_delivery_status_to_course_commercial_documents` | the commercial delivery status |
| `2026_08_26_000005_add_course_commercial_document_idempotency_key` | the commercial idempotency key |
| `2026_08_26_000006_add_delivery_discard_reason_to_course_commercial_documents` | the commercial discard reason |

**This entry previously said TWO migrations and materially understated the problem — the
independent verification measured it and corrected it.** Because the FOUNDATION migration is
also pending, the module has NO TABLES at all on that database: every route, dashboard card and
sidebar entry fails on a missing table, not merely commercial idempotency and discard.

**`php artisan migrate` is a pending owner action and a deploy prerequisite.** All six are
additive and were validated against the in-memory test connection only, because running them
against a real database was out of scope in every unit.

Note: the unique-index-permits-many-NULLs behaviour was measured on SQLite and is only
DOCUMENTED for MySQL/InnoDB — not measured, for the same reason.

## 4. The `mailOperation` stub is a live trap

`CourseDocumentDeliveryService` takes a mandatory `Closure $mailOperation` consumed only by
`sendAcademicEmail()` / `sendCommercialEmail()`. The only construction in `app/` injects
`static fn (): bool => true`, so those two methods would mark a document SENT without sending
anything.

**Why it is accepted**: nothing in `app/` calls either method — both delivery surfaces use
the queued paths (`queueAcademicEmail()` / `queueCommercialEmail()`), which send for real.
The trap is inert at this revision.

**What closing it would take**: removing the two methods and the constructor parameter, or
binding a real mail operation. Until then, any NEW caller of `sendAcademicEmail()` or
`sendCommercialEmail()` reintroduces a false-send path.

## 5. `HasAuditColumns` fills `created_by`/`updated_by` from the session only

That trait is shared across the whole CRM and its behaviour is documented: in a console or
job context those columns are null even when the domain knows the actor. This is a DIFFERENT
mechanism from the activitylog causer, which unit 7.C corrected via `CourseAuditActor`, and
the suite proves the two can disagree (`causer_id = 3` while `grades.created_by = 4`).

Deliberately not changed: it is shared infrastructure and changing it would affect every
module, not just this change.

## 6. Academic/commercial duplication (review finding, no defect attached)

Left open by the independent review of the documents/money/delivery tramo: the email body
builders, three pairs of identical FormRequests, four copies of `editionOf()`, roughly fifty
duplicated Blade form lines, and a document-type label map defined in three places.

This is refactoring with no defect behind it. It is recorded so the review's findings are not
mistaken for "all closed".

## 7. Pre-existing suite failures

See `suite-baseline.md`: 11 failures owned by other, in-flight changes, proven against `main`
and excluded from this change's verification claim.

## 8. The schema rollback is destructive and no test drives it

CORRECTIVE UNIT — documented here because it was missing; the `DatabaseSeeder` docblock
documents the SERVICE rollback and does so correctly, and is intentionally unchanged.

Migration `2026_08_26_000001_create_course_domain_foundation_tables` is destructive in the
DOWN direction. Its `down()` drops all 12 domain tables (`course_commercial_documents`,
`course_academic_documents`, `course_certificate_templates`, `course_grades`,
`course_attendances`, `course_enrollments`, `course_enrollment_groups`, `course_participants`,
`course_sessions`, `course_edition_teachers`, `course_editions`, `course_activities`), so
`php artisan migrate:rollback` leaves two kinds of debris behind:

1. **Uninventoriable PII.** The generated and uploaded PDFs stay in `storage/app/private/docs`
   with no surviving row to locate them: not orphaned-but-traceable, simply untraceable. They
   are private documents (certificates, comprobantes and their attachments), so that is a PII
   leak with no inventory.
2. **Stale references.** The `documents` rows are NOT dropped by that migration, so they
   survive pointing at ids MySQL will reuse once `AUTO_INCREMENT` restarts at 1 for the
   dropped and recreated tables. A stale `documents` row can therefore end up pointing at a
   DIFFERENT certificate after the schema is re-created and re-populated.

**Why it is accepted for now**: nothing in the suite exercises any `down()` and the test
connection is in-memory, so the damage is invisible to every test we run. Making the rollback
safe is a data-operation decision (drop order and semantics), not a test fix.

**What closing it would take**: a deliberate rollback story — refuse the rollback while course
documents exist, or drop the `documents` rows together with their files, or move the course
schema into a migration that owns both directions. That is its own review unit.

## 9. `course-talks.audit.view` has no surface in this module

CORRECTIVE UNIT — open decision, deliberately NOT closed by inventing an audit screen.

`course-talks.audit.view` is seeded and `CourseActivityPolicy::viewAudit` consumes it, but the
module exposes no audit surface at all, so nothing invokes that ability: the generic audit
viewer (`AuditController`) uses the unrelated `audit.view` permission. The permission is kept
because the design lists it in the seeded set; removing it would contradict the design, and
building a module audit screen would be inventing scope.

**What closing it would take**: either a module-level audit screen gated by `viewAudit`, or the
decision to drop the permission and the policy method together. Both are product decisions.

## 10. The commercial `sent` status is reserved, never written

CORRECTIVE UNIT — the dead read is kept on purpose, with the reason recorded here.

`course_commercial_documents.status` accepts `pending_file`, `registered`, `sent` and
`discarded` (design, `design.md`), and `sent` is READ in three places:
`PublicCertificateQrController::showSignedCommercial()`, `CourseAlertService`
(`SERVABLE_COMMERCIAL_STATUSES`) and `CourseDocumentDeliveryService::hasStreamableCommercialDocument()`.
Nothing in `app/` ever WRITES it: `register()`/`upload()` write `pending_file`/`registered`, and
sending is tracked by `delivery_status`, not by this column. The value is therefore reserved.

**Why it is kept**: it is a persisted status the design declares, and the readers accept it on
purpose — a comprobante marked `sent` by a future or external writer must stay streamable and
deliverable. Dropping the reads would couple the delivery predicate to today's set of writers
instead of to the schema contract, and would silently break the first writer that appears. No
writer was invented to justify the branch.

## 11. Two raw status columns have no enum

CORRECTIVE UNIT — deliberately deferred, not fixed here.

`course_attendances.status` and the commercial `course_commercial_documents.status` are plain
`string` columns compared against raw literals (`unmarked`, `pending_file`, `registered`,
`sent`, `discarded`) instead of a backed enum, unlike `course_enrollments.state`,
`course_academic_documents.status` and `delivery_status`, which are cast. Introducing those two
enums touches the models, the migrations and every view that prints the value.

**What closing it would take**: one unit adding both enums, the casts, the literal migrations
and the label map, with the existing string comparisons migrated in the same change.

## 12. Referenced documents fail closed instead of being deleted

CORRECTIVE UNIT — the rule is intentional; the remaining friction is recorded.

`DocumentService::delete()` refuses (HTTP 409, Spanish message) when a course document still
references the row, and removes the DB row BEFORE the file. A rejected deletion therefore
leaves row and file exactly as they were, and no path can destroy a file that is still
referenced. Two consequences stay open:

- The Documents catalogue (`DocumentController::destroy`) does not map the refusal to a flash
  message, so the operator sees the 409 error page rather than a redirect with a banner. The
  data-destroying path is closed either way; making the message friendlier is a controller
  change outside this unit's surfaces.
- The superseded attachment of a replaced comprobante is left unreferenced (not deleted) by
  `CourseCommercialDocumentService::upload()`, so it stays removable by hand; the previous file
  is never removed automatically.
- If the physical delete fails AFTER the row is gone (a disk problem), the outcome is an
  orphaned file with no row — a storage leak, never a dangling reference, which is the safe
  direction of the two.
