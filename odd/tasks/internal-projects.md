# Internal projects module (software/initiative planning with progress)

Plan internal initiatives (e.g. a software development) as phases and tasks so marketing and management can see what is planned and how it progresses.
Branch: `feat/internal-projects` (stacked on `fix/content-module-review`). Never commit `storage/fonts/*.json`. UI Spanish, "usted".

## Specs

- S1 User request: "lo que quieren es que se plantee las actividades del software para que el equipo de marketing y gerencia pueda ver lo que se plantea y el avance".
- S2 Structure "Fases con tareas (Recomendado)": Project → Phases (ordered, e.g. Análisis, Diseño, Desarrollo, Pruebas, Lanzamiento) → Tasks. Project fields: name, code (unique), description, color (reuse app/Support/Content/ProjectColor palette idea), start/end dates (planned), status (Planificado, En curso, En pausa, Terminado, Cancelado), responsible user. Phase: name, order, optional planned start/end. Task: title, description, phase, responsible user, start date, due date, status, weight (integer ≥1, default 1), "Hito" flag (milestone).
- S3 Progress "Por estado de tarea (Recomendado)": task statuses Pendiente / En curso / Bloqueada / Terminada. Phase % = sum(weight of Terminada tasks) / sum(weight of tasks) × 100 (0 tasks → 0%); project % = same over all tasks. A task is "Atrasada" when its due date (Lima) has passed and it is not Terminada. Status changes are recorded (who/when) so progress history is auditable.
- S4 Views "Resumen con barras de avance (Recomendado)", "Línea de tiempo / Gantt (Recomendado)", "En el calendario del CRM":
  - S4.1 Summary dashboard: list of visible projects with color, status, responsible, overall % bar, per-phase % bars, counts (total/terminadas/en curso/bloqueadas/atrasadas), next milestones (upcoming 3), dates; project detail page with phases, tasks table (filters by phase/status/responsible), progress bars.
  - S4.2 Timeline/Gantt: per project, phases as group rows and tasks as bars between start and due date over a day/week scale, today line, overdue in red, done in green, milestones as diamonds; server-rendered (no external JS lib), horizontally scrollable; months header.
  - S4.3 CRM calendar: milestones and task due dates of visible projects appear in the existing CRM calendar (/calendar) as a new layer "Proyectos" (filter option alongside Todo/Comercial/Interno), painted with the project color, linking to the task/project.
- S5 Permissions "Equipo edita, otros solo ven (Recomendado)": project members (team) and the responsible create/update phases, tasks and statuses; audience (all organization | roles | users, same model as internal activities) only views; admin everything; any active user may create a project (becomes responsible + member) — creation open like internal activities; only responsible/admin edit project settings, members and audience, and delete. Non-visible project → 404 on direct URL.
- S6 Sidebar entry "Proyectos internos"; user guide docs/guia-usuario/proyectos-internos.md.

## Tasks

- [ ] T1 S2,S3,S5 foundation — migrations, models, enums, policy, progress service, factories, tests. Route: worker P1. Commit: —
- [ ] T2 S4.1,S5,S6 UI — CRUD screens, dashboard, detail, task status updates, members/audience, sidebar. Route: worker P2 (after T1). Commit: —
- [ ] T3 S4.2 Gantt timeline. Route: worker P3 (after T1, parallel to T2). Commit: —
- [ ] T4 S4.3 CRM calendar layer. Route: worker P4 (after T1, parallel). Commit: —
- [ ] T5 Integration, browser smoke, user guide, commits. Route: inline. Commit: —

## Log

- L1 User: "una consulta las actividades internas esta orientado a que por ejemplo estamos planificando desarrollar un proyecto de software y se pueda ver ahi ??" → explained limits. Then: "o sea lo que quieren es que se plantee las actividades del software para que el equipo de marketing y gerencia pueda ver lo que se plantea y el avance".
- L2 Decisions: estructura = "Fases con tareas (Recomendado)"; avance = "Por estado de tarea (Recomendado)"; vistas = "Resumen con barras de avance (Recomendado)", "Línea de tiempo / Gantt (Recomendado)", "En el calendario del CRM"; permisos = "Equipo edita, otros solo ven (Recomendado)".
- L3 Reuse: internal activities audience model (app/Models/InternalActivity.php scopeVisibleTo, audience_type all|roles|users); calendar layers in app/Services/CalendarEventService.php (layer param commercial|internal); ProjectColor helper app/Support/Content/ProjectColor.php; PeruTime for Lima dates; multi-select picker auto-applies to select[multiple].
