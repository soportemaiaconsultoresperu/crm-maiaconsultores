# Pruebas

Estado: **al cierre de B09**. Runner: PHPUnit (Laravel 13), base de tests SQLite en memoria (`phpunit.xml`), app dev sobre MySQL.

## Ejecución

```bash
# Host (Laragon)
export PATH="/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
php artisan test                     # suite completa (364 tests / 1461 assertions)
vendor/bin/phpunit --filter AuthTest # un archivo

# Docker (cuando el daemon esté disponible)
docker compose exec app php artisan test
```

## Estado al cierre de B09

**364 tests, 1461 assertions — todas en verde** (`php artisan test`, evidencia real).

| Bloque | Suite al cierre | Δ desde B anterior |
|---|---|---|
| B01 (base) | 33 tests / 123 assertions | — |
| B02 (prospectos) | 73 tests / 257 assertions | +40 |
| B03 (clientes / contactos) | 110 tests / 441 assertions | +37 |
| B04 (oportunidades) | 159 tests / 588 assertions | +49 |
| B05 (actividades / calendario) | 222 tests / 772 assertions | +63 |
| B06 (productos / cotizaciones) | 267 tests / 966 assertions | +45 |
| B07 (dashboard / reportes) | 307 tests / 1179 assertions | +40 |
| B08 (administración) | 344 tests / 1392 assertions | +37 |
| B09 (seguridad / estabilización) | **364 tests / 1461 assertions** | +20 |

## Suite por archivo (60 archivos de Feature + 1 de Unit)

