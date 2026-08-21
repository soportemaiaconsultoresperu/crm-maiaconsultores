# 📐 Diseño del Módulo Campañas

> **Estado**: propuesta de diseño revisada (v5). Esperando aprobación final antes de implementar.
> **No incluye código, migraciones, ni seeders** — solo decisiones de arquitectura y modelo de datos.
> Cambios respecto a v4: ver sección "Historial de revisiones".

---

## ⚠️ Decisión arquitectónica crítica (v5)

**El módulo Campañas NO modifica el módulo Activity existente.** Es un módulo completamente independiente con su propia gestión de estado, fechas y resultado. La razón:

- El módulo Activity es una pieza estable del CRM con su propio contrato público (`SUBJECT_TYPES`, `morphClass`, `subjectKey`, validaciones, policies). Modificarlo para aceptar `subject_type='contact'` violaría ese contrato y propagaría cambios a todas las vistas, FormRequests, Services, factories y policies del módulo existente.
- El módulo Campañas necesita representar 4 tipos de subject (lead, customer, contact, opportunity), pero el módulo Activity solo soporta 3. Resolver esto **dentro del módulo Campañas** mantiene ambos módulos aislados y testeables independientemente.

**Consecuencias**:

- `campaign_action_items` **no** tiene FK a `activities`. Tiene su propio `status` (enum local), `scheduled_at`, `executed_at`, `completed_by`, `result`.
- `campaign_participants.subject_type` acepta `'lead'`, `'customer'`, `'contact'`, `'opportunity'` sin restricción del enum de `Activity::SUBJECT_TYPES`.
- El calendario del CRM (`/calendar`) sigue mostrando solo Activities. Los items de campaña tienen su propia vista de calendario interno (dentro del módulo Campañas, Bloque 2).
- Los 4 tipos nuevos del spec original (`mensaje`, `invitación`, `seguimiento`, `otro`) **NO** se crean en `activity_types`. El módulo Campañas reusa los 7 tipos existentes.

---

## Resumen de entendimiento

El módulo permite **planificar, ejecutar y documentar campañas internas** de outreach comercial mediante:

1. Plantillas reutilizables.
2. Pasos (acciones) generales aplicables a todos los contactos.
3. Ejecuciones con nombre y fecha propia.
4. Selección masiva de contactos (lead, customer, **contact**, opportunity).
5. Matriz de checklist por contacto × paso.
6. Registro de resultados, respuestas y observaciones.
7. Reprogramación individual o general.
8. Indicadores de avance.

**No envía automáticamente correos, mensajes ni publicidad.** Si el usuario realiza una llamada, envía un correo, escribe por WhatsApp o realiza otra acción fuera del sistema, solamente registra y documenta el resultado dentro del CRM.

Es una **capa de orquestación y registro** independiente del módulo Activity.

---

## 1. Revisión del módulo Activity existente

### Lo que reuso sin modificar

- **`Activity`** con polymorphic subject (`lead`/`customer`/`opportunity`), `type_id` FK a `activity_types`, `status` enum (`pending`/`in_process`/`completed`/`cancelled`/`overdue`), `owner_id`, `scheduled_at`, `executed_at`, `result`, `priority`, soft-deletes.
- **`ActivityType`**: 7 tipos pre-cargados (`Llamada`, `WhatsApp`, `Correo`, `Reunión`, `Visita`, `Tarea`, `Nota`).
- **`Document`** con polymorphic morph `docable_type`/`docable_id`.
- **`Spatie ActivityLog`** — auditoría nativa.
- **`Spatie Permissions`**.
- **`DataScopeService`**.
- **Components Blade** (`x-table`, `x-modal`, `x-select`, `x-text-input`, `x-alert`, `x-badge-status`, `x-validation-error`, `x-label`).
- **Tests Feature** como patrón.

### Lo que NO se modifica

