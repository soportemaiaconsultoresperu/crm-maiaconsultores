# Diseño preliminar — Módulo “Soporte técnico”

> Estado: **S01–S07 implementados (V1 pragmática)**

---

## 1. Resumen de entendimiento

El módulo **Soporte técnico** debe centralizar solicitudes realizadas por clientes dentro del CRM existente Laravel/MySQL.

Cada solicitud será un **ticket de soporte** y deberá cubrir el ciclo completo:

```text
Solicitud → clasificación → asignación → programación → atención → solución → validación → cierre
```

El módulo debe reutilizar funcionalidades existentes cuando sean compatibles:

- Clientes.
- Contactos.
- Usuarios.
- Equipos.
- Roles y permisos.
- Activities/calendario.
- Documents/almacenamiento privado.
- Notificaciones internas.
- Auditoría.
- Blade/Livewire.
- DataScopeService o mecanismo equivalente de alcance.

---

## 2. Funcionalidades incluidas y excluidas

### Incluido en V1

- Tickets de soporte.
- Catálogos configurables:
  - tipos,
  - categorías,
  - canales,
  - prioridades,
  - estados,
  - tiempos objetivo.
- Asignación manual.
- Reasignaciones con historial.
- Programación mediante `Activity`.
- Reprogramación append-only.
- Incidentes técnicos.
- Observaciones individuales.
- Línea de tiempo.
- Documentos/evidencias privadas.
- Tiempos reales y efectivos.
- Ciclos por reapertura.
- Dashboard y reportes.
- Policies, permisos y alcance.
- Auditoría con valores anteriores/nuevos.

### Excluido en V1

- Portal externo del cliente.
- Chat en tiempo real.
- Envío automático de WhatsApp.
- Envío automático de correos.
- Distribución automática de tickets.
- Facturación.
- Cobros.
- Inventario.
- IA.
- SLA contractuales avanzados.
- Horarios laborales y feriados.
- Integraciones externas nuevas.
- Aplicación móvil.

---

## 3. Revisión del modelo Activity y calendario existente

Archivos inspeccionados:

- `app/Models/Activity.php`
- `app/Services/ActivityService.php`
- `app/Services/CalendarEventService.php`
- `app/Http/Controllers/CalendarController.php`
- `database/migrations/2026_08_17_174100_create_activities_table.php`
- `resources/views/calendar/*.blade.php`

### Hallazgo principal

`Activity` ya está documentado como:

```php
Activity: the single source of truth for next actions.
```

Actualmente `activities` maneja:

- `subject_type`
- `subject_id`
- `owner_id`
- `title`
- `description`
- `scheduled_at`
- `executed_at`
- `result`
- `status`
- `priority`
- `reminder_at`

El calendario actual proyecta eventos desde `Activity` mediante `CalendarEventService`.

### Decisión propuesta

No crear una tabla paralela como fuente principal de programación.

Usar `Activity` como fuente de verdad para:

- fecha programada,
- responsable de la atención,
- estado de actividad programada,
- ejecución/cancelación,
- visualización en calendario,
- notificaciones internas existentes.

---

## 4. Propuesta de integración sin duplicidades

### Fuente de verdad

| Dato | Fuente de verdad |
|---|---|
| Estado del ticket | `support_tickets.status_id` |
| Responsable principal | `support_tickets.responsible_id` |
| Equipo responsable | `support_tickets.team_id` |
| Fecha/hora programada | `activities.scheduled_at` |
| Estado de atención calendarizada | `activities.status` |
| Responsable de actividad | `activities.owner_id` |
| Reprogramaciones | `support_reschedules` |
| Historial/auditoría | `activity_log` + tablas históricas |
| Documentos | `documents` |

### Ajuste requerido

Actualmente `Activity::SUBJECT_TYPES` permite:

```php
['lead', 'customer', 'opportunity']
```

Para soporte propongo extenderlo a:

```php
['lead', 'customer', 'opportunity', 'support_ticket']
```

De esa forma una actividad puede estar asociada directamente a un ticket.

---

## 5. Modelo de datos propuesto

### Catálogos

- `support_ticket_types`
- `support_categories`
- `support_channels`
- `support_priorities`
- `support_statuses`
- `support_target_times`

### Núcleo

- `support_tickets`
- `support_assignments`
- `support_ticket_updates`
- `support_reschedules`
- `support_status_periods`
- `support_resolution_cycles`

### Especializadas

- `support_incident_details`
- `support_observations`
- `support_observation_histories`
- `support_session_details`
- `support_session_participants`

### Tablas que NO recomiendo crear como fuente principal

| Tabla preliminar | Decisión |
|---|---|
| `support_schedules` | No como fuente de verdad. Usar `Activity`. |
| `support_ticket_documents` | No. Reutilizar `documents`. |
| `support_audits` | No. Reutilizar `activity_log`. |

