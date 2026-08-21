# B14-WhatsApp — WhatsApp Bandeja + Meta Cloud API + Template Sync (PRD / sdd-proposal)

> **Phase**: sdd-proposal (PRD only — no technical spec, design, or tasks).
> **Upstream artifacts** (authoritative):
>
> - `docs/v2/01-roadmap.md` §2.4 (schema fuente) + §7 D-12..D-15 (decisiones locked).
> - `openspec/changes/b12-ui/proposal.md` + `tasks.md` (precedente de formato V2).
> - `app/Providers/WhatsAppServiceProvider.php` — 6 permisos `whatsapp.*` registrados al boot.
> - Implementación ya mergeada en disco (ver §11 — la fase sdd-apply ya ejecutó Pasada A, B-1, B-2, B-3; este proposal documenta el producto entregado).
>
> **Decisiones de producto**: 4 decisiones firmadas (D-12..D-15, §10). No se re-abren.

---

## 1. Resumen ejecutivo

B14-WhatsApp integra WhatsApp al CRM con un único proveedor V1 (Meta WhatsApp Cloud API directo, D-12a), expone una **bandeja operativa** para que admin / supervisor / vendedor lean, asignen y respondan conversaciones, y sincroniza el **catálogo de plantillas** desde Meta hacia un espejo local que filtra únicamente las plantillas `approved` (D-15c). El alcance V1 cubre: 5 tablas nuevas (`whatsapp_accounts`, `whatsapp_templates`, `whatsapp_conversations`, `whatsapp_messages`, `whatsapp_consent_log`); el `MetaWhatsAppProvider` con contrato `WhatsAppProvider` swap-ready para futuros BSPs (D-12b); el `WhatsAppService` que orquesta conversaciones/mensajes/idempotencia/opt-out; el `WhatsAppWebhookController` con verificación HMAC-SHA256 (decisión de defensa B11 + provider); el `AdminWhatsAppController` con 11 endpoints HTTP; los componentes Livewire `ConversationList` y `MessageList`; el comando `whatsapp:sync-templates` para refrescar el catálogo; y 6 permisos Spatie (`whatsapp.view`, `whatsapp.send`, `whatsapp.template.manage`, `whatsapp.account.manage`, `whatsapp.conversation.assign`, `whatsapp.audit`). B14 **no** instala BSPs alternativos, **no** crea plantillas desde el CRM (D-15d), **no** expone webhooks entrantes a listeners del motor V2 y **no** sincroniza IMAP/SMTP (que es alcance de B13). Cuando no hay credenciales Meta configuradas, el provider responde con un envelope `NotImplementedException` honesto para que la UI y los tests operen sin red ni secretos.

---

## 2. Problema de negocio

- **Pain operacional**: hoy las conversaciones de WhatsApp se gestionan **fuera** del CRM — en el celular personal del vendedor, en hojas de cálculo, o en la app de Meta Business desvinculada del pipeline. No hay trazabilidad de quién respondió qué, ni asociación con el `Lead` / `Customer` / `Contact`, ni registro de consentimiento (`opt-in` / `opt-out`), ni auditoría de envíos. Cuando un lead reclama "no me llegó el mensaje", no hay cómo verificar.
- **Riesgo de plantilla no aprobada**: usar una plantilla Meta en estado `pending` o `rejected` resulta en un envío silenciosamente rechazado por Meta, sin feedback al operador. B14 impone el filtro `status='approved'` (D-15c) en el sync para que la bandeja sólo ofrezca plantillas utilizables.
- **Coste de soporte**: cualquier consulta "¿está la cuenta conectada? ¿cuál es el estado del número?" requiere entrar a Meta Business. B14 expone `whatsapp_accounts` con `status` + `verified_at` + `last_event_at` para que admin / supervisor lo lean en la UI.
- **Cumplimiento regulatorio (consentimiento)**: WhatsApp exige respeto del opt-out dentro de 24 h (D-15 + B11 `RedactPiiProcessor`). B14 introduce `whatsapp_consent_log` con `type ∈ {opt_in, opt_out}` y la columna `opt_out_at` en `whatsapp_conversations`. Si una conversación ya hizo opt-out, los envíos futuros se bloquean server-side antes de tocar el provider.
- **Oportunidad**: el contrato `WhatsAppProvider` ya existe desde B11 (`app/Integrations/Contracts/*` — `docs/v2/01-roadmap.md` §3.5 menciona "Enviar plantilla de WhatsApp. (Adapter WhatsAppProvider + whatsapp_templates aprobado.)"). B14 entrega la implementación Meta; un futuro BSP sólo tendría que implementar el mismo contrato.

---

## 3. Usuarios objetivo