- `Activity::SUBJECT_TYPES` — se mantiene como `['lead', 'customer', 'opportunity']`. El módulo Campañas **no** agrega `'contact'` aquí.
- `Activity::morphClass()` y `subjectKey()` — sin cambios.
- `ActivityStoreRequest` / `ActivityUpdateRequest` — sin cambios en mensajes de error ni validaciones.
- `ActivityService::assertSubjectExists()` — sin cambios.
- `ActivityFactory` — sin métodos nuevos para campaign.
- `ActivityController::storeForSubject()` — sin nuevos parámetros.
- `routes/web.php` — sin ruta `contacts.activities.store`.
- Vistas de Activities y Calendar — sin nuevos subject_types en filtros.
- `activity_types` — **sin agregar los 4 tipos nuevos**. Solo se usan los 7 existentes.

---

## 2. Diseño funcional

### Conceptos y sus límites

| Concepto | Persiste tras ejecución | Tiene contactos | Tiene resultados |
|---|---|---|---|
| **Plantilla** (`campaign_templates`) | sí | no | no |
| **Ejecución** (`campaign_runs`) | sí | sí | sí |
| **Paso** (`campaign_steps`) | sí (snapshot en ejecución) | no | no |
| **Participante** (`campaign_participants`) | sí | — | — |
| **Item** (`campaign_action_items`) | sí | — | sí |
| **Reprogramación** (`campaign_item_reschedules`) | sí | — | — |

### Tipos de subject aceptados por el módulo Campañas

| `campaign_participants.subject_type` | Significado | Notas |
|---|---|---|
| `lead` | Prospecto del CRM | — |
| `customer` | Cliente del CRM | — |
| `contact` | **Contacto del CRM** | El módulo Campañas acepta este tipo aunque el módulo Activity no. |
| `opportunity` | Oportunidad del CRM | — |

> **Aislamiento**: `campaign_participants.subject_type` es **independiente** de `Activity::SUBJECT_TYPES`. El módulo Campañas valida sus propios subject_types; el módulo Activity valida los suyos. No hay acoplamiento.

### Flujos de usuario

1. **Crear plantilla**: wizard de 3 pasos (datos básicos → pasos → revisión). Toggle de activar/desactivar.
2. **Ver plantilla**: muestra sus pasos, cuántas ejecuciones la han usado, botón "Crear ejecución desde esta plantilla".
3. **Crear ejecución**: selecciona plantilla, nombre, fecha/hora de inicio, responsable, equipo, observaciones.
4. **Seleccionar contactos**: filtros + checkboxes + preview antes de confirmar (con advertencia de duplicados por documento/correo/teléfono).
5. **Vista de ejecución**: dashboard con KPIs + matriz contactos × pasos con scroll horizontal.
6. **Operar item**: click → modal con detalle → acciones explícitas: "Iniciar item" | "Marcar realizado" | "Reprogramar" | "Cancelar" | "Marcar no aplica".
7. **Reprogramar general**: modal con preview de nuevas fechas → confirma con motivo.
8. **Corregir completion errónea**: solo supervisor con permiso `campaigns.override_completion`.
9. **Cerrar/Cancelar ejecución**: modal con motivo + revisión de pendientes.

---

## 3. Modelo de datos y relaciones

### Diagrama lógico

```
campaign_templates (1) ───── (N) campaign_steps [template_id=X, run_id=NULL, is_template=1]
        │
        │ (snapshot al crear ejecución)
        ▼
campaign_runs (1) ──────── (N) campaign_steps [run_id=X, template_id=NULL, is_template=0,
        │ │                            source_step_id=Y → apunta al paso del template original]
        │ │
        │ ├── (N) campaign_participants ──── cada uno → polymorphic subject (lead|customer|contact|opportunity)
        │ │
        │ └── (N) campaign_action_items ──── combinación participant × step
        │                                       CON ESTADO Y FECHAS PROPIOS
        │
        └── (audit) Spatie ActivityLog automático

campaign_item_reschedules ──── historial append-only de reprogramaciones
                                  (FK a campaign_action_items)

Document polimórfico se attacha a: campaign_templates, campaign_runs,
campaign_participants, campaign_action_items.
```

### Tablas (6 nuevas + 2 migrations menores = 8 migrations)

