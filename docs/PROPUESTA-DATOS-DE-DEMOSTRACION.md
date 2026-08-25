# Propuesta: Sistema de datos de demostración

## Estado

Etapa 1: análisis y propuesta.  
No se implementó código todavía.

## Objetivo

Implementar una funcionalidad administrativa para llenar el CRM Maia con información ficticia, coherente y visualmente útil para presentaciones comerciales.

La finalidad es que el CRM no se vea vacío y que puedan demostrarse correctamente:

- Dashboard.
- Tablas principales.
- Calendario.
- Reportes.
- Indicadores.
- Gráficas.
- Flujo comercial completo.

Los datos generados deben ser eliminables de forma segura sin afectar información real.

---

## 1. Inventario de módulos compatibles detectados

Los módulos reales detectados que pueden participar en la demo son:

| Módulo | Evidencia principal | Uso en demo |
|---|---|---|
| Dashboard | `DashboardController`, `DashboardService`, `resources/views/dashboard/index.blade.php` | Alimentar KPIs, gráficos y tendencia comercial. |
| Prospectos / Leads | `app/Models/Lead.php`, tabla `leads` | Prospectos nuevos, en seguimiento, convertidos y perdidos. |
| Clientes | `app/Models/Customer.php`, tabla `customers` | Empresas ficticias peruanas. |
| Contactos | `app/Models/Contact.php`, tabla `contacts` | Personas de contacto por cliente. |
| Oportunidades | `Opportunity`, `OpportunityStageHistory`, tablas `opportunities`, `opportunity_stage_histories` | Embudo comercial con etapas variadas. |
| Actividades / Calendario | `Activity`, tabla `activities`, ruta `calendar.index` | Llamadas, reuniones, tareas, visitas y actividades vencidas. |
| Productos / Servicios | `Product`, tabla `products` | Servicios comerciales cotizables. |
| Cotizaciones | `Quotation`, `QuotationItem`, tablas `quotations`, `quotation_items` | Cotizaciones en distintos estados. |
| Documentos | `Document`, tabla `documents` | Documentos demo con archivo placeholder controlado. |
| Campañas | `CampaignTemplate`, `CampaignRun`, `CampaignStep`, `CampaignParticipant`, `CampaignActionItem` | Campaña demo básica para presentación. |
| Reportes | `ReportController`, `ReportsService` | Datos filtrables por fechas, asesor y estado. |
| Automatizaciones | `AutomationRule`, `AutomationAction`, `AutomationConditionGroup`, `AutomationCondition`, `AutomationExecution` | Reglas demo deshabilitadas o en modo test. |

Módulos con mayor riesgo o para segunda iteración:

- Soporte técnico.
- Integraciones externas.
- Email real.
- WhatsApp real.
- Google Calendar.
- Webhooks.
- Notificaciones externas.

---

## 2. Dependencias y orden recomendado

### Orden de creación

1. Catálogos existentes.
2. Usuarios demo / asesores demo.
3. Productos demo.
4. Prospectos demo.
5. Clientes demo.
6. Contactos demo.
7. Oportunidades demo.
8. Historial de etapas.
9. Actividades demo.
10. Cotizaciones demo.
11. Ítems de cotización.
12. Documentos demo.
13. Campañas demo.
14. Automatizaciones demo deshabilitadas o en modo test.

### Orden de eliminación

La eliminación debe hacerse en orden inverso:

1. Automatizaciones demo.
2. Campañas demo dependientes.
3. Documentos demo.
4. Ítems de cotización.
5. Cotizaciones.
6. Actividades.
7. Historial de oportunidades.
8. Oportunidades.
9. Contactos.
10. Clientes.
11. Prospectos.
12. Productos.
13. Usuarios demo, si fueron creados por el lote.
14. Registro del lote demo.

La eliminación nunca debe basarse en nombres, correos, fechas, teléfonos o rangos de IDs.

---

## 3. Estrategia de identificación de datos demo

### Opción evaluada A: columnas `is_demo` y `demo_batch_id`

Agregar estas columnas a cada tabla participante.

Ventajas:

- Filtros simples.
- Etiquetas visuales directas.

Desventajas:

- Muy invasivo.
- Requiere muchas migraciones.
- Riesgo de olvidar tablas.
- Riesgo alto en relaciones polimórficas.
- Complica módulos opcionales o futuros.

### Opción evaluada B: ledger central

Crear tablas centrales:

- `demo_data_batches`
- `demo_data_records`

#### `demo_data_batches`

Campos propuestos:

- `id`
- `uuid`
- `scenario_name`
- `status`
- `modules`
- `record_counts`
- `created_by`
- `started_at`
- `finished_at`
- `reset_at`
- `deleted_at`
- `created_at`
- `updated_at`

#### `demo_data_records`

