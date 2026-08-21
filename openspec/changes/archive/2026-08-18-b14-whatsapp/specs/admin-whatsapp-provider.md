# Admin WhatsApp — Provider (Meta Cloud API + stub honesto + factory + webhook)

> **Module slice of `b14-whatsapp`**. Upstream: `docs/v2/01-roadmap.md` §2.4 + §7 D-12a/b/c + §3.5 (acción "Enviar plantilla de WhatsApp"); `openspec/changes/b14-whatsapp/proposal.md` §7 #2..#4 + §11 R1/R6; implementation on disk en `app/Services/WhatsApp/{MetaWhatsAppProvider.php, WhatsAppService.php, Exceptions/NotImplementedException.php}` + `app/Http/Controllers/WhatsAppWebhookController.php`.
>
> Scope: contrato `WhatsAppProvider`, implementación Meta, factory swap-ready, verificación HMAC-SHA256 del webhook, persistencia inbound.

---

## Purpose

Definir el contrato del proveedor de WhatsApp para V1: la implementación `MetaWhatsAppProvider` que cumple el interfaz `WhatsAppProvider` de B11, el `WhatsAppProviderFactory` que devuelve la implementación correcta según `integration_accounts.provider`, el `WhatsAppWebhookController` que verifica la firma HMAC-SHA256 y persiste los mensajes entrantes, y el envelope `NotImplementedException` cuando las credenciales Meta no están configuradas (stub honesto para dev + tests sin red ni secretos).

---

## Requirements

### REQ-WHATSAPP-PROV-01 — `MetaWhatsAppProvider` implementa el contrato `WhatsAppProvider`

El sistema SHALL proveer `App\Services\WhatsApp\MetaWhatsAppProvider` que implementa el interfaz `App\Integrations\Contracts\WhatsAppProvider` (definido en B11). Métodos requeridos:

- `sendTemplateMessage(WhatsAppAccount $account, string $phoneNumber, WhatsAppTemplate $template, array $variables): array` — devuelve `{success: bool, provider_message_id: ?string, wamid: ?string, error_class: ?string, error_message: ?string}`.
- `sendFreeformMessage(WhatsAppAccount $account, string $phoneNumber, string $body): array` — mismo envelope.
- `fetchTemplates(WhatsAppAccount $account): array` — devuelve `[{name, language, status, category, body, header_kind, header_text, footer_text, variables: [...], approved_at, rejected_reason}]`.
- `verifyWebhookSignature(WhatsAppAccount $account, string $body, ?string $signatureHeader): bool` — verifica HMAC-SHA256.
- `handleInbound(WhatsAppAccount $account, array $payload): array` — persiste mensaje entrante vía `WhatsAppService::handleInbound`.

Todos los métodos que tocan la red Meta SHALL chequear primero que `$account->business_id` + `$account->phone_number_id` no sean null; si falta alguno, SHALL devolver el envelope de error con `success=false`, `error_class='NotImplementedException'`, `error_message=NotImplementedException::credentialsNotConfigured()->getMessage()` — sin lanzar excepciones crípticas (Guzzle, timeout, etc.).

### REQ-WHATSAPP-PROV-02 — Stub honesto cuando faltan credenciales

El sistema SHALL exponer `App\Services\WhatsApp\Exceptions\NotImplementedException` (extiende `RuntimeException`) con métodos estáticos:

- `NotImplementedException::credentialsNotConfigured(): self` — mensaje `"Las credenciales de Meta WhatsApp (business_id / phone_number_id) no están configuradas para esta cuenta."`.

El provider SHALL devolver el envelope `{success: false, error_class: 'NotImplementedException', error_message: <message>, ...}` para los 3 métodos de envío/fetch (no lanza la excepción, devuelve el envelope; la UI lo lee sin tirar el flujo). Este envelope permite que `tests/Feature/WhatsApp/MetaWhatsAppProviderTest.php` opere sin red ni secretos.

### REQ-WHATSAPP-PROV-03 — Verificación de firma webhook vía SHA-256 HMAC

El sistema SHALL exponer `WhatsAppProvider::verifyWebhookSignature(WhatsAppAccount $account, string $body, ?string $signatureHeader): bool` que:

- Si `$account->webhook_secret` es null, devuelve `false` (no se puede verificar sin secreto).
- Si `$signatureHeader` es null o no comienza con `'sha256='`, devuelve `false`.
- Calcula `hash_hmac('sha256', $body, $account->webhook_secret)` con `raw_output: false`.
- Compara el resultado con la porción después de `'sha256='` usando `hash_equals` (timing-safe).
- Devuelve `true` si coincide, `false` en cualquier otro caso (cuerpo alterado, firma de otro payload, secreto incorrecto).

Defense-in-depth: el `WhatsAppWebhookController` también verifica la firma antes de invocar al provider (no se delega ciegamente).

### REQ-WHATSAPP-PROV-04 — Factory swap-ready para futuros BSPs

El sistema SHALL exponer `App\Services\WhatsApp\WhatsAppProviderFactory` con métodos:

- `for(WhatsAppAccount $account): WhatsAppProvider` — devuelve `MetaWhatsAppProvider` cuando `$account->integrationAccount->provider === 'whatsapp'` (siempre en V1).
- `make(string $provider): WhatsAppProvider` — switch por string; soporta `'meta'`; lanza `InvalidArgumentException` para providers desconocidos.

El factory SHALL estar binded en `WhatsAppServiceProvider::register()` vía `$this->app->bind(WhatsAppProviderFactory::class, ...)`. Un futuro BSP (Twilio, MessageBird, 360dialog) sólo necesita extender la factory + implementar el contrato — ningún cambio al service / controller / UI.

### REQ-WHATSAPP-PROV-05 — `idempotency_key` en envío outbound

El sistema SHALL garantizar que cada `WhatsAppMessage` saliente lleve un `idempotency_key` CHAR(64) UNIQUE. Cálculo: `sha256($conversation_id . '|' . $phone_norm . '|' . ($template_id ?? 'freeform') . '|' . $body . '|' . $window_start_timestamp)`. La UNIQUE constraint (`database/migrations/2026_08_18_030030_create_whatsapp_messages_table.php`) garantiza que dos intentos concurrentes con la misma combinación fallen con `QueryException` SQLSTATE 23000 — `WhatsAppService` swallowea la excepción y devuelve el mensaje ya existente (sin duplicar).

### REQ-WHATSAPP-PROV-06 — `syncTemplates` filtra sólo `approved`

El sistema SHALL exponer `WhatsAppService::syncTemplates(WhatsAppAccount $account): array` que:

1. Llama `WhatsAppProvider::fetchTemplates($account)`.
2. Filtra `status === 'approved'` (D-15c — sólo plantillas aprobadas).
3. Hace upsert en `whatsapp_templates` por `UNIQUE (account_id, name, language)` — actualiza `status`, `category`, `body`, `header_kind`, `header_text`, `footer_text`, `variables_json`, `synced_at`.
4. Devuelve `{synced: int, skipped: int, error_class: ?string}` donde `synced` cuenta upserts exitosos y `skipped` cuenta las plantillas ignoradas (no `approved`).

Las plantillas `draft`, `pending`, `rejected`, `disabled` SHALL ignorarse silenciosamente — el comando no las registra.

### REQ-WHATSAPP-PROV-07 — `handleInbound` persiste con `status='received'`

El sistema SHALL exponer `WhatsAppService::handleInbound(WhatsAppAccount $account, array $payload): WhatsAppMessage` que:

1. Extrae `from` (phone_number), `body` (texto), `type` (image/document/audio/text), `id` (provider_message_id), `timestamp` del payload Meta.
2. Busca o crea `WhatsAppConversation` por `(account_id, phone_norm)` — si no existe, crea con `status='open'`, `assigned_to=NULL`.
3. Crea `WhatsAppMessage` con `direction='inbound'`, `type=$type`, `body=$body`, `provider_message_id=$id`, `status='received'`, `sent_at=$timestamp`, `idempotency_key=sha256('inbound' . $id)`.
4. Si el `body` contiene un patrón de opt-out (`/^\s*(stop|cancel|unsubscribe|opt[\s\-]?out|detener|parar)\s*$/i`), crea una fila en `whatsapp_consent_log` con `type='opt_out'`, `source='inbound_keyword'`, y fija `conversation.opt_out_at`.
5. Emite el evento `App\Events\WhatsAppInboundReceived` (interno, NO del motor V2 de B12).
6. Devuelve el `WhatsAppMessage` creado.

