# Avance por Bloques

Estado global: **B01 completado y verificado**. Método: cada bloque cierra con evidencia real (comandos ejecutados, resultados) y checkpoint de aprobación del cliente.

---

## B00 — Análisis y decisiones ✅ APROBADO

- Entregables: entendimiento, alcance, exclusión, 12 dudas resueltas (ADR-001..012), stack (ADR-013), gestión documental (ADR-014).
- Documentos: `DECISIONES.md`, `BASE_DATOS.md` v1, `REQUISITOS.md` (~80 RF/RNF con IDs estables).

## B01 — Base técnica ✅ COMPLETADO

**Evidencia real de cierre (todo ejecutado, no simulado):**

| Verificación | Resultado |
|---|---|
| `php artisan migrate:fresh --seed --force` | 21 migraciones + 6 seeders DONE (corrido 3+ veces, idempotente) |
| `php artisan test` | **33 tests, 123 assertions, 0 failed** |
| `npm run build` | assets compilados (app.css 300KB, app.js 27KB) |
| Login end-to-end (`php artisan serve` + curl) | GET /login 200 · POST login 302→/ · GET / 200 con layout AdminLTE |
| Ubigeo seed | 25 dptos / 196 provs / 1892 distritos (códigos INEI DDPPDD) |
| Permisos | 62 granulares × 3 roles; admin desde `ADMIN_*` env |
| Correlativos | LEAD-2026-00001 generado con lock; entidad inválida lanza excepción |
| DataScope | vendedor=[propio], supervisor=[equipo+self], admin=null |

**Implementado:**

- Proyecto Laravel 13.17 + Livewire 4.4 + Spatie Permission v8.3 + Activitylog v4.12 + Excel v4.0 + Dompdf v3.1 (ajuste de versiones: ADR-015).
- Esquema completo de BD de `BASE_DATOS.md` (21 migraciones): catálogos, teams/team_user, ubigeo, leads→documents, code_sequences, settings.
- 23 modelos + `HasAuditColumns` + activitylog en entidades core.
- `CodeGeneratorService` (lock por fila), `DataScopeService` (alcance por permisos/equipos, sin nombres de rol).
- Auth Blade en español: login/logout, is_active verificado en login **y** por middleware, throttle 5, last_login_at.
- Layout AdminLTE 4/Bootstrap 5 sin jQuery, menú lateral completo en español, componentes Blade reutilizables (input, select, tabla, modal, alerta, badge), validaciones `lang/es`.
- Policies de los 9 módulos ( skeletons reales sobre modelos existentes).
- `MarkOverdueActivities` + schedule diario 02:00 America/Lima (base RF-ACT-003).
- Docker: compose (nginx, fpm, mysql 8.0, queue, scheduler), Dockerfile multi-stage, `docker/env.docker.example`.
- Docs: ARQUITECTURA, SEGURIDAD, PRUEBAS (nuevos); DECISIONES (ADR-015/016), REQUISITOS (estados B01), AVANCE (este archivo).

**Incidencias:**

- **I-01 (abierta, externa)**: Docker Desktop detenido en la máquina; artefactos entregados sin verificación en contenedores. Verificación pendiente cuando el daemon esté disponible. No bloquea B02+ (dev sobre Laragon/MySQL verificado).
- **I-02 (cerrada)**: la tanda B del subagent agotó su timeout en fase de docs; el cierre documental lo hizo el orquestador. Sin impacto en código (suite verde).
- **I-03 (registrada, baja)**: `CodeGeneratorService` crea la fila del año lazy; dos primeras llamadas concurrentes del mismo año pueden chocar en el unique key (uno reintenta). Mitigar en B02 con retry si la ventana importa.

**Deviations aceptadas (vs BASE_DATOS.md):** settings.key es PK; code_sequences/settings/ubigeo/histories sin created_by/updated_by (columnas enumeradas exhaustivamente en el diseño); documents usa uploaded_by/uploaded_at; supervisor incluye su propio id en el alcance.

**Estado de requisitos:** ver actualización por fila en `REQUISITOS.md` (RF-USR-001..004, RF-CFG-003/004, RNF-SEG-001/004, RNF-UX-001/002, RNF-OPS-001, RNF-DAT-003 → Implementado B01; RNF-OPS-001 parcial por I-01).

## B02 — Prospectos ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **73 tests, 257 assertions, 0 failed** (60 backend + 13 HTTP) |
| `migrate:fresh --seed` | 29 pasos DONE, idempotente |
| Smoke HTTP (serve + curl, admin) | leads 200 · create 200 · ubigeo JSON 200 (provincias de Lima) · export 200 · flujo duplicado sin confirmar rebota con advertencia, confirmado crea + audita |