Campos propuestos:

- `id`
- `batch_id`
- `module`
- `table_name`
- `model_type`
- `record_id`
- `created_by`
- `created_at`
- `updated_at`

### Recomendación

Usar **ledger central**.

Motivos:

- Es más seguro para este CRM.
- Evita alterar todas las tablas funcionales.
- Permite trazabilidad por lote.
- Permite eliminación precisa.
- Funciona mejor con relaciones polimórficas.
- Reduce el riesgo de confundir datos reales con datos demo.
- Permite ampliar módulos después sin migrar todas las tablas existentes.

---

## 4. Permiso y acceso administrativo

Agregar permiso específico:

```txt
demo-data.manage
```

Asignarlo únicamente al rol `admin`.

No asignarlo a:

- `supervisor`
- `vendedor`
- asesores sin permiso explícito

Rutas protegidas con middleware:

```php
middleware(['auth', 'active', 'can:demo-data.manage'])
```

Ubicación de interfaz:

```txt
Configuración → Datos de demostración
```

Rutas propuestas:

```txt
GET     /admin/demo-data
POST    /admin/demo-data/load
POST    /admin/demo-data/load-modules
POST    /admin/demo-data/{batch}/reset
DELETE  /admin/demo-data/{batch}
```

---

## 5. Escenario base

Nombre del escenario:

```txt
Presentación comercial completa
```

### Usuarios demo

Crear 3 asesores demo, salvo que se decida reutilizar usuarios existentes:

- Carla Rojas Demo
- Miguel Torres Demo
- Valeria Salazar Demo

Correos:

```txt
carla.rojas@maia-demo.example
miguel.torres@maia-demo.example
valeria.salazar@maia-demo.example
```

### Productos / servicios demo

Montos en PEN:

- Implementación CRM Comercial.
- Consultoría de Procesos Comerciales.
- Capacitación Equipo de Ventas.
- Soporte Mensual CRM.
- Automatización de Seguimiento.

### Prospectos demo

Cantidad sugerida: 20 a 30 prospectos.

Distribuidos por:

- Web.
- Referido.
- Campaña.
- Feria.
- Redes sociales.

Estados según catálogos existentes:

- Nuevo.
- Contactado.
- Calificado.
- No calificado.
- Convertido.
- Perdido.

### Clientes y contactos

Cantidad sugerida:

- 8 a 12 empresas ficticias.
- 1 a 2 contactos por empresa.

Ejemplos:

- Andes Soluciones Empresariales SAC.
- Innova Textil Lima SAC.
- Grupo Comercial Miraflores SAC.
- Norte Digital Consultores SAC.
- Sur Andino Servicios Empresariales SAC.

Correos reservados:

```txt
contacto@andes-demo.example
ventas@innova-demo.example
operaciones@grupo-demo.example
```

### Oportunidades

Cantidad sugerida: 15 a 20 oportunidades.

Distribución:

- Nueva oportunidad.
- Contacto realizado.
- Reunión programada.
- Propuesta enviada.
- Negociación.
- Ganada.
- Perdida.

Fechas distribuidas entre:

- Mes anterior.
- Mes actual.
- Próximos días.

### Actividades y calendario

Cantidad sugerida: 40 a 60 actividades.

Tipos:

- Llamadas.
- Reuniones.
- Tareas.
- Correos.
- Visitas.
- Notas.

Estados:

- `pending`
- `completed`
- `overdue`
- `cancelled`
- `in_process`

Deben alimentar:

- Dashboard.
- Calendario.
- Listados de actividades.
- Reportes por fechas.

### Cotizaciones

Cantidad sugerida: 8 a 12 cotizaciones.

Estados:

- `draft`
- `sent`
- `accepted`
- `rejected`
- `expired`

Cada cotización debe tener ítems relacionados con productos demo.

### Documentos

Recomendación:

- Crear documentos demo con archivo placeholder real y controlado.
- Evitar metadata sin archivo, porque podría romper descarga o vista.

Ejemplos:

- Propuesta comercial demo.
- Acta de reunión demo.
- Brief de requerimientos demo.

### Campañas

Crear una campaña demo básica:

```txt
Reactivación de prospectos fríos
```

Con:

- Template.
- Run.
- Steps.
- Participants basados en prospectos demo.
- Action items con estados variados.

### Automatizaciones

Crear 2 o 3 automatizaciones demo en modo seguro:

- Seguimiento rápido de prospectos web.
- Crear tarea para oportunidad caliente.
- Recordatorio de cotización enviada.

Deben quedar:

- Deshabilitadas; o
- En modo test, si el modelo actual lo soporta.

No deben disparar acciones reales.

---

## 6. Bloqueo de acciones externas

La generación demo debe ejecutarse dentro de un contexto explícito:

```php
DemoDataContext::run(fn () => ...);
```

Durante ese contexto deben bloquearse explícitamente:

- Envío de correos reales.
- Envío de WhatsApp.
- SMS.
- Webhooks externos.
- Google Calendar.
- Gmail API.
- Meta / WhatsApp API.
- Notificaciones push externas.
- Pagos.
- Emisión de comprobantes.
- Jobs outbound.
- Automatizaciones activas.
- Servicios de IA.

No alcanza con usar correos o teléfonos ficticios. La ejecución externa debe estar bloqueada por diseño.

---

## 7. Interfaz de usuario

Pantalla propuesta:

```txt
Configuración → Datos de demostración
```

Debe mostrar:

- Estado actual.
- Si hay datos demo activos.
- Lote activo.
- Fecha de generación.
- Usuario que generó el lote.
- Módulos incluidos.
- Conteo por módulo.
- Historial de lotes.
- Acciones disponibles.

Acciones:

- Cargar demostración completa.
- Cargar módulos seleccionados.
- Restablecer lote.
- Eliminar lote.

Etiqueta visible cuando haya datos demo:

```txt
Datos de demostración activos
```

Confirmaciones obligatorias:

- Antes de cargar demo.
- Antes de restablecer.
- Antes de eliminar.

Resumen final:

- Registros creados.
- Registros eliminados.
- Módulos procesados.
- Errores encontrados.

---

## 8. Datos reales existentes

Antes de generar demo, el sistema debe detectar si existen datos reales.

Si existen, mostrar advertencia:

```txt
El sistema contiene información real. Los datos de demostración se agregarán sin modificarla. Podrás identificarlos y eliminarlos posteriormente.
```

Reglas:

- No eliminar datos reales.
- No modificar datos reales.
- No convertir datos reales en demo.
- No vincular datos demo con datos reales sin aprobación explícita.

Recomendación inicial:

- Crear usuarios demo y entidades demo propias.
- Evitar depender de clientes/contactos reales.

---

## 9. Reportes y filtros demo/reales

Filtro solicitado:

- Solo datos reales.
- Solo datos demo.
- Datos reales y demo.

Evaluación:

- Dashboard: alcance medio.
- Reportes: alcance mayor, porque `ReportsService` contiene múltiples consultas.

Recomendación:

Implementarlo como segunda iteración, salvo que se apruebe ampliar el alcance.

Primera versión propuesta:

- Generar datos demo correctamente.
- Permitir eliminarlos/restablecerlos.
- Alimentar dashboard y reportes existentes.
- No alterar silenciosamente el comportamiento actual.

Segunda versión:

- Agregar filtro `real/demo/both` en Dashboard y Reportes.

---

## 10. Archivos y migraciones propuestas

### Crear

```txt
database/migrations/*_create_demo_data_batches_table.php
database/migrations/*_create_demo_data_records_table.php
app/Models/DemoDataBatch.php
app/Models/DemoDataRecord.php
app/Services/DemoData/DemoDataService.php
app/Services/DemoData/DemoScenarioBuilder.php
app/Services/DemoData/DemoDataPurger.php
app/Services/DemoData/DemoDataContext.php
app/Http/Controllers/Admin/DemoDataController.php
resources/views/admin/demo-data/index.blade.php
app/Console/Commands/DemoDataSeedCommand.php
app/Console/Commands/DemoDataClearCommand.php
tests/Feature/Admin/DemoData/*
```

### Modificar

```txt
routes/web.php
database/seeders/RolesAndPermissionsSeeder.php
resources/views/layouts/partials/sidebar.blade.php
```

Posibles modificaciones adicionales, según hallazgos de implementación:

```txt
Servicios de email
Servicios de WhatsApp
Servicios de notificaciones
Servicios de webhooks
Servicios de calendario
Servicios de automatizaciones
DashboardService
ReportsService
```

---

## 11. Riesgos y decisiones pendientes

### Riesgos

| Riesgo | Severidad | Mitigación |
|---|---:|---|
| Acciones externas reales | Alta | `DemoDataContext` + guards en servicios outbound. |
| Eliminación accidental de datos reales | Alta | Ledger central obligatorio. |
| Documentos sin archivo real | Media | Crear placeholder controlado. |
| Campañas y soporte con muchas dependencias | Media | Implementar por bloques. |
| Filtros demo/reales en reportes | Media | Segunda iteración. |
| Usuarios demo visibles en administración | Baja/media | Ledger + nombres/correos demo claros. |

### Decisiones pendientes

1. ¿Incluimos soporte técnico en primera versión?
2. ¿Creamos usuarios demo o usamos usuarios existentes?
3. ¿Documentos demo tendrán archivo placeholder descargable?
4. ¿El filtro real/demo/both entra ahora o en segunda iteración?
5. ¿Automatizaciones demo deshabilitadas o modo test?