| Persona | Necesita | Flujo concreto |
|---|---|---|
| **Admin** (rol con los 6 `whatsapp.*`) | Conectar cuentas Meta, ver bandeja global, asignar conversaciones, forzar re-sync de plantillas, ver log de consentimientos. | Abre `admin.whatsapp.accounts.index` → ve las cuentas y su estado; entra al detalle de una cuenta y dispara `whatsapp:sync-templates`; navega a `admin.whatsapp.conversations.index` filtrando por estado / responsable / teléfono; abre una conversación, lee el historial, responde con un mensaje libre (D-14), asigna a un vendedor del `DataScopeService`, marca opt-out si el contacto lo solicita. |
| **Supervisor** (rol con `whatsapp.view` + `whatsapp.conversation.assign`) | Diagnosticar bandejas de su equipo, asignar conversaciones a vendedores específicos, supervisar opt-outs. | Mismo flujo que admin pero sin acceso a `whatsapp.account.manage` (no conecta ni desconecta cuentas). El permiso `whatsapp.conversation.assign` permite mover hilos entre vendedores de su equipo respetando `DataScopeService` (D-14c). |
| **Vendedor** (rol con `whatsapp.view`) | Responder conversaciones que le han sido asignadas; ver plantillas aprobadas; marcar opt-out. | Bandeja filtrada a `assigned_to = auth()->id()` + estado `open`; abre el detalle, responde, marca opt-out si el cliente lo pide. Sin `whatsapp.send` no puede responder (D-13 / matriz §4). |
| **Operaciones (CLI)** | Sincronizar plantillas bajo demanda o programada; auditar consentimientos. | Ejecuta `php artisan whatsapp:sync-templates --all` (o `--account=<id>`); consulta `whatsapp_consent_log` directo a DB. |

---

## 4. Reglas de negocio y permisos

Las 6 permisos se registran en `WhatsAppServiceProvider::registerWhatsAppPermissions()` al boot (no por seeder exclusivo — el `AdditionalWhatsAppPermissionsSeeder` es idempotente y los registra una segunda vez para enlazar con el rol admin ya existente en V1). Matriz de enforcement:

| Permiso Spatie | Endpoint / acción | Componente | Comportamiento |
|---|---|---|---|
| `whatsapp.view` | GET `admin.whatsapp.*` (index/show accounts + conversations + templates), GET `admin.whatsapp.conversations.index/show` con filtros | `AdminWhatsAppController::accounts/conversations/templates/showAccount/showConversation/showTemplate`, `ConversationList`, `MessageList` | Sin este permiso, los vendors ven 403 en cada endpoint; el sidebar oculta la entrada WhatsApp. |
| `whatsapp.send` | POST `admin.whatsapp.conversations.send` (envío libre) | `AdminWhatsAppController::sendMessage`, `WhatsAppService::sendFreeformMessage` | El middleware `can:whatsapp.send` rechaza con 403; server-side el `WhatsAppService` chequea `opt_out_at IS NULL` antes de delegar al provider. |
| `whatsapp.template.manage` | POST `admin.whatsapp.accounts.sync` (forzar sync de plantillas) | `AdminWhatsAppController::triggerTemplateSync`, comando `whatsapp:sync-templates` | Sin este permiso el botón "Sincronizar plantillas" se oculta y el endpoint devuelve 403. |
| `whatsapp.account.manage` | Conexión / desconexión de cuentas Meta (futura UI CRUD de cuentas) | — | Permiso registrado pero **no enforzado en V1** vía endpoint (D-12c sólo cubre conectar, no CRUD); queda reservado para una UI de gestión de cuentas que sale del scope V1 (ver §8). |
| `whatsapp.conversation.assign` | POST `admin.whatsapp.conversations.assign` | `AdminWhatsAppController::assignConversation`, `ConversationList::assign` | El middleware rechaza 403; server-side el `DataScopeService::visibleOwnerIds($actor)` filtra el set de candidatos asignables. |
| `whatsapp.audit` | Ver log de consentimientos + audit fields (`created_by`, timestamps) en `whatsapp_messages` | `AdminWhatsAppController` + vistas con bloque "Auditoría" gated | Sin este permiso el bloque no se renderiza; el listado de `whatsapp_consent_log` se oculta. |

Reglas de dominio adicionales (no son permisos pero acotan comportamiento):