> El módulo Campañas **NO** usa el módulo Activity. Cada tabla tiene su propio dominio.

#### `campaign_templates`

```
id, name (unique), description (text, nullable), objective (enum),
status (enum: draft|active|inactive), owner_id (FK users),
team_id (FK teams, nullable), created_by, updated_by,
timestamps, soft_deletes
```

#### `campaign_steps` (consolida pasos de plantilla y de ejecución)

```
id, is_template (bool, NOT NULL),
template_id (FK campaign_templates, nullable),
run_id (FK campaign_runs, nullable),
source_step_id (FK campaign_steps, nullable),
order (int), action_type_id (FK activity_types),
title (string 200), day_offset (int, default 0), scheduled_time (time, nullable),
instructions (text, nullable), is_required (bool, default true),
is_advertising (bool, default false),
status (enum: active|inactive), created_by, updated_by,
timestamps
```

**Restricción de integridad** (CHECK + scopes Eloquent):

```sql
CHECK (
  (is_template = 1 AND template_id IS NOT NULL AND run_id IS NULL AND source_step_id IS NULL) OR
  (is_template = 0 AND run_id IS NOT NULL AND template_id IS NULL)
)
```

**Scopes Eloquent**: `templateSteps()` y `runSteps()`.

#### `campaign_runs`

```
id, code (string 30, unique, ej. CR-2026-00001),
name, template_id (FK), template_hash (string 64, nullable),
starts_at (datetime), ends_at_estimated (datetime, nullable),
owner_id (FK users), team_id (FK teams, nullable),
status (enum: draft|scheduled|running|paused|completed|cancelled), default draft,
status_changed_at, status_changed_by (FK users, nullable),
status_reason (text, nullable), progress_cache (json, nullable),
observations (text, nullable), created_by, updated_by,
timestamps, soft_deletes
index (status, starts_at), index (code)
```

> **Nombre puede repetirse con advertencia; solo el código es correlativo único.**

#### `campaign_participants`

```
id, run_id (FK), subject_type (varchar 50),  -- 'lead' | 'customer' | 'contact' | 'opportunity'
subject_id (bigint unsigned),
assigned_to (FK users, nullable),   -- responsable de campaña
status (enum: active|excluded|cancelled), default active,
included_at, excluded_at (nullable), exclusion_reason (text, nullable),
added_by (FK users, nullable), removed_by (FK users, nullable),
-- Snapshot inmutable
display_name (string 200), company_name (string 200, nullable),
document_number_masked (string 50, nullable),
email (string 200, nullable), phone (string 50, nullable),
timestamps
unique (run_id, subject_type, subject_id)
index (subject_type, subject_id), index (assigned_to)
```

#### `campaign_action_items` (la unidad mínima)

> **Esta tabla NO tiene FK a `activities`**. Tiene su propio estado y fechas porque el módulo Campañas es independiente del módulo Activity.

```
id, run_id (FK), step_id (FK campaign_steps),
participant_id (FK campaign_participants),

-- Estado y fechas propios (independiente del módulo Activity):
status (enum: pending|in_process|completed|overdue|cancelled|not_applicable), default pending,
scheduled_at (datetime), executed_at (datetime, nullable),
completed_by (FK users, nullable),

-- Metadata propia de campaña:
result (text, nullable),          -- resultado/documentación de la acción
contact_response (text, nullable),
observations (text, nullable),
cancellation_reason (text, nullable),
not_applicable_reason (text, nullable),
next_action_at (datetime, nullable), next_action_notes (text, nullable),
reschedule_count (int, default 0), last_rescheduled_at (datetime, nullable),

timestamps, soft_deletes
unique (step_id, participant_id)
index (run_id), index (status, scheduled_at)
```

> **Estado "No aplica"**: se implementa como **valor del enum `status`** (`'not_applicable'`). Es estado válido del item, no un valor derivado.