---

## 12. Plan de pruebas

Se deben crear pruebas para verificar:

1. Solo un administrador autorizado puede acceder.
2. Un usuario sin permiso no puede generar datos demo.
3. La demostración completa genera registros relacionados.
4. La generación por módulo respeta dependencias.
5. Todos los registros creados quedan identificados en ledger.
6. El restablecimiento elimina y vuelve a generar solo datos demo.
7. La eliminación borra únicamente datos demo.
8. Los datos reales permanecen intactos.
9. Una falla durante generación no deja datos parciales.
10. No se ejecutan correos, WhatsApp, webhooks ni integraciones externas.
11. No se duplican lotes por doble ejecución.
12. Los conteos mostrados coinciden con registros generados.
13. Las relaciones y llaves foráneas permanecen válidas.

---

## 13. Alcance recomendado para Etapa 2

Primera implementación segura:

- Ledger central.
- Permiso `demo-data.manage`.
- Pantalla administrativa.
- Cargar demo completa.
- Cargar por módulo.
- Restablecer lote.
- Eliminar lote.
- Datos demo para:
  - usuarios,
  - prospectos,
  - clientes,
  - contactos,
  - oportunidades,
  - actividades,
  - productos,
  - cotizaciones,
  - documentos placeholder,
  - campañas básicas,
  - automatizaciones test/deshabilitadas.
- Bloqueo explícito de acciones externas.
- Pruebas feature principales.

Fuera de la primera implementación, salvo aprobación explícita:

- Soporte técnico.
- Filtro real/demo/both en todos los reportes.
- Cambios profundos en dashboard/reportes.
- Integraciones reales.

---

## Recomendación final

Aprobar la Etapa 2 con enfoque seguro y reversible:

1. Usar ledger central.
2. Crear permiso `demo-data.manage`.
3. Generar escenario “Presentación comercial completa”.
4. Bloquear acciones externas por contexto.
5. Eliminar/restablecer únicamente registros identificados en ledger.
6. Mantener datos reales completamente aislados.

No comenzar implementación hasta aprobación explícita.

---

## 14. Addendum aprobado antes de implementación

Fecha: 2026-08-24.

Estas decisiones reemplazan o precisan la propuesta inicial y son obligatorias para la Etapa 2.

### 14.1 Ledger central aprobado

Se aprueba la estrategia de ledger central mediante:

- `demo_data_batches`
- `demo_data_records`

No se agregará `is_demo` / `demo_batch_id` a todas las tablas funcionales en esta primera implementación.

### 14.2 Guard permanente contra acciones externas

`DemoDataContext` solo cubre el momento de generación. No alcanza como barrera de seguridad posterior.

La Etapa 2 debe incluir además un guard permanente, por ejemplo:

```php
DemoDataGuard::assertNotDemo($modelOrType, $id, $operation);
```

Este guard debe consultar el ledger antes de permitir acciones reales sobre registros demo, incluso cuando la generación ya terminó.

Debe aplicarse, según los puntos reales de salida del proyecto, antes de:

- Enviar correos.
- Enviar WhatsApp.
- Enviar SMS, si existiera.
- Disparar webhooks externos.
- Sincronizar Google Calendar / Outlook Calendar.
- Crear o procesar notificaciones externas.
- Ejecutar automatizaciones con efectos reales.
- Consumir servicios de IA.
- Procesar pagos o comprobantes, si existieran.
- Despachar jobs outbound relacionados con entidades demo.

Ejemplo obligatorio: si un usuario abre una cotización demo e intenta enviarla, el sistema debe bloquear el envío real porque la cotización está registrada en `demo_data_records`, aunque `DemoDataContext` ya no esté activo.

### 14.3 Usuarios demo propios

La demo debe crear usuarios propios para asesores demo. No se reutilizarán usuarios reales.

Usuarios propuestos:

- Carla Rojas Demo.
- Miguel Torres Demo.
- Valeria Salazar Demo.

Reglas obligatorias:

- Deben quedar registrados en `demo_data_records`.
- Deben tener `is_active = false` para no poder iniciar sesión.
- Deben tener contraseña aleatoria fuerte, no conocida ni compartida.
- Deben usar correos reservados `@maia-demo.example`.
- No deben recibir correos ni notificaciones.
- Pueden aparecer como responsables en actividades, oportunidades, cotizaciones y reportes.

### 14.4 Documentos demo con placeholder real

Los documentos demo deben tener archivos placeholder descargables y claramente identificados como demostración.

Reglas obligatorias:

- Crear archivo físico en disco privado/controlado.
- Registrar el `Document` en `demo_data_records`.
- Registrar también la relación de archivo para purga.
- Al eliminar o restablecer el lote, eliminar el registro y el archivo físico asociado.
- No dejar metadata huérfana apuntando a archivos inexistentes.

