# Roles and permissions: intuitive Spanish catalog and better UI

Make role/permission management understandable for non-technical admins, fully in Spanish, without changing what any permission means.
Branch: `feat/roles-permissions-ux` (stacked on `feat/internal-projects`). Never commit `storage/fonts/*.json`. UI Spanish, "usted".

## Specs

- S1 User request: "los roles y permisos, haz que los permisos que sean mas intuitivos para cualquier usuario no tech, y que esten en español para que sea de manera intuitiva, ademas que la ui sea mejorada".
- S2 Spanish permission catalog (single source, e.g. `app/Support/Permissions/PermissionCatalog.php`): every permission defined anywhere in the app (all seeders: RolesAndPermissionsSeeder, Additional*PermissionsSeeder, SupportPermissionsSeeder, ContentPermissionsSeeder, CoursePermissionsSeeder, and any permission present in the DB) gets: module (Spanish name matching the sidebar, icon, order), group (Ver / Crear y editar / Acciones especiales / Administración), Spanish label in plain language, one-line description, and a "sensible" flag (delete, export, import, manage settings/users/roles, audit, override). Unknown permissions (in DB but not in catalog) fall back to a readable label derived from the name and appear in an "Otros" module — never hidden. A test fails if any seeded permission lacks a catalog entry.
- S3 Permission semantics unchanged: role ↔ permission storage stays Spatie names; existing roles keep exactly their permissions after saving the new form without changes (round-trip test).
- S4 Role edit/create UI: modules as collapsible cards (icon, Spanish name, "N de M permisos" counter, "Marcar todo / Quitar todo" per module); read-scope permissions `*.view.own / *.view.team / *.view.any` shown as one radio group "¿Qué registros puede ver?" with options "Sin acceso / Solo los suyos / Los de su equipo / Todos" (saving maps to the matching single permission; when a role currently holds several of them, the widest is preselected and saving keeps only that one — document); other actions as switches with label + description, sensitive ones marked with a warning badge; search box filtering permissions by Spanish text; on create: "Copiar permisos de otro rol" select (prefills the form); sticky save bar with total selected count; role name field with help text; admin role shown as "Acceso total" and not editable in permissions (admin bypasses all checks — explain it).
- S5 Role list and detail: list shows role name, description of access (modules covered), users count, permissions count, actions; detail shows a readable summary per module ("Prospectos: ver los de su equipo, crear, editar, exportar") and the users with that role.
- S6 User form: role select shows friendly role names (ucfirst) plus a short summary of what the chosen role can do (modules) under the select.
- S7 Seed missing permissions locally (CoursePermissionsSeeder not run: course-talks.* missing) — integration step, idempotent seeders only.

## Tasks

- [x] T1 S2–S6 catalog + role UI + user form summary + tests. Route: worker R1. Commit: e9aee8a
- [x] T2 S7 + integration, browser smoke, guide section, commits. Route: inline. Commit: 250fd4b + docs

## Log

- L1 User: "los roles y permisos, haz que los permisos que sean mas intuitivos para cualquier usuario no tech, y que esten en español para que sea de manera intuitiva, ademas que la ui sea mejorada"
- L2 Discovery: RoleController::permissionList() labels = raw names grouped by prefix (app/Http/Controllers/RoleController.php:179); views resources/views/admin/roles/{index,create,edit,show}; 161 permissions in local DB, roles admin/supervisor/vendedor; course-talks.* (13) defined in CoursePermissionsSeeder but absent locally; admin bypass via Gate::before in AuthServiceProvider.
- L3 Integration: system role names locked (validation + readonly field, test). Local DB: CoursePermissionsSeeder run (merge-only) → 174 permissions; products.import existed only in DB → catalogued (250fd4b). Browser (admin): roles list with module chips and Acceso total; vendedor edit shows no raw names, scope radios, switches, Sensible badges; search 'exportar' leaves the 7 export permissions; real Guardar cambios without changes kept the 40 vendedor permissions identical. Guide docs/guia-usuario/roles-y-permisos.md.
