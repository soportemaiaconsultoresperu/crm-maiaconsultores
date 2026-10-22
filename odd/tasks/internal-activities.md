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
- [x] T1 S1-S4,S8 — model, migration, audience + participants, CRUD, policy, calendar layer + filter, tests. Route: inline. Commit: 103f63a
- [x] T2 S5 — recurrence (rule fields, read-time expansion into calendar), tests. Route: inline. Commit: fefc9b6
- [x] T3 S6 — notifications on create/update + scheduled reminder, tests. Route: inline. Commit: d5beace
- [x] T4 S7 — Google Calendar sync for participants with a connected account, tests. Route: worker. Commit: be449af

## Log
- L1 User: "lo que quiero es crear un modulo pero para proyectar actividades de todo tipo de manera interna en la organizacion y que esta se pueda ver en el calendario y aasi sea mas facil ser mapeados por todo el personal"
- L2 Answers: visibilidad = Por audiencia; calendario = "cual me recomiendas??" -> recommended same calendar with "Interno" layer; alcance v1 = participantes, recurrencia, notificaciones, sync Google; crear = cualquier usuario. Branch: new branch from current, leave uncommitted changes untouched.
- L3 Discovery: users have no area/department field, so audience = all | roles (spatie) | users. CalendarEventService merges event kinds into CalendarEventItem; tests run on sqlite :memory:; PHP at /c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.
- L4 T1 done (103f63a): 10/10 InternalActivityTest. Full suite 1667/1682; the 13 failures fail identically on the base commit (pre-existing, unrelated: admin settings, automations banners, email correlation, Gmail, Google webhook, invoices, academic docs). Calendar requires calendar.view (admin/supervisor/vendedor only); internal activities also have their own list open to every active user.
- L5 T2 done (fefc9b6): 6/6 recurrence tests; calendar+module 80/81 (only the pre-existing webhook failure).
- L6 T3 done (d5beace): 4/4 notification tests; Notif|InternalActivity|Schedule 76/76. Reminder command internal-activities:notify-upcoming scheduled hourly.
- L7 T4 done (be449af, gentle-ai-worker): 6/6 InternalActivityGoogleSyncTest; InternalActivity|GoogleCalendar|Calendar 90/91 (only the pre-existing webhook failure). Known limits: removed-participant delete targets the account's current default calendar; Google-side edits are not synced back.
- L8 User: "si claro" (grant calendar to all roles). Done (e7d6c4e): calendar opened to every active user instead of per-role calendar.view, so custom/future roles are covered; commercial events stay owner-scoped (pinned by test). Calendar|InternalActivity 91/92 (pre-existing webhook failure).