### 14.5 Automatizaciones demo deshabilitadas

Las automatizaciones demo deben quedar deshabilitadas mediante:

```txt
is_active = false
```

El campo `mode` existe actualmente con valores `live` y `test`, pero la seguridad de demo no debe depender de `mode=test`. La barrera obligatoria es que la regla demo esté deshabilitada y que los servicios outbound consulten el ledger.

### 14.6 Soporte técnico fuera de primera versión

Soporte técnico queda explícitamente fuera de la primera implementación.

No crear demo para:

- Tickets de soporte.
- Categorías de soporte.
- Sesiones de soporte.
- Estados de soporte.
- Cualquier módulo de soporte técnico.

### 14.7 Filtro real/demo/both queda para segunda iteración

El filtro completo:

- Solo datos reales.
- Solo datos demo.
- Datos reales y demo.

queda fuera de la primera versión.

En esta primera versión sí debe implementarse:

- Indicador global de datos demo activos.
- Lote activo visible.
- Advertencia si existen datos reales.
- Etiqueta visual discreta `Demo` en registros cuando sea viable y de bajo riesgo.

### 14.8 Campañas solo visuales y documentarias

Las campañas demo se crearán únicamente para presentación visual.

No deben ejecutar:

- Correos.
- WhatsApp.
- Webhooks.
- Jobs outbound.
- Automatizaciones reales.
- Cualquier integración externa.

### 14.9 Ledger reforzado

`demo_data_batches` debe incluir como mínimo:

- `id`
- `uuid` único
- `scenario_name`
- `status`
- `modules` JSON
- `record_counts` JSON
- `created_by` FK nullable o restrictiva según convenga
- `started_at`
- `finished_at`
- `reset_at`
- `deleted_at`
- timestamps

Estados controlados propuestos para lote:

- `running`
- `completed`
- `failed`
- `deleting`
- `deleted`
- `resetting`
- `reset`

La columna puede ser `string` con constantes de modelo y validación de servicio para mantener compatibilidad cross-driver; si se agrega restricción DB, debe ser compatible con MySQL y SQLite de tests.

`demo_data_records` debe incluir como mínimo:

- `id`
- `batch_id` FK a `demo_data_batches`
- `module`
- `table_name`
- `model_type`
- `record_id`
- `created_by`
- timestamps

Índices/restricciones obligatorias:

- FK `batch_id`.
- Índice por `batch_id`.
- Índice por `module`.
- Índice por `model_type, record_id`.
- Índice por `table_name, record_id`.
- Restricción única global para impedir que la misma entidad quede asociada a dos lotes diferentes:

```txt
unique(model_type, record_id)
```

Justificación: `model_type` es la forma más compatible con Eloquent y relaciones polimórficas; evita colisiones cuando dos modelos pudieran compartir `record_id` en tablas distintas. `table_name` se mantiene como dato auditable e índice auxiliar, pero la unicidad principal debe ser por modelo real + ID real.

No se usará solamente `unique(batch_id, model_type, record_id)`, porque esa restricción evita duplicados dentro de un lote pero permite que el mismo registro quede asociado a múltiples lotes, lo que haría insegura la purga.

Toda generación, reset y eliminación debe ejecutarse transaccionalmente cuando sea posible.

La eliminación debe respetar dependencias y también purgar archivos físicos asociados.

### 14.10 Carga por módulo sin vincular datos reales

La carga por módulo debe mostrar previamente qué dependencias se crearán.

Ejemplo: si se selecciona cotizaciones y no existe cliente demo en el lote activo, la UI debe informar que también creará dependencias demo como cliente, contacto, oportunidad o productos, según lo requerido por el modelo actual.

Regla obligatoria:

- No vincular registros demo con información real.
- Si falta una dependencia, crearla como demo y registrarla en el ledger.

---

## 15. Estados reales confirmados del sistema

Esta lista se basa en catálogos, migraciones, modelos y enums actuales. No se deben inventar estados nuevos sin autorización.

### 15.1 Prospectos / Leads

Catálogo `lead_statuses`, sembrado en `database/seeders/CatalogSeeder.php`:

| Nombre | Slug | Final |
|---|---|---:|
| Nuevo | `nuevo` | No |
| Contactado | `contactado` | No |
| Calificado | `calificado` | No |
| No calificado | `no-calificado` | No |
| Convertido | `convertido` | Sí |
| Perdido | `perdido` | Sí |

Otros valores reales relacionados:

- `interest_level`: `bajo`, `medio`, `alto`.
- Fuentes: `web`, `referido`, `campana`, `llamada`, `feria`, `redes-sociales`, `otro`.