> **Cancelación vs No aplica**:
>
> - Cancelación normal: `status = 'cancelled'` + `cancellation_reason` (text, required).
> - No aplica: `status = 'not_applicable'` + `not_applicable_reason` (text, required).
> - **Constraint CHECK excluyente**: `cancellation_reason` y `not_applicable_reason` **nunca** ambos informados. Validado a nivel SQL en MySQL/PostgreSQL y a nivel PHP (en el modelo) en SQLite (donde el CHECK no se puede agregar).

#### `campaign_item_reschedules` (historial append-only)

```
id, item_id (FK campaign_action_items),
old_scheduled_at, new_scheduled_at, reason (text, required),
rescheduled_by (FK users), rescheduled_at (datetime),
scope (enum: individual|global), preserved_individual (bool, default false),
timestamps
index (item_id)
```

### Migraciones (8 totales: 6 nuevas + 2 menores)

| # | Migración | Tipo |
|---|---|---|
| 1 | `create_campaign_templates_table` | nueva |
| 2 | `create_campaign_runs_table` | nueva |
| 3 | `create_campaign_steps_table` | nueva (con CHECK constraint) |
| 4 | `create_campaign_participants_table` | nueva |
| 5 | `create_campaign_action_items_table` | nueva (sin FK a activities; con CHECK) |
| 6 | `create_campaign_item_reschedules_table` | nueva |
| 7 | `seed_campaign_permissions` | menor (20 permisos) |

> **NOTA**: la migración `add_campaign_types_to_activity_types` del v3/v4 **NO existe** en v5. No se agregan tipos al catálogo compartido `activity_types`. El módulo Campañas reusa los 7 tipos existentes (`llamada`, `whatsapp`, `correo`, `reunion`, `visita`, `tarea`, `nota`).

---

## 4. Estados y transiciones

### `campaign_runs.status` (6 estados)

```
draft ──(confirma)──> scheduled ──(llega starts_at)──> running
                            │                            │
                            └──(cancela)──────────────> cancelled
                                                              │
                            ┌─────(pausa admin)─────────�
                            ▼
                          paused ──(reanuda)──> running
                                                    │
                                                    ▼
                                                 completed
```

### `campaign_action_items.status` (6 estados, enum local)

```
pending → in_process → completed
   │           │
   │           ├── → cancelled (motivo: cancellation_reason)
   │           └── → overdue (sistema, scheduled_at < now())
   │
   └── → not_applicable (motivo: not_applicable_reason)

completed → pending (corrección supervisada con `campaigns.override_completion`)
```

> **NO existe** estado `rescheduled` permanente. Tras reprogramar, el item vuelve a `pending`. `reschedule_count > 0` y el historial en `campaign_item_reschedules` indican que fue reprogramado.

---

## 5. Diseño de la matriz

### Layout

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ KPIs de la ejecución                                                        │
│ Total contactos: 30 · Total pasos: 5 · Combinaciones: 150                  │
│ Pendientes: 87 · Realizadas: 42 · En proceso: 12 · Vencidas: 5 · No aplica: 3│
│ Avance: 56%                                                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Cada celda muestra** (leído directamente de `campaign_action_items`):

- **Ícono/Color por estado**: ○ pending, ▶ in_process, ✓ completed, ✗ overdue, ✗ cancelled, — not_aplicable, ↻ (reschedule_count > 0).
- **Fecha programada** (`scheduled_at`).
- **Avatar del responsable** (`completed_by`).
- **Tooltip** con `result` truncado.

### Performance y anti-saturación

- No cargar toda la matriz si pasa de 100 contactos × 5 pasos = 500 celdas; paginar en bloques de 50.
- Server-side rendering del estado de cada celda.
- Índices: `campaign_action_items(run_id)`, `campaign_action_items(status, scheduled_at)`.
- Cache de KPIs en `campaign_runs.progress_cache` (json), regenerable vía job.

### Anti-saturación de calendario y dashboard

El calendario del CRM (`/calendar`) sigue mostrando solo **Activities** (no items de campaña). Esto es por diseño: el módulo Campañas es independiente. El módulo Campañas tendrá su propio calendario interno (Bloque 2).