---

## 6. Relaciones

```text
Customer
  hasMany SupportTicket

Contact
  hasMany SupportTicket as requester

SupportTicket
  belongsTo Customer
  belongsTo Contact requester
  belongsTo SupportTicketType
  belongsTo SupportCategory
  belongsTo SupportChannel
  belongsTo SupportPriority
  belongsTo SupportStatus
  belongsTo User responsible
  belongsTo Team
  hasMany Activity
  hasMany SupportTicketUpdate
  hasMany SupportAssignment
  hasMany SupportObservation
  hasOne SupportIncidentDetail
  hasMany SupportStatusPeriod
  hasMany SupportResolutionCycle
  morphMany Document

Activity
  morphTo subject
  subject may be SupportTicket

SupportObservation
  belongsTo SupportTicket
  belongsTo User responsible
  hasMany SupportObservationHistory
  morphMany Document
```

---

## 7. Estados y transiciones

### Estados iniciales

1. Nuevo
2. Asignado
3. Programado
4. En atención
5. En espera del cliente
6. En espera interna
7. Resuelto
8. Cerrado
9. Cancelado
10. Reabierto

### Transiciones propuestas

```text
Nuevo
  → Asignado
  → Cancelado

Asignado
  → Programado
  → En atención
  → En espera del cliente
  → En espera interna
  → Cancelado

Programado
  → En atención
  → En espera del cliente
  → En espera interna
  → Cancelado

En atención
  → En espera del cliente
  → En espera interna
  → Resuelto
  → Cancelado

En espera del cliente
  → En atención
  → Programado
  → Cancelado

En espera interna
  → En atención
  → Programado
  → Cancelado

Resuelto
  → Cerrado
  → Reabierto

Cerrado
  → Reabierto

Reabierto
  → Asignado
  → Programado
  → En atención
  → Cancelado
```

### Validaciones

| Estado | Validación |
|---|---|
| Asignado | requiere responsable |
| Programado | requiere actividad calendarizada |
| En atención | registra `work_started_at` |
| Resuelto | requiere solución documentada |
| Cerrado | requiere validación o motivo |
| Cancelado | requiere motivo |
| Reabierto | requiere motivo y nuevo ciclo |

---

## 8. Diferencia entre solicitud, incidente y observación

### Solicitud

La solicitud es el **ticket completo**.

Ejemplos:

- capacitación,
- reunión,
- ayuda funcional,
- asesoría,
- configuración,
- atención virtual,
- atención presencial.

### Incidente

Un incidente es un **tipo especializado de ticket**.

Tiene datos técnicos adicionales:

- sistema o producto afectado,
- módulo afectado,
- entorno,
- versión,
- pasos para reproducir,
- resultado esperado,
- resultado obtenido,
- severidad,
- navegador,
- sistema operativo,
- dispositivo,
- diagnóstico,
- causa,
- solución técnica,
- pruebas posteriores.

No todos los campos técnicos deben ser obligatorios al crear el ticket.

### Observación

Una observación es un **ítem hijo del ticket**.

Sirve para registrar puntos específicos a levantar, validar, rechazar o reabrir sin borrar historial.

---

## 9. Reglas de programación y reprogramación

### Programación

Para reuniones, capacitaciones y atenciones programadas:

- crear una `Activity` asociada al `SupportTicket`;
- usar `activities.scheduled_at` como fecha/hora oficial;
- usar `activities.owner_id` como responsable programado;
- usar `activities.status` como estado de la actividad.

Datos complementarios de reunión/capacitación:

- tema,
- objetivo,
- agenda,
- apuntes,
- resultado,
- próxima acción,
- lista de asistencia,
- materiales,
- evidencias.

Se guardan en:

- `support_session_details`
- `support_session_participants`
- `documents`

### Reprogramación

Cada reprogramación debe crear un registro append-only en `support_reschedules`.

Campos:

- `ticket_id`
- `activity_id`
- `old_scheduled_at`
- `new_scheduled_at`
- `reason`
- `rescheduled_by`
- `responsible_id`
- `created_at`

La actividad puede actualizar su `scheduled_at`, pero el historial queda preservado.

---

## 10. Reglas de solución, cierre y reapertura

### Resolver ticket

Requiere:

- resumen de solución,
- fecha de solución,
- usuario responsable,
- revisión de observaciones pendientes,
- evidencia cuando corresponda.

Estado resultante:

```text
Resuelto
```

### Cerrar ticket

Requiere:

- validación o motivo de cierre,
- fecha de cierre,
- usuario que cierra.

Si existen observaciones pendientes, se exige motivo obligatorio.

Estado resultante:

```text
Cerrado
```

### Reabrir ticket

Permitido desde:

- `Resuelto`
- `Cerrado`

Requiere:

- motivo,
- descripción de lo que sigue fallando,
- usuario que reabre,
- fecha,
- nuevo responsable si corresponde.

La reapertura:

- conserva solución anterior,
- conserva fechas anteriores,
- conserva KPI del ciclo anterior,
- crea nuevo ciclo,
- mantiene historial completo.

---

## 11. Diseño de medición de tiempos

### Timestamps reales en `support_tickets`

- `created_at`
- `assigned_at`
- `first_responded_at`
- `work_started_at`
- `resolved_at`
- `validated_at`
- `closed_at`

### Periodos en `support_status_periods`

Cada cambio de estado:

1. cierra el periodo anterior;
2. abre uno nuevo;
3. indica si pausa reloj efectivo.

Campos:

- `ticket_id`
- `cycle_id`
- `status_id`
- `period_type`
- `started_at`
- `ended_at`
- `pauses_clock`

### Reglas V1

| Estado | Efecto |
|---|---|
| En espera del cliente | detiene reloj efectivo |
| En espera interna | no detiene reloj automáticamente |
| Resuelto | termina reloj del ciclo |
| Cerrado | termina reloj |
| Cancelado | termina reloj |
| Reabierto | inicia nuevo ciclo |

---

## 12. Fórmulas KPI

Todas las fórmulas deben protegerse contra valores nulos y división por cero.

```text
Tiempo de asignación =
assigned_at - created_at
```

```text
Tiempo de primera respuesta =
first_responded_at - created_at
```

```text
Tiempo de inicio de atención =
work_started_at - created_at
```

```text
Tiempo total de resolución =
resolved_at - created_at
```

```text
Tiempo de cierre =
closed_at - created_at
```

```text
Tiempo efectivo =
tiempo total transcurrido - suma(periodos pausados)
```

```text
Tiempo promedio de resolución =
suma de tiempos de resolución / cantidad de tickets resueltos
```

```text
Porcentaje dentro del objetivo =
tickets resueltos dentro del tiempo objetivo / tickets resueltos * 100
```

```text
Porcentaje de tickets reabiertos =
tickets reabiertos / tickets resueltos o cerrados * 100
```

---

## 13. Permisos y alcance

Convención existente: permisos por módulo, no por nombre de rol.

Prefijo propuesto:

```text
support.*
```

### Permisos mínimos

```text
support.view.any
support.view.team
support.view.own
support.create
support.update
support.assign
support.reassign
support.schedule
support.reschedule
support.priority.update
support.attention.start
support.updates.create
support.observations.create
support.observations.lift
support.observations.validate
support.observations.reject
support.resolve
support.close
support.reopen
support.cancel
support.reports.view
support.catalogs.manage
support.target-times.manage
```

### Alcance de datos

Hallazgo:

`DataScopeService` actualmente no reconoce permisos `support.view.any` ni `support.view.team`.

Actualmente toma visibilidad global desde:

- `leads.view.any`
- `customers.view.any`
- `opportunities.view.any`

Y visibilidad de equipo desde:

- `leads.view.team`

### Decisión propuesta

Extender `DataScopeService` o crear un wrapper de alcance para soporte que use:

- `support.view.any`
- `support.view.team`
- `support.view.own`

El alcance debería considerar:

- responsable del ticket,
- equipo responsable,
- creador del ticket para tickets nuevos sin responsable.

---

## 14. Casos límite

- Ticket `Nuevo` sin responsable.
- Contacto solicitante que no pertenece al cliente.
- Catálogo desactivado pero usado históricamente.
- Reasignación a usuario inactivo.
- Cambio de prioridad sin auditoría.
- Programación sin fecha/hora.
- Reprogramación de actividad ya realizada.
- Resolución con observaciones pendientes.
- Cierre sin validación.
- Reaperturas múltiples.
- División por cero en KPI.
- Timestamps nulos.
- Periodos abiertos por errores previos.
- Documentos eliminados físicamente vs evidencia histórica.
- Aislamiento entre equipos.
- Ticket visible por cliente pero asignado a otro equipo.
- Actividad calendarizada con responsable distinto al responsable principal.
- Incidente sin detalle técnico completo al inicio.
- Registros soft-deleted usados históricamente.

---

## 15. Plan por bloques

### S00 — Análisis del sistema existente

Estado: completado a nivel diseño.

Incluye:

- inspección,
- reutilización,
- modelo de datos,
- decisiones,
- criterios de aceptación preliminares.

### S01 — Base del ticket

Incluye:

- catálogos,
- tickets,
- correlativo,
- permisos,
- policies,
- asignación,
- estados básicos,
- pruebas críticas.

### S02 — Programación de atenciones

Incluye:

- reuniones,
- capacitaciones,
- modalidad,
- integración con `Activity`,
- calendario,
- reprogramaciones,
- participantes.

