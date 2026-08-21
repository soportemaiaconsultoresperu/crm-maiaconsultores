# V2 — Hoja de ruta (corregida) · CRM Maia Consultores

> **Estado**: BORRADOR preaprobado por la dirección. Pendiente de aprobación final del cierre de B10.
> **Reemplaza**: el documento Hoja-de-ruta inicial entregado con la primera respuesta.
> **Aplica**: arreglos arquitectónicos `C-01` a `C-06` y decisiones `D-01` a `D-21`.

---

## 0. Cambios respecto del documento anterior

| ID | Antes | Ahora |
|---|---|---|
| `C-01` | `spatie/activitylog` era la fuente principal de eventos del motor | `activitylog` sigue siendo la **auditoría**. El motor escucha **eventos Laravel explícitos** emitidos por los servicios V1 al final de cada transacción. |
| `C-02` | `automation_rules.conditions_json` y `actions_json` almacenaban la lógica | Las tablas `automation_conditions` y `automation_actions` son **fuente principal**. JSON sólo en `payload_json` por acción para configuración específica. |
| `C-03` | Algunos ENUM nuevos previstos en MySQL | V2 usa **VARCHAR + clase `enum` PHP + validación + índice**, hasta que el catálogo sea administrable. **No modificar ENUM existentes** de V1 sin justificación. |
| `C-04` | Mezcla de credenciales en `settings` y `integration_accounts` | `settings` sólo **flags + parámetros no secretos**. Credenciales y tokens van **exclusivamente** a `integration_accounts` con cifrado. |
| `C-05` | Livewire incluido pero sin uso claro | **Conservado**. Se usa en V2 para automatizaciones, bandeja WhatsApp, webforms, filtros y preferencias. |
| `C-06` | Soporte “multi-organización” neutro | **No multi-tenant**. V1 y V2 corresponden a una sola organización. Varios formularios, cuentas y equipos sin columna `organization_id`. |

---

## 1. Principios arquitectónicos actualizados

1. **Un bus de eventos Laravel por encima de la lógica actual.** Los servicios V1 emiten `event(new XxxEvent(...))` *después* del `DB::commit`. `spatie/activitylog` no se modifica. `C-01` Reduce disparos duplicados: la nueva familia de listeners consume exclusivamente el bus Laravel.
2. **El motor de automatizaciones es asincrónico por default.** Toda acción se enqueue como `Job` (`ShouldQueue`). Sólo la **proyección** del evento es síncrona (para registrar el execution_id).
3. **Cada ejecución idempotente.** `automation_executions.idempotency_key` se calcula como `sha1(rule_id + event_class + subject_id + event_payload_hash)`. Se persiste y se combina con `UNIQUE` + `Cache::lock()` para prevenir duplicados durante ventana de carrera.
4. **Las credenciales jamás tocan `settings`.** Sólo `integration_accounts.config_json` (cifrado con `Crypt::encryptString`) y nunca en logs ni en respuestas.
5. **Plantillas libres de código.** Variables en lista permitida; no se permiten `{{ Blade }}`, `<?php`, `eval`, ni expresiones.
6. **Datos personales en logs nunca completos.** `app/Logging/RedactPiiProcessor` redacta email, números de documento y teléfonos antes de escribir a disco.
7. **Los datos cifrados no son buscables.** Para localizar email o teléfono se mantienen `email_norm`, `phone_norm`, `whatsapp_norm` y `doc_number_norm` (ya presentes en V1) y, cuando se necesite, hashes `sha256` indexados en columnas paralelas.
8. **Acciones externas después de la transacción.** Toda llamada a Meta/Google/Outlook/SMTP va en un `Job` que arranca cuando el `DB::commit` ya ocurrió.
9. **Asignación siempre respeta `DataScopeService`.** Ninguna automatización puede asignar a un usuario que el actor no podría asignar manualmente.
10. **Webhooks con verificación de firma obligatoria.** Fallar la firma es `400` y `webhook_events.status='rejected'`.

---

## 2. Diseño preliminar de base de datos (corregido)