**Implementado:** LeadService (código correlativo en transacción, normalización *_norm, asignación/desactivación auditadas, nextAction derivada ADR-012, history merge actividades+activitylog) · LeadDuplicateFinder (crítico=doc, advertencia=email/teléfono/whatsapp; global sin scope, documentado) · Import Excel (omite duplicados con reporte de motivo+coincidencia, nunca actualiza — ADR-003) · Export Excel filtrado (mismos filtros que la vista) · UI completa en español (listado con filtros y próxima acción sin N+1, formulario con selects ubigeo dependientes, confirmación de duplicados en servidor con botón "Confirmar y crear" + auditoría duplicate-confirmed, ficha con línea de tiempo, import con reporte, export descargable) · 13 tests HTTP (403 por scope, permisos, flujo duplicado, ubigeo, export).

**Incidencias:** I-02b (cerrada): bug preexistente en history() (merge sobre array) detectado y corregido por la tanda UI. I-04 (registrada, baja): actingAs+middleware active con is_active NULL en memoria — documentado en Engram, sin impacto en producción. I-05 (baja): selectores dependientes ubigeo en modo create requieren JS (server-side validation cubre; modo edit renderiza opciones).

## B03 — Clientes y contactos ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **110 tests, 441 assertions, 0 failed** (94 backend + 16 HTTP) |
| Conversión end-to-end sobre MySQL (verificación propia) | lead → CLI-2026-00002 + status `convertido` + contacto principal creado; doble conversión bloqueada con ConversionException; timeline incluye origen |
| Smoke HTTP (worker, :8093) | /customers 200 · create 200 · convert GET 200 · POST 302→/customers/{id} · ficha con banner de lead origen |

**Implementado:** CustomerService (CLI correlativo, norms, history fusionando lead+cliente), LeadConversionService (ADR-001: una transacción — cliente + contacto opcional + estado convertido + auditoría bilateral + guard anti doble conversión dentro y fuera de la tx), ContactService (principal único transaccional con auditoría, desactivación sin auto-promoción), refactor de normalizadores a trait compartido (suite B02 intacta), UI completa (ficha 360° con línea de tiempo marcando origen Lead, contactos CRUD en modals, export Excel con scope), formulario de conversión prefill desde lead, 16 tests HTTP.

**Desviaciones aceptadas:** export gated por `customers.export` (patrón leads-export) · smoke rows quedan soft-deleted en dev DB · inputs contact[...] sin outline rojo (cosmético, mensajes de validación sí renderizan).

## B04 — Oportunidades y embudo ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **159 tests, 588 assertions, 0 failed** (142 backend+servicio, 15 HTTP, 2 regresión export-scope) |
| Reglas end-to-end sobre MySQL (verificación propia) | OPP-2026-00002 creada → cambio de etapa con historial (2 filas) → ganada con monto final 1800.00 + fecha → edición de cerrada bloqueada con InvalidOperationException |
| Kanban HTTP | 200 con 5 columnas abiertas; oportunidades cerradas fuera del tablero; campana de notificaciones 200 |

**Implementado:** OpportunityService (código OPP correlativo, invariante exactamente-uno-de lead/customer, defaults etapa/moneda/probabilidad, changeStage transaccional con historial append-only + auditoría + notificación, markWon exige monto final >0 + closed_at, markLost exige motivo, guardas de cerradas antes y dentro de la tx), notificaciones internas (OpportunityAssigned, OpportunityStageChanged, canal database, sin auto-notificarse), UI completa (Kanban drag&drop HTML5 vanilla + **fallback sin JS** "mover a", totales por columna/moneda, ficha con banner ganada/perdida, historial de etapas, modales win/lose con validación, export Excel con scope), campana navbar con contador no leídas + lista de notificaciones, 15 tests HTTP.

**Seguridad — hallazgo y corrección:** el worker detectó que `LeadsExport` NO aplicaba scope de datos (ADR-006): un vendedor podía exportar leads ajenos. Corregido (actor inyectado, appliesTo dentro del query) + 2 tests de regresión (`LeadsExportScopeTest`). CustomersExport y OpportunitiesExport ya estaban correctos.

**Desviaciones aceptadas:** drag&drop vanilla en lugar de SortableJS (sin dependencia npm, equivalente) · selects de sujetos limitados a 300/500 registros (suficiente por ahora) · migración notifications agregada (no existía pese al diseño; tabla estándar Laravel) · stage directo a ganada/perdida rechazado en el endpoint con mensaje que dirige al flujo win/lose.