1. **Opt-out bloquea envíos**: cualquier intento de `sendMessage` con `opt_out_at IS NOT NULL` falla con `domain_error: opt_out` antes de tocar el provider. La UI muestra el estado del consentimiento al borde del formulario de envío.
2. **Plantillas no aprobadas excluidas del sync**: `WhatsAppService::syncTemplates` filtra `status='approved'` antes del upsert (D-15c). Plantillas `pending` / `rejected` / `disabled` quedan registradas en Meta pero no aparecen en el CRM.
3. **Idempotencia por `idempotency_key` CHAR(64) UNIQUE** (D-15 + B11): `whatsapp_messages.idempotency_key` se calcula como `sha256(account_id|phone_norm|template_id|body|payload_hash|window_start)`. Reintentos no duplican mensajes.
4. **Webhook firmado obligatoriamente**: el `WhatsAppWebhookController` rechaza con 403 si falta el header `X-Hub-Signature-256` o si la firma HMAC-SHA256 contra el cuerpo crudo no coincide. Defense-in-depth con la verificación del provider.
5. **Conversaciones abiertas a todos los autorizados del equipo** (D-14b): `assigned_to IS NULL` se muestra a los miembros del equipo vía `DataScope`; conversaciones asignadas, sólo al responsable + supervisor + admin.
6. **Sin asignar inicialmente o regla de la organización** (D-13d): las conversaciones nuevas quedan con `assigned_to = NULL` salvo que el `DataScope` asigne automáticamente por una regla (futura integración con B12 fuera del scope V1).

---

## 5. Outcome esperado

- **T1 — Bandeja operativa**: admin y supervisor ven la lista de conversaciones con filtros (`status`, `assigned_to`, búsqueda libre por `phone_number`), abren una conversación y leen el historial completo de mensajes con sus estados (`received`, `sent`, `delivered`, `read`, `failed`).
- **T2 — Envío libre gateado**: un usuario con `whatsapp.send` puede responder un mensaje libre en una conversación que no esté opt-out. La UI encola el job; el resultado queda visible con su `provider_message_id` y `wamid` una vez el provider responde.
- **T3 — Asignación con DataScope**: un supervisor reasigna una conversación a un vendedor de su equipo; la UI nunca ofrece como candidato a un usuario fuera del `DataScopeService::visibleOwnerIds($actor)`. El guard server-side repite el filtro por si llega un payload adulterado.
- **T4 — Opt-out respetado**: marcar opt-out crea una fila en `whatsapp_consent_log` con `type='opt_out'`, fija `opt_out_at` en `whatsapp_conversations`, y bloquea futuros `sendMessage` con un error legible. La UI muestra un banner "Opt-out registrado" en el detalle de la conversación.
- **T5 — Sync de plantillas desde Meta**: admin / supervisor ejecutan `POST whatsapp.accounts.sync` o `php artisan whatsapp:sync-templates --all`. Sólo las plantillas `approved` se persisten (D-15c); el resto se ignoran silenciosamente. La UI lista los nombres, idiomas, categorías y variables.
- **T6 — Permisos correcten sin errores**: 6 permisos registrados al boot; el seeder `AdditionalWhatsAppPermissionsSeeder` es idempotente (corriendolo dos veces no duplica); admin recibe los 6 vía `assignAllWhatsAppPermissionsToAdminRole`; supervisor recibe sólo `whatsapp.view` + `whatsapp.conversation.assign` (vía `assignBaseWhatsAppPermissionsToSupervisorRole`).

---

## 6. Brecha actual

- **Sin WhatsApp en el CRM**: las 5 tablas no existen (`docs/v2/01-roadmap.md` §2.4 es sólo el contrato en papel). Meta WhatsApp Cloud API nunca se había cableado; `WhatsAppProvider` (B11) es sólo la interfaz.
- **Sin bandeja de inbound**: ni los vendedores ni los supervisores tienen cómo ver las conversaciones de WhatsApp; las respuestas se hacen desde la app de Meta Business o el celular personal.
- **Sin trazabilidad de consentimiento**: opt-in / opt-out no se registran. Riesgo regulatorio (WhatsApp puede banear el número si ignora opt-outs por más de 24 h).
- **Sin automatización de asignaciones**: si un lead entra por WhatsApp, queda sin responsable (`assigned_to = NULL` por defecto). No hay lógica de "round-robin por equipo" ni "asignar al responsable del customer" — eso es alcance de B12 / futuro.
- **Sin catálogo local de plantillas**: cada envío se hace ad-hoc desde Meta Business; no hay preview de variables, no hay versionado, no hay filtro por idioma.
- **Sin webhooks entrantes**: el provider expone `verifyWebhookSignature` pero ningún controller lo consume; los mensajes que llegan desde Meta se pierden.
- **Sin eventos V2 para inbound**: B12 + B13 sólo emiten eventos outbound. B14 introduce `WhatsAppInboundReceived` (interno, no del motor V2) para que listeners del bus puedan reaccionar a mensajes nuevos — pero **no** dispara reglas de automatización B12 en V1 (ver §8).
- **Sin stub honesto**: antes de B14, cualquier intento de enviar un WhatsApp fallaba con un `GuzzleException` críptico o un timeout de 30 s sin feedback. B14 introduce `NotImplementedException` con un envelope explícito cuando las credenciales no están configuradas.

