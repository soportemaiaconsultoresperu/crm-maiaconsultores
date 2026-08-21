# Admin WhatsApp — Bandeja (bandeja + accounts + templates UI)

> **Module slice of `b14-whatsapp`**. Upstream: `docs/v2/01-roadmap.md` §2.4 (schema) + §7 D-12..D-15 (decisiones); `openspec/changes/b14-whatsapp/proposal.md` §4 (permisos) + §7 (scope) + §12 (AC); implementation on disk under `app/Http/Controllers/Admin/WhatsAppController.php` (328 LOC) + `app/Livewire/Admin/WhatsApp/{ConversationList,MessageList}.php` (300 LOC) + `app/Console/Commands/SyncWhatsAppTemplates.php` (94 LOC) + routes `routes/web.php` líneas 511-548.
>
> Scope: bandeja operativa + accounts + templates UI. Provider contract + stub honesto vive en `admin-whatsapp-provider.md`. Permission gates + seeder idempotencia vive en `admin-whatsapp-permissions.md`.

---

## Purpose

Definir el contrato del módulo admin de WhatsApp en V1: 11 endpoints HTTP en `routes/web.php` (`whatsapp.accounts.*` 3, `whatsapp.conversations.*` 6, `whatsapp.templates.*` 2), dos componentes Livewire (`ConversationList`, `MessageList`) y un comando de consola (`whatsapp:sync-templates`). El cambio `b14-whatsapp` es un nuevo SDD — esta es una **lite spec spec** (no un delta contra un canónico existente; no requiere `## Capabilities`).

---

## Requirements

### REQ-WHATSAPP-01 — Listar cuentas

El sistema SHALL exponer `GET admin.whatsapp.accounts.index` que devuelva la vista `admin.whatsapp.accounts.index` con todas las filas de `whatsapp_accounts` (incluyendo `inactivas`) paginadas a 20 por página, mostrando `id`, `phone_number`, `display_name`, `status`, `verified_at`, `last_event_at`. SHALL requerir el permiso `whatsapp.view` server-side (`Gate::authorize` o middleware `can:`).

### REQ-WHATSAPP-02 — Mostrar detalle de cuenta

El sistema SHALL exponer `GET admin.whatsapp.accounts.show` (ruta nombrada `whatsapp.accounts.show`, parámetro `account` route-model-bound a `WhatsAppAccount`) que devuelva 404 si el id no existe y 200 con la vista de detalle conteniendo: campos del account + las últimas 20 conversaciones (`WhatsAppConversation` orderBy `last_message_at DESC`). SHALL requerir `whatsapp.view`.

### REQ-WHATSAPP-03 — Listar conversaciones con filtros

El sistema SHALL exponer `GET admin.whatsapp.conversations.index` (ruta `whatsapp.conversations.index`) que liste `WhatsAppConversation` paginadas (20 por defecto), aplicando opcionalmente tres filtros query-string: `status` (`open|closed|archived`), `assigned_to` (id de `users`), y `phone` (búsqueda libre con `LIKE '%phone%'` sobre `phone_number`). SHALL honrar `DataScopeService::visibleConversations($actor)` — un vendedor sólo ve conversaciones asignadas a él o las `assigned_to IS NULL` de su equipo; un supervisor ve las suyas + las de su equipo; un admin las ve todas. SHALL requerir `whatsapp.view`.

### REQ-WHATSAPP-04 — Mostrar conversación + historial de mensajes

El sistema SHALL exponer `GET admin.whatsapp.conversations.show` (ruta `whatsapp.conversations.show`, route-model-bound) que devuelva la conversación + todas las filas de `whatsapp_messages` ordenadas por `sent_at ASC` (nulls first para mensajes sin envío aún). SHALL requerir `whatsapp.view` y SHALL verificar que la conversación esté en el `DataScope` del actor (403 si no).

### REQ-WHATSAPP-05 — Enviar mensaje libre

El sistema SHALL exponer `POST admin.whatsapp.conversations.send` (ruta `whatsapp.conversations.send`, route-model-bound) que: valide `body` (string required, máx 4096), persista una fila `whatsapp_messages` con `direction='outbound'`, `type='text'`, `status='queued'`, `body=body`, `idempotency_key=sha256(conversation_id|phone_norm|body|window_start)`, y delegue el envío real al `WhatsAppService::sendFreeformMessage`. SHALL requerir `whatsapp.send` (middleware `can:`). SHALL rechazar con `domain_error: opt_out` si `whatsapp_conversations.opt_out_at IS NOT NULL`. SHALL rechazar con `403` si la conversación no está en el `DataScope` del actor.

### REQ-WHATSAPP-06 — Asignar conversación

El sistema SHALL exponer `POST admin.whatsapp.conversations.assign` (ruta `whatsapp.conversations.assign`) que: valide `user_id` (integer required, fk `users`), actualice `whatsapp_conversations.assigned_to = user_id` y `assigned_at` (timestamp). SHALL filtrar el set de candidatos via `DataScopeService::visibleOwnerIds($actor)` — un POST con `user_id` fuera de ese set devuelve 403 (no 422, para no leakear la membresía). SHALL requerir `whatsapp.conversation.assign` (middleware `can:`).