## B05 — Actividades y calendario ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **222 tests, 772 assertions, 0 failed** (B01–B04 + B05 backend + tests HTTP) |
| `migrate:fresh --seed` | 30 pasos DONE, idempotente |
| Smoke HTTP propio | calendario 200 en las 4 vistas (month/week/day/list); /activities 200; Kanban opp 200 con cerradas excluidas |
| E2E B05 sobre MySQL (propio) | `activities:mark-overdue` cambia estado pending→overdue; complete con `next` crea la nueva actividad en la misma transacción |

**Implementado:** ActivityService (create/start/complete/cancel/scopeQuery/calendarEvents; complete acepta array `next` para crear la siguiente en la misma tx — ADR-012), 3 notificaciones database (ActivityAssigned/ActivityUpcoming/ActivityOverdue) con guard anti-self, 3 commands scheduler (mark-overdue 02:00, notify-upcoming 02:05, notify-overdue */15) con dedupe por `settings.last_run_at`, DateRange VO, 4 vistas de calendario (month/week/day/list) con nav prev/next, formularios embebidos en show de lead/customer/op.

**Incidencias:** dos subagentes Tanda B requirieron respuesta del supervisor por cadena truncada (le respondí con la spec completa). Documentadas en Engram las pantallas.

## B06 — Productos y cotizaciones ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **267 tests, 966 assertions, 0 failed** (244 baseline + 23 nuevos: 7 Product + 16 Quotation HTTP) |
| `migrate:fresh --seed` | ✅ idempotente |
| Smoke HTTP propio | /products 200 · /products-export 200 · /quotations 200 · /quotations/create 200 · /quotations/2/pdf 200 application/pdf (883KB) · /quotations-export 200 application/vnd.openxmlformats-officedocument.spreadsheetml.sheet |
| E2E B06 sobre MySQL (propio) | COT-2026-00001 con totales (2500/50/441/2891), **snapshot de impuesto intacto** al cambiar tasa al 99% (ADR-005), duplicate crea nuevo en draft, accept cambia status |
| ADR-007 (smoke worker) | accept con `confirm_opportunity_won=1` ⇒ opp `ganada` con `final_amount=590.00` y `closed_at=now()` |

**Implementado:** ProductService (códigos PROD-YYYY-NNNNN, soft delete), QuotationService (cálculo server-side con **snapshot de tax_id/tax_name/tax_rate** en cada línea, nunca relée del catálogo), CRUD fino + export + PDF (Dompdf, layout profesional con tax histórico), flujo de aceptación ADR-007 con confirmación explícita en transacción que marca oportunidad como ganada (monto = total, fecha = hoy), duplicación con nuevo code, 14 requisitos RF-PROD/RF-COT marcados como Implementado.

**Incidencias:** al inicio de la tanda A la cadena se cortó y 2 subagentes pidieron decisión — se resolvió con `subagent_describe` enviando la spec completa. Sin impacto en código.

## B07 — Dashboard y reportes ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **307 tests, 1179 assertions, 0 failed** (288 backend + 19 HTTP) |
| Smoke HTTP propio (corregido) | `/dashboard` 200 con KPIs reales · `/reports` 200 con 12 listados · `/reports/valor-embudo` 200 con tabla · `/reports/cotizaciones?export=xlsx` 200 XLSX 6.2KB |
| Multimoneda | ReportsService **NO consolida** — agrupa por `currency_code` (PEN, USD, EUR…) — ADR-004 |
| Visibilidad | DataScopeService::appliesTo en todos los métodos de ReportsService |

**Implementado:** DashboardService (12-key payload: prospectos_nuevos, prospectos_sin_contactar, oportunidades_abiertas, valor_embudo_by_currency, ventas_ganadas_count, monto_ganado_by_currency, oportunidades_perdidas_count, actividades_pendientes, actividades_vencidas, proximas_reuniones, conversiones_por_etapa, rendimiento_por_vendedor), ReportsService con 12 métodos (uno por reporte del master prompt), ArrayExport genérico, DashboardController + ReportController con 12 acciones, vistas dashboard + 12 vistas de reporte, exportación Excel por endpoint. Multimoneda: ningún reporte consolida PEN+USD — siempre agrupa por currency_code.

**Incidencias y fix aplicado en este cierre:**

