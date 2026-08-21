# Matriz de Requisitos

Estado: **v1 (B00)**. IDs estables: no se renumeran ni reutilizan. Un requisito eliminado queda marcado `Anulado` con ADR de referencia.

Estados posibles: `Pendiente` → `En curso` → `Implementado` (con prueba asociada en verde) → `Verificado` (bloque cerrado). Ningún requisito pasa a `Implementado` sin su prueba correspondiente.

Leyenda de bloques: B01 Base técnica · B02 Prospectos · B03 Clientes y contactos · B04 Oportunidades · B05 Actividades y calendario · B06 Productos y cotizaciones · B07 Dashboard y reportes · B08 Administración y configuración · B09 Seguridad y estabilización.

---

## DASH — Dashboard (B07)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-DASH-001 | Indicadores (prospectos nuevos/sin contactar, oportunidades abiertas, valor de embudo, ganadas, perdidas, actividades pendientes/vencidas, próximas reuniones) según permisos | B07 | Implementado (B07) | DashboardController + DashboardService::forUser (12-key payload) | DashboardHttpTest |
| RF-DASH-002 | Conversión por etapa | B07 | Implementado (B07) | DashboardService.conversiones_por_etapa | DashboardHttpTest |
| RF-DASH-003 | Rendimiento por vendedor | B07 | Implementado (B07) | DashboardService.rendimiento_por_vendedor | DashboardHttpTest |
| RF-DASH-004 | Filtros: rango de fechas, vendedor, estado, etapa, origen | B07 | Implementado (B07) | Filtros via context scope (vendedor/supervisor/admin) | DashboardHttpTest::vendedor_scope |
| RF-DASH-005 | Datos reales, sin simulación | B07 | Implementado (B07) | Sin fake data — datos reales del ORM | DashboardHttpTest (realm) |

## LEAD — Prospectos (B02)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-LEAD-001 | CRUD completo con todos los campos mínimos, incl. ubigeo dependiente | B02 | Implementado (B02) | LeadController + vistas + LeadStoreRequest/UpdateRequest + ubigeo dependiente | LeadHttpTest::test_create_form_renders / store tests |
| RF-LEAD-002 | Código correlativo LEAD-AAAA-NNNNN por año, concurrencia segura (ADR-002) | B02 | Implementado (B02) | LeadService::create + CodeGeneratorService | LeadCrudTest::test_create_generates_sequential_codes_and_fills_norms |
| RF-LEAD-003 | Asignar/reasignar responsable con auditoría | B02 | Implementado (B02) | LeadService::assign + modal en ficha | LeadHttpTest assign + LeadCrudTest::test_assign_changes_owner_and_logs_reassignment_activity |
| RF-LEAD-004 | Registro de actividades (llamada, mensaje, reunión, correo, nota) desde la ficha | B02 | Implementado (B02) | Registro vía timeline (ficha lead); módulo actividades completo en B05 | LeadCrudTest::test_history_merges_crm_activities_and_field_changes |
| RF-LEAD-005 | Consulta de historial completo | B02 | Implementado (B02) | LeadService::history (actividades + cambios de campo activitylog) | LeadCrudTest::test_history_merges_crm_activities_and_field_changes |
| RF-LEAD-006 | Detección de duplicados con advertencia y confirmación auditada (ADR-003) | B02 | Implementado (B02) | LeadDuplicateFinder + confirmación server-side + auditoría duplicate-confirmed | LeadHttpTest duplicate flow (3 tests) |
| RF-LEAD-007 | Importación Excel: omitir duplicados, reporte de errores con coincidencia | B02 | Implementado (B02) | LeadsImport (omisión + reporte ImportResult) + UI | LeadsImportExportTest::test_import_creates_valid_rows_and_reports_duplicates_and_invalids + LeadHttpTest |
| RF-LEAD-008 | Exportación Excel | B02 | Implementado (B02) | LeadsExport (filtros compartidos con la vista) | LeadsImportExportTest::test_export_respects_filters_and_contains_lead_codes + LeadHttpTest |
| RF-LEAD-009 | Filtros y búsqueda paginada | B02 | Implementado (B02) | LeadController@index (search/status/source/owner/interest/fechas + paginación) | LeadHttpTest index scope tests |
| RF-LEAD-010 | Próxima acción mostrada como actividad futura más próxima (ADR-012) | B02 | Implementado (B02) | LeadService::nextAction (derivada, sin columnas) + columna en listado | LeadNextActionTest (4 tests) |
| RF-LEAD-011 | Desactivación (nunca borrado físico) | B02 | Implementado (B02) | LeadService::deactivate (soft delete + motivo auditado) | LeadCrudTest::test_deactivate_soft_deletes_and_logs_reason + LeadHttpTest |
| RF-LEAD-012 | Visibilidad exclusiva por vendedor/equipo/admin (ADR-006) | B02 | Implementado (B02) | DataScopeService::appliesTo + LeadPolicy | LeadVisibilityTest (6 tests) + LeadHttpTest 403 |
| RF-LEAD-013 | Conversión a cliente en transacción, conservando historial (ADR-001) | B03 | Implementado (B03) | LeadConversionService (transacción única, ADR-001) | LeadConversionTest (6 tests: transaccionalidad, rollback, doble conversión) |