| Archivo (`tests/Feature`) | # tests | Cubre | Requisitos |
|---|---:|---|---|
| AuthTest.php | 9 | Render login, guest redirect, login válido/inválido, **inactivo rechazado en login y por middleware**, dashboard autenticado, logout, throttle 5 | RF-USR-002, RNF-SEG-001 |
| RolesAndPermissionsTest.php | 10 | 62 permisos exactos, 3 roles, matriz admin/supervisor/vendedor, checks sin nombres de rol | RF-USR-003 |
| DataScopeTest.php | 5 | Admin sin restricción, vendedor solo propio, fuera de equipo no ve, supervisor ve equipo+self, equipo inactivo excluido | RF-USR-004, RNF-SEG-004 |
| CodeGeneratorTest.php | 3 | Secuencia por año por entidad, entidades independientes, entidad desconocida lanza | ADR-002 |
| AuditLogTest.php | 3 | Creación y edición logueadas con old/new, atributos sin cambio no loguean | RNF-DAT-003 |
| SeedersTest.php | 2 | Seed completo (catálogos, roles, settings, ubigeo 2113) e idempotencia | RNF-DOC-001 base |
| UsersTest.php | 3 | Admin bootstrap por env con rol, casts is_active/last_login_at | RF-USR-001 base |
| LeadCrudTest.php | 7 | Service: create con correlativo + norms, assign con auditoría, history merge, deactivate, filtros | RF-LEAD-002..011 |
| LeadHttpTest.php | 13 | Controller: CRUD HTTP, duplicado con confirmación, asignación, ubigeo dependiente, export, 403 por scope | RF-LEAD-001/003/006..012 |
| LeadDuplicateFinderTest.php | 8 | Detección de duplicados por doc/email/teléfono, normalización, case insensitivity | RF-LEAD-006, ADR-003 |
| LeadConversionTest.php | 6 | Conversión transaccional lead→cliente, rollback, doble conversión, banner de origen | RF-LEAD-013, ADR-001 |
| LeadVisibilityTest.php | 6 | vendedor solo ve lo propio, supervisor ve equipo, admin ve todo, 403 cross-scoped | RF-LEAD-012, ADR-006 |
| LeadNextActionTest.php | 4 | Próxima acción derivada de agenda (ADR-012), "sin próximo seguimiento" | RF-LEAD-010, ADR-012 |
| LeadsImportExportTest.php | 2 | Import crea filas válidas, omite duplicados, export respeta filtros | RF-LEAD-007/008 |
| LeadsExportScopeTest.php | 2 | **Regresión B04**: export aplica `DataScopeService`; vendedor solo propio, admin todo | RF-LEAD-008, ADR-006 |
| CustomerCrudTest.php | 6 | Service: create con CLI-AAAA-NNNNN, deactivate, history | RF-CLI-001..003 |
| CustomerHttpTest.php | 9 | Controller: CRUD, deactivate, export, ficha 360° | RF-CLI-001..006 |
| CustomerHistoryTest.php | 2 | Línea de tiempo con origen lead marcado | RF-CLI-005 |
| ConversionHttpTest.php | 7 | Flujo HTTP de conversión lead→cliente, doble conversión | RF-LEAD-013 |
| ContactTest.php | 7 | CRUD, principal único transaccional, deactivate | RF-CON-001..003 |
| OpportunityCrudTest.php | 11 | Service: OPP correlativo, stage, win/lose, scopeQuery | RF-OPP-001..010 |
| OpportunityHttpTest.php | 15 | Controller: CRUD, Kanban, drag&drop, win/lose, show, export | RF-OPP-001..009 |
| OpportunityStageTest.php | 6 | Cambio de etapa con historial append-only | RF-OPP-005 |
| OpportunityWinLoseTest.php | 8 | Ganada exige monto + fecha, perdida exige motivo, edición cerrada bloqueada | RF-OPP-006/007 |
| OpportunityNextActionTest.php | 4 | Próxima acción derivada (ADR-012) | RF-OPP-009 |
| OpportunityStageTest.php + StageTest | 6 | Incluido arriba (RF-OPP-005) | RF-OPP-005 |
| OpportunityCrudTest.php (scope) | incluido | Scope por `DataScopeService` | RF-OPP-010 |
| ActivityLifecycleTest.php | 10 | Service: create, start, complete, cancel, soft delete, next array | RF-ACT-001..005 |
| ActivityHttpTest.php | 19 | Controller: CRUD, complete, cancel, start, notificaciones embebidas | RF-ACT-001..007 |
| ActivityScopeTest.php | 4 | Scope por owner_id | RF-ACT-008 |
| MarkOverdueCommandTest.php | 4 | `activities:mark-overdue` cambia estado | RF-ACT-003 |
| NotifyOverdueCommandTest.php | 5 | `activities:notify-overdue` deduplica y envía | RF-NOT-001 |
| NotifyUpcomingCommandTest.php | 5 | `activities:notify-upcoming` con ventana y dedupe | RF-NOT-001 |
| CalendarHttpTest.php | 9 | 4 vistas (month/week/day/list), crear/editar desde calendario | RF-CAL-001/002 |
| CalendarQueryTest.php | 7 | DateRange VO, calendarEvents, filtros | RF-CAL-001/002 |
| ProductCrudTest.php | 5 | Service: create con PROD-AAAA-NNNNN, deactivate | RF-PROD-001/002 |
| ProductHttpTest.php | 7 | Controller: CRUD, deactivate, export | RF-PROD-001/002 |
| QuotationMathTest.php | 5 | Cálculo de totales en servidor, snapshot de impuesto | RF-COT-003/009, ADR-005 |
| QuotationTaxSnapshotTest.php | 2 | Cambio de tasa del catálogo no afecta cotizaciones históricas | RF-COT-009, ADR-005 |
| QuotationLifecycleTest.php | 5 | draft → sent → accepted / rejected / voided, duplicate, PDF | RF-COT-002/004/006 |
| QuotationHttpTest.php | 16 | Controller: CRUD, send, accept, reject, pdf, export, scope | RF-COT-001..008/011 |
| QuotationExportScopeTest.php | 2 | **Regresión B09**: export respeta `DataScopeService` | RF-COT-011, ADR-006 |
| QuotationAcceptanceOppTest.php | 2 | Aceptación sin mutar opp, snapshot de opp_id | RF-COT-009 |
| CurrencyPerQuotationTest.php | 1 | Multimoneda por oportunidad / cotización | ADR-004 |
| DocumentHttpTest.php | 6 | Controller: upload, delete, download, 403 sin permiso, 404 binding | RF-DOC-001..005 |
| DocumentServiceTest.php | 5 | Service: validación extensión / MIME / tamaño, disco privado, auditoría | RF-DOC-002..005 |
| DashboardHttpTest.php | 8 | Controller: 12-key payload, filtros, scope | RF-DASH-001..005 |
| DashboardServiceTest.php | 6 | Service: KPIs reales, conversión por etapa, rendimiento | RF-DASH-001..005 |
| ReportHttpTest.php | 11 | Controller: 12 reportes, export, scope, multimoneda | RF-REP-001..006 |
| ReportsServiceTest.php | 13 | Service: 12 métodos, filters, group by currency | RF-REP-001/002/003 |
| ReportsExcelExportTest.php | 2 | `ArrayExport` + headings + filename | RF-REP-004 |
| AdminHttpTest.php | 11 | Controller: users, teams, roles, catalogs, settings, audit | RF-USR-005..008, RF-CFG-001..005 |
| Admin/UserServiceTest.php | 7 | Service: create con password auto, reset, setActive, recordLogin | RF-USR-001/005/006 |
| Admin/TeamServiceTest.php | 3 | Service: addMember, removeMember, setSupervisor | RF-USR-004, ADR-006 |
| Admin/RoleServiceTest.php | (no existe) | cubierto por RolesAndPermissionsTest | RF-USR-003/008 |
| Admin/CatalogServiceTest.php | 4 | Service: 8 catálogos, deactivate, activate | RF-CFG-001/002 |
| Admin/SettingsServiceTest.php | 3 | Service: get / set / all | RF-CFG-004/005 |
| Admin/AuditServiceTest.php | 5 | Service: query paginada con filtros sobre activitylog | RF-USR-007, ADR-008 |
| SecurityHardeningTest.php | 5 | **Regresión B09 H-03**: logs sin secretos, APP_DEBUG, CSRF, XSS, RBAC | RNF-SEG-001/003/004 |
| NPlusOneTest.php | 4 | **Regresión B09**: index leads / customers / opportunities / quotations bajo techo de queries | RNF-DAT-005 |
| NotificationsTest.php | 3 | Notificaciones: OpportunityAssigned, OpportunityStageChanged, ActivityAssigned | RF-NOT-001 |
| Tests\Unit\ExampleTest.php | 1 | Smoke | — |
| Tests\Feature\ExampleTest.php | 1 | Smoke | — |
| Tests\Feature\ContactTest.php | (incluido arriba) | — | — |