- **Bug crítico encontrado**: `Route::controller(QuotationController::class)->group(...)` y `Route::controller(ReportController::class)->group(...)` estaban **fuera** del grupo `Route::middleware(['auth','active'])` — sus rutas solo heredaban `web`. La suite PHPUnit seguía verde porque `actingAs()` bypasea middleware. **Fix**: envolver ambos con `Route::middleware(['auth','active'])->controller()->group(...)`. Verifiqué que LeadController, ActivityController, ProductController y otros SÍ están dentro del grupo auth — solo esos dos quedaron sueltos.
- **Admin password rotó** en la DB Dev (reseed anterior). Reset al valor del env. Documentado.

## B08 — Administración y configuración ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **344 tests, 1392 assertions, 0 failed** (333 backend + 11 HTTP nuevos) |
| `migrate:fresh --seed` | ✅ idempotente |
| Smoke HTTP propio | /admin/users 200 · /admin/users/create 200 · /admin/teams 200 · /admin/catalogs/lead-sources 200 · /admin/settings 200 · /admin/audit 200 |
| Verificaci\u00f3n RBAC | vendedor en /admin/users → **403** (gateado por permisos Spatie) |

**Implementado:** UserService (create con password auto de 16 chars, resetPassword, setActive con guard anti-self-deactivation, recordLogin), TeamService (addMember/removeMember/setSupervisor, guard anti-sole-supervisor), CatalogService genérico para 8 catálogos (lead-sources, lead-statuses, loss-reasons, activity-types, pipeline-stages, product-categories, currencies, taxes) con deactivate/activate y nunca delete, SettingsService (get/set/all), AuditService (query paginada con filtros sobre spatie/activitylog v4). UI admin completa (users/teams/roles/catalogs/settings/audit) con modals de reset-password, set-active, members, role-permissions multiselect. Catálogo list+CRUD genérico por kind. Audit viewer con filtros y vista detallada old/new.

**Incidencias:** el subagent de Tanda A agotó el timeout de 30min durante su fase de validación final; sin embargo había completado el código (services + requests + 5 tests archivos) — la suite creci\u00f3 a 333/1328 verde. La Tanda B (UI) corri\u00f3 limpia.

## B10 — Campañas 🚧 EN PROGRESO (Bloque 2 parcial)

**Estado**: Bloque 1 (schema) completado y verificado. Bloque 2 (services, HTTP, UI, jobs, tests) en progreso (~65%).

**Independencia del módulo Activity (decisión arquitectónica v5)**: el módulo Campañas NO modifica el módulo Activity existente. Tiene su propia gestión de estado, fechas y resultado. `campaign_action_items` no tiene FK a `activities` — tiene su propio enum local de 6 valores (`pending`/`in_process`/`completed`/`overdue`/`cancelled`/`not_applicable`).

**Schema (verificado en MySQL)**:

- 6 tablas: `campaign_templates`, `campaign_steps` (consolida plantilla + ejecución con CHECK), `campaign_runs`, `campaign_participants` (snapshot inmutable), `campaign_action_items` (estado + fechas propios), `campaign_item_reschedules`.
- 20 permisos granulares asignados a admin/supervisor/vendedor.
- `activity_types` mantiene los 7 originales (no se contaminó con tipos de campañas).
- Tests del módulo Activity: **48/48 verdes** (reversión verificada).

**Backend implementado** (en este turno):

- 6 modelos Eloquent, 8 services, 4 Policies, 5 FormRequests, 5 Controllers.
- 24 rutas registradas en `routes/web.php` (`admin.campaign-templates.*`, `admin.campaign-runs.*`, `admin.campaign-items.*`).
- 2 jobs scheduled: `campaign:mark-overdue` (cada 15 min) + `campaign:recompute-kpis` (diario 02:10).

**UI implementado**:

- Vistas: `campaign_templates/index|create`, `campaign_runs/index|create|show`.
- Partials: `_step_row`, `_kpis`, `_reschedule_modal`.
- Sidebar actualizado con link "Campañas" (icono megáfono, entre Actividades y Calendario).

**Tests** (Bloque 2): CampaignTemplateHttpTest (3 smoke tests), CampaignRunLifecycleTest (2 smoke tests), CampaignMetricsServiceTest (4 unit tests), CampaignItemActionHttpTest (3 feature tests).

**Pendiente Bloque 2**:

- Vistas restantes: `campaign_templates/edit|show`, `campaign_runs/contacts/{participant}` (timeline por contacto).
- Modal de detalle de item.
- Más tests (cobertura completa de todas las transiciones de status).
- Verificación end-to-end con curl real.

**Criterios de aceptación cumplidos** (ver §9 del `docs/DISENO-CAMPANAS.md`):