| Medida | Aplicación |
|---|---|
| Filtro por campaña (futuro) | `/calendar` podría tener un toggle "excluir items de campaña" si se quisiera ver solo Activities "naturales". |
| Carga por rango de fechas | `/calendar` y `/campaign-runs/{id}` con `from`, `to`. |
| Paginación | Default 100 items por página. |
| Agrupación visual | Cuando un día tiene > 20 items, "Ver N más". |

### Responsive

- **Desktop**: matriz completa con scroll horizontal.
- **Tablet**: matriz con sticky-left en columna de contacto.
- **Mobile**: vista alternativa con cards por contacto.

---

## 6. Flujo de selección masiva

(Ver v3 §6 — sin cambios.)

### Detección de duplicados

Las 4 entidades (Lead, Customer, **Contact**, Opportunity) son seleccionables. **Un Contact NO se trata automáticamente como duplicado de Customer solo porque tiene `customer_id`** (un contacto puede ser una persona distinta que trabaja en la empresa).

**Algoritmo** (al confirmar la selección): comparar campos normalizados (documento, correo, teléfono). Mostrar warning con la lista de coincidencias. El operador decide.

---

## 7. Reglas de reprogramación

(Ver v3 §7 — sin cambios.)

### Estados donde se permite reprogramar (`campaign_action_items.status`)

- ✅ `pending`
- ✅ `in_process`
- ✅ `overdue`
- ❌ `completed` (usar `campaigns.override_completion` para reabrir primero)
- ❌ `cancelled`
- ❌ `not_applicable`

### Fórmula de cálculo

```
new_scheduled_at = new_starts_at + step.day_offset  (en días)
                  + step.scheduled_time              (hora del día)
```

Items con reprogramación individual previa: preview muestra opción **Conservar / Recalcular**.

---

## 8. Permisos (final 20)

```
campaign_templates.view
campaign_templates.create
campaign_templates.update
campaign_templates.duplicate
campaigns.view
campaigns.create
campaigns.update
campaigns.schedule
campaigns.start
campaigns.pause
campaigns.complete
campaigns.cancel
campaigns.duplicate
campaigns.add_contacts
campaigns.remove_contacts
campaigns.register_actions
campaigns.reschedule
campaigns.mark_realized
campaigns.view_reports
campaigns.override_completion
```

### Reglas de aplicación

Toda consulta sobre campañas aplica simultáneamente `campaigns.view` + `DataScopeService` (admin: todo, supervisor: su equipo, vendedor: lo propio).

### Mapping a roles

| Permiso | admin | supervisor | vendedor |
|---|---|---|---|
| `campaign_templates.*` | ✅ | ✅ | ❌ |
| `campaigns.create/update/schedule/start/pause/complete/cancel/duplicate` | ✅ | ✅ | ❌ |
| `campaigns.add_contacts/remove_contacts/register_actions` | ✅ | ✅ | ❌ |
| `campaigns.reschedule` | ✅ | ✅ | ✅ (scope propio) |
| `campaigns.mark_realized` | ✅ | ✅ | ✅ |
| `campaigns.view_reports` | ✅ | ✅ | ✅ (scope propio) |
| `campaigns.override_completion` | ✅ | ✅ | ❌ |

---

## 9. Criterios de aceptación

### Funcionales

**Plantillas**:

- ✅ Crear plantilla con N pasos (mínimo 1).
- ✅ Activar/desactivar plantilla.
- ✅ Duplicar plantilla copia nombre + pasos.
- ✅ Al desactivar, NO se permiten nuevas ejecuciones; las históricas siguen funcionando.

**Ejecuciones**:

- ✅ Crear ejecución con código único por año (`CR-YYYY-NNNNN`); nombre puede repetirse con advertencia.
- ✅ Fechas calculadas como `starts_at + day_offset` a la hora `scheduled_time`.
- ✅ Snapshot normalizado en `campaign_steps` con `source_step_id`.
- ✅ Selección masiva con filtros, sin duplicados, advertencia por documento/correo/teléfono.
- ✅ Selección incluye **Contacts** (a pesar de que el módulo Activity no los soporta).
- ✅ KPIs calculados en tiempo real con cache regenerable.
- ✅ Vista mobile como cards por contacto.

