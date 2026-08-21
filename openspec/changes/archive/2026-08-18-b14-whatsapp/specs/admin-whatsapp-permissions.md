# Admin WhatsApp — Permissions (6 Spatie gates + seeder idempotente + engine regression)

> **Module slice of `b14-whatsapp`**. Upstream: `docs/v2/01-roadmap.md` §7 D-13..D-15 (visibilidad por DataScope); `openspec/changes/b14-whatsapp/proposal.md` §4 (matriz de permisos) + §12 AC-6 + §11 R4; implementation on disk en `app/Providers/WhatsAppServiceProvider.php` (167 LOC) + `database/seeders/AdditionalWhatsAppPermissionsSeeder.php` (117 LOC) + tests en `tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php` (19 tests).
>
> Scope: 6 permisos Spatie registrados al boot + matriz de gates server-side + seeder idempotente + regresión del motor B12.

---

## Purpose

Definir el contrato de permisos del módulo WhatsApp en V1: 6 permisos Spatie (`whatsapp.view`, `whatsapp.send`, `whatsapp.template.manage`, `whatsapp.account.manage`, `whatsapp.conversation.assign`, `whatsapp.audit`), su registro al boot en `WhatsAppServiceProvider`, su enforcement via `Gate::authorize` / middleware `can:` en los 11 endpoints admin, el seeder idempotente que asigna al rol `admin` los 6 y al rol `supervisor` los 2 base, y la garantía de no regresión del motor B12 (`AutomationEngineTest` 10/10 / 21 assertions verde).

---

## Requirements

### REQ-WHATSAPP-PERM-01 — 5 gates server-side por endpoint (matriz de enforcement)

El sistema SHALL enforzar server-side las 6 permisos Spatie sobre los 11 endpoints admin + el comando `whatsapp:sync-templates` según la siguiente matriz:

| Endpoint / Comando | Permiso requerido | Mecanismo |
|---|---|---|
| `GET admin.whatsapp.accounts.index` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::accounts` |
| `GET admin.whatsapp.accounts.show` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::showAccount` |
| `POST admin.whatsapp.accounts.sync` | `whatsapp.template.manage` | middleware `can:whatsapp.template.manage` en la ruta |
| `GET admin.whatsapp.conversations.index` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::conversations` |
| `GET admin.whatsapp.conversations.show` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::showConversation` |
| `POST admin.whatsapp.conversations.send` | `whatsapp.send` | middleware `can:whatsapp.send` en la ruta |
| `POST admin.whatsapp.conversations.assign` | `whatsapp.conversation.assign` | middleware `can:whatsapp.conversation.assign` en la ruta |
| `POST admin.whatsapp.conversations.close` | `whatsapp.send` | `Gate::authorize` en `AdminWhatsAppController::closeConversation` |
| `POST admin.whatsapp.conversations.opt_out` | `whatsapp.send` | `Gate::authorize` en `AdminWhatsAppController::markOptOut` |
| `GET admin.whatsapp.templates.index` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::templates` |
| `GET admin.whatsapp.templates.show` | `whatsapp.view` | `Gate::authorize` en `AdminWhatsAppController::showTemplate` |
| `php artisan whatsapp:sync-templates` | (CLI; gate via el endpoint HTTP) | el comando es invocable desde CLI sin permisos; el equivalente HTTP `POST whatsapp.accounts.sync` requiere `whatsapp.template.manage` |

El `whatsapp.audit` queda REGISTRADO pero NO enforzado en V1 vía endpoint dedicado (D-12c reserva el CRUD UI de cuentas para B14.1 — la auditoría contextual se surface en las vistas de detalle gated por `@can('whatsapp.audit')` cuando se renderice el bloque).

Sin el permiso requerido, la respuesta SHALL ser 403 (`Symfony\Component\HttpKernel\Exception\HttpException(403)`).

### REQ-WHATSAPP-PERM-02 — Rol admin recibe los 6 permisos

El sistema SHALL asignar al rol `admin` (Spatie) los 6 permisos `whatsapp.*` (`view`, `send`, `template.manage`, `account.manage`, `conversation.assign`, `audit`). Esta asignación se ejecuta vía método `assignAllWhatsAppPermissionsToAdminRole()` en `AdditionalWhatsAppPermissionsSeeder::run()`. La asignación SHALL ser idempotente — ejecutar el seeder dos veces seguidas SHALL resultar en 6 filas (no 12) en `permissions` y el rol `admin` SHALL tener exactamente los 6 `whatsapp.*` permisos (no duplicados).

### REQ-WHATSAPP-PERM-03 — Rol supervisor recibe `whatsapp.view` + `whatsapp.conversation.assign`

El sistema SHALL asignar al rol `supervisor` los 2 permisos base: `whatsapp.view` (read-only sobre bandeja + plantillas) y `whatsapp.conversation.assign` (reasignar hilos entre vendedores de su equipo). Vía método `assignBaseWhatsAppPermissionsToSupervisorRole()` en `AdditionalWhatsAppPermissionsSeeder::run()`. NO recibe `whatsapp.send` (los supervisors no envían WhatsApp — sólo diagnostican y asignan), ni `whatsapp.template.manage`, ni `whatsapp.account.manage`, ni `whatsapp.audit`.

### REQ-WHATSAPP-PERM-04 — 3 permisos reservados / dead-branch verifiable

El sistema SHALL garantizar que 3 permisos queden **registrados pero no enforzados** en V1 vía endpoint dedicado, sin caer en un dead-branch silencioso:

- `whatsapp.account.manage` — registrado al boot; sin endpoint V1 que lo enforcé (futuro B14.1 CRUD de cuentas). La verificación de "no enforzado" es: `grep -r 'can:whatsapp.account.manage' routes/` devuelve 0 resultados; el seeder asigna el permiso al rol admin, pero ningún endpoint lo chequea en V1.
- `whatsapp.audit` — registrado al boot; sin endpoint V1 dedicado (la auditoría contextual se surface en bloques gated por `@can(...)` dentro de las vistas). Verificación: `grep -r 'can:whatsapp.audit' routes/` devuelve 0 resultados.
- `automations.webhook.execute` — mencionado en el proposal de B12-UI como "reservado / no usado en V1"; NO tiene relación con WhatsApp pero es ejemplo del patrón "dead-branch verifiable" — un permiso registrado, sin endpoint V1, documentado en `openspec/changes/b12-ui/specs/admin-automations-permissions.md` (no en este spec).

Estos 3 permisos no son bug — son decisiones de roadmap que reservan la puerta para V+1 sin ensuciar V1 con código muerto.

### REQ-WHATSAPP-PERM-05 — Regresión del motor B12 (engine unchanged)

El sistema SHALL garantizar que la introducción del módulo WhatsApp NO toca el motor de automatización B12. Verificación:

- `php artisan test --filter=AutomationEngineTest` SHALL devolver **10/10 / 21 assertions** verde (mismas cifras que en el baseline pre-B14).
- Los modelos `Automation*` y los servicios `Automation\*` SHALL quedar intactos (sin diff).
- Las 7 tablas `automation_*` SHALL quedar intactas.
- El `AutomationServiceProvider` SHALL quedar intacto (sin merge con `WhatsAppServiceProvider`).

Esto es el guard de no-regresión contractual del motor de automatizaciones; cualquier rotura bloquea archive.

---

## Scenarios

#### SCN-PERM-01 — Usuario sin `whatsapp.view` recibe 403 en index

- **GIVEN** usuario autenticado sin permisos `whatsapp.*`
- **WHEN** GET `admin.whatsapp.conversations.index`
- **THEN** respuesta 403 (`Gate::authorize('whatsapp.view')` falla)

#### SCN-PERM-02 — Usuario con `whatsapp.view` pero sin `whatsapp.send` recibe 403 en send

- **GIVEN** usuario con `whatsapp.view` solamente
- **AND** una conversación abierta
- **WHEN** POST `admin.whatsapp.conversations.send` `{body: "hola"}`
- **THEN** respuesta 403 (middleware `can:whatsapp.send` falla)

#### SCN-PERM-03 — Sync templates requiere `whatsapp.template.manage`

- **GIVEN** usuario con `whatsapp.view` solamente (sin `template.manage`)
- **WHEN** POST `admin.whatsapp.accounts.sync` `{account: <id>}`
- **THEN** respuesta 403 (middleware `can:whatsapp.template.manage` falla)

#### SCN-PERM-04 — Asignación respeta DataScope + requiere `whatsapp.conversation.assign`

- **GIVEN** usuario sin `whatsapp.conversation.assign`
- **WHEN** POST `admin.whatsapp.conversations.assign` `{user_id: <otro>}`
- **THEN** respuesta 403 (middleware falla antes del DataScope check)

#### SCN-PERM-05 — Seeder idempotente: 2 ejecuciones = 6 permisos

- **GIVEN** DB limpia sin permisos `whatsapp.*`
- **WHEN** `php artisan db:seed --class=AdditionalWhatsAppPermissionsSeeder`
- **AND** segunda ejecución del mismo comando
- **THEN** la tabla `permissions` tiene exactamente 6 filas con nombre `whatsapp.*` (no 12)
- **AND** el rol `admin` tiene los 6 permisos asignados (uno por uno, sin duplicados)
- **AND** el rol `supervisor` tiene `whatsapp.view` + `whatsapp.conversation.assign` solamente

#### SCN-PERM-06 — Admin tiene los 6 permisos; supervisor tiene 2

- **GIVEN** seeder ejecutado
- **WHEN** `auth()->user()->hasAllPermissions(['whatsapp.view', 'whatsapp.send', 'whatsapp.template.manage', 'whatsapp.account.manage', 'whatsapp.conversation.assign', 'whatsapp.audit'])` sobre usuario con rol `admin`
- **THEN** devuelve `true`

- **WHEN** la misma comprobación sobre usuario con rol `supervisor`
- **THEN** devuelve `false` (le faltan 4 permisos)

#### SCN-PERM-07 — Vendedor sin `whatsapp.send` no puede responder

- **GIVEN** usuario con `whatsapp.view` solamente
- **WHEN** intenta `POST admin.whatsapp.conversations.send`
- **THEN** respuesta 403; ningún mensaje se persiste; ningún job se dispara

#### SCN-PERM-08 — Engine B12 sin regresión tras B14

- **GIVEN** B14 mergeado con `app/Models/WhatsApp/*` + `app/Services/WhatsApp/*` + `app/Providers/WhatsAppServiceProvider.php` + 5 migraciones
- **WHEN** `php artisan test --filter=AutomationEngineTest`
- **THEN** respuesta `OK (10 tests, 21 assertions)` en ~1.7 s (mismo baseline que pre-B14)

#### SCN-PERM-09 — Dead-branch verificable: `whatsapp.account.manage` sin endpoint

- **GIVEN** B14 mergeado
- **WHEN** `grep -r 'can:whatsapp.account.manage' routes/` en `routes/web.php`
- **THEN** devuelve 0 líneas (permiso registrado, sin endpoint V1 — decisión B14.1)

- **WHEN** `grep -r 'can:whatsapp.audit' routes/`
- **THEN** devuelve 0 líneas (permiso registrado, sin endpoint V1 dedicado)

#### SCN-PERM-10 — Suite completa sin regresión

- **GIVEN** B14 mergeado
- **WHEN** `php artisan test`
- **THEN** respuesta `OK (631 tests, 2206 assertions)` en ~78-243 s (baseline post-B14)

---

## Affected routes

(Mismos 11 endpoints admin + 1 webhook que `admin-whatsapp-bandeja.md` y `admin-whatsapp-provider.md`. Este spec NO introduce rutas nuevas — define la matriz de gates sobre las rutas ya existentes.)

| Method | URI | Name | Permission enforced |
|---|---|---|---|
| GET | `/admin/whatsapp/accounts` | `whatsapp.accounts.index` | `whatsapp.view` |
| GET | `/admin/whatsapp/accounts/{account}` | `whatsapp.accounts.show` | `whatsapp.view` |
| POST | `/admin/whatsapp/accounts/{account}/sync-templates` | `whatsapp.accounts.sync` | `whatsapp.template.manage` |
| GET | `/admin/whatsapp/conversations` | `whatsapp.conversations.index` | `whatsapp.view` |
| GET | `/admin/whatsapp/conversations/{conversation}` | `whatsapp.conversations.show` | `whatsapp.view` |
| POST | `/admin/whatsapp/conversations/{conversation}/send` | `whatsapp.conversations.send` | `whatsapp.send` |
| POST | `/admin/whatsapp/conversations/{conversation}/assign` | `whatsapp.conversations.assign` | `whatsapp.conversation.assign` |
| POST | `/admin/whatsapp/conversations/{conversation}/close` | `whatsapp.conversations.close` | `whatsapp.send` |
| POST | `/admin/whatsapp/conversations/{conversation}/opt-out` | `whatsapp.conversations.opt_out` | `whatsapp.send` |
| GET | `/admin/whatsapp/templates` | `whatsapp.templates.index` | `whatsapp.view` |
| GET | `/admin/whatsapp/templates/{template}` | `whatsapp.templates.show` | `whatsapp.view` |
| POST | `/webhooks/whatsapp/{account}` | `webhooks.whatsapp` | HMAC-SHA256 (NO `auth`/`active`) |

---

## Cross-references

- **Proposal**: `openspec/changes/b14-whatsapp/proposal.md` §4 (matriz de permisos + tabla), §5 T6 (permisos correctos sin errores), §12 AC-6 (6 permisos + seeder idempotente), AC-10 (engine regression), AC-11 (suite sin regresión).
- **Roadmap**: `docs/v2/01-roadmap.md` §7 D-13a..d (número desconocido configurable + normalización + búsqueda + asignación), §7 D-14a/b/c (visibilidad por responsable + supervisor + admin; sin asignar visible al equipo; siempre `DataScope`), §7 D-15c (filtro `approved` — no es un permiso pero acota el alcance del sync).
- **Implementation**:
  - Provider: `app/Providers/WhatsAppServiceProvider.php` (167 LOC) — método `registerWhatsAppPermissions()` registra los 6 al boot + `boot()` configura factory bindings.
  - Seeder: `database/seeders/AdditionalWhatsAppPermissionsSeeder.php` (117 LOC) — métodos `assignAllWhatsAppPermissionsToAdminRole()` + `assignBaseWhatsAppPermissionsToSupervisorRole()`, idempotentes vía `firstOrCreate` + `syncPermissions`.
  - Gates en routes: `routes/web.php` líneas 511-548 — middlewares `can:whatsapp.view`, `can:whatsapp.send`, `can:whatsapp.template.manage`, `can:whatsapp.conversation.assign`.
  - Gates en controller: `app/Http/Controllers/Admin/WhatsAppController.php` — `Gate::authorize` en `accounts`, `conversations`, `templates`, `showAccount`, `showConversation`, `showTemplate`, `closeConversation`, `markOptOut`.
  - DataScope: `app/Services/DataScope/DataScopeService.php` (B12 — reusado por B14 vía `visibleOwnerIds($actor)`).
- **Tests**:
  - `tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php` (19 tests) — SCN-PERM-01..04 + SCN-PERM-07 (gate matrix en el HTTP layer).
  - `tests/Feature/Admin/WhatsApp/Livewire/ConversationListLivewireTest.php` — `test_assign_conversation_requires_assign_permission` (SCN-PERM-04 a nivel Livewire).
  - `tests/Feature/Admin/Automations/AdminAutomationPermissionsTest.php` (B12) — referencia del patrón de provider boot (`setUp()` re-registra `AutomationServiceProvider` con `force: true`). El equivalente WhatsApp vive en el seeder.
  - `tests/Feature/AutomationEngineTest.php` (B12) — SCN-PERM-08 (engine regression guard).
  - `php artisan test` — SCN-PERM-10 (suite completa).
- **Adjacent specs**: `admin-whatsapp-bandeja.md` (controller + Livewire + comando), `admin-whatsapp-provider.md` (provider + factory + webhook + stub).
- **Config**: `openspec/config.yaml` — `strict_tdd: true`, artifact store `openspec`, `repository: none` (no git).