### 2.1 Motor de automatizaciones (B12)

- `automation_rules` — `id, name, description, trigger_event (VARCHAR 80), is_active (bool), order (int), mode (enum PHP: live|test), created_by, owner_id (FK users), created_at, updated_at, deleted_at`. **No** almacena `conditions_json` ni `actions_json` completos.
- `automation_conditions` — `id, rule_id (FK), group_id (FK nullable), position, field, operator, value, value_type, created_at, updated_at`. Estas son las condiciones reales, consultables.
- `automation_condition_groups` — `id, rule_id (FK), logical_operator (VARCHAR 8: AND/OR), position`. Para agrupar.
- `automation_actions` — `id, rule_id (FK), position, type (VARCHAR 80), channel (VARCHAR 40 nullable), payload_json (JSON nullable), recipient_strategy (VARCHAR 80 nullable), retry_policy_json (JSON nullable), created_at, updated_at`.
- `automation_executions` — `id, rule_id (FK), trigger_event, subject_type, subject_id, idempotency_key (UNIQUE), status (VARCHAR 16: queued|running|success|partial|failed|skipped|circuit-broken), started_at, finished_at, error_class, error_message, attempt (int), created_at`.
- `automation_execution_steps` — `id, execution_id, action_id, status (VARCHAR 16), response_json (JSON nullable), queued_at, started_at, finished_at, attempt, error_class, error_message`.
- `automation_cycle_breaks` — `id, rule_id, subject_type, subject_id, detected_at, reason`. `INDEX (rule_id, subject_type, subject_id)`.

### 2.2 Integraciones (transversal — B11..B17)

- `integration_accounts` — `id, provider (VARCHAR 40: gmail|outlook|smtp|whatsapp|google_calendar|outlook_calendar), label, owner_id (FK users nullable), is_shared (bool), team_id (FK teams nullable), is_active (bool), test_mode (bool), config_json (TEXT encrypted), credentials_encrypted (TEXT encrypted), scopes (JSON nullable), last_synced_at, last_refresh_at, expires_at, error_class, error_message, created_at, updated_at`. Indexed `(provider, is_active)`.
- `oauth_states` — `id, provider (VARCHAR 40), state (VARCHAR 64 UNIQUE), redirect_after (TEXT), payload_json (JSON), expires_at, created_at`. Para conexiones OAuth in-flight.
- `webhook_events` — `id, provider (VARCHAR 40), external_event_id (VARCHAR 191), payload_hash (CHAR 64), signature (TEXT), received_at, processed_at, status (VARCHAR 16), error_class, error_message, created_at`. `UNIQUE (provider, external_event_id)`. **No** se duplica por proveedor (decisión `C-04` sobre `whatsapp_webhook_events`).

### 2.3 Correo (B13)

- `email_messages` — `id, account_id (FK), direction (VARCHAR 8: outbound|inbound), provider_message_id (VARCHAR 191), thread_id (VARCHAR 191), in_reply_to (VARCHAR 191 nullable), from_email, from_name, subject, body_html (LONGTEXT), body_text (LONGTEXT), status (VARCHAR 16: queued|sent|delivered|bounced|failed|received), sent_at, received_at, error_class, error_message, related_lead_id, related_customer_id, related_opportunity_id, related_quotation_id, related_contact_id, created_by, created_at, updated_at`. `UNIQUE (account_id, provider_message_id)`.
- `email_participants` — `id, message_id (FK), kind (VARCHAR 8: to|cc|bcc|from), email, name`.
- `email_templates` — `id, name, slug (VARCHAR 80 UNIQUE), subject, body_html, body_text, variables_json (JSON), is_active (bool), version (int), owner_id (FK users nullable), created_by, created_at, updated_at`. Versionado básico.
- `email_template_versions` — `id, template_id (FK), version (int), subject, body_html, body_text, variables_json, snapshot_by (FK users), created_at`.
- `email_attachments` — `id, message_id (FK), document_id (FK documents nullable), filename, mime, size, storage_path, sha256 (CHAR 64)`.