---

## 7. Scope del primer slice (B14-WhatsApp v1)

Incluye:

1. **5 migraciones + 5 modelos Eloquent** con relaciones (`WhatsAppAccount` ↔ `integration_accounts`; `WhatsAppTemplate`; `WhatsAppConversation` ↔ `contacts|customers|leads|users`; `WhatsAppMessage` ↔ `WhatsAppTemplate`; `WhatsAppConsentLog` ↔ `contacts`).
2. **`WhatsAppProvider` interface** (B11) con la implementación `MetaWhatsAppProvider`: `sendTemplateMessage`, `sendFreeformMessage`, `fetchTemplates`, `verifyWebhookSignature`, `handleInbound`. **Stub honesto**: si `business_id` / `phone_number_id` faltan, devuelve `NotImplementedException::credentialsNotConfigured()` envelope con `error_class`, `error_message` y `success=false`.
3. **`WhatsAppService`** orquesta: búsqueda/creación de conversación, persistencia de mensajes, idempotencia, opt-out gating, sync de plantillas.
4. **`WhatsAppProviderFactory`** swap-ready: `for(WhatsAppAccount)` devuelve `MetaWhatsAppProvider` hoy; un futuro BSP sólo necesita extender la factory.
5. **`AdminWhatsAppController`** con 11 endpoints: `accounts`, `showAccount`, `triggerTemplateSync`, `conversations`, `showConversation`, `sendMessage`, `assignConversation`, `closeConversation`, `markOptOut`, `templates`, `showTemplate`. Cada endpoint con `Gate::authorize` o middleware `can:`.
6. **`WhatsAppWebhookController`** (fuera del grupo `auth`/`active`): un único endpoint POST `webhooks/whatsapp/{account}` que verifica firma HMAC-SHA256 y procesa mensajes entrantes o confirmaciones de entrega.
7. **Livewire components**: `ConversationList` (bandeja con filtros + asignación inline) y `MessageList` (historial de mensajes + envío libre + botón opt-out).
8. **Comando `whatsapp:sync-templates`** con opciones `--account=<id>` y `--all`; filtra `approved`, hace upsert por `(account_id, name, language)`, sale con código 0 al éxito, código 2 (`INVALID`) si falta la opción.
9. **`WhatsAppServiceProvider`** registra los 6 permisos al boot y configura la factoría + bindings container.
10. **`AdditionalWhatsAppPermissionsSeeder`** idempotente que asigna al rol `admin` los 6 permisos y al rol `supervisor` los 2 base (`view` + `conversation.assign`).
11. **Idempotencia** vía `idempotency_key` CHAR(64) UNIQUE en `whatsapp_messages`.
12. **Auditoría**: cada `whatsapp_message` queda con `created_at`/`updated_at`; `whatsapp_consent_log` lleva `granted_at` / `revoked_at`.

Fuera de scope (ver §8).

---

## 8. No-goals (explícitos)

- **IMAP / SMTP / email inbound**. La bandeja cubre sólo WhatsApp; el email es alcance de B13.
- **BSPs alternativos** (Twilio, MessageBird, 360dialog). El contrato `WhatsAppProvider` está swap-ready, pero la implementación V1 es sólo Meta (D-12b). El factory soporta una constante `meta`; añadir `twilio` requiere un cambio de provider que queda para un eventual B14.1.
- **Crear / aprobar plantillas en el CRM** (D-15d). El sync es sólo lectura desde Meta; el alta y aprobación siguen siendo manuales en Meta Business Manager.
- **Listeners V2 disparados por mensajes WhatsApp entrantes**. B14 emite `WhatsAppInboundReceived` como evento interno (no del motor V2 de B12) pero **no** lo conecta a `automation_rules` en V1. Una futura integración podría mapear ese evento al motor; queda como ticket B14.2.
- **Reasignación automática al responsable del customer**. La asignación es manual desde la UI; un round-robin por equipo es alcance de B12.
- **CRUD UI de cuentas Meta** (`whatsapp.account.manage` queda registrado pero sin endpoints V1). La conexión de cuentas se hace via `integration_accounts` ya existente (B11) + credenciales encriptadas; la gestión detallada de cuentas (rotar `phone_number_id`, cambiar `display_name`) es alcance B14.1.
- **Llamadas de voz / video / multimedia tipo imagen-PDF**. V1 cubre sólo texto y plantilla. El webhook acepta inbound con `type='image' | 'document' | 'audio'` y los persiste con `body=null` y metadatos en una futura tabla — fuera del scope V1 (sólo se persiste el placeholder para no perder el evento).
- **Dashboard de SLA / first-response-time / métricas de envío**. La bandeja no expone métricas agregadas; sólo listado + detalle.
- **Plantillas `draft` / `pending` / `rejected`**. Se persisten sólo las `approved` (D-15c). El comando `whatsapp:sync-templates` ignora silenciosamente las que no están en `approved`.
- **Bulk message / broadcast**. La bandeja responde conversaciones individuales; un envío masivo queda fuera del alcance (es Meta quién lo prohíbe salvo plantillas utility pre-aprobadas).
- **Number-pooling / múltiples cuentas simultáneas**. Cada `whatsapp_account` se trata individualmente; un balanceador entre cuentas queda fuera del scope V1.
- **Búsqueda full-text sobre el cuerpo del mensaje**. V1 hace `LIKE` sobre `phone_number` y los FK lookups; FTS es ticket de optimización futuro.