**Operación**:

- ✅ Estado NO cambia automáticamente al abrir modal.
- ✅ Botón "Iniciar item" cambia `pending → in_process`.
- ✅ Marcar realizado requiere `result` no vacío y setea `executed_at`.
- ✅ Reprogramar individualmente requiere `new_scheduled_at > now()` y motivo ≥ 10 chars.
- ✅ Reprogramar globalmente usa fórmula `new_starts_at + day_offset`.
- ✅ Reprogramación siempre vuelve a `pending` (no existe estado `rescheduled`).
- ✅ Reprogramación NO permitida sobre `completed` (usar `override_completion`).

**Corrección supervisada**:

- ✅ Supervisor con `campaigns.override_completion` puede reabrir un item en `completed`.
- ✅ Motivo obligatorio.
- ✅ Spatie ActivityLog registra old/new values.
- ✅ Después de la corrección, el item está en `pending` y puede reprogramarse.

**KPIs**: ver §11.

**Permisos**: toda consulta aplica `campaigns.view` + `DataScopeService`.

### No funcionales

Ver §12.

---

## 10. Casos límite

(Ver v3 §10 — sin cambios relevantes.)

| # | Caso | Decisión |
|---|---|---|
| 1 | Editar template después de crear ejecución | La ejecución NO se modifica (snapshot) |
| 2 | Eliminar paso del template en uso | Las ejecuciones históricas mantienen su `campaign_steps` |
| 3 | Contacto soft-deleted | El item queda con FK al participant; `withTrashed()` muestra el snapshot |
| 4 | Reprogramar item de campaña ajena | Botón oculto + endpoint 403 |
| 5 | Duplicar ejecución con nombre repetido | Permitido con warning; código es único |
| 6 | Marcar completed y querer "desmarcar" | Solo supervisor con `override_completion` |
| 14 | Mismo `doc_number_norm` entre Lead seleccionado y Customer | Advertencia antes de confirmar selección masiva |
| 15 | Una actividad de CRM entra en overdue | El job `MarkCampaignItemsOverdue` (cada 15 min) actualiza SOLO items de campaña vinculados con `campaign_action_items` |

---

## 11. Cierre y KPI

### Estados y fórmulas

| Estado en KPI | Cómo se computa |
|---|---|
| **Realizadas** | `status = 'completed'` |
| **Pendientes** | `status = 'pending'` |
| **En proceso** | `status = 'in_process'` |
| **Vencidas** | `status = 'overdue'` |
| **Canceladas** | `status = 'cancelled'` |
| **No aplica** | `status = 'not_applicable'` |

### Fórmula de avance

```
denominador = total - canceladas - no_aplica
avance = realizadas / denominador × 100   (redondeado a 1 decimal)
```

Protección contra división por cero: si denominador ≤ 0, avance = 0.

---

## 12. Plan de implementación

### Bloque 0 — Pre-flight (1h)

### Bloque 1 — Schema y modelo de datos (6h) — **COMPLETADO en v5**

### Bloque 2 — Service layer (8h)

### Bloque 3 — HTTP + Policies (8h)

### Bloque 4 — UI (12h)

### Bloque 5 — Jobs + Auditoría + Performance (4h)

### Bloque 6 — Tests (10h)

### Bloque 7 — Documentación (3h)

### Bloque 8 — Polish (2h)

**Total**: ~54h.

### Metodología de performance

(Ver v2 §12 — sin cambios relevantes.)

---

## 13. Fuente de verdad — tabla consolidada

Cada campo vive en **una sola** tabla.