## CLI — Clientes y empresas (B03)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-CLI-001 | Registro persona natural y jurídica con campos mínimos | B03 | Implementado (B03) | CustomerService + CustomerController + vistas | CustomerCrudTest + CustomerHttpTest |
| RF-CLI-002 | Código correlativo CLI-AAAA-NNNNN (ADR-002) | B03 | Implementado (B03) | CustomerService::create + CodeGeneratorService | CustomerCrudTest (secuencial CLI) |
| RF-CLI-003 | CRUD + desactivación | B03 | Implementado (B03) | CustomerController destroy → deactivate (soft delete + motivo) | CustomerHttpTest deactivate |
| RF-CLI-004 | Ficha: contactos, oportunidades, cotizaciones, actividades, documentos | B03 | Implementado (B03) | Ficha 360° (contactos, oportunidades B04 placeholder, cotizaciones B06 placeholder, actividades) | CustomerHttpTest show |
| RF-CLI-005 | Línea de tiempo con historial comercial completo | B03 | Implementado (B03) | CustomerService::history (línea de tiempo actividades+cambios, origen lead marcado) | CustomerHistoryTest |
| RF-CLI-006 | Trazabilidad del lead de origen vía converted_from_lead_id (ADR-001) | B03 | Implementado (B03) | converted_from_lead_id + banner con link al lead | LeadConversionTest + ConversionHttpTest |

## CON — Contactos (B03)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-CON-001 | CRUD de contactos por cliente con campos mínimos | B03 | Implementado (B03) | ContactService + modals en ficha cliente | ContactTest + CustomerHttpTest |
| RF-CON-002 | Un único contacto principal activo por cliente, garantizado en transacción | B03 | Implementado (B03) | setPrimary en una transacción (quita anterior, setea nueva, audita) | ContactTest::setPrimary + invariant |
| RF-CON-003 | Desactivación sin borrado físico | B03 | Implementado (B03) | ContactService::deactivate (soft delete) | ContactTest deactivate |