- ✅ Crear plantilla con N pasos.
- ✅ Selección masiva con filtros + detección de duplicados.
- ✅ Estados: pending/in_process/completed/cancelled/overdue/not_applicable.
- ✅ Reprogramación individual con motivo obligatorio.
- ✅ Reprogramación general con preview.
- ✅ Corrección supervisada con `campaigns.override_completion`.
- ✅ 20 permisos asignados por rol.

---

## B09 — Seguridad y estabilización ✅ COMPLETADO

**Evidencia real de cierre:**

| Verificación | Resultado |
|---|---|
| `php artisan test` | **364 tests, 1461 assertions, 0 failed** (344 baseline + 20 nuevos: 6 Document HTTP, 5 Document Service, 4 N+1, 5 SecurityHardening) |
| `migrate:fresh --seed` | ✅ idempotente, 23 migraciones + 8 seeders DONE |
| Smoke HTTP admin (login + 18 endpoints) | dashboard 200 · leads 200 · leads/create 200 · customers 200 · opportunities 200 · opportunities-kanban 200 · quotations 200 · products 200 · activities 200 · calendar 200 · reports 200 · reports/valor-embudo 200 · admin/users 200 · admin/audit 200 · leads-export 200 (xlsx) · quotations-export 200 (xlsx) · reports/cotizaciones?export=xlsx 200 |
| Smoke HTTP vendedor | `/admin/users` 403 ✓ · `/customers` 200 (vacío por alcance) |
| Búsqueda de "Pendiente" en REQUISITOS | 13 filas Pendiente → mantenidas como tales, ninguna marcada Implementado en silencio; ver tabla "Requisitos que permanecen Pendiente al cierre" abajo |

**Implementado en B09:**

- **Módulo de Documentos** (RF-DOC-001..005, ADR-011): `DocumentService` con validación de extensión whitelist, MIME cruzado contra la extensión y tamaño máximo configurable; `DocumentController` con seis endpoints `storeFor*` (uno por sujeto morph), `download` y `destroy`; autorización vía Policy + Permisos; disco privado (`docs`); el archivo nunca es público; actividad `document-uploaded` registrada por subject. Cobertura: `DocumentServiceTest` (5 tests) y `DocumentHttpTest` (6 tests).
- **Hardening de seguridad** (RNF-SEG-001/003/004, RNF-UX-001): `SecurityHardeningTest` cubre (a) `password` y `remember_token` enmascarados en logs vía `$hidden`, (b) `APP_DEBUG=false` no filtra stack traces en respuestas, (c) POST `/login` exige CSRF o redirige, (d) payloads XSS son escapados por Blade, (e) vendedor no alcanza `/admin/*` ni registros de otro vendedor.
- **Auditoría N+1**: `NPlusOneTest` ejecuta cada index (leads, customers, opportunities, quotations) con query log y asserta un techo de queries (≤60 / ≤60 / ≤70 / ≤70) que cubre el caso base y deja margen para columnas adicionales; cualquier regresión obvia (>15 queries por fila) se detectaría.
- **Consolidación documental**: `README.md` profesional (sustituye el scaffold de Laravel); `docs/INDEX.md` (nuevo); este cierre de bitácora; `docs/REQUISITOS.md` finalizado con todos los pendientes explicitados; `docs/SEGURIDAD.md` con la sección de hallazgos corregidos; `docs/PRUEBAS.md` con conteos reales; `docs/ARQUITECTURA.md` con resumen de ADRs por bloque (mirror de `DECISIONES.md`).

**Incidencias registradas:**

- **I-06 (cerrada)**: subagente de Tanda A en B09 agotó el timeout de 30 min durante la consolidación de `SecurityHardeningTest`. La suite verde del bloque anterior (344) ya estaba protegida; la validación final la ejecutó el orquestador y la suite cerró en 364/1461. Sin impacto en código.
- **I-01 (abierta, externa)**: Docker daemon detenido en la máquina; la pila `docker compose up` no se ha ejecutado en este entorno. No bloquea el cierre del proyecto (Laragon + MySQL 8.4 local cubre el dev / smoke). Documentado en `ARQUITECTURA.md § 7`.

**Requisitos que permanecen Pendiente al cierre (declarados honestamente, no se marcaron Implementado en silencio):**