### 2.4 WhatsApp (B14)

- `whatsapp_accounts` — `id, account_id (FK integration_accounts), phone_number_id, business_id, phone_number, display_name, status, verified_at, last_event_at, created_at, updated_at`.
- `whatsapp_templates` — `id, account_id (FK), name (VARCHAR 80), language (VARCHAR 16), status (VARCHAR 16: draft|pending|approved|rejected|disabled), category (VARCHAR 40), body, header_kind (VARCHAR 20), header_text, footer_text, variables_json (JSON), approved_at, rejected_reason, synced_at`. `UNIQUE (account_id, name, language)`.
- `whatsapp_conversations` — `id, account_id (FK), contact_id (FK contacts nullable), customer_id (FK customers nullable), lead_id (FK leads nullable), phone_number, contact_name, status (VARCHAR 16: open|closed|archived), assigned_to (FK users nullable), last_message_at, last_direction (VARCHAR 8), consent_at, opt_out_at, window_opens_at, window_closes_at, created_at, updated_at`. `INDEX (assigned_to, status)`.
- `whatsapp_messages` — `id, conversation_id (FK), provider_message_id (VARCHAR 191), wamid (VARCHAR 191), direction (VARCHAR 8), type (VARCHAR 16), body (TEXT), template_id (FK nullable), status (VARCHAR 16), error_class, error_message, sent_at, delivered_at, read_at, idempotency_key (CHAR 64 UNIQUE)`. `UNIQUE (account_id, provider_message_id)`.
- `whatsapp_consent_log` — `id, contact_id (FK), conversation_id (FK), type (VARCHAR 16: opt_in|opt_out), source (VARCHAR 40), granted_at, revoked_at, created_at`.

### 2.5 Formularios web (B15)

- `web_forms` — `id, name, slug (VARCHAR 80 UNIQUE), description, is_active (bool), owner_id (FK users nullable), submit_success_path, redirect_url, confirm_message, css_json (JSON), settings_json (JSON), created_by, updated_by, deleted_at`.
- `web_form_fields` — `id, form_id (FK), position, name, label, type (VARCHAR 16), required (bool), validation_json (JSON), options_json (JSON), default_value, is_visible (bool), is_pii (bool)`.
- `web_form_submissions` — `id, form_id (FK), payload_json (JSON), status (VARCHAR 16: received|validated|rejected|spam), rejection_reason, ip_hash (CHAR 64), user_agent_hash (CHAR 64), geolocation_json (JSON nullable), utm_json (JSON), lead_id (FK nullable), customer_id (FK nullable), contact_id (FK nullable), created_at, created_by (nullable)`.
- `web_form_submissions_log` — `id, form_id, payload_hash (CHAR 64), submitted_at, ip_hash (CHAR 64)`. `UNIQUE (form_id, payload_hash)`. **Idempotencia**. Política de retención: registros `rejected`/`spam` purgados a 30 días; `validated` siguen la política del dominio.
- `web_form_throttle` — Una entrada en `cache` (no DB) con `Cache::lock('form:'.$formId.':'.$ipHash, 5)`.

### 2.6 Calendarios externos (B16)

- `calendar_connections` — `id, account_id (FK), provider (VARCHAR 16: google|outlook), external_calendar_id, display_name, is_default (bool), sync_token (TEXT nullable), last_synced_at, last_error, is_active (bool), created_at, updated_at`.
- `calendar_event_links` — `id, activity_id (FK activities), provider (VARCHAR 16), external_event_id (VARCHAR 191), external_calendar_id (VARCHAR 191), etag (VARCHAR 191), sync_status (VARCHAR 16: pending|synced|conflict|removed), last_status_at, error_message, created_at, updated_at`. `UNIQUE (activity_id, provider)`.

### 2.7 Notificaciones y entregas (B17)