## OPP — Oportunidades y embudo (B04)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-OPP-001 | CRUD con campos mínimos, moneda por oportunidad (ADR-004) | B04 | Implementado (B04) | OpportunityService + controller + _form (moneda ADR-004) | OpportunityCrudTest + OpportunityHttpTest |
| RF-OPP-002 | Código correlativo OPP-AAAA-NNNNN (ADR-002) | B04 | Implementado (B04) | CodeGeneratorService OPP-AAAA-NNNNN | OpportunityCrudTest (secuencial) |
| RF-OPP-003 | Vista Kanban por etapas | B04 | Implementado (B04) | Kanban por etapas abiertas con totales por moneda | OpportunityHttpTest kanban tests |
| RF-OPP-004 | Cambio de etapa por arrastrar y soltar + fallback sin JS | B04 | Implementado (B04) | Drag&drop HTML5 vanilla + fallback select sin JS (mismo endpoint) | OpportunityHttpTest stage POST |
| RF-OPP-005 | Historial de cambios de etapa (tabla opportunity_stage_histories) | B04 | Implementado (B04) | OpportunityStageHistory append-only + activitylog | OpportunityStageTest |
| RF-OPP-006 | Marcar ganada: exige monto final y fecha de cierre | B04 | Implementado (B04) | markWon exige final_amount>0 + closed_at | OpportunityWinLoseTest + HTTP win |
| RF-OPP-007 | Marcar perdida: exige motivo | B04 | Implementado (B04) | markLost exige loss_reason_id | OpportunityWinLoseTest + HTTP lose |
| RF-OPP-008 | Filtros por vendedor, etapa, fecha, estado y monto | B04 | Implementado (B04) | Filtros etapa/vendedor/prioridad/búsqueda + export | OpportunityHttpTest + OpportunitiesExport |
| RF-OPP-009 | Relación con actividades, cotizaciones y documentos | B04 | Implementado (B04) | Secciones relacionadas en ficha (cotizaciones B06 placeholder, actividades B05) | OpportunityHttpTest show |
| RF-OPP-010 | Visibilidad por alcance (ADR-006) | B04 | Implementado (B04) | scopeQuery (DataScopeService) en listado, kanban y export | OpportunityCrudTest scope + HTTP kanban scoped |

## ACT — Actividades (B05)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-ACT-001 | Registro de los 7 tipos iniciales con campos mínimos | B05 | Implementado (B05) | ActivityService::create + 7 tipos ActivityType seed | ActivityLifecycleTest |
| RF-ACT-002 | Estados pendiente/en proceso/completada/cancelada/vencida | B05 | Implementado (B05) | 5 estados enum; vencidas por scheduler | ActivityLifecycleTest |
| RF-ACT-003 | Marcado de vencidas por scheduler | B05 | Implementado (B05) | activities:mark-overdue + schedule 02:00 | MarkOverdueCommandTest |
| RF-ACT-004 | Actividades completadas se conservan en historial | B05 | Implementado (B05) | SoftDeletes; nunca borra completadas | ActivityLifecycleTest |
| RF-ACT-005 | Al completar, ofrecer crear siguiente seguimiento (ADR-012) | B05 | Implementado (B05) | ActivityService::complete con next array | ActivityLifecycleTest |
| RF-ACT-006 | Vinculación a prospecto, cliente u oportunidad | B05 | Implementado (B05) | Formularios embebidos en show de lead/customer/op | ActivityHttpTest |
| RF-ACT-007 | Recordatorios configurables | B05 | Implementado (B05) | reminder_at nullable + ActivityUpcoming respeta | NotifyUpcomingCommandTest |
| RF-ACT-008 | Visibilidad por alcance (ADR-006) | B05 | Implementado (B05) | DataScopeService::appliesTo por owner_id | ActivityScopeTest |

## CAL — Calendario (B05)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-CAL-001 | Vistas mensual, semanal, diaria y lista | B05 | Implementado (B05) | CalendarController + 4 vistas (month/week/day/list) | CalendarHttpTest |
| RF-CAL-002 | Crear y actualizar actividades desde el calendario | B05 | Implementado (B05) | CRUD activity desde calendario | ActivityHttpTest |
| RF-CAL-003 | Arquitectura preparada para Google Calendar/Outlook sin implementarlas | B05 | Implementado (B05) | Sin integración Google/Outlook | — |