### 15.2 Oportunidades

Catálogo `pipeline_stages`, sembrado en `database/seeders/CatalogSeeder.php`:

| Nombre | Slug | Tipo |
|---|---|---|
| Nueva oportunidad | `nueva-oportunidad` | `open` |
| Contacto realizado | `contacto-realizado` | `open` |
| Reunión programada | `reunion-programada` | `open` |
| Propuesta enviada | `propuesta-enviada` | `open` |
| Negociación | `negociacion` | `open` |
| Ganada | `ganada` | `won` |
| Perdida | `perdida` | `lost` |

Tipos reales de etapa:

- `open`
- `won`
- `lost`

Otros valores reales relacionados:

- Prioridad: `baja`, `media`, `alta`.
- Motivos de pérdida: `precio`, `competencia`, `sin-respuesta`, `sin-presupuesto`, `no-interesado`, `otro`.

### 15.3 Actividades / Calendario

Estados reales de `activities.status`, según migración `2026_08_17_174100_create_activities_table.php`:

- `pending`
- `in_process`
- `completed`
- `cancelled`
- `overdue`

Prioridades reales:

- `baja`
- `media`
- `alta`

Tipos reales de actividad, sembrados en `CatalogSeeder`:

- `llamada`
- `whatsapp`
- `correo`
- `reunion`
- `visita`
- `tarea`
- `nota`

### 15.4 Cotizaciones

Estados reales de `quotations.status`, según migración `2026_08_17_174120_create_quotations_tables.php` y exportador:

- `draft` — Borrador
- `sent` — Enviada
- `accepted` — Aceptada
- `rejected` — Rechazada
- `expired` — Vencida
- `voided` — Anulada

La demo puede usar `draft`, `sent`, `accepted`, `rejected` y `expired`. `voided` existe, pero solo debe usarse si aporta valor visual.

### 15.5 Campañas

#### CampaignTemplate

Objetivos reales (`campaign_templates.objective`):

- `reactivation`
- `nurturing`
- `cross_sell`
- `onboarding`
- `custom`

Estados reales (`campaign_templates.status`):

- `draft`
- `active`
- `inactive`

Uso concreto en demo:

- Objetivo: `reactivation`.
- Estado: `draft` o `inactive`.

La campaña demo será visual/documentaria. No debe quedar `active` si eso habilita ejecución operacional.

#### CampaignRun

Estados reales (`campaign_runs.status`):

- `draft`
- `scheduled`
- `running`
- `paused`
- `completed`
- `cancelled`

Uso concreto en demo:

- `draft` para campaña preparada pero no ejecutable.
- `scheduled` solo si se necesita mostrar una campaña planificada en pantalla.
- `completed` para alimentar vistas históricas sin disparar acciones.

No usar en v1:

- `running`, salvo que se confirme que no despacha acciones reales.
- `paused`, salvo necesidad visual.
- `cancelled`, salvo necesidad visual.

Decisión de seguridad: la demo no debe crear campañas realmente ejecutables. Si se crean `campaign_action_items`, serán registros visuales con estados estáticos y sin jobs outbound.

#### CampaignStep

Estados reales (`campaign_steps.status`):

- `active`
- `inactive`

Uso concreto en demo:

- `inactive` para pasos de plantilla si existe riesgo de ejecución.
- `active` solo para pasos visuales ligados a un `CampaignRun` demo que no tenga mecanismo de despacho real durante v1.

Cada paso debe usar `action_type_id` de `activity_types` reales:

- `llamada`
- `correo`
- `reunion`
- `tarea`

No usar pasos que representen WhatsApp, webhook o integraciones externas para evitar interpretación de ejecución real.

#### CampaignParticipant

Tipos reales (`campaign_participants.subject_type`):

- `lead`
- `customer`
- `contact`
- `opportunity`

Estados reales (`campaign_participants.status`):

- `active`
- `excluded`
- `cancelled`

Uso concreto en demo:

- `active` para participantes incluidos en la campaña visual.
- `excluded` para mostrar un caso de exclusión, si aporta valor.
- No usar `cancelled` salvo necesidad visual específica.

Regla obligatoria: los participantes deben apuntar únicamente a registros demo del mismo lote. No se permite vincular participantes demo con leads, clientes, contactos u oportunidades reales.

#### CampaignActionItem

Estados reales (`campaign_action_items.status`):

- `pending`
- `in_process`
- `completed`
- `overdue`
- `cancelled`
- `not_applicable`

Uso concreto en demo:

- `pending` para próximas acciones visuales.
- `completed` para mostrar avance histórico.
- `overdue` para alimentar alertas y demostrar seguimiento.
- `not_applicable` opcional para mostrar un caso documentario.

No usar en v1:

- `in_process`, salvo que se confirme que no activa flujos de ejecución.
- `cancelled`, salvo necesidad visual.

Regla obligatoria: los action items demo no deben crear ni despachar correos, WhatsApp, webhooks, notificaciones externas ni jobs outbound.

### 15.6 Automatizaciones y ejecuciones relacionadas

#### AutomationRule

`automation_rules` no tiene columna `status`. Sus controles reales son:

- `is_active`: booleano.
- `mode`: string con valores reales `live` y `test`.

Valores reales de `AutomationMode`:

- `live`
- `test`

Uso concreto en demo:

- `is_active = false` obligatorio.
- `mode = test` puede guardarse como valor defensivo/documentario, porque existe en el código, pero no será la barrera principal.

Regla de seguridad: ninguna automatización demo debe quedar activa. La seguridad no depende de `mode=test`, sino de `is_active=false` más el guard permanente por ledger en servicios/jobs outbound.

#### AutomationExecution

Estados reales de `AutomationExecutionStatus`:

- `queued`
- `running`
- `success`
- `partial`
- `failed`
- `skipped`
- `circuit-broken`

Uso concreto en demo:

- En v1 no se crearán ejecuciones reales salvo que sean estrictamente necesarias para una vista de historial.
- Si se crean registros visuales de historial, usar solo estados terminales seguros:
  - `success`
  - `skipped`
  - `failed`

No usar en v1:

- `queued`
- `running`

Motivo: esos estados pueden ser interpretados por comandos de recuperación o workers como pendientes de procesamiento.

#### AutomationExecutionStep

Estados reales de `AutomationStepStatus`:

- `pending`
- `simulated`
- `running`
- `success`
- `failed`
- `skipped`

Uso concreto en demo:

- Si se crean steps visuales, usar solo estados terminales seguros:
  - `simulated`
  - `success`
  - `failed`
  - `skipped`

No usar en v1:

- `pending`
- `running`

Motivo: `pending` / `running` pueden ser interpretados como trabajo real o recuperable por comandos de automatización.

### 15.7 Clientes y contactos

Clientes (`customers.status`):

- `activo`
- `inactivo`

Uso concreto en demo:

- `activo` para clientes usados en oportunidades, cotizaciones y documentos.
- `inactivo` opcional para mostrar variedad en listados.

Contactos no tienen columna `status`; usan:

- `is_active`: booleano.
- `is_primary`: booleano.

Uso concreto en demo:

- `is_active = true`.
- un contacto principal por cliente cuando corresponda.

### 15.8 Productos

Productos no tienen columna `status`; usan:

- `is_active`: booleano.

Uso concreto en demo:

- `is_active = true` para productos/servicios cotizables.

Categorías reales:

- `consultoria`
- `software`
- `soporte`
- `capacitacion`

Monedas reales:

- `PEN`
- `USD`
- `EUR`

Uso concreto en demo:

- Usar preferentemente `PEN` para presentación comercial en Perú.
- No mezclar monedas en agregados sin etiqueta explícita.

---

## 16. Restricción global definitiva del ledger

La restricción `unique(batch_id, model_type, record_id)` no es suficiente porque permite asociar la misma entidad a dos lotes distintos.

La Etapa 2 debe usar restricción global:

```txt
unique(model_type, record_id)
```

Razones:

- `model_type` representa la clase Eloquent real.
- Evita colisiones entre tablas que comparten IDs numéricos.
- Protege la purga: una entidad no puede pertenecer a dos lotes.
- Es compatible con relaciones polimórficas.

`table_name` debe mantenerse como metadato de auditoría e índice auxiliar:

```txt
index(table_name, record_id)
```

No será la restricción única principal, salvo que durante implementación se detecte un modelo sin clase Eloquent estable. Si eso ocurriera, debe informarse antes de cambiar el diseño.

---

## 17. Guard permanente en jobs

El guard permanente debe aplicarse en dos niveles:

1. Antes de despachar acciones externas.
2. Dentro del job, justo antes de procesar la acción externa.

Esto protege el sistema si un job relacionado con datos demo hubiera quedado en cola antes de la purga, reset o cambio de estado.

Regla obligatoria:

```txt
Todo job que envíe correo, WhatsApp, webhook, notificación externa, calendario, IA, pago o integración debe consultar el ledger antes de ejecutar el efecto real.
```

Si el job detecta una entidad demo, debe bloquear la salida externa y marcar el intento como omitido, fallido seguro o estado equivalente existente, sin llamar al proveedor externo.

Puntos de especial atención detectados:

- `Jobs/V2/SendOutboundDelivery.php`
- `Jobs/V2/SendWhatsAppMessage.php`
- `Jobs/V2/SendEmailMessage.php`
- `Jobs/V2/RunAutomationAction.php`
- jobs/comandos de automatización que reprocesen steps pendientes
- servicios de Google Calendar / integraciones externas