- `notification_preferences` — `id, user_id (FK), subject_type (VARCHAR 80), channel (VARCHAR 16: database|mail|whatsapp|webhook), enabled (bool), scope (VARCHAR 16: optional|administrative|security)`. `UNIQUE (user_id, subject_type, channel)`.
- `outbound_deliveries` — `id, channel (VARCHAR 16), recipient_ref (VARCHAR 191), template_id (FK nullable), related_entity_type (VARCHAR 80), related_entity_id (BIGINT), account_id (FK nullable), status (VARCHAR 16), attempts (int), next_attempt_at, last_error, last_response_code, idempotency_key (CHAR 64 UNIQUE)`. **Idempotencia por operación**, no por payload_hash.

---

## 3. Eventos del motor (alineado con `C-01`)

### 3.1 Nuevos eventos Laravel

```
App\Events\V2\LeadCreated
App\Events\V2\LeadAssigned
App\Events\V2\LeadStatusChanged
App\Events\V2\LeadDeactivated
App\Events\V2\OpportunityCreated
App\Events\V2\OpportunityStageChanged
App\Events\V2\OpportunityWon
App\Events\V2\OpportunityLost
App\Events\V2\QuotationCreated
App\Events\V2\QuotationSent
App\Events\V2\QuotationAccepted
App\Events\V2\QuotationWillExpire      // scheduler-driven, no se emite desde servicio
App\Events\V2\ActivityCompleted
App\Events\V2\ActivityOverdue          // scheduler-driven
App\Events\V2\CustomerDeactivated
App\Events\V2\ContactPrimaryChanged
App\Events\V2\ContactDeactivated
App\Events\V2\CustomerIdle            // scheduler-driven
App\Events\V2\WebFormSubmitted
App\Events\V2\IntegrationAccountConnected
App\Events\V2\IntegrationAccountDisconnected
App\Events\V2\AutomationRuleCreated
App\Events\V2\AutomationRuleUpdated
App\Events\V2\AutomationRuleDeleted
App\Events\V2\AutomationExecutionRequested
App\Events\V2\AutomationStepSucceeded
App\Events\V2\AutomationStepFailed
App\Events\V2\AutomationCycleDetected
```

### 3.2 Reglas de emisión

- Todo `event(new XxxEvent(...))` se invoca **después** del `DB::commit` del servicio. No se usa `DB::afterCommit()` en el servicio, sólo la emisión explícita.
- Los listeners proyectan el evento y crean un `automation_executions` idempotente.
- `AutomationExecutionRequested` es el **único** evento que el motor emite **al exterior** (para UI de historial y para otros módulos).
- `WebhookReceived` (interno) traduce `webhook_events` en eventos V2 específicos (`MessageReceived`, etc.).

### 3.3 Disambiguación con `activitylog`

`activitylog` continúa registrando los cambios para auditoría. **No se suscribe** al bus de eventos V2, no genera ejecuciones, no dispara notificaciones. La capa de auditoría es estrictamente append-only y la de motor es estrictamente ignorante de la auditoría.

### 3.4 Disparadores soportados (B12)

| Disparador | Evento | Notas |
|---|---|---|
| Prospecto creado | `LeadCreated` | — |
| Prospecto asignado | `LeadAssigned` | — |
| Prospecto sin contacto | scheduler | Días configurables en la regla |
| Cambio de estado del prospecto | `LeadStatusChanged` | — |
| Oportunidad creada | `OpportunityCreated` | — |
| Cambio de etapa | `OpportunityStageChanged` | — |
| Oportunidad ganada | `OpportunityWon` | — |
| Oportunidad perdida | `OpportunityLost` | — |
| Cotización creada | `QuotationCreated` | — |
| Cotización enviada | `QuotationSent` | — |
| Cotización aceptada | `QuotationAccepted` | — |
| Cotización próxima a vencer | `QuotationWillExpire` | Días configurables (rango 1–365) |
| Actividad creada | `ActivityCreated` | — |
| Actividad vencida | `ActivityOverdue` | — |
| Cliente sin actividad | `CustomerIdle` | Días configurables; **desde la última `Activity` completada** ligada al cliente |
| Formulario web recibido | `WebFormSubmitted` | — |

### 3.5 Acciones permitidas (B12)