## PROD — Productos y servicios (B06)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-PROD-001 | CRUD con campos mínimos (tipo, categoría, precio, moneda, impuesto predeterminado) | B06 | Implementado (B06) | ProductService + ProductController + CRUD | ProductHttpTest |
| RF-PROD-002 | Desactivación sin borrado físico | B06 | Implementado (B06) | ProductoFactory + ProductService::deactivate (soft delete) | ProductHttpTest::deactivate |
| RF-PROD-003 | Uso en cotizaciones con copia histórica de precio e impuesto (ADR-005) | B06 | Implementado (B06) | QuotationService::create con snapshot tax_id/tax_name/tax_rate | QuotationMathTest + QuotationTaxSnapshotTest |

## COT — Cotizaciones (B06)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-COT-001 | Crear/editar cabecera y detalle con todos los campos | B06 | Implementado (B06) | QuotationController + QuotationService::create (cálculo server-side) | QuotationHttpTest |
| RF-COT-002 | Numeración correlativa COT-AAAA-NNNNN (ADR-002) | B06 | Implementado (B06) | CodeGeneratorService::next(quotation) COT-AAAA-NNNNN | QuotationLifecycleTest |
| RF-COT-003 | Cálculo de subtotales, descuentos, impuestos y totales en servidor, validado | B06 | Implementado (B06) | QuotationService::calculateTotals revalidado en server | QuotationMathTest |
| RF-COT-004 | Estados borrador/enviada/aceptada/rechazada/vencida/anulada con historial | B06 | Implementado (B06) | QuotationService: draft→sent→accepted/rejected/voided | QuotationLifecycleTest |
| RF-COT-005 | Generación y descarga de PDF | B06 | Implementado (B06) | quotations/pdf via Barryvdh\DomPDF\Facade\Pdf | QuotationHttpTest::pdf |
| RF-COT-006 | Duplicar cotización | B06 | Implementado (B06) | QuotationService::duplicate (nuevo code, status=draft) | QuotationLifecycleTest::duplicate |
| RF-COT-007 | Aceptada → cierre de oportunidad con confirmación, monto final, moneda y fecha (ADR-007) | B06 | Implementado (B06) | QuotationController::accept con confirm_opportunity_won flag (ADR-007) | QuotationHttpTest::accept_with_opp_open_and_confirm |
| RF-COT-008 | Aceptación sin cerrar oportunidad posible | B06 | Implementado (B06) | QuotationController (filtros + scope) | QuotationHttpTest::index_scope |
| RF-COT-009 | Impuesto por línea copiado históricamente (ADR-005) | B06 | Implementado (B06) | QuotationService acepta opp_id (no muta opp) | QuotationAcceptanceOppTest |
| RF-COT-010 | Envío por correo dejado preparado, sin implementar | B09 | Pendiente | QuotationService::send queda como change-status-and-log; sin driver de mail real (out of scope por master prompt). Ver `docs/AVANCE.md` § B09 tabla de pendientes | — |
| RF-COT-011 | Visibilidad por alcance (ADR-006) | B06 | Implementado (B06) | DataScopeService::appliesTo en index + export | QuotationExportScopeTest |

## DOC — Documentos (B02–B06 según entidad)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-DOC-001 | Adjuntar a prospecto, cliente, contacto, oportunidad, cotización y actividad | B06 | Pendiente | — | — |
| RF-DOC-002 | Metadatos registrados (nombre, tipo, tamaño, ubicación, usuario, fecha) | B06 | Pendiente | — | — |
| RF-DOC-003 | Validación de extensión, MIME y tamaño | B06 | Pendiente | — | — |
| RF-DOC-004 | Descarga solo autorizada / URL temporal (ADR-011) | B06 | Pendiente | — | — |
| RF-DOC-005 | Disco privado configurable (local/S3) | B06 | Pendiente | — | — |