### S03 — Incidentes y errores

Incluye:

- detalle técnico,
- evidencias,
- diagnóstico,
- solución.

### S04 — Observaciones

Incluye:

- registro individual,
- responsables,
- levantamiento,
- validación,
- rechazo,
- reapertura,
- historial.

### S05 — Tiempos y ciclos

Incluye:

- primera respuesta,
- periodos,
- pausas,
- resolución,
- cierre,
- reapertura,
- ciclos.

### S06 — Dashboard y reportes

Incluye:

- KPI,
- filtros,
- reportes,
- rendimiento.

### S07 — Estabilización

Incluye:

- pruebas integrales,
- seguridad,
- optimización,
- auditoría,
- documentación.

---

## 16. Estimación revisada

| Bloque | Estimación |
|---|---:|
| S01 | 2–3 días |
| S02 | 2 días |
| S03 | 1–1.5 días |
| S04 | 2 días |
| S05 | 2 días |
| S06 | 1.5–2 días |
| S07 | 1–2 días |

Estimación total:

```text
11.5 a 14.5 días
```

---

## Decisiones confirmadas para S01

1. **Alcance de datos**  
   El alcance se basa en el responsable y equipo del ticket. No se basa en el owner comercial del cliente.

2. **Primera respuesta**  
   `first_responded_at` se registra solamente cuando el asesor documenta una respuesta real al cliente. Las notas internas no cuentan.

3. **Calendario**  
   Se aprueba extender `Activity` para aceptar `support_ticket` como subject. S01 solo deja el soporte base; los flujos de programación quedan para S02.

4. **Código correlativo**  
   Formato aprobado:

   ```text
   SUP-YYYY-00001
   ```

5. **Cierre con observaciones pendientes**  
   Bloqueado por defecto. La excepción con permiso y motivo obligatorio queda para los bloques de observaciones/cierre, no para S01.

---

## S01 implementado — Base del ticket

S01 incorpora:

- catálogos base de soporte;
- tabla y modelo `SupportTicket`;
- correlativo `SUP-YYYY-00001` mediante `CodeGeneratorService`;
- permisos base `support.*`;
- `SupportTicketPolicy` y `SupportTicketScopeService`;
- creación de ticket en estado `Nuevo` sin responsable;
- asignación/reasignación manual con historial;
- cancelación básica con motivo;
- actualizaciones internas y respuestas al cliente;
- regla de primera respuesta: nota interna no marca `first_responded_at`, respuesta al cliente sí;
- extensión mínima de `Activity` para reconocer `support_ticket`;
- vistas base de listado, creación y detalle;
- menú `Soporte` visible según permiso;
- pruebas S01 enfocadas.

S01 no incluía todavía programación, incidentes, observaciones, ciclos/KPI ni dashboard. Esos puntos se agregaron en la implementación posterior S02–S07 descrita abajo.

---

## Implementación S02–S07

- **S02:** `Activity` permanece como fuente única de fecha, estado y responsable. Las sesiones viven en `support_session_details`; modalidades fijas (`virtual`, `presential`, `phone`, `not_applicable`) son slugs internos, no ENUM MySQL. Las reprogramaciones se conservan append-only en `support_reschedules`.
- **S03:** `support_incident_details` agrega el detalle técnico opcional uno-a-uno del ticket. Las evidencias usan la relación `Document` polimórfica ya existente; no se creó almacenamiento paralelo.
- **S04:** observaciones y sus transiciones quedan en tablas separadas y con historial append-only, sin blobs JSON. Rechazo, reapertura y no aplicable exigen motivo.
- **S05:** períodos y ciclos registran la línea de tiempo. Solo `en-espera-del-cliente` pausa el reloj; resolver/cancelar finaliza el ciclo y reapertura inicia uno nuevo. Resolver exige resumen y cerrar exige motivo, bloqueando observaciones no finalizadas salvo excepción autorizada por el llamador.
- **S06:** `SupportDashboardService` expone conteos y promedios seguros frente a nulos/división por cero. El dashboard filtra por rango de creación, cliente, responsable, equipo, tipo, categoría, prioridad, estado y modalidad, y puede exportar el conjunto filtrado a CSV nativo.
- **S07:** migración y modelos preservan soft deletes en detalles/observaciones. La ficha del ticket expone acciones de ciclo de vida, actualización, programación/reprogramación, incidente y observaciones. Los adjuntos privados se pueden cargar para ticket, observación, incidente y sesión. La asistencia se registra como participantes (nombre, email y bandera de asistencia) por sesión.

### Limitaciones V1 restantes

- La ficha mantiene formularios compactos; no incorpora edición/eliminación individual de participantes ni una grilla de reportes paginada.
- La exportación CSV refleja tickets y la primera modalidad de sesión asociada; no es un exportador de detalle de observaciones o asistencia.