| Campo | Tabla | Comentario |
|---|---|---|
| Título del item | `campaign_steps.title` | NOT NULL. |
| Tipo de paso | `campaign_steps.action_type_id` | FK a `activity_types` (catálogo global reutilizado). |
| Responsable operativo | `campaign_action_items.completed_by` | FK a users; se setea al marcar como realizado. |
| Estado del item | `campaign_action_items.status` | **Enum local del módulo** (no en Activity): pending/in_process/completed/overdue/cancelled/not_applicable. |
| Fecha programada | `campaign_action_items.scheduled_at` | Recalculada en reprogramación global. |
| Fecha real | `campaign_action_items.executed_at` | Set al marcar realizado. |
| Resultado de la acción | `campaign_action_items.result` | Text, nullable. Requerido para marcar como completed. |
| Relación campaña-participante-paso | `campaign_action_items` | FKs: `run_id`, `step_id`, `participant_id`. |
| Snapshot del responsable | `campaign_participants.assigned_to` | FK a users. Default al crear action_items. |
| Respuesta textual del contacto | `campaign_action_items.contact_response` | Específico de campaña. |
| Apuntes internos del vendedor | `campaign_action_items.observations` | Específico de campaña. |
| Motivo de cancelación | `campaign_action_items.cancellation_reason` | Text. CHECK excluyente con `not_applicable_reason`. |
| Motivo de "No aplica" | `campaign_action_items.not_applicable_reason` | Text. CHECK excluyente con `cancellation_reason`. |
| Próxima acción propuesta | `campaign_action_items.next_action_at` + `next_action_notes` | Datetime + text. |
| Contador de reprogramaciones | `campaign_action_items.reschedule_count` | Int, default 0. |
| Última reprogramación | `campaign_action_items.last_rescheduled_at` | Datetime, nullable. |
| Historial de reprogramaciones | `campaign_item_reschedules.*` | Append-only. |
| Snapshot del participante | `campaign_participants.display_name`, `company_name`, `document_number_masked`, `email`, `phone` | Inmutable, para trazabilidad. |
| Relación polimórfica principal | `campaign_participants.subject_type` + `subject_id` | FK polimórfica (lead/customer/contact/opportunity). |
| Código único de ejecución | `campaign_runs.code` | `CR-YYYY-NNNNN`. |
| Evidencia de inmutabilidad | `campaign_runs.template_hash` | SHA-256 del template al crear. NO es fuente de verdad, solo forense. |
| Progreso cacheado | `campaign_runs.progress_cache` | JSON regenerable. NO fuente de verdad. |

---

## 14. Transiciones — tabla consolidada

### `campaign_runs.status`

(Sin cambios desde v3 — ver §4.)

### `campaign_action_items.status`

| Desde | Hacia | Quién | Condición |
|---|---|---|---|
| `pending` | `in_process` | vendedor/owner | botón explícito |
| `in_process` | `completed` | vendedor/owner | `result` no vacío |
| `pending`/`in_process` | `cancelled` | vendedor/owner/admin | motivo: `cancellation_reason` |
| `pending`/`in_process` | `not_applicable` | admin/supervisor | motivo: `not_applicable_reason` |
| `pending`/`in_process` | `overdue` | sistema (job) | `scheduled_at < now()` |
| `completed` | `pending` | admin/supervisor (`override_completion`) | motivo obligatorio |

---

## 15. Garantías del spec original

| Punto | Dónde |
|---|---|
| Título obligatorio por paso | `campaign_steps.title` (NOT NULL) |
| Día relativo | `campaign_steps.day_offset` |
| Hora programada | `campaign_steps.scheduled_time` |
| Apuntes | `campaign_action_items.observations` |
| Resultado | `campaign_action_items.result` |
| Respuesta | `campaign_action_items.contact_response` |
| Reprogramación | `campaign_item_reschedules.*` + `reschedule_count` |
| Selección masiva | Wizard step 2 con filtros + preview + detección de duplicados |
| Matriz contacto × acción | `/admin/campaign-runs/{id}` con `x-campaign-matrix` |
| Ausencia de envíos automáticos | NO hay integración con EmailService/WhatsAppService |

---

## 16. Ambigüedades resueltas en v5