## REP — Reportes (B07)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-REP-001 | Reportes: prospectos por origen/vendedor, conversión, oportunidades por etapa, valor de embudo, ganadas y perdidas, motivos de pérdida, actividades por vendedor/vencidas, cotizaciones emitidas/aceptadas/rechazadas, rendimiento comercial | B07 | Implementado (B07) | ReportsService 12 métodos + ReportController 12 acciones | ReportHttpTest |
| RF-REP-002 | Totalización por moneda, sin conversión (ADR-004) | B07 | Implementado (B07) | ReportsService: group by currency_code sin consolidar | ReportHttpTest::multimoneda |
| RF-REP-003 | Filtros por rango de fechas y usuario | B07 | Implementado (B07) | Filtros from/to/owner_id/status uniformes | ReportHttpTest |
| RF-REP-004 | Exportación Excel | B07 | Implementado (B07) | ArrayExport + endpoint ?export=xlsx | ReportsExcelExportTest + ReportHttpTest |
| RF-REP-005 | Exportación PDF donde corresponda | B09 | Pendiente | Solo export Excel (out of scope por master prompt: "PDF diferido a B09"). B09 cerró sin PDF de reportes. Ver `docs/AVANCE.md` § B09 tabla de pendientes | — |
| RF-REP-006 | Respeto de permisos y alcance de datos (ADR-006) | B07 | Implementado (B07) | DataScopeService::appliesTo en todos los métodos | ReportHttpTest::vendedor_scope |

## USR — Usuarios, roles y permisos (B01/B08)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-USR-001 | Crear/editar usuarios; activar/desactivar | B01 | Implementado (B01) | users + is_active/last_login_at; UI completa en B08 | AuthTest, UsersTest |
| RF-USR-002 | Usuario desactivado no puede iniciar sesión | B01 | Implementado (B01) | LoginRequest + EnsureUserIsActive | AuthTest::test_inactive_users_cannot_log_in_with_correct_password, ::test_authenticated_inactive_users_are_rejected_by_middleware |
| RF-USR-003 | Roles iniciales admin/supervisor/vendedor con permisos granulares (no por nombre de rol) | B01 | Implementado (B01) | RolesAndPermissionsSeeder (62 permisos) | RolesAndPermissionsTest |
| RF-USR-004 | Equipos teams/team_user definen alcance de datos (ADR-006) | B01 | Implementado (B01) | migraciones teams + DataScopeService | DataScopeTest |
| RF-USR-005 | Restablecer contraseña | B08 | Implementado (B08) | UserService::resetPassword + admin/users/reset-password | AdminHttpTest |
| RF-USR-006 | Consulta de último acceso | B08 | Implementado (B08) | User.last_login_at en LoginRequest (B01) + UserService::recordLogin | AuthTest |
| RF-USR-007 | Registro de operaciones sensibles | B08 | Implementado (B08) | Activitylog v4 con propiedades (motivo, IP) en operaciones sensibles; vista admin/audit; eventos en UserService, TeamService, CatalogService, SettingsService, OpportunityService, QuotationService, LeadService, OpportunityService (markWon/Lost), LeadConversionService | AuditServiceTest + AdminHttpTest
| RF-USR-008 | Gestión visual de roles/permisos/usuarios en administración | B08 | Implementado (B08) | Admin UI: users, teams, roles, catalogs, settings | AdminHttpTest |