- Crear tarea. (Delega a `ActivityService::create`.)
- Asignar responsable. (Valida `DataScope`.)
- Cambiar estado. (Delega a la `Service` responsable.)
- Cambiar etapa. (Delega a `OpportunityService`.)
- Agregar etiqueta. (Usa el catálogo de etiquetas — V2 nuevo.)
- Enviar notificación interna. (Por `Notification` con `via()` ampliado.)
- Enviar correo. (Adapter `EmailProvider`.)
- Enviar plantilla de WhatsApp. (Adapter `WhatsAppProvider` + `whatsapp_templates` aprobado.)
- Crear actividad. (Vía `ActivityService::create`.)
- Registrar nota. (Vía `ActivityService` con `type_id = nota`.)
- Ejecutar un webhook autorizado. (B12 fase tardía, con lista de destinos, HTTPS, timeout, firma, idempotencia, SSRF bloqueado, registro, permisos admin.)

### 3.6 Modo de prueba

`mode='test'`: el listener evalúa la condición y registra las acciones en `automation_execution_steps` con `status='simulated'` y muestra en UI "qué habría hecho". No se enqueuea `Job` externo. No se modifica ninguna entidad. No se envía correo ni WhatsApp. No se asigna nada.

### 3.7 Asignación

Estrategias soportadas en V1 del motor:

- **A un vendedor específico**: fija `assigned_to = X`.
- **A un equipo**: fija `assigned_to = lead.user->supervisorId` (o coordinador del equipo, configurable).
- **Al vendedor responsable actual**: deja el mismo.
- **Distribución entre integrantes activos del equipo**: estrategia determinista `hash(subject_id) % active_count`. Auditable: el execution_step guarda `recipient_strategy` y el índice calculado.

Ninguna estrategia puede asignar a un usuario fuera del `DataScope` del actor que creó la regla.

### 3.8 Webhook autorizado (acción)

- Lista de destinos en `integration_accounts` o en `settings` (no secretos).
- HTTPS obligatorio.
- Timeout 10 s.
- Firma `HMAC-SHA256` con secreto por destino.
- Idempotencia por `idempotency_key` calculado por la regla + el `execution_id`.
- Bloqueo de IPs privadas (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16, .local, IPv6 link-local). Sin resolución DNS recursiva al vuelo.
- PATCH/POST/GET según configuración.
- Registro de la respuesta (código + tamaño, truncado a 4 KB) en `automation_execution_steps.response_json`.
- Permiso `automations.webhook.execute` requerido.

---

## 4. Decisiones B11 — Infraestructura

| # | Decisión | Elección final |
|---|---|---|
| 1a | Cola dev | `database` |
| 1b | Cola prod | `redis` |
| 1c | Configurabilidad | `QUEUE_CONNECTION` en `.env` (`sync`, `database`, `redis`, `beanstalkd`, `sqs`, `failed`) |
| 1d | Worker | `php artisan queue:work` con `supervisor` (Linux) o `nssm` (Windows) / proceso equivalente en Docker |
| 1e | Horizon | **No se instala** inicialmente |
| 2a | Almacenamiento dev | `local` con `storage/app/private` |
| 2b | Almacenamiento prod | `s3` (paquete `league/flysystem-aws-s3-v3`) |
| 2c | Configurabilidad | `FILESYSTEM_DISK` en `.env` |
| 2d | Accesos | Sólo URLs firmadas (`Storage::temporaryUrl`) cuando aplique. **No** symlinks públicos para privados. |
| 3a | Cifrado | `Crypt::encryptString` (Laravel núcleo) |
| 3b | APP_KEY | **Verificar** que no esté en git al inicializar |
| 3c | Logs | No escribir `APP_KEY`; `RedactPiiProcessor` enmascara emails, DNI, RUC, teléfonos |
| 3d | Respaldo/rotación | Documentar procedimiento en `docs/v2/operations/key-rotation.md` como B11 |

---

## 5. Decisiones B12 — Automatizaciones

