# Known limitations and open debts — `course-talks-management`

Snapshot taken at the end of Slice 7.C. Each item states what is limited, why it was
accepted, and what closing it would take. Nothing here is a hidden defect: everything
listed was either deliberately deferred or surfaced by a review and consciously left open.

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

## 3. Two migrations are NOT applied to any real database

| Migration | What it adds | Forward / rollback |
|---|---|---|
| `2026_08_26_000005_add_course_commercial_document_idempotency_key` | nullable unique `idempotency_key` on `course_commercial_documents` | additive, existing rows keep NULL; rollback drops index then column |
| `2026_08_26_000006_add_delivery_discard_reason_to_course_commercial_documents` | nullable `delivery_discard_reason` on the same table | additive; rollback drops the column |

Both were validated against the in-memory test connection only. **`php artisan migrate` is a
pending owner action.** Until it runs, commercial registration idempotency and commercial
follow-up discard will fail on a real database with a missing-column error.

Note: the unique-index-permits-many-NULLs behaviour was measured on SQLite and is only
DOCUMENTED for MySQL/InnoDB — not measured, because running the migration against a real
database was out of scope.

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