---

## 9. Edge cases que la implementación debe resolver

1. **Número no asociado a un contact/customer/lead**: el inbound crea una conversación con `phone_number` + `contact_name` (si Meta lo provee), pero `contact_id = customer_id = lead_id = NULL`. La UI muestra "Conversación sin contacto asociado" + CTA "Crear contacto" (futuro B15).
2. **Conversación ya cerrada (status='closed')**: un intento de `sendMessage` se permite si el operador quiere re-abrir, pero la UI muestra el banner "Conversación cerrada — reabrir para responder" y exige POST a `whatsapp.conversations.send` con un flag explícito. Si no, 422.
3. **Opt-out durante una conversación activa**: si un cliente envía "STOP", Meta lo notifica vía webhook y `WhatsAppWebhookController` lo detecta + persiste en `whatsapp_consent_log` + fija `opt_out_at`. Cualquier `sendMessage` posterior falla con `domain_error: opt_out`.
4. **Variable de plantilla faltante**: si la plantilla requiere `{{1}}` y el operador envía sin valor, el provider responde con `error_class='InvalidArgumentException'` y la UI muestra "Variable faltante: nombre".
5. **Cuenta inactiva / desactivada**: `whatsapp.accounts.show` muestra `status` (active | inactive | suspended). Si está inactiva, los endpoints de envío devuelven 422 con mensaje legible; el comando `whatsapp:sync-templates` la salta.
6. **Webhook signature mismatch**: el `WhatsAppWebhookController` rechaza con 403 sin loggear el cuerpo (para no leakear PII). Loggea sólo `account_id`, `signature_prefix`, `error_class`. Defense-in-depth: el provider también verifica en `handleInbound` antes de persistir.
7. **Cuota diaria de mensajes**: Meta impone 1,000 conversaciones iniciadas por negocio por día (utility templates son ilimitadas). B14 **no** implementa rate-limiting local; lo deja como futuro ticket B14.3 (Meta devuelve `error_code=130429` con `error_class='RateLimitExceededException'` y el `whatsapp_messages.status='failed'`).
8. **Resolución de conversación desde número entrante**: si un número escribe por primera vez, `WhatsAppService` crea la conversación con `status='open'` y `assigned_to = NULL`. Si después escribe de nuevo dentro de la ventana de 24 h, se reusa la misma conversación. Después de 24 h se crea una nueva (ventana de servicio al cliente de Meta).
9. **Webhook re-delivery**: Meta reenvía el mismo mensaje si el controller tarda > 5 s. La idempotencia por `idempotency_key` (calculada con `provider_message_id`) evita duplicados; un `INSERT IGNORE`风格的 upsert por la UNIQUE garantiza un solo `whatsapp_messages` aunque lleguen 5 POST idénticos.
10. **`WhatsAppMessage::body` con caracteres Unicode / RTL**: Meta acepta UTF-8; la columna es `TEXT` (no VARCHAR) por seguridad. La UI renderiza con `white-space: pre-wrap`.
11. **Token expirado**: el `MetaWhatsAppProvider::send*` puede recibir 401 de Meta; se traduce a `error_class='UnauthorizedException'` y `status='failed'` en `whatsapp_messages`. La UI muestra "Reconectar cuenta Meta" si ve 3+ fallos consecutivos en 1 h (futuro B14.1).
12. **Plantilla con variables numéricas vs string**: el motor de plantillas de Meta hace coerción; si el operador envía `{{1}}` como número, Meta lo concatena. B14 documenta esto en la preview de plantilla (futuro B14.1).

