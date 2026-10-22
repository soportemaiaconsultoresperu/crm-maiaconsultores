# Support tickets: fixes and completion

Fix the defects and gaps found by the read-only review of the Soporte/tickets module and complete the agent UI per docs/soporte-tecnico-diseno.md.
Branch: `fix/support-tickets` (stacked on `feat/roles-permissions-ux`). Never commit `storage/fonts/*.json`. UI Spanish, "usted".

## Specs

- S1 User: "haz los 5" — the five recommendations:
  1. Dashboard and CSV respect the user's data scope; dashboard reachable from the sidebar.
  2. Assign and cancel respect the state machine, with row locks against double submit.
  3. KPIs fixed: positive times, effective time used, correct reopened %, per-cycle times kept; each with real-data tests.
  4. Observation state machine; "cerrar con observaciones pendientes" available in the UI.
  5. State-aware ticket page: only valid actions per state; waiting states (cliente / interna); priority change; ticket edit; reschedule/assignment history; list search (code/customer) and "mis tickets"; Spanish labels; dead permissions implemented or documented.
- S2 Scope: SupportDashboardService::query() applies SupportTicketScopeService for the acting user (own/team/any) for metrics, list and CSV; CSV headers in Spanish; sidebar link "Dashboard de soporte" for users with support.reports.view.
- S3 Assign: only changes status to "Asignado" when the ticket is Nuevo or Reabierto (through the lifecycle transition, recording the status period); reassigning in other non-terminal states keeps the status and records the assignment history; assigning a Resuelto/Cerrado/Cancelado ticket is refused with a Spanish message; responsible must be an active user.
- S4 Cancel goes through the lifecycle transition (closes the open period/cycle, sets end time) and is refused from states the map does not allow (e.g. Resuelto, Cerrado).
- S5 Transitions run inside a transaction with lockForUpdate on the ticket; a double submit never duplicates periods/cycles; a disallowed transition shows a Spanish flash error (no 500) — InvalidOperationException rendered/handled.
- S6 Resolve: solution summary saved atomically with the transition (nothing saved if refused); resolving with pending observations is refused unless the design says otherwise (follow docs/soporte-tecnico-diseno.md §10); reopen starts a new cycle keeping previous cycle times; second resolution does not erase the first cycle's times; ticket validated_at set when the design says.
- S7 KPIs: averages use created→event in positive seconds (Carbon 3 signed diff fixed); effective time (excluding waiting periods per design §11) computed and shown; reopened % = tickets with >1 cycle / tickets ever resolved (never >100%); resolved-within-target stays null with a Spanish note "Sin tiempos objetivo configurados" (target times out of scope); dashboard avoids loading every ticket where aggregation suffices.
- S8 Observations: explicit transition map per design §8/§10 (pending → in_process → lifted → validated | rejected → reopened …; follow the doc exactly), invalid transitions refused with Spanish message, each transition guarded by the right permission (lift/validate/reject/create per catalog), Spanish state labels, empty state text, responsible/due date shown if present.
- S9 UI: actions shown only when valid for the current state and permitted; waiting-for-customer / internal-waiting buttons (transitions existing in the map); priority change (support.priority.update); ticket edit of descriptive fields (support.update); assignment + reschedule history on the ticket page; close-with-pending-observations checkbox (support.close.with-pending-observations); list search by code/customer name and "Mis tickets" filter.
- S10 Permission catalog: support.view.team description matches the real rule; support.catalogs.manage labelled as reserved (no screen yet); no permission removed.
- S11 Out of scope (documented): Lima time zone conversion for support datetimes (shared with Activities/Calendar data; needs a cross-module data migration); catalog/target-time admin UI; notifications.

## Tasks

- [ ] T1 S2, S7, S10 — scope, dashboard, KPIs, catalog texts. Route: worker S-A. Commit: —
- [ ] T2 S3, S4, S5, S6, S8 (service side) — state integrity. Route: worker S-B. Commit: —
- [ ] T3 S9 + S8 UI — ticket page and list. Route: worker S-C (after T2). Commit: —
- [ ] T4 Integration: suites, browser smoke, guide, commits. Route: inline. Commit: —

## Log

- L1 User: "una consulta el modulo de tickets considera que esta bien elaborado ??" → review verdict "MVP usable, no listo para producción" with 5 recommendations. Then: "haz los 5".
- L2 Parent decision: Lima time zone NOT changed in this feature (S11) — support schedules share the naive-datetime convention with Activities and the Calendar; display-only conversion would shift existing data 5h.
- L3 Review evidence: SupportDashboardService.php:28 no scope; :35 signed diffInSeconds; SupportTicketService.php:70 assign forces Asignado; :100-126 cancel bypasses transition(); LifecycleService.php:271-282 observations without state machine; :296 no lock; Controller.php:80 resolve saves summary outside transaction; show.blade.php:201-231 actions not state-aware. Baseline tests: tests/Feature/SupportTicket*.php 24/24.