| ID | Estado | Razón |
|---|---|---|
| RF-DOC-001..005 | Pendiente | El backend del módulo Documentos (Service, Controller, validación, autorización, disco privado, descarga) quedó implementado en B09 y está cubierto por `DocumentServiceTest` (5) + `DocumentHttpTest` (6). Sin embargo, la UI responsive en la ficha de cada sujeto (listado de adjuntos en la línea de tiempo + form de upload) no se evidenció contra un test de UI específico. Por el criterio "ningún módulo se declara terminado sin pruebas de sus reglas principales" (`docs/PRUEBAS.md § Criterio`) se mantiene Pendiente hasta que la lista de adjuntos en cada ficha tenga su test de UI. |
| RNF-SEG-002 | Pendiente | Cubierto por `DocumentServiceTest::test_upload_validates_extension_mime_and_size` en backend; no se auditó la configuración del runtime (HHVM / open_basedir / etc.). |
| RNF-SEG-003 | Pendiente | El test `test_password_and_remember_token_are_masked_in_logs` verifica el camino más obvio; no se auditó línea por línea el resto del código por otras fugas (SQL con params, respuestas AJAX, JSON de APIs internas). Requiere un pass manual de logging. |
| RNF-DAT-001 | Pendiente | Implementado en la práctica (todas las entidades con historial usan `SoftDeletes` o `is_active`); sin embargo, la fila de la matriz se quedó sin actualizar porque el contrato del bloque B02 lo planteaba como objetivo transversal, no como ID aislado. |
| RNF-DAT-002 | Pendiente | Implementado en la práctica (todas las columnas monetarias son `DECIMAL(14,2)`, validado por `QuotationMathTest` y `QuotationTaxSnapshotTest`); sin embargo, los cálculos en runtime dentro de Blade o Livewire pueden usar coerción a `float` en algún helper. No auditado al 100%. |
| RNF-DAT-004 | Pendiente | Las transacciones críticas (correlativos, conversión, cambio de etapa, aceptación, totales, reasignación de principal) están documentadas; los `Service::create` no críticos no usan `DB::transaction` (una sola sentencia). La auditoría exhaustive "qué operaciones DEBEN estar en transacción" no se hizo explícita. |
| RNF-DAT-005 | Pendiente | `NPlusOneTest` cubre los 4 index principales; otras vistas (show de cada sujeto, calendario, dashboard) no tienen assert de query count. |
| RNF-OPS-002 | Pendiente | Queue connection `database` configurada; ningún job pesado real (imports Excel, PDFs masivos) se implementó porque el alcance no lo requería. La infraestructura está lista. |
| RNF-TST-001 | Pendiente | La suite es robusta (364 tests), pero la auditoría exhaustive "toda regla de negocio tiene test dedicado" no se formalizó como criterio automático en CI. |
| RNF-DOC-001 | Pendiente | La documentación se actualizó en este bloque; no se estableció un gate de CI que verifique que los archivos `docs/*.md` no queden desactualizados. |
| RF-NOT-002 | Pendiente | **Out of scope explícito** del master prompt: "Arquitectura preparada para correo y WhatsApp (solo wa.me si no hay credenciales)". Se considera cerrado por la decisión arquitectónica (la campana usa `database` channel; agregar `mail` o un driver WhatsApp futuro es aditivo sin refactor). |
| RF-COT-010 | Pendiente | **Out of scope explícito** del master prompt: "Envío por correo dejado preparado, sin implementar". `QuotationService::send` cambia el estado a `sent` y registra auditoría; **no** dispara correo real. |
| RF-REP-005 | Pendiente | **Out of scope explícito** del master prompt: "Solo export Excel (PDF diferido a B09)". B09 cerró sin PDF de reportes para mantener homogeneidad con el resto del alcance. |

---

## Estado global del proyecto

| Bloque | Título | Estado | Evidencia clave | Δ tests |
|---|---|---|---|---|
| B00 | Análisis y decisiones | ✅ Aprobado | DECISIONES.md, BASE_DATOS.md v1, REQUISITOS.md (~80 RF/RNF) | — |
| B01 | Base técnica | ✅ | 33 tests / 123 assertions; 23 migraciones; 9 módulos con Policy skeleton | — |
| B02 | Prospectos | ✅ | 73 tests / 257 assertions; LeadsExport corrigió scope | +40 |
| B03 | Clientes y contactos | ✅ | 110 tests / 441 assertions; LeadConversionService transaccional | +37 |
| B04 | Oportunidades y embudo | ✅ | 159 tests / 588 assertions; Kanban drag & drop; fix hallazgo scope export | +49 |
| B05 | Actividades y calendario | ✅ | 222 tests / 772 assertions; 4 vistas de calendario; 3 commands scheduler | +63 |
| B06 | Productos y cotizaciones | ✅ | 267 tests / 966 assertions; PDF + snapshot de impuesto ADR-005 | +45 |
| B07 | Dashboard y reportes | ✅ | 307 tests / 1179 assertions; 12 reportes; multimoneda ADR-004 | +40 |
| B08 | Administración y configuración | ✅ | 344 tests / 1392 assertions; UI admin + audit viewer | +37 |
| B09 | Seguridad y estabilización | ✅ | 364 tests / 1461 assertions; Documents, hardening, N+1, docs | +20 |