> Totales: 60 archivos de Feature + 1 de Unit = 61 archivos. Suma de `# tests` por archivo = 364, congruente con `php artisan test`.

## Criterio

**Ningún módulo se declara terminado sin pruebas de sus reglas principales.**

Esto significa:

1. Toda **regla de negocio** (Service) llega con su test de comportamiento (`ServiceTest`), no solo de estructura.
2. Toda **rut a HTTP** nueva se cubre en `HttpTest` con escenarios: caso feliz, permisos faltantes (403), datos inválidos (422), binding inválido (404).
3. Toda **decisión de scope** (alcance de datos por rol) tiene su test de regresión específico cuando es crítica (ej. `LeadsExportScopeTest`, `QuotationExportScopeTest`).
4. Toda **corrección de seguridad** llega con un test de regresión que evita la reintroducción (ej. `SecurityHardeningTest::test_vendedor_cannot_reach_admin_routes_or_other_vendor_resources`).
5. Una fila de la matriz `REQUISITOS.md` no pasa a `Implementado` mientras su test no esté verde.

Excepciones explícitas (y por qué son excepciones, no atajos):

- **Pruebas de UI (Blade componentes)**: la verificación visual se hace en smoke HTTP directo. La renderización completa de componentes Blade complejos se cubre indirectamente vía `HttpTest::create_form_renders`, `HttpTest::edit_form_renders`, etc.
- **Backlog de tests N+1**: `NPlusOneTest` cubre los 4 index principales; otras vistas (show, calendario, dashboard) no tienen assert de query count — quedan en `RNF-DAT-005` como `Pendiente`.

## Reglas del proyecto

1. **No se eliminan ni debilitan pruebas** para lograr verde. Un test rojo es un bug o un requisito mal entendido.
2. **No se simulan datos comerciales**: los tests usan factories y seeds de catálogos; nunca se persisten clientes / cotizaciones inventados.
3. **TDD estricto** cuando el cambio lo justifica: RED → GREEN → TRIANGULATE → REFACTOR.
4. Cada acción sensible queda en `activity_log`; los tests que la cubren usan `Spatie\Activitylog\Models\Activity::query()`, no asserts manuales sobre el array de propiedades.
5. Cada permiso nuevo en `RolesAndPermissionsSeeder` requiere un test en `RolesAndPermissionsTest` que verifique su presencia en la lista de 62.

## Próximos pasos (post-B09)

El alcance del cierre del proyecto está completo. Mejoras fuera de scope que quedarían para una futura iteración:

- **CI**: gate automático en `.github/workflows/test.yml` que ejecute `php artisan test` en PHP 8.3 con MySQL 8.0.
- **Coverage**: `phpunit --coverage-text` para cuantificar la cobertura de Statements / Branches / Paths.
- **Mutation testing**: `infection/infection` para validar que la suite realmente detecta bugs.
- **Visual regression**: `phpunit-snapshot` sobre la salida HTML de las vistas más críticas.