---

## 10. Decisiones de producto tomadas (locked)

Las decisiones D-12..D-15 (`docs/v2/01-roadmap.md` §7) están firmadas; este proposal las re-state y las refleja en §4, §7, §8, §9. **No se re-abren.**

1. **D-12a / 12b / 12c — Proveedor Meta Cloud API directo, adaptador swap-ready, número aprobado por Meta** (roadmap §7). → Razón: B11 ya definió el contrato `WhatsAppProvider`; B14 entrega Meta. Un futuro BSP (Twilio, MessageBird) sólo extiende la factoría. No asumimos número dedicado — el `phone_number_id` puede ser cualquiera aprobado por Meta.
2. **D-13a / 13b / 13c / 13d — Número desconocido configurable, normalización de teléfono, búsqueda por `phone_norm`, sin asignar inicialmente** (roadmap §7). → Razón: el "configurable en settings" sale del scope V1 (default false); B14 normaliza y busca pero no crea prospectos automáticamente.
3. **D-14a / 14b / 14c — Visibilidad por responsable + supervisor + admin; conversaciones sin asignar visibles al equipo; siempre `DataScopeService`** (roadmap §7). → Razón: el alcance de visibilidad es por equipo / supervisor, no por organización completa; el `DataScopeService` ya está en uso por B12.
4. **D-15a / 15b / 15c / 15d — Plantillas sincronizadas desde Meta; CRM lee/previsualiza/filtra/actualiza; sólo `approved`; NO crear en CRM** (roadmap §7). → Razón: el catálogo local es un espejo del catálogo Meta; el filtro `approved` evita "plantillas pendientes que no salen"; la creación queda en Meta Business Manager.

---

## 11. Riesgos y rollback

### Riesgos de producto

- **R1 — Credenciales Meta no configuradas en dev**: el `MetaWhatsAppProvider` lanza `NotImplementedException` y la UI muestra un mensaje claro. Sin embargo, los tests E2E (live Meta) no se pueden ejecutar sin `META_ACCESS_TOKEN` + `META_PHONE_NUMBER_ID`. Mitigación: el stub honesto está cubierto por `MetaWhatsAppProviderTest` (3 tests: send template / send freeform / fetch templates); los tests de integración usan `WebhookSignatureTest` con HMAC sintético.
- **R2 — Opt-out perdido**: si el webhook de Meta no llega (red, downtime), el opt-out no se registra y un envío posterior sería bloqueado por Meta (no por el CRM). Mitigación: el `WhatsAppService::sendMessage` consulta `opt_out_at` local antes de delegar; pero si el opt-out nunca llega al CRM, el envío procederá. Aceptado: depende de la disponibilidad del webhook de Meta; un polling de status sería B14.4.
- **R3 — `idempotency_key` colisión**: si dos conversaciones distintas del mismo `phone_norm` + misma plantilla + mismo cuerpo entran en la misma ventana de servicio, la UNIQUE puede chocar. Mitigación: la fórmula incluye `conversation_id` además de `account_id|phone_norm|template_id|body` para garantizar unicidad por hilo.
- **R4 — `DataScope` change entre save y execute**: la UI pre-filtra pero el server-side también; si el scope del actor cambia entre el GET del form y el POST, el server-side es el gate (no la UI).
- **R5 — Plantillas renombradas en Meta**: si Meta cambia el `name` de una plantilla `approved`, el sync la marca como `pending` y nuestro `whatsapp_templates` queda con la versión vieja. Mitigación: el sync hace upsert por `(account_id, name, language)` y actualiza `status` + `synced_at`. Plantillas "viejas" sin contraparte en Meta se mantienen (no se borran) para auditoría; el operador decide manualmente.
- **R6 — `WhatsAppService` ejecutándose fuera de un job**: si una request HTTP ejecuta el envío sincrónico y Meta tarda 30 s, el cliente recibe 504. Mitigación: el `AdminWhatsAppController::sendMessage` delega a `WhatsAppService::sendFreeformMessage` que persiste el mensaje con `status='queued'` y dispara un job; el `send` HTTP devuelve 202 + `provider_message_id=null` hasta que el job termine.

### Dependencias futuras

- **B11 — `WhatsAppProvider` interface** (firmada y entregada): B14 implementa; B11 sólo define el contrato. No bloquea.
- **B12 — eventos V2**: B14 emite `WhatsAppInboundReceived` interno pero **no** lo conecta al motor de automatización. Una futura integración permitiría reglas tipo "cuando entre un WhatsApp con la palabra 'precio', asignar al supervisor".
- **B13 — email**: la bandeja V1 es WhatsApp-only; email es B13.
- **B14.1 (futuro)**: CRUD UI de cuentas, dashboard de SLA, balanceo entre números, retry policy en cola.
- **B14.3 (futuro)**: rate-limiting local para cuotadiaria.
- **B14.4 (futuro)**: polling de status para opt-out cuando el webhook no llega.