---

## Scenarios

#### SCN-PROV-01 — Provider devuelve envelope honesto sin credenciales

- **GIVEN** un `WhatsAppAccount` con `business_id=null` y `phone_number_id=null`
- **WHEN** se invoca `MetaWhatsAppProvider::sendTemplateMessage($account, '+51999888777', $template, ['nombre' => 'Juan'])`
- **THEN** el método devuelve `['success' => false, 'error_class' => 'NotImplementedException', 'error_message' => 'Las credenciales de Meta WhatsApp...', 'provider_message_id' => null, 'wamid' => null]`
- **AND** NO se realiza ninguna llamada HTTP a Meta
- **AND** NO se lanza ninguna excepción fuera del provider

#### SCN-PROV-02 — Verificación HMAC acepta firma válida

- **GIVEN** `WhatsAppAccount` con `webhook_secret = 'shhh-test-secret'`
- **AND** cuerpo raw del webhook = `'{"entry":[{"changes":[{"value":{"messages":[{"from":"+51999888777","text":{"body":"hola"}}]}}]}]}'`
- **AND** header `X-Hub-Signature-256: sha256=<hash_hmac('sha256', $body, 'shhh-test-secret')>`
- **WHEN** `verifyWebhookSignature($account, $body, $signatureHeader)`
- **THEN** devuelve `true`

#### SCN-PROV-03 — Verificación HMAC rechaza firma inválida

- **GIVEN** misma cuenta + cuerpo alterado por un atacante (`'{"entry":[{"malicious":true}]}'`) + firma calculada contra el cuerpo original
- **WHEN** `verifyWebhookSignature($account, $alteredBody, $originalSignature)`
- **THEN** devuelve `false`

#### SCN-PROV-04 — Verificación HMAC rechaza firma ausente

- **GIVEN** `WhatsAppAccount` con `webhook_secret = 'shhh-test-secret'`
- **WHEN** `verifyWebhookSignature($account, $body, null)`
- **THEN** devuelve `false`

#### SCN-PROV-05 — Factory devuelve Meta provider para cuenta WhatsApp

- **GIVEN** `WhatsAppAccount` cuyo `integrationAccount->provider === 'whatsapp'`
- **WHEN** `WhatsAppProviderFactory::for($account)`
- **THEN** devuelve instancia de `MetaWhatsAppProvider`

#### SCN-PROV-06 — Factory lanza para provider desconocido

- **GIVEN** `$provider = 'twilio'`
- **WHEN** `WhatsAppProviderFactory::make($provider)`
- **THEN** lanza `InvalidArgumentException` con mensaje `"Unsupported WhatsApp provider: twilio"`

#### SCN-PROV-07 — Idempotencia: re-envío del mismo cuerpo no duplica

- **GIVEN** `WhatsAppConversation` abierta
- **WHEN** `WhatsAppService::sendFreeformMessage($conversation, 'hola')` se invoca dos veces seguidas con el mismo cuerpo
- **THEN** se crea 1 fila en `whatsapp_messages` con `idempotency_key = sha256(...)`
- **AND** la segunda invocación devuelve la misma fila (sin error, sin duplicado)

#### SCN-PROV-08 — Sync filtra sólo `approved`

- **GIVEN** Meta devuelve 5 templates: 3 con `status='approved'`, 1 con `status='pending'`, 1 con `status='rejected'`
- **WHEN** `WhatsAppService::syncTemplates($account)`
- **THEN** devuelve `{synced: 3, skipped: 2, error_class: null}`
- **AND** la tabla `whatsapp_templates` tiene 3 filas nuevas, todas con `status='approved'`

#### SCN-PROV-09 — Inbound crea conversación si no existe

- **GIVEN** ninguna `WhatsAppConversation` para `phone_number='+51999888777'`
- **WHEN** `WhatsAppService::handleInbound($account, ['from' => '+51999888777', 'body' => 'hola', 'id' => 'wamid.123', 'timestamp' => 1700000000, 'type' => 'text'])`
- **THEN** crea una `WhatsAppConversation` con `phone_number='+51999888777'`, `status='open'`, `assigned_to=NULL`
- **AND** crea un `WhatsAppMessage` con `direction='inbound'`, `status='received'`, `provider_message_id='wamid.123'`