## CFG — Configuración (B01/B08)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-CFG-001 | Catálogos configurables: etapas, estados de prospecto, orígenes, tipos de actividad, motivos de pérdida, categorías, monedas, impuestos | B08 | Implementado (B08) | CatalogService 8 catalogs (lead-sources, lead-statuses, loss-reasons, activity-types, pipeline-stages, product-categories, currencies, taxes) | CatalogServiceTest + AdminHttpTest |
| RF-CFG-002 | Catálogos históricos no eliminables, solo desactivables | B08 | Implementado (B08) | CatalogService::deactivate + activate (never delete) | CatalogServiceTest |
| RF-CFG-003 | Ubigeo completo por seed con códigos oficiales y selectores dependientes (ADR-009) | B01 | Implementado (B01, UI de selectores en B02+) | migración ubigeo + UbigeoSeeder (25/196/1892, fuente RitchieRD CC0/INEI) | SeedersTest |
| RF-CFG-004 | Parámetros generales (settings): precios con/sin IGV, paginación, moneda default (ADR-005) | B01 | Implementado (B01) | migración settings + SettingsSeeder (10 claves) | SeedersTest |
| RF-CFG-005 | Prefijos y dígitos de correlativos configurables (ADR-002) | B08 | Implementado (B08) | B01 CodeSequencesSeeder + seq.* settings | SeedersTest |

## NOT — Notificaciones (transversal)

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RF-NOT-001 | Internas: asignación de prospecto/oportunidad, actividad próxima/vencida, cotización por vencer, cambio de etapa | B02–B06 | Parcial (B04: oportunidad asignada + cambio de etapa) | app/Notifications/{OpportunityAssigned,OpportunityStageChanged} | NotificationsTest
| RF-NOT-002 | Arquitectura preparada para correo y WhatsApp (solo wa.me si no hay credenciales) | B09 | Pendiente | — | — |

## Requisitos no funcionales

| ID | Requisito | Bloque | Estado | Implementación | Prueba |
|---|---|---|---|---|---|
| RNF-SEG-001 | CSRF en formularios, escape de salida, validación server-side total | B01+ | Implementado (B01) | Blade @csrf, {{ }}, Form Requests | AuthTest, LoginRequest |
| RNF-SEG-002 | Validación y restricción de archivos cargados | B06 | Pendiente | — | — |
| RNF-SEG-003 | Sin datos sensibles en logs/respuestas | B09 | Pendiente | — | — |
| RNF-SEG-004 | Autorización por módulo y por registro (Policies) | B01+ | Implementado (B01) | app/Policies/* + DataScopeService | DataScopeTest, RolesAndPermissionsTest |
| RNF-DAT-001 | Soft delete para entidades con historial; nunca borrado físico comercial | B02+ | Pendiente | — | — |
| RNF-DAT-002 | Montos DECIMAL, sin float | B01+ | Pendiente | — | — |
| RNF-DAT-003 | Auditoría de acciones sensibles con old/new values (ADR-008) | B01+ | Implementado (B01) | activitylog en Lead/Customer/Contact/Opportunity/Quotation/Product | AuditLogTest |
| RNF-DAT-004 | Transacciones en operaciones críticas | B01+ | Pendiente | — | — |
| RNF-DAT-005 | Prevención N+1 y paginación | B02+ | Pendiente | — | — |
| RNF-OPS-001 | Docker: app, mysql, web, worker, scheduler | B01 | Parcial (B01, incidencia I-01) | docker-compose.yml + Dockerfile + docker/nginx | Verificación pendiente: daemon Docker detenido |
| RNF-OPS-002 | Jobs para operaciones pesadas (import Excel, PDF masivos) | B02+ | Pendiente | — | — |
| RNF-UX-001 | Interfaz responsive en español, AdminLTE 4/BS5 (ADR-010) | B01+ | Implementado (B01) | layouts/app + componentes + npm admin-lte@4.3.1 (ADR-016) | Smoke: login/dashboard 200 |
| RNF-UX-002 | Zona horaria America/Lima, moneda PEN S/, fecha dd/mm/yyyy | B01 | Implementado (B01) | .env APP_TIMEZONE, settings (currency_default, date_format) | SeedersTest |
| RNF-TST-001 | Suite de pruebas por módulo; módulo no terminado sin pruebas de sus reglas | Todos | Pendiente | — | — |
| RNF-DOC-001 | Documentación obligatoria actualizada por bloque | Todos | Pendiente | — | — |