### REQ-WHATSAPP-07 — Cerrar conversación

El sistema SHALL exponer `POST admin.whatsapp.conversations.close` (ruta `whatsapp.conversations.close`) que fije `whatsapp_conversations.status='closed'`. SHALL requerir `whatsapp.send` (el operador que puede responder también puede cerrar). SHALL actualizar `last_message_at` con el timestamp del cierre. Una conversación cerrada puede re-abrirse con un nuevo `sendMessage` (status vuelve a `open` automáticamente).

### REQ-WHATSAPP-08 — Marcar opt-out

El sistema SHALL exponer `POST admin.whatsapp.conversations.opt_out` (ruta `whatsapp.conversations.opt_out`) que, en una transacción DB: cree una fila en `whatsapp_consent_log` con `type='opt_out'`, `source='manual'`, `conversation_id`, `contact_id`, `granted_at=null`, `revoked_at=now()`; y fije `whatsapp_conversations.opt_out_at=now()`. SHALL requerir `whatsapp.send` (o `whatsapp.view` + flag, decisión de producto: por simplicidad, `whatsapp.send` basta). Tras opt-out, cualquier `sendMessage` posterior falla con `domain_error: opt_out` (ver REQ-WHATSAPP-05).

### REQ-WHATSAPP-09 — Listar plantillas

El sistema SHALL exponer `GET admin.whatsapp.templates.index` (ruta `whatsapp.templates.index`) que liste `whatsapp_templates` paginadas (20 por página), con filtros `account_id` (FK `whatsapp_accounts`), `status` (`approved|pending|rejected|disabled|draft`), `category` (string libre, LIKE). SHALL requerir `whatsapp.view`. Sólo se persisten plantillas `approved` (D-15c), pero el filtro UI acepta los otros valores para inspección manual si alguna quedara de un sync anterior.

### REQ-WHATSAPP-10 — Mostrar detalle de plantilla

El sistema SHALL exponer `GET admin.whatsapp.templates.show` (ruta `whatsapp.templates.show`, route-model-bound) que devuelva: campos del template (`name`, `language`, `status`, `category`, `body`, `header_kind`, `header_text`, `footer_text`, `variables_json`, `synced_at`); las últimas 20 filas de `whatsapp_messages` con `template_id` igual (referencias para auditoría). SHALL requerir `whatsapp.view`.

---

## Scenarios

#### SCN-WHATSAPP-01 — Vendedor abre bandeja y ve sólo sus conversaciones asignadas o las del equipo sin asignar

- **GIVEN** un usuario con `whatsapp.view` y rol `vendedor`, miembro del equipo `equipo-1`
- **AND** 3 conversaciones: `conv-A` (assigned_to=vendedor, status=open), `conv-B` (assigned_to=otro-vendedor, status=open), `conv-C` (assigned_to=NULL, equipo-1 según DataScope)
- **WHEN** GET `admin.whatsapp.conversations.index`
- **THEN** respuesta 200 + ve `conv-A` + `conv-C`, NO ve `conv-B` (otro equipo)

#### SCN-WHATSAPP-02 — Supervisor reasigna una conversación de su equipo a un vendedor específico

- **GIVEN** supervisor con `whatsapp.conversation.assign`, `conv-X` con `assigned_to=NULL` y `phone_number=+51999888777`, equipo-1
- **AND** vendedor-1 del equipo-1 con `phone_norm` matching
- **WHEN** POST `admin.whatsapp.conversations.assign` `{user_id: vendedor-1.id}`
- **THEN** respuesta 302 redirect a `whatsapp.conversations.show` + `conv-X.assigned_to = vendedor-1.id`

#### SCN-WHATSAPP-03 — Envío bloqueado por opt-out

- **GIVEN** `conv-Y` con `opt_out_at IS NOT NULL`
- **AND** usuario con `whatsapp.send`
- **WHEN** POST `admin.whatsapp.conversations.send` `{body: "Hola"}`
- **THEN** respuesta 422 + `error_code: 'opt_out'` + `error_message: 'El contacto ha solicitado no recibir más mensajes.'`
- **AND** NO se crea fila en `whatsapp_messages`
- **AND** NO se llama al provider

#### SCN-WHATSAPP-04 — Filtro por status

- **GIVEN** 5 conversaciones: 3 con `status=open`, 2 con `status=closed`
- **WHEN** GET `admin.whatsapp.conversations.index?status=open`
- **THEN** respuesta 200 + ve las 3 conversaciones abiertas, NO ve las 2 cerradas

#### SCN-WHATSAPP-05 — Búsqueda libre por phone

- **GIVEN** conversaciones con `phone_number=+51999888777`, `+51999111222`, `+51999555333`
- **WHEN** GET `admin.whatsapp.conversations.index?phone=99111`
- **THEN** respuesta 200 + ve sólo la conversación con `+51999111222` (LIKE match)

#### SCN-WHATSAPP-06 — Sync command filtra sólo `approved`