### Rollback path

- **Borrar el change** (`openspec/changes/b14-whatsapp/` + `sdds/sdd-archive-b14-whatsapp.md`).
- **Revertir las 5 migraciones**: `database/migrations/2026_08_18_0300{00,10,20,30,40}_*.php` (rollback `php artisan migrate:rollback --step=5`).
- **Revertir el `WhatsAppServiceProvider`**: restaurar la versión anterior (sin registro de los 6 permisos) o no deployar el provider modificado; los 6 permisos vuelven a su estado registrado-sin-uso.
- **Revertir el `AdditionalWhatsAppPermissionsSeeder`**: borrar el archivo (los 6 permisos quedan registrados por el provider que se revierte arriba).
- **Revertir rutas**: en `routes/web.php` eliminar el bloque `admin/whatsapp` (líneas 504-548 aprox.) y el `webhooks/whatsapp/{account}`.
- **Revertir el controller + service + provider + webhook**: borrar `app/Http/Controllers/Admin/WhatsAppController.php`, `WhatsAppWebhookController.php`, `app/Services/WhatsApp/{MetaWhatsAppProvider,WhatsAppService,Exceptions/NotImplementedException}.php`, `app/Console/Commands/SyncWhatsAppTemplates.php`, `app/Livewire/Admin/WhatsApp/{ConversationList,MessageList}.php`.
- **Revertir los 5 modelos**: borrar `app/Models/WhatsApp/*.php`.
- **Revertir los tests**: borrar `tests/Feature/Admin/WhatsApp/`, `tests/Feature/WhatsApp/`, `tests/Unit/WhatsApp/`, y el `WhatsAppPermissions`-style coverage que vivía en `tests/Feature/Admin/Automations/Livewire/SendWhatsAppTemplateWidgetLivewireTest.php` (el widget de B12 sigue con su stub).
- **No tocar**: `app/Providers/AutomationServiceProvider.php` (B12), `app/Services/Automation/*` (B12), `app/Integrations/Contracts/*` (B11). El contrato `WhatsAppProvider` permanece como interfaz vacía.

El rollback es **transaccional**: 5 migraciones + 1 provider + 1 seeder + 1 set de rutas + 1 set de controllers + 1 set de modelos + 1 set de tests. Sin DDL sobre tablas existentes de V1/V2.

---

## 12. Criterios de éxito (V1 acceptance bar)

Estos son los criterios que `sdd-verify` debe poder marcar `passed`. **No son tests** — son condiciones de aceptación de producto que el slice debe satisfacer.