#### SCN-PROV-10 — Inbound con keyword "STOP" marca opt-out

- **GIVEN** conversación abierta con `phone_number='+51999888777'`
- **WHEN** `WhatsAppService::handleInbound($account, ['from' => '+51999888777', 'body' => 'STOP', ...])`
- **THEN** crea fila en `whatsapp_consent_log` con `type='opt_out'`, `source='inbound_keyword'`
- **AND** fija `conversation.opt_out_at = now()`
- **AND** el `WhatsAppMessage` se persiste igual con `body='STOP'`, `status='received'`

---

## Affected routes

| Method | URI | Name | Permission |
|---|---|---|---|
| POST | `/webhooks/whatsapp/{account}` | `webhooks.whatsapp` | HMAC-SHA256 signature (NO `auth`/`active`) |

El controller `WhatsAppWebhookController` está FUERA del grupo `auth`/`active` (línea 496 de `routes/web.php`). El único gate es la firma HMAC-SHA256 calculada contra el cuerpo crudo + el `webhook_secret` del `WhatsAppAccount` route-model-bound.

---

## Cross-references

- **Proposal**: `openspec/changes/b14-whatsapp/proposal.md` §4 (permisos: `whatsapp.account.manage` registrado pero no enforzado en V1 — el CRUD UI de cuentas queda como ticket B14.1), §7 #2/3/4/6 (alcance del provider + service + factory + webhook), §11 R1/R6 (riesgos de dev sin credenciales + envío sincrónico), §12 AC-7/AC-8/AC-9.
- **Roadmap**: `docs/v2/01-roadmap.md` §2.4 (5 tablas), §3.5 (acción `SendWhatsAppTemplateAction`), §3.8 (webhook HMAC-SHA256 + allow-list + SSRF — decisiones que se reusan para WhatsApp webhook), §7 D-12a/b/c (Meta Cloud API directo + adapter + número aprobado), §7 D-15c (filtro `approved`).
- **B11**: `app/Integrations/Contracts/WhatsAppProvider.php` (interfaz) — B14 implementa; `app/Integrations/ProviderFactory.php` (factory base) — B14 extiende para `whatsapp`.
- **Implementation**:
  - Provider: `app/Services/WhatsApp/MetaWhatsAppProvider.php` (365 LOC) — implementa los 5 métodos.
  - Service: `app/Services/WhatsApp/WhatsAppService.php` (244 LOC) — orquesta `sendTemplateMessage`, `sendFreeformMessage`, `handleInbound`, `syncTemplates`, idempotencia, opt-out.
  - Factory: `app/Services/WhatsApp/WhatsAppProviderFactory.php` — `for()` + `make()` + `InvalidArgumentException` para provider desconocido.
  - Webhook: `app/Http/Controllers/WhatsAppWebhookController.php` (257 LOC) — método único `verify` que verifica firma + delega al `WhatsAppService::handleInbound`.
  - Exception: `app/Services/WhatsApp/Exceptions/NotImplementedException.php` (extiende `RuntimeException`) — `credentialsNotConfigured()`.
- **Tests**:
  - `tests/Feature/WhatsApp/MetaWhatsAppProviderTest.php` — 6 tests cubriendo REQ-PROV-01/02/03.
  - `tests/Feature/WhatsApp/WhatsAppServiceTest.php` — 8 tests cubriendo REQ-PROV-05/06/07.
  - `tests/Feature/WhatsApp/WhatsAppWebhookControllerTest.php` — 6 tests cubriendo REQ-PROV-03/07 + el controller end-to-end.
  - `tests/Unit/WhatsApp/WhatsAppProviderFactoryTest.php` — 3 tests cubriendo REQ-PROV-04.
  - `tests/Feature/WebhookSignatureTest.php` — cobertura HMAC con cuerpo sintético.
- **Adjacent specs**: `admin-whatsapp-bandeja.md` (controller HTTP + Livewire), `admin-whatsapp-permissions.md` (matriz de gates).
- **Config**: `openspec/config.yaml` — `strict_tdd: true`, artifact store `openspec`.