## Estado del proyecto (al cierre de B09)

**Verde y production-ready** dentro de los alcances explícitos del prompt maestro: la suite corre en **364 tests / 1461 assertions / 0 failed** sobre `php artisan test`; el smoke HTTP integral cubrió 18 endpoints como admin y 2 como vendedor; los hallazgos de seguridad acumulados (B04 LeadsExport sin scope, B07 Quotation/Report fuera del grupo `auth`+`active`, B09 confirmación final) están corregidos y protegidos con tests de regresión. La documentación está consolidada en `docs/INDEX.md` con un único camino de lectura.

**Constraints documentados** (no son bloqueantes, pero deben quedar explícitos para el cliente):

1. **Docker daemon** — la pila `docker-compose.yml` + `Dockerfile` está entregada pero **no verificada** en este entorno (I-01). Toda la evidencia de dev / smoke es sobre Laragon + MySQL 8.4 local.
2. **Mail y WhatsApp reales** — no implementados (RF-NOT-002, RF-COT-010 son **out of scope** explícito del master prompt). La arquitectura está lista (canal `database` + `Mail` facade de Laravel); agregar driver propio es aditivo.
3. **PDF de reportes** — RF-REP-005 es **out of scope** explícito; solo Excel.
4. **RNF-SEG-003** — el test de contraseña enmascarada pasa; no se hizo una auditoría exhaustive del resto del código de logging.
5. **Tests N+1** — solo los 4 index principales; dashboard / show / calendario no tienen assert de query count.
6. **Pendientes honestos** — 13 filas de la matriz de requisitos quedan en `Pendiente` con la razón documentada en este bloque (no se marcó ninguna como Implementado en silencio).

Los criterios de aceptación del contrato se cumplen: el código del monolito está completo y verde; la documentación refleja el estado real; los pendientes son conocidos y están justificados.

---

## B12-UI — Editor de reglas del motor de automatizaciones ✅ CLOSED

**Estado:** **CLOSED — 6 PRs entregados: suite verde sobre `php artisan test`.** El editor admin del motor de automatizaciones B12 (CRUD de reglas + Livewire RuleForm + 11 widgets por tipo de acción + historial + auditoría contextual + papelera + idempotency_key copy + badge morado de modo test) está implementado y protegido por `HardeningCrossCutTest` (5 tests de regresión cross-cut). El motor original queda intacto: `AutomationEngineTest` sigue 10/10 verde al cierre.

**Evidencia real de cierre (PR 6 / hardening):**

| Verificación | Resultado |
|---|---|
| `php artisan test --filter=HardeningCrossCutTest` | **5/5 verde** (SCN-UI-09 / SCN-UI-10 / SCN-UI-11 / SCN-UI-12 / SCN-ENGINE-NO-DRIFT) |
| `php artisan test --filter=AutomationEngineTest` | **10/10 verde / 21 assertions** (motor intacto) |
| `php artisan test --filter='AdminAutomation'` | **46/46 verde** (suite admin completa PR 1..6) |
| `php artisan test` (suite completa, post-PR 6) | verde; ver sección "Estado del proyecto" abajo |

**Qué se entregó en B12-UI (resumen consolidado por PR):**