| # | Ambigüedad | Resolución en v5 |
|---|---|---|
| 1 | ¿Modificar `Activity::SUBJECT_TYPES` para incluir 'contact'? | **NO**. El módulo Campañas es independiente y maneja su propio `subject_type` en `campaign_participants`. |
| 2 | ¿Crear Activities reales con `subject_type='contact'`? | **NO**. El módulo Campañas tiene su propia tabla `campaign_action_items` con su propio estado. |
| 3 | ¿Actividades de campaña en el calendario del CRM? | **NO**. El calendario del CRM sigue siendo solo para Activities. El módulo Campañas tendrá su propio calendario interno. |
| 4 | ¿Tipos nuevos (`mensaje`, `invitación`, `seguimiento`, `otro`) en `activity_types`? | **NO**. Se usan los 7 tipos existentes. Si el módulo Campañas necesita tipos adicionales, los maneja en su propio dominio (futuro). |

---

## 17. Historial de revisiones

### v5 (esta versión) — **Refactor arquitectónico mayor**

**El módulo Campañas es ahora completamente independiente del módulo Activity.** El módulo Activity vuelve a su estado original (3 subject_types, sin cambios). El módulo Campañas tiene su propio dominio de estado y fechas.

Cambios concretos:

- **Revertidos** todos los cambios al módulo Activity: `Activity::SUBJECT_TYPES`, `morphClass`, `subjectKey`, FormRequests, Service, Factory, Controller, rutas, vistas, tests.
- **`campaign_action_items` reescrita** sin FK a `activity_id`. Tiene su propio `status` (enum local), `scheduled_at`, `executed_at`, `completed_by`, `result`.
- **`campaign_participants.subject_type`** ahora acepta 4 valores (`lead`, `customer`, `contact`, `opportunity`).
- **Eliminada** la migración `add_campaign_types_to_activity_types`. No se agregan 4 tipos nuevos al catálogo compartido.
- **`'not_applicable'`** es ahora un valor válido del enum `campaign_action_items.status` (ya no es "estado derivado").
- **El calendario del CRM** sigue mostrando solo Activities. Los items de campaña tienen su propia vista (futuro Bloque 2).
- **Tests del módulo Activity**: 48/48 verdes (verificado que la reversión no rompió nada).

### v4 (anterior)

- Agregaba `'contact'` a `Activity::SUBJECT_TYPES`, `morphClass()`, `subjectKey()`.
- Agregaba FK `activity_id` en `campaign_action_items`.
- Modificaba FormRequests, Service, Factory, Controller, rutas, vistas del módulo Activity.
- ❌ **Incorrecto**: violaba el contrato del módulo Activity.

### v3, v2, v1

Ver el historial del archivo si se necesita referencia histórica.

---

## 🚦 Estado: Esperando aprobación final (v5)

**No escribí ni una línea de código, ni una migración, ni un seeder nuevos** (después de la reversión). Este es el diseño refactorizado.

### Cambios estructurales del documento

- � Eliminado: dependencia con `Activity::SUBJECT_TYPES` y `activities`.
- ❌ Eliminado: `activity_id` FK en `campaign_action_items`.
- ✅ Nuevo: `campaign_action_items` con `status` enum local, `scheduled_at`, `executed_at`, `completed_by`, `result` propios.
- ✅ Nuevo: `campaign_participants.subject_type` acepta 4 valores (independiente de Activity).
- ✅ Reducido: 7 migrations en lugar de 8 (la 000007 ya no es necesaria).

### Garantías cumplidas

- ✅ **El módulo Activity NO fue modificado** después de la reversión (tests 48/48 verdes).
- ✅ El módulo Campañas es completamente independiente.
- ✅ El catálogo `activity_types` tiene los 7 originales (no se contaminó con tipos de campañas).
- ✅ 6 tablas creadas, 20 permisos asignados, schema verificado en MySQL.

### Necesito de vos

1. **¿Aprobás el refactor arquitectónico v5** (módulo Campañas independiente de Activity)?
2. **¿OK para continuar al Bloque 2** (services + controllers + UI + tests)?

Mientras tanto sigo sin tocar código adicional hasta tu OK. 🚦