| # | Decisión | Elección final |
|---|---|---|
| 4 | "Cliente sin actividad" | Sobre la **última `Activity` completada** relacionada con el cliente. Días configurables en la regla. |
| 5 | "Cotización próxima a vencer" | Días configurables por regla (rango 1–365). |
| 6 | Webhook autorizado | En alcance. Implementación post-acciones internas, con todas las guardas. |
| 7 | Modo de prueba | Aprueba: evalúa condiciones y registra, sin lanzar acciones reales. |
| 8a | Asignación a vendedor específico | Soporte completo. |
| 8b | Asignación a equipo | Fija `assigned_to = supervisorId` o coordinador configurable. |
| 8c | Asignación al responsable actual | Mantiene el actual. |
| 8d | Distribución entre equipo | Determinista (`hash % count`). Auditable. |

---

## 6. Decisiones B13 — Correo

| # | Decisión | Elección final |
|---|---|---|
| 9a | Una cuenta personal por vendedor | Sí. |
| 9b | Una o más cuentas compartidas | Sí. |
| 9c | Permisos para usar cuentas compartidas | Permisos independientes `email.shared.use`. |
| 10a | Envío SMTP, Gmail, Outlook | Sí. |
| 10b | Recepción Gmail / Outlook | Vía APIs oficiales. |
| 10c | IMAP | **No** en V2. |
| 10d | "Conversación sincronizada" | Sólo si hay recepción real, no envío. |
| 11a | Plantillas editables en UI | Sí. |
| 11b | Campos | Asunto, contenido, variables, vista previa, envío de prueba, activar/desactivar, versionado. |
| 11c | Variables | Lista permitida. Sin código embebido. |

---

## 7. Decisiones B14 — WhatsApp

| # | Decisión | Elección final |
|---|---|---|
| 12a | Proveedor | Meta WhatsApp Cloud API directo. |
| 12b | Adaptador | Contrato `WhatsAppProvider` permite un BSP futuro. |
| 12c | Número | Admite número y modalidad aprobadas por Meta. Sin asumir dedicado. |
| 13a | Crear prospecto desde número desconocido | Configurable en `settings`. **Default: false**. |
| 13b | Normalizar teléfono | Sí. |
| 13c | Duplicados | Búsqueda por `phone_norm`. Un prospecto por número. |
| 13d | Asignación | Sin asignar inicialmente o regla de la organización. |
| 13e | Origen | `WhatsApp`. |
| 14a | Conversaciones asignadas | Visibles para responsable, supervisor del equipo y administradores. |
| 14b | Sin asignar | Visibles para vendedores autorizados del equipo. |
| 14c | Scope | Toujours `DataScopeService`. |
| 15a | Plantillas | Sincronización desde Meta. |
| 15b | CRM lee, previsualiza, filtra, actualiza estado | Sí. |
| 15c | Sólo plantillas `approved` | Sí. |
| 15d | Crear/aprobar plantillas en CRM | No en V2. |

---

## 8. Decisiones B15 — Formularios

| # | Decisión | Elección final |
|---|---|---|
| 16a | CAPTCHA | Cloudflare Turnstile. |
| 16b | Rate limit | `throttle:10,1` por IP+form. |
| 16c | Honeypot | Campo oculto `company_website` rechazado si está lleno. |
| 16d | Validación servidor | Estricta. |
| 16e | Anti-duplicados | `payload_hash` por formulario. |
| 17a | Datos validados | Guardar. |
| 17b | Consentimiento | Guardar. |
| 17c | UTM | Guardar. |
| 17d | IP / UA | Hash (no texto). |
| 17e | Payload rechazado | No indefinidamente. Retención 30 días. |
| 18a | Varios formularios | Permitido. |
| 18b | URL | `/f/{slug}`. |
| 18c | Multi-tenant | **No**. Una sola organización. |

---

## 9. Decisiones B16 — Calendarios