La implementación debe verificar cada punto real antes de modificarlo.

---

## 18. Alcance final de Etapa 2

### Incluido

La Etapa 2 implementará:

- Ledger central:
  - `demo_data_batches`
  - `demo_data_records`
- Restricción global `unique(model_type, record_id)`.
- Permiso `demo-data.manage` asignado solo a `admin`.
- Pantalla administrativa:
  - `Configuración → Datos de demostración`
- Cargar demostración completa.
- Cargar uno o varios módulos compatibles.
- Preview de dependencias antes de generar por módulo.
- Restablecer lote demo.
- Eliminar lote demo.
- Historial de lotes.
- Conteos por módulo.
- Advertencia si existen datos reales.
- Indicador global de datos demo activos.
- Identificación del lote activo.
- Etiqueta visual `Demo` donde sea viable y de bajo riesgo.
- Usuarios demo propios, inactivos y registrados en ledger.
- Documentos demo con archivos placeholder descargables.
- Purga de archivos físicos al eliminar/restablecer.
- Guard permanente por ledger para acciones externas.
- Guard dentro de jobs antes de ejecutar efectos reales.
- Comandos Artisan reutilizables para seed/clear demo.
- Pruebas feature principales.

### Módulos incluidos

- Usuarios demo asesores.
- Prospectos / Leads.
- Clientes.
- Contactos.
- Oportunidades.
- Historial de etapas de oportunidad.
- Actividades / Calendario.
- Productos / Servicios.
- Cotizaciones.
- Ítems de cotización.
- Documentos con placeholder.
- Campañas básicas visuales/documentarias.
- Automatizaciones demo deshabilitadas.
- Dashboard alimentado por datos demo.
- Reportes alimentados por datos demo mediante el comportamiento actual.

### Módulos excluidos de v1

- Soporte técnico.
- Filtro completo `real/demo/both` en dashboard/reportes.
- Ejecución real de campañas.
- Envío real de correos.
- Envío real de WhatsApp.
- Webhooks reales.
- Google Calendar / Outlook Calendar real.
- Pagos.
- Comprobantes.
- Servicios de IA.
- Cualquier integración externa no revisada.

---

## 19. Pruebas definitivas de Etapa 2

La implementación debe cubrir como mínimo:

1. Solo un administrador con `demo-data.manage` puede acceder a la pantalla.
2. Usuario sin permiso no puede acceder ni ejecutar generación/reset/delete.
3. Demo completa genera registros relacionados.
4. Generación por módulo muestra y crea dependencias demo necesarias.
5. La generación por módulo no vincula registros demo con datos reales.
6. Todos los registros creados quedan registrados en `demo_data_records`.
7. La restricción `unique(model_type, record_id)` impide asociar una entidad a dos lotes.
8. Reset elimina y regenera solo datos demo.
9. Delete elimina únicamente datos demo.
10. Datos reales permanecen intactos.
11. Falla durante generación hace rollback y no deja lote parcial como completado.
12. Archivos placeholder se crean y son descargables.
13. Archivos placeholder se eliminan físicamente al borrar/resetear lote.
14. Usuarios demo quedan inactivos y no pueden iniciar sesión.
15. Usuarios demo pueden figurar como responsables en reportes/actividades/oportunidades.
16. Automatizaciones demo quedan `is_active = false`.
17. Campañas demo no despachan acciones reales.
18. Guards bloquean correos/WhatsApp/webhooks/calendario/notificaciones externas sobre registros demo.
19. Guards también bloquean dentro de jobs al momento de procesar.
20. No se duplican lotes por doble ejecución o ejecución concurrente.
21. Conteos de UI coinciden con `demo_data_records`.
22. Relaciones y llaves foráneas permanecen válidas.
23. Pruebas existentes relevantes siguen pasando.

---

## 20. Cierre definitivo de propuesta

La propuesta queda cerrada con las siguientes decisiones:

- Ledger central aprobado.
- Restricción global por entidad aprobada: `unique(model_type, record_id)`.
- Demo users propios e inactivos aprobados.
- Documentos demo con placeholder físico aprobados.
- Soporte técnico excluido de v1.
- Filtro `real/demo/both` diferido a segunda iteración.
- Automatizaciones demo deshabilitadas.
- Campañas demo solo visuales/documentarias.
- Guard permanente por ledger obligatorio.
- Guard dentro de jobs obligatorio.
- No se crearán estados nuevos.
- No se vincularán datos demo con datos reales.
- No se ejecutará ninguna integración externa real sobre datos demo.

No quedan decisiones pendientes para iniciar Etapa 2, salvo aprobación explícita final del usuario.

La implementación no debe comenzar hasta recibir esa aprobación final.
