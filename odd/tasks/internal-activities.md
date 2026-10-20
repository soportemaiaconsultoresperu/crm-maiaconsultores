# Internal activities module

Organization-wide internal activities (meetings, trainings, events of any kind) that are not tied to a lead, customer or opportunity, shown as an "Interno" layer in the existing calendar so all staff can see them.
Branch: `feat/internal-activities`. Unrelated uncommitted changes in the working tree (CourseEnrollmentController, UserController, their requests) must never be staged or committed.

## Specs
- S1 User request: "crear un modulo pero para proyectar actividades de todo tipo de manera interna en la organizacion y que esta se pueda ver en el calendario y aasi sea mas facil ser mapeados por todo el personal".
- S2 Visibility "Por audiencia": on creation the author chooses the audience: whole organization, one or more roles, or specific users. Only users inside the audience (plus creator and participants) can see the activity, in the calendar and by direct URL.
- S3 Shown in the same calendar as commercial activities (recommended and accepted), as an "Interno" layer with its own color and a filter to show/hide it.
- S4 Participants/responsables: people can be assigned to the activity.
- S5 Recurrence (daily, weekly, monthly with an end date); occurrences are expanded at read time.
- S6 Notifications to participants (on create/update and as reminder) via the existing database notifications.
- S7 Google Calendar sync reusing the existing Google integration.
- S8 Permissions "Cualquier usuario": any active user can create; only the creator or an admin can edit/delete.

## Tasks
- [ ] T1 S1-S4,S8 — model, migration, audience + participants, CRUD, policy, calendar layer + filter, tests. Route: inline. Commit: —
- [ ] T2 S5 — recurrence (rule fields, read-time expansion into calendar), tests. Route: inline. Commit: —
- [ ] T3 S6 — notifications on create/update + scheduled reminder, tests. Route: inline. Commit: —
- [ ] T4 S7 — Google Calendar sync for participants with a connected account, tests. Route: inline. Commit: —

## Log
- L1 User: "lo que quiero es crear un modulo pero para proyectar actividades de todo tipo de manera interna en la organizacion y que esta se pueda ver en el calendario y aasi sea mas facil ser mapeados por todo el personal"
- L2 Answers: visibilidad = Por audiencia; calendario = "cual me recomiendas??" -> recommended same calendar with "Interno" layer; alcance v1 = participantes, recurrencia, notificaciones, sync Google; crear = cualquier usuario. Branch: new branch from current, leave uncommitted changes untouched.
- L3 Discovery: users have no area/department field, so audience = all | roles (spatie) | users. CalendarEventService merges event kinds into CalendarEventItem; tests run on sqlite :memory:; PHP at /c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.