| # | Decisión | Elección final |
|---|---|---|
| 19a | Conexión por vendedor | Sí. |
| 19b | Conexión compartida | Permitida. |
| 19c | Supervisor | Ve actividades del equipo por `DataScope`. No accede a información privada externa no sincronizada. |
| 20a | Sincronización CRM → externos | Sí, primero. |
| 20b | Cambios externos | Segunda fase: cambio de fecha, cancelación, RSVP. |
| 20c | Calendario personal completo | No. |
| 20d | Fuente principal | `Activity` del CRM. |
| 20e | Conflictos | Marcado en `calendar_event_links.sync_status='conflict'`. |

---

## 10. Decisiones B17 — Notificaciones

| # | Decisión | Elección final |
|---|---|---|
| 21a | Fallos críticos de integración a admins | Obligatorio. |
| 21b | Desconexión / expiración de cuenta | Obligatorio. |
| 21c | Automatizaciones detenidas por ciclos | Obligatorio. |
| 21d | Errores permanentes tras agotar reintentos | Obligatorio. |
| 21e | Notificaciones comerciales | Configurables por usuario. |
| 21f | Detección de nuevo dispositivo | **No** en V2. |
| 21g | SLA | **No** en V2. |

---

## 10.1 Decisiones complementarias (post-aprobación)

### D-22 — Redis en producción

| Punto | Decisión |
|---|---|
| Modo de hospedaje | **Auto-hospedado** dentro del servidor, mediante **Docker**. |
| Servicios externos prohibidos en V2 | Redis Cloud, Upstash, AWS ElastiCache, Memorystore u otro administrado. No se contratan. |
| Cola en desarrollo | `database` (driver nativo Laravel). |
| Cola en producción | `redis`. |
| Configurabilidad | `QUEUE_CONNECTION` en `.env`. `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DB`, `REDIS_CACHE_DB` en `.env`. |
| Persistencia | `appendonly yes` con `appendfsync everysec`. Volumen `redis-data` montado en `/data`. |
| Contraseña | Requerida, vía `REDIS_PASSWORD`. No se registra en repo, docs ni DB. |
| Red | Docker network privada (`internal: true`). Redis **no expone** puerto a Internet. |
| Memoria | `maxmemory 512mb` y `maxmemory-policy allkeys-lru`. Configurable vía `.env`. |
| Reinicio | `restart: unless-stopped`. Health check `redis-cli ping`. |
| Servidor no definido aún | B11 deja la configuración lista en `.env.example` y un `docker-compose.v2.yml` de referencia, parametrizada. |

### D-23 — Cuentas externas

| Proveedor | Política de B11 |
|---|---|
| **Meta Business** (WhatsApp Cloud API) | Las credenciales se entregan en **B14**. B11 sólo define el contrato `WhatsAppProvider` y la tabla `integration_accounts`. No bloquea B11. |
| **Google Cloud** (Gmail / Calendar) | Las credenciales se confirman en **B13 / B16**. B11 sólo define los contratos. No bloquea B11. |
| **Microsoft Azure / Entra ID** | Sólo si se confirma Outlook. B11 sólo define el contrato. No bloquea B11. |
| Regla común | B11 **no crea proyectos, aplicaciones ni credenciales** en ningún proveedor. No hace falta tenerlas para entregar la infraestructura. |

> **Corrección aplicada durante B11 (constraints composer.json)**: B10 propuso instalar `socialiteproviders/google ^5.0` y `socialiteproviders/microsoft ^5.0`. Al ejecutar `composer update` se confirmó que **ninguno de los dos paquetes ha publicado rama 5.x**; las versiones estables vigentes son `4.1.0` (Google) y `4.10.0` (Microsoft). Por indicación de la dirección se relajaron ambos constraints a `^4.0`. La decisión funcional `D-23` y los contratos de B11 no cambian: las credenciales reales de Google/Microsoft siguen sin existir en V2 y la integración OAuth se cablea en B13/B16. Ver también nota en `docs/v2/00-baseline.md` §7.

### D-24 — SMTP inicial

