# Arquitectura

Estado: **v1 (B01)**. Monolito modular Laravel (no SPA, no microservicios).

## 1. Stack real instalado (ADR-015)

Laravel 13.17 · PHP 8.3 · Livewire 4.4 · MySQL 8.4 (dev) / 8.0 (Docker) · spatie/laravel-permission v8.3 · spatie/laravel-activitylog v4.12 · maatwebsite/excel v4.0 · barryvdh/laravel-dompdf v3.1 · AdminLTE 4.3 + Bootstrap 5 (sin jQuery) · PHPUnit (tests sobre SQLite en memoria; app sobre MySQL).

## 2. Capas y flujo de una petición

```
HTTP
 └─ Middleware (auth, active, can:permiso)          → bootstrap/app.php registra 'active'
     └─ Controller (fino)  /  Componente Livewire (interactivos: listas, Kanban)
         └─ Form Request (validación de entrada)
             └─ Service (regla de negocio, transacciones)
                 └─ Model (Eloquent + casts + relaciones + LogsActivity)
                     └─ MySQL (utf8mb4, DECIMAL para montos)
 Policy (autorización por módulo y por registro)
 DataScopeService (alcance de datos por rol/equipo)
```

Reglas: controladores y componentes Livewire sin lógica de negocio compleja; cálculos y validaciones de negocio en Services, siempre en servidor; transacciones en operaciones críticas (correlativos, conversión lead→cliente, cambio de etapa, aceptación de cotización, totales).

## 3. Mapa de directorios (lo propio del proyecto)

```
app/
  Console/Commands/MarkOverdueActivities.php   scheduler: marca actividades vencidas (RF-ACT-003)
  Http/Controllers/Auth/...                    login/logout con verificación is_active + throttle
  Http/Controllers/DashboardController.php     placeholder sin datos falsos
  Http/Middleware/EnsureUserIsActive.php       rechaza usuarios desactivados en cada request
  Http/Requests/Auth/LoginRequest.php
  Policies/                                    una por módulo + ModulePolicy (base)
  Services/CodeGeneratorService.php            correlativos con lock (ADR-002)
  Services/DataScopeService.php                alcance admin/supervisor/vendedor (ADR-006)
  Traits/HasAuditColumns.php                   created_by/updated_by automáticos
  Models/                                      23 modelos Eloquent
database/
  migrations/                                  21 (framework + dominio completo de docs/BASE_DATOS.md)
  seeders/                                     roles/permisos (62), admin por env, catálogos ES, ubigeo 2113
  data/ubigeo*.json                            dataset INEI (fuente RitchieRD, CC0)
resources/views/
  layouts/app.blade.php + partials/            AdminLTE 4, menú lateral ES, flash, breadcrumbs
  components/                                  x-text-input, x-select, x-table, x-modal, x-alert, ...
lang/es/                                       validaciones en español
docker-compose.yml + Dockerfile + docker/      app(fpm) + nginx + mysql + queue + scheduler
docs/                                          ARQUITECTURA/BASE_DATOS/REQUISITOS/DECISIONES/SEGURIDAD/PRUEBAS/AVANCE
```

## 4. Autorización y alcance de datos

- **Permisos granulares** (Spatie): 62 permisos `modulo.accion[.alcance]` (ej. `leads.view.any|team|own`, `quotations.accept`). Nada del código consulta nombres de rol.
- **Policies** por módulo: `viewAny/create/update/delete` chequean permiso; `view` además valida que el `owner_id` esté dentro del alcance del usuario.
- **DataScopeService**: `visibleOwnerIds(User): ?array` — admin → `null` (sin restricción); vendedor → `[su id]`; supervisor → miembros de sus equipos + él mismo. `appliesTo(Builder, User, 'owner_id')` lo aplica a cualquier query. Reportes y dashboards consumen el mismo servicio (única fuente de verdad del alcance).

## 5. Auditoría

spatie/laravel-activitylog v4 sobre Lead, Customer, Contact, Opportunity, Quotation, Product: old/new values, usuario causante, IP cuando corresponde; propiedades extra para motivos en operaciones críticas. Vista administrativa de consulta (B08).

## 6. Jobs, colas y scheduler

- Queue connection `database`; worker en contenedor dedicado (imports Excel, PDFs masivos en bloques posteriores).
- Scheduler (contenedor dedicado): `activities:mark-overdue` diario 02:00 America/Lima (RF-ACT-003, base de B05).

## 7. Entornos

| Entorno | App | DB | Assets |
|---|---|---|---|
| Dev (Laragon, actual) | `php artisan serve` | MySQL 8.4 local `crm_maia` | `npm run dev`/`build` |
| Docker | compose: nginx 8080 → fpm | mysql 8.0 con volumen | compilado en build |

Docker queda entregado pero no verificado en esta máquina (daemon detenido — incidencia I-01 en docs/AVANCE.md).

## 8. Decisiones técnicas por bloque

Mirror de las ADRs en `DECISIONES.md` con foco en cómo afectan la arquitectura del monolito. Una línea por ADR.

| ADR | Decisión | Bloque |
|---|---|---|
| ADR-001 | Conversión lead → cliente crea una entidad nueva en `customers` con `converted_from_lead_id`; la operación va en una sola transacción. | B03 |
| ADR-002 | Correlativos `LEAD/CLI/OPP/COT-AAAA-NNNNN` con `SELECT FOR UPDATE` sobre `code_sequences`. | B01 |
| ADR-003 | Duplicados de leads = advertencia, no bloqueo; Excel nunca actualiza, omite y reporta. | B02 |
| ADR-004 | Multimoneda sin conversión: PEN es default; los reportes totalizan por `currency_code`. | B04, B07 |
| ADR-005 | Impuestos configurables por línea; `quotation_items` copia `tax_id/name/rate` al crear la línea. | B06 |
| ADR-006 | Visibilidad por alcance: `DataScopeService` con `visibleOwnerIds(User)`. Vendedor = propio; supervisor = equipo + self; admin = sin restricción. | B01, B04 |
| ADR-007 | Aceptación de cotización con confirmación explícita para cerrar oportunidad (monto + fecha). | B06 |
| ADR-008 | Auditoría con `spatie/laravel-activitylog` v4: usuario, old/new, IP, `properties` con motivo. | B01, B08 |
| ADR-009 | Ubigeo oficial como seed (códigos INEI 6 dígitos); relación por código, nunca texto libre. | B01 |
| ADR-010 | Frontend: AdminLTE 4 + Bootstrap 5 + Livewire 4.4, sin jQuery. | B01 |
| ADR-011 | Documentos en disco privado; descarga solo autorizada o URL temporal. | B09 |
| ADR-012 | Próxima acción = actividad futura pendiente más próxima; sin columnas en `leads` / `opportunities`. | B02, B05 |
| ADR-013 | Stack objetivo (intención original: Laravel 12, PHP 8.3). | B00 |
| ADR-014 | Documentación en `docs/` (no OpenSpec). | B00 |
| ADR-015 | Resolución real: Laravel 13.25, spatie/permission 8.3, maatwebsite/excel 4.0, dompdf 3.1, Livewire 4.4. | B01 |
| ADR-016 | AdminLTE 4 distribuido vía npm `admin-lte@^4.3.1`, compilado por Vite. | B01 |
| B12-UI | Livewire editor for the B12 automation engine (RuleForm + 11 per-type action widgets + history + audit contextual block + soft-delete papelera + idempotency_key visibility). | B12-UI |