- **GIVEN** un `WhatsAppAccount` con `business_id` configurado
- **AND** Meta devuelve 5 templates: 3 `approved`, 1 `pending`, 1 `rejected`
- **WHEN** se ejecuta `php artisan whatsapp:sync-templates --account=<id>`
- **THEN** exit code 0 + se persisten 3 filas en `whatsapp_templates` (todas con `status='approved'`)
- **AND** las 2 plantillas `pending`/`rejected` no se persisten (D-15c)

#### SCN-WHATSAPP-07 — Filtros Livewire operan sin recarga

- **GIVEN** componente `ConversationList` montado en la página
- **WHEN** `wire:model.live="statusFilter"` cambia de `null` a `'open'`
- **THEN** la lista se actualiza via AJAX sin recarga completa
- **AND** la paginación se resetea (REQ Livewire 4 + `#[On]`)

#### SCN-WHATSAPP-08 — Opt-out persiste log + bloquea envíos

- **GIVEN** `conv-Z` con `status=open`, `assigned_to=user-1`
- **WHEN** POST `admin.whatsapp.conversations.opt_out` por user-1 (con `whatsapp.send`)
- **THEN** fila creada en `whatsapp_consent_log` con `type='opt_out'`, `conversation_id=conv-Z.id`, `source='manual'`, `revoked_at=now()`
- **AND** `conv-Z.opt_out_at` fijado a `now()`
- **AND** un POST subsiguiente a `whatsapp.conversations.send` con `body="hola"` devuelve 422 + `error_code: 'opt_out'`

#### SCN-WHATSAPP-09 — Template sync requiere `whatsapp.template.manage`

- **GIVEN** usuario con sólo `whatsapp.view`
- **WHEN** POST `admin.whatsapp.accounts.sync` `{account: <id>}`
- **THEN** respuesta 403 (middleware `can:whatsapp.template.manage`)

#### SCN-WHATSAPP-10 — Asignación respeta `DataScope` server-side

- **GIVEN** supervisor `sup-1` del equipo `equipo-1` con `whatsapp.conversation.assign`
- **AND** `conv-W` (equipo-1, `assigned_to=NULL`)
- **WHEN** POST `admin.whatsapp.conversations.assign` `{user_id: vendedor-externo.id}` (vendedor de otro equipo)
- **THEN** respuesta 403 + NO se actualiza `assigned_to`
- **AND** `DataScopeService::visibleOwnerIds(sup-1)` no contiene `vendedor-externo.id` — por eso el rechazo (no leakear la membresía)

---

## Affected routes

| Method | URI | Name | Permission |
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

(11 admin routes; total de 12 named routes incluye `webhooks.whatsapp` — ver `admin-whatsapp-provider.md`.)

---

## Cross-references

- **Proposal**: `openspec/changes/b14-whatsapp/proposal.md` §4 (matriz de permisos), §5 (outcome T1..T6), §7 (scope), §9 (edge cases 1, 2, 3, 5, 8, 10, 12), §12 (AC-1..AC-5, AC-9, AC-12).
- **Roadmap**: `docs/v2/01-roadmap.md` §2.4 (5 tablas `whatsapp_*`), §7 D-13b/c/d (normalización + duplicados + asignación), §7 D-14a/b/c (visibilidad + DataScope), §7 D-15a/c (sync desde Meta + filtro `approved`), §11 (B14 en plan).
- **Implementation**:
  - Controller: `app/Http/Controllers/Admin/WhatsAppController.php` (328 LOC, 11 métodos públicos).
  - Routes: `routes/web.php` líneas 511-548 (grupo `auth`+`active`+`prefix('admin/whatsapp')`).
  - Livewire bandeja: `app/Livewire/Admin/WhatsApp/ConversationList.php` (158 LOC) + `MessageList.php` (142 LOC).
  - Service: `app/Services/WhatsApp/WhatsAppService.php` (244 LOC) — orquesta persistencia, idempotencia, opt-out.
  - Command: `app/Console/Commands/SyncWhatsAppTemplates.php` (94 LOC) — opciones `--account=<id>` y `--all`.
- **Tests**:
  - `tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php` — 19 tests cubriendo los 10 REQ-id del HTTP layer.
  - `tests/Feature/Admin/WhatsApp/Livewire/ConversationListLivewireTest.php` — 5 tests cubriendo REQ-WHATSAPP-03/06/07 a nivel Livewire.
  - `tests/Feature/Admin/WhatsApp/Livewire/MessageListLivewireTest.php` — cubre REQ-WHATSAPP-04/05/08 a nivel Livewire.
  - `tests/Feature/Console/SyncWhatsAppTemplatesTest.php` — 4 tests cubriendo REQ-WHATSAPP-09 a nivel command.
- **Adjacent specs**: `admin-whatsapp-provider.md` (stub honesto + HMAC + factory), `admin-whatsapp-permissions.md` (matriz de gates + seeder idempotente).
- **Config**: `openspec/config.yaml` — Laravel 13.25, PHP 8.3.16, Livewire 4, Spatie Permission + Activitylog, `strict_tdd: true`.