| Punto | Decisión |
|---|---|
| Proveedor inicial | SMTP de la **cuenta empresarial disponible** mediante `MAIL_MAILER=smtp`. |
| Servicios prohibidos en V2 | Resend, Postmark, Mailgun, Amazon SES. No se contratan. |
| Configurabilidad | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` en `.env`. |
| Procesamiento | Vía `ShouldQueue`. Una `Job` `SendQueuedMail` con `tries=3`, `backoff=[30, 120, 600]`. |
| Pruebas | `Mail::fake()` en todas las pruebas que cubran flujo de notificaciones y registros. |
| Credenciales prohibidas | Contraseña SMTP **jamás** en DB, docs, logs, ni `composer.json`. Sólo en `.env` (no commiteado). |
| Gmail / Outlook | Reservados para **sincronización de cuentas y respuestas** (B13 y B16). No sustituyen SMTP. |

---

## 11. Plan de implementación por bloques (revisado)

| Bloque | Alcance | Salidas verificables |
|---|---|---|
| **B10** | Auditoría de V1 | `docs/v2/00-baseline.md`, `docs/v2/01-roadmap.md`, suite en verde |
| **B11** | Infraestructura de integraciones | `app/Integrations/Contracts/*`, `integration_accounts`, `webhook_events`, `oauth_states`, `RedactPiiProcessor`, scheduler configurado, `supervisor` documentado |
| **B12** | Motor de automatizaciones | `app/Events/V2/*`, `app/Listeners/V2/*`, `app/Jobs/V2/*`, `automation_rules`, `automation_conditions`, `automation_actions`, `automation_executions`, `automation_execution_steps`, UI admin con Livewire |
| **B13** | Correo | Conectores Gmail/Outlook/SMTP, `email_messages`, `email_templates`, editor con variables, firma, sincronización |
| **B14** | WhatsApp | `MetaProvider`, `whatsapp_accounts`, `whatsapp_templates`, `whatsapp_conversations`, `whatsapp_messages`, bandeja Livewire, webhooks |
| **B15** | Formularios | `web_forms`, `web_form_fields`, `web_form_submissions`, layout público, Turnstile, honeypot, rate-limit |
| **B16** | Calendarios | `calendar_connections`, `calendar_event_links`, adapters Google/Outlook, sync + conflictos |
| **B17** | Estabilización | `notification_preferences`, `outbound_deliveries`, pruebas integrales, monitoreo, docs |

---

## 12. Criterios de aceptación de B10 (revisados)

1. V2-Hoja-de-ruta aprobada por la dirección. ✅
2. Respaldo externo del estado de V1. ⏸️ no ejecutado — no autorizado en el bloque de correcciones.
3. `docs/v2/00-baseline.md` creado. ✅
4. `docs/v2/01-roadmap.md` actualizado con correcciones `C-01..C-06` y decisiones `D-01..D-21`. ✅
5. `php artisan test` en verde. ✅ 372/372.
6. Mapa de integración a V1 (en baseline). ✅
7. Decisiones pendientes convertidas en decisiones explícitas. ✅
8. No se modifica código de V1. ✅
9. No se inicializa git. ✅
10. No se modifica `composer.json`. ✅
11. No se ejecuta `composer install`. ✅
12. No se ejecuta respaldo sobreescribiendo archivos. ✅

---

## 13. Lo que B10 deja pendiente a la dirección

| # | Acción | Responsable |
|---|---|---|
| A1 | Respaldo externo no-sobrescribible del estado V1 | Dirección |
| A2 | Verificar que `APP_KEY` está fuera de git (no aplica — no hay git) | — |
| A3 | Aprobar dependencias propuestas para B11 | Dirección |
| A4 | Confirmar contrato de hosting de Redis en producción | Dirección |
| A5 | Confirmar cuenta de Meta Business para WhatsApp | Dirección |
| A6 | Confirmar proyecto Google Cloud y redirect URI | Dirección |
| A7 | Confirmar app Azure AD para Outlook | Dirección |
| A8 | Confirmar SMTP transaccional elegido (SES / Postmark / Resend / Mailgun) | Dirección |

---

*Cierre de B10 sujeto a las acciones A1..A8 y a la aprobación explícita de la dirección.*