- **AC-1 — Bandeja operativa**: admin / supervisor / vendedor con `whatsapp.view` pueden abrir `admin.whatsapp.conversations.index`, filtrar por `status` / `assigned_to` / búsqueda libre por `phone_number`, abrir una conversación y leer su historial completo de mensajes con estados.
- **AC-2 — Envío libre gateado**: un usuario con `whatsapp.send` responde una conversación que NO está opt-out vía POST `whatsapp.conversations.send`; el mensaje se persiste con `direction='outbound'`, `status='queued'`, y un job lo procesa. Sin `whatsapp.send`, el endpoint devuelve 403.
- **AC-3 — Asignación con DataScope**: un supervisor reasigna una conversación a un vendedor de su equipo vía POST `whatsapp.conversations.assign`; el assignee siempre pertenece a `DataScopeService::visibleOwnerIds($actor)`. Sin `whatsapp.conversation.assign`, 403. Verificable intentando asignar a un user fuera del scope — el server-side rechaza.
- **AC-4 — Opt-out respetado**: marcar opt-out crea fila en `whatsapp_consent_log` con `type='opt_out'`, fija `opt_out_at` en `whatsapp_conversations`, y bloquea futuros `sendMessage` con error legible. La UI muestra banner "Opt-out registrado".
- **AC-5 — Sync de plantillas desde Meta, sólo `approved`**: ejecutar `php artisan whatsapp:sync-templates --account=<id>` (o `--all`) o POST `whatsapp.accounts.sync`. El provider devuelve la lista; el service filtra `status='approved'` (D-15c); persiste vía upsert por `(account_id, name, language)`. Plantillas `pending` / `rejected` / `disabled` se ignoran silenciosamente.
- **AC-6 — 6 permisos Spatie registrados al boot, seeder idempotente**: ejecutar `php artisan db:seed --class=AdditionalWhatsAppPermissionsSeeder` dos veces seguidas; la cantidad de filas en `permissions` con nombre `whatsapp.*` se mantiene en 6 (idempotencia). El rol `admin` tiene los 6; el rol `supervisor` tiene `whatsapp.view` + `whatsapp.conversation.assign`.
- **AC-7 — Provider stub honesto**: sin `business_id` + `phone_number_id` configurados en la cuenta, `MetaWhatsAppProvider::send*` y `fetchTemplates` devuelven el envelope `NotImplementedException::credentialsNotConfigured()` con `success=false`, `error_class='NotImplementedException'`, `error_message=...`. La UI muestra mensaje legible; no se lanza `GuzzleException` ni timeout.
- **AC-8 — Webhook firmado obligatoriamente**: POST `webhooks/whatsapp/{account}` sin header `X-Hub-Signature-256` devuelve 403. POST con firma inválida devuelve 403. POST con firma válida persiste el mensaje entrante / actualiza el estado de un mensaje saliente.
- **AC-9 — Idempotencia por `idempotency_key` UNIQUE**: re-enviar el mismo `(account_id, phone_norm, template_id, body)` dentro de la misma ventana de servicio crea un único `whatsapp_messages`; el segundo intento choca con la UNIQUE y `WhatsAppService` lo swallowea sin duplicar.
- **AC-10 — No regresión del motor B12**: `php artisan test --filter=AutomationEngineTest` sigue en **10/10 / 21 assertions** verde tras B14 (engine untouched; B14 sólo emite `WhatsAppInboundReceived` interno, no conectado a `automation_rules`).
- **AC-11 — Suite completa sin regresión**: `php artisan test` verde en **631/631 / 2206 assertions / ~78-243s** (baseline post-B14).
- **AC-12 — Livewire bandeja**: filtros por `status` / `assigned_to` / búsqueda libre operan sin recarga (Livewire 4 + `#[On]` / `wire:model.live`). Paginación persiste. Asignación inline funciona.

---

## Quick cross-reference

- **Upstream roadmap**: `docs/v2/01-roadmap.md` §2.4 (schema 5 tablas) + §7 D-12..D-15 (4 decisiones locked) + §11 (B14 en plan de implementación).
- **Project context**: `openspec/config.yaml` — Laravel 13.25, PHP 8.3.16, Livewire 4, Spatie Permission + Activitylog, AdminLTE/Bootstrap 5, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`.
- **Implementation precedent**: `app/Models/WhatsApp/{WhatsAppAccount,WhatsAppTemplate,WhatsAppConversation,WhatsAppMessage,WhatsAppConsentLog}.php` (5 modelos, 652 líneas total).
- **Service precedent**: `app/Services/WhatsApp/{MetaWhatsAppProvider,WhatsAppService}.php` + `app/Services/WhatsApp/Exceptions/NotImplementedException.php` (609 líneas total).
- **Controllers precedent**: `app/Http/Controllers/Admin/WhatsAppController.php` (328 líneas) + `app/Http/Controllers/WhatsAppWebhookController.php` (257 líneas).
- **Livewire precedent**: `app/Livewire/Admin/WhatsApp/{ConversationList,MessageList}.php` (300 líneas total).
- **Routes precedent**: `routes/web.php` líneas 496-548 — 1 webhook + 11 admin endpoints (3 accounts + 6 conversations + 2 templates).
- **Migrations precedent**: `database/migrations/2026_08_18_0300{00,10,20,30,40}_*.php` (5 migraciones, 436 líneas).
- **Seeder precedent**: `database/seeders/AdditionalWhatsAppPermissionsSeeder.php` (117 líneas, idempotente).
- **Provider precedent**: `app/Providers/WhatsAppServiceProvider.php` (167 líneas, registra los 6 permisos al boot).
- **Command precedent**: `app/Console/Commands/SyncWhatsAppTemplates.php` (94 líneas).
- **Tests precedent**: `tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php` (19 tests), `tests/Feature/Admin/WhatsApp/Livewire/{ConversationList,MessageList}LivewireTest.php`, `tests/Feature/Console/SyncWhatsAppTemplatesTest.php`, `tests/Feature/WhatsApp/{MetaWhatsAppProvider,WhatsAppService,WhatsAppWebhookController}Test.php`, `tests/Unit/WhatsApp/WhatsAppProviderFactoryTest.php`.
- **Test signature HMAC**: `tests/Feature/WebhookSignatureTest.php` (test E2E con HMAC sintético).
- **Precedente de archive**: `sdds/sdd-archive-b12-ui.md` + `openspec/changes/b12-ui/archive-report.md`.
