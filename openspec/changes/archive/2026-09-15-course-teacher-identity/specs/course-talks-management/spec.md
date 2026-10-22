# Delta for course-talks-management

## ADDED Requirements

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

## MODIFIED Requirements

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