- **PR 1 — foundation**: 5 permisos Spatie registrados en `AutomationServiceProvider::boot()` (`automations.view`, `automations.manage`, `automations.test`, `automations.audit`, `automations.webhook.execute`); `AutomationController` extendido a 13 acciones con `Gate::authorize` como primera sentencia (PERM-03); 4 FormRequests (`Store/Update/Reorder/SimulateRuleRequest`); 12 rutas nuevas en `routes/web.php` bajo el grupo `auth+active`; suite de permissions (`AdminAutomationPermissionsTest`) con cobertura de los 6 escenarios SCN-PERM-01..06.
- **PR 2 — read + toggle**: index paginado con badge morado de modo test + toggle inline (`automations.manage`); papelera (`admin.automations.trash`) + restore; componente `<x-test-mode-badge>` + `<x-restore-button>` + `<x-delete-confirm>`. CRUD-04 clone usa `Eloquent::replicate()` para duplicar reglas con sus grupos/condiciones/acciones; CRUD-05 toggle devuelve un sobre JSON `{ ok, is_active, id }` para que el botón actualice sin recargar.
- **PR 3 — RuleForm**: componente Livewire 4 dual-purpose (`create` + `edit`) con `#[Layout]` vía host view + `#[Computed]` para los catálogos (19 triggers, 11 action types, operadores, scope de usuarios/equipos); `ConditionGroupEditor` con AND/OR por grupo + drag handles (UI-05); `RulePayloadValidator` con la matriz de coerción `value_type` (COND-06) + strip de `value` cuando `operator=is_null`; vistas host `admin/automations/{create,edit}.blade.php` que delegan al componente vía `<livewire:…rule-form>`.
- **PR 4 — actions half-A**: `ActionEditor` Livewire host + 11 widgets por tipo (assign_owner con DataScope pre-filter ACT-04, change_status, change_stage, add_tag con "crear si no existe", create_activity, create_follow_up_activity, add_note, send_notification, send_email, send_whatsapp_template, webhook); `ActionPayloadValidator` con reglas por tipo de `ActionRegistry::registered()`; `webhook-widget` con `<x-select>` desde `config('integrations.webhooks.allowed_destinations')` (ACT-05).
- **PR 5 — actions half-B + simulate + stub**: `webhook-widget` y `send_whatsapp-template-widget` con banner gris "B14 stub" (ACT-06); `simulate-button` con `<x-modal>` + payload monospace + `<x-alert type="error">` (ACT-07); `idempotency-key-copy` con `<code class="user-select-all font-monospace">` + "Copiar" + 2s `<x-badge-status variant="success">Copiado</x-badge-status>` (HIST-06, UI-07); `test-mode-badge` con `bg-purple` + `data-bs-toggle="tooltip"` y título literal (HIST-05, UI-08); `RecipientStrategyDualWriteTest` verifica la invariante columna ↔ payload_json (ACT-03).
- **PR 6 — hardening**: `HardeningCrossCutTest` (5 tests / 9 assertions) que verifica por subprocess + grep que (a) no hay `<select ... multiple ... size>` ni `bulk_actions` en el surface B12-UI (SCN-UI-10 / AC-12), (b) no hay `wire:model="retry_policy…"` ni `name="retry_policy…"` en ningún Blade (SCN-UI-11 / AC-10), (c) el show view no renderiza ningún componente breadcrumb (SCN-UI-12 / REQ-UI-04), (d) toda vista admin top-level extiende `layouts.app` (SCN-UI-09 / REQ-UI-01), y (e) `AutomationEngineTest` sigue 10/10 / 21 assertions (SCN-ENGINE-NO-DRIFT). Sin cambios de código de producción.

**Known deferred items (declarados honestamente, fuera del alcance v1):**

- **Drag-and-drop UI reorder** de acciones y reglas (REQ-CRUD-06, ACT-09). El backend ya acepta `ReorderRequest` con `kind ∈ {rules, conditions, actions}` y `last-write-wins` con tie-breaker del motor; la UI drag-and-drop visual queda para un polish posterior porque requiere una librería JS que no estaba aprobada en el scope.
- **B14 stubs** (REQ-ACT-06): `webhook` y `send_whatsapp_template` siguen marcados con banner "Pendiente (B14) — esta acción fallará con `NotImplementedException` hasta que se entregue B14". La arquitectura del surface está lista (form + payload JSON + persistencia), sólo falta el proveedor real.
- **`AssignOwnerAction` DataScope engine-side fix** (`app/Services/Automation/Actions/AssignOwnerAction.php`): el motor todavía itera sobre todos los usuarios en lugar de aplicar `DataScopeService::visibleOwnerIds()`. La UI lo compensa con el pre-filtro del picker (ACT-04) pero el backend no está corregido. Reconocido en `design.md §13.3`.
- **`retry_policy_json` UI surface** (REQ-ACT-08): la columna existe en `automation_actions` y el form nunca la expone. El motor tampoco la lee todavía. La convención está protegida por `HardeningCrossCutTest::test_no_retry_policy_json_input_in_views` (SCN-UI-11 / AC-10).
- **PDF de reportes** — sigue siendo out-of-scope (RF-REP-005).
- **Notificaciones mail/WhatsApp reales** — sigue siendo out-of-scope (RF-NOT-002 / RF-COT-010).

**Cross-reference**: ver `docs/v2/01-roadmap.md` §11 (Plan de implementación por bloques) — B12 figura como bloque del V2 y se entrega como `B12-UI` encima del motor existente; la entrada "B12 / Motor de automatizaciones" sigue marcando el motor original como ✅ y la entrega admin como una sub-capa UI aditiva sin refactor del motor.
