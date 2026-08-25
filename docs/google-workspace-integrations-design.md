# Diseño de integración Google Workspace por usuario

Este documento define el diseño actualizado para conectar **Gmail** y **Google Calendar** al CRM mediante Google OAuth 2.0 por usuario. Cada vendedor usa su propia identidad Google para enviar cotizaciones y sincronizar actividades. No se usan cuentas globales, no se selecciona la “última cuenta activa” y no se promete idempotencia mayor a la que permiten los scopes del MVP.

## 1. Decisiones definitivas

| Tema | Decisión |
|---|---|
| Modelo de conexión | **Una conexión Google por usuario** (`owner_id = usuario autenticado`). |
| Registro recomendado | Un único `integration_accounts.provider = google` por usuario, con servicios habilitados en `config_json.services`. |
| Protección DB | Debe existir una restricción/índice que impida dos conexiones Google activas para el mismo usuario, considerando soft deletes. |
| UI | Tarjetas separadas para Gmail y Google Calendar, aunque internamente compartan identidad Google. |
| Gmail MVP | Solo envío real mediante Gmail API; no lectura de bandeja. |
| Calendar MVP | Sincronización unidireccional **CRM → Google Calendar**. |
| Botón actual de cotización | Renombrar `Enviar` a `Marcar como enviada`. |
| Envío real | Agregar acción separada `Enviar por Gmail`. |
| Estado `sent` de cotización | Significa que Gmail API aceptó el mensaje para envío, no que fue entregado o leído. |
| Resultado indeterminado Gmail | Usar `EmailMessage.status = send_unconfirmed`; no reenviar automáticamente y no marcar la cotización como `sent`. |
| Jobs | Gmail y Calendar deben ejecutarse mediante colas. |
| Sync bidireccional | Fuera del MVP. |
| Eliminación de eventos | No eliminar eventos externos silenciosamente. |
| Tokens | Access/refresh tokens siempre cifrados; nunca en logs ni frontend. |
| OAuth state | Debe consumirse una sola vez de forma atómica; agregar `consumed_at` a `oauth_states`. |
| Duración Calendar fallback | 60 minutos cuando la actividad no tenga hora final ni duración. |
| Sync inicial Calendar | Solo actividades futuras/pendientes del usuario, con confirmación previa de cantidad. |
| Plantilla Gmail inicial | Definida en §8.3 y editable antes del envío. |
| CC/CCO | Sin valores predeterminados; el usuario los agrega manualmente. |
| Nombre PDF | `Cotizacion-{numero}.pdf`, con número saneado. |
| Soft-delete de actividad | Actualizar evento como cancelado; no eliminarlo; vínculo `cancelled` o `not_syncable`. |
| Integraciones antiguas | Verificar datos reales antes de diseñar cualquier migración de consolidación. |

## 2. Alcance MVP

### Incluido

1. Google OAuth 2.0 por usuario.
2. Pantalla `Mi cuenta → Integraciones`.
3. Conectar/reconectar/desconectar Google.
4. Habilitar/deshabilitar Gmail y/o Calendar dentro del CRM sobre la misma identidad Google.
5. Enviar cotizaciones por Gmail con PDF adjunto.
6. Marcar cotizaciones como enviadas manualmente sin llamar a Gmail.
7. Sincronizar actividades del CRM hacia Google Calendar.
8. Persistir vínculo Activity ↔ evento Google.
9. Procesamiento mediante jobs, con idempotencia local, reintentos limitados y estado `send_unconfirmed` para resultados indeterminados.
10. Auditoría funcional sin secretos.

### No incluido

- Leer la bandeja de Gmail.
- Solicitar permisos de lectura de Gmail para resolver casos indeterminados.
- Confirmar entrega, lectura, apertura o rebote de correos.
- Importar eventos desde Google Calendar hacia el CRM.
- Sincronización bidireccional.
- Resolver conflictos Google ↔ CRM.
- Usar cuentas compartidas como fallback.
- Enviar desde la cuenta Google de otro usuario.
- Eliminar eventos de Google automáticamente ante una cancelación simple.
- Revocar permisos individuales de Google sin revocar la autorización completa.

## 3. Arquitectura encontrada

### `integration_accounts`

Evidencia:

- `database/migrations/2026_08_18_003845_create_integration_accounts_table.php`
- `app/Models/IntegrationAccount.php`

La tabla ya tiene una base útil:

| Campo | Uso actual / utilidad |
|---|---|
| `provider` | String libre de 40 caracteres; comentarios mencionan `gmail`, `google_calendar`, etc. |
| `owner_id` | Permite conexión por usuario. |
| `team_id` / `is_shared` | Soporta cuentas compartidas futuras, pero no se usarán como fallback del MVP. |
| `config_json` | Configuración no secreta. |
| `credentials_encrypted` | Cast `encrypted:array`; apto para tokens cifrados. |
| `scopes` | Lista de permisos autorizados. |
| `last_synced_at` / `last_refresh_at` / `expires_at` | Metadatos de sync/token. |
| `error_class` / `error_message` | Diagnóstico sin secretos. |
| `is_active`, `test_mode`, soft deletes | Gestión de conexión y pruebas. |

Problemas:

1. La migración documenta providers separados (`gmail`, `google_calendar`), mientras que este diseño recomienda `provider = google`.
2. `config/integrations.php` registra Calendar como canal `calendar` con provider `google`, pero `integrations.enabled` tiene flags `google_calendar` / `outlook_calendar`; `AdapterFactory::calendar()` chequea canal `calendar`, por lo que Calendar real requiere normalización.
3. No existe una restricción DB que impida dos conexiones Google activas para el mismo `owner_id`.

### `oauth_states`

Evidencia:

- `database/migrations/2026_08_18_003846_create_oauth_states_table.php`
- `app/Models/OAuthState.php`

Ya permite:

- `provider`
- `state` único
- `redirect_after`
- `payload_json`
- `expires_at`
- scope `valid()`
- método `isExpired()`

Gap encontrado:

- No existe `consumed_at` ni otro mecanismo persistente para impedir reutilización del mismo state si el registro no se elimina.

Decisión:

- Agregar `consumed_at` y consumir el state de forma atómica en el callback. Ver §6.4.

### Email module

Evidencia:

- `app/Models/Email/EmailMessage.php`
- `database/migrations/2026_08_18_020000_create_email_messages_table.php`
- `app/Models/Email/EmailParticipant.php`
- `app/Models/Email/EmailAttachment.php`
- `app/Services/Email/EmailService.php`
- `app/Jobs/V2/SendEmailMessage.php`
- `app/Contracts/Email/EmailProviderFactory.php`
- `app/Services/Email/GmailProvider.php`

Base existente:

- `EmailMessage` relaciona `account_id`, participantes, adjuntos y entidades CRM (`related_quotation_id`, etc.).
- `SendEmailMessage` ya usa `ShouldQueue`, `tries = 3` y `backoff = [30, 120, 600]`.
- `EmailAttachment` ya modela adjuntos privados con `filename`, `mime`, `size`, `storage_path`, `sha256`.
- Existe unique `(account_id, provider_message_id)`.

Problemas:

1. `EmailMessage` usa estados `queued`, `sent`, `delivered`, `bounced`, `failed`, `received`; Gmail necesita `pending`, `processing`, `send_unconfirmed`, `sent`, `failed`.
2. `EmailService::resolveAccountId()` selecciona la última cuenta activa global (`orderByDesc('id')`), lo cual queda prohibido para Gmail por usuario.
3. `GmailProvider` es stub: no construye MIME, no adjunta PDF, no llama Gmail API y devuelve envelope `NotImplementedException`.
4. `SendEmailMessage` marca `failed` inmediatamente cuando el provider devuelve error; Gmail necesita distinguir error explícito, error temporal e indeterminado.
5. No hay idempotency key explícita para “enviar esta cotización por Gmail”; la deduplicación por `provider_message_id` solo existe después de que el provider responde.

### Cotizaciones

Evidencia:

- `app/Http/Controllers/QuotationController.php`
- `app/Services/QuotationService.php`
- `resources/views/quotations/show.blade.php`
- `routes/web.php`

Estado actual:

- El botón `Enviar` hace POST a `quotations/{quotation}/send`.
- `QuotationController::send()` llama `QuotationService::send()`.
- `QuotationService::send()` solo cambia `status = sent`, setea `issued_at` y registra actividad.
- La generación PDF actual está en `QuotationController::pdf()` usando `Barryvdh\DomPDF\Facade\Pdf` y descarga `{$quotation->number}.pdf`.

Problema crítico:

- En `QuotationService::send()` hay código inalcanzable después de `return DB::transaction(...)`, incluyendo `event(new QuotationSent(...))`.
- Antes de Gmail real, el flujo debe separarse en:
  - envío manual: `markAsSentManually()`;
  - envío por Gmail: cambiar a `sent` solo tras confirmación exitosa del job.

### Actividades y Calendar

Evidencia:

- `app/Models/Activity.php`
- `app/Services/ActivityService.php`
- `app/Http/Controllers/ActivityController.php`
- `app/Services/CalendarEventService.php`
- `app/Integrations/Contracts/CalendarProvider.php`
- `app/Integrations/Dto/CalendarEventDto.php`
- `app/Integrations/Dto/CalendarEventRef.php`

Base existente:

- `Activity` es la fuente de verdad de seguimientos.
- `ActivityService` centraliza create/update/start/complete/cancel.
- `CalendarEventService` proyecta actividades e invoices para vistas internas del calendario.
- Existe contrato externo `CalendarProvider` con `createEvent`, `updateEvent`, `deleteEvent`, `fetchCalendars`.
- Existe DTO con `startsAt`, `endsAt`, `timezone`, `attendees`, `metadata`.

Gaps:

- No existe adapter Google Calendar real.
- No existe tabla persistente Activity ↔ external event.
- No existe job de sync Calendar.
- No existe política de timezone específica por usuario/empresa/sistema.
- No existe política formal para evento eliminado manualmente en Google.

### Jobs, auditoría y seguridad

Base existente:

- Jobs con `ShouldQueue`, `tries` y `backoff` ya existen (`SendEmailMessage`, `SendWhatsAppMessage`, `RunAutomationAction`, `SendOutboundDelivery`).
- `CredentialCipher` y cast `encrypted:array` protegen credenciales.
- Spatie activitylog se usa para auditoría de dominio.
- Hay campos `error_class` / `error_message` en varias tablas.

## 4. Modelo de conexión Google recomendado

### Recomendación

Usar un único registro por usuario:

```text
integration_accounts.provider = google
integration_accounts.owner_id = usuario autenticado
```

`config_json` debe describir los servicios habilitados:

```json
{
  "google_account_email": "asesor@empresa.com",
  "services": {
    "gmail": true,
    "calendar": true
  },
  "calendar": {
    "default_calendar_id": "primary"
  },
  "status": "connected",
  "disconnected_at": null,
  "reconnection_required_at": null
}
```

`scopes` debe guardar el conjunto real autorizado:

```json
[
  "openid",
  "email",
  "profile",
  "https://www.googleapis.com/auth/gmail.send",
  "https://www.googleapis.com/auth/calendar.events"
]
```

`credentials_encrypted` debe guardar, cifrado:

```json
{
  "access_token": "...",
  "refresh_token": "...",
  "token_type": "Bearer",
  "expires_at": "..."
}
```

### Por qué no dos registros separados

La arquitectura actual menciona `gmail` y `google_calendar`, pero Google OAuth representa una **identidad Google** con un refresh token. Separar en dos filas produce riesgos:

- duplicar refresh tokens para la misma cuenta;
- que una reconexión de Gmail invalide Calendar;
- que un disconnect parcial borre tokens necesarios para otro servicio;
- que el usuario vea dos tarjetas desconectadas aunque Google sea una sola identidad;
- mayor riesgo de scopes inconsistentes.

### Impacto sobre arquitectura existente

Como `provider` es string libre, la DB permite `google` sin cambiar la columna. Pero sí hay que adaptar código:

- `EmailProviderFactory` debe resolver Gmail cuando `account.provider = google` y `config_json.services.gmail = true`.
- `AdapterFactory` / config de Calendar deben normalizar canal `calendar` y provider `google`.
- Las consultas de Gmail no deben buscar `provider = gmail`; deben buscar `provider = google` + servicio Gmail habilitado + `owner_id`.
- Si existen filas antiguas `provider = gmail` / `google_calendar`, se requiere análisis previo. No se migra a ciegas. Ver §4.5.

### Desactivar Gmail o Calendar dentro del CRM

Google no garantiza revocación individual de scopes concedidos mediante una reconexión incremental con menos permisos. Por eso:

- Para desactivar Gmail dentro del CRM:

```text
config_json.services.gmail = false
```

- Para desactivar Calendar dentro del CRM:

```text
config_json.services.calendar = false
```

Reglas:

1. Desactivar un servicio localmente **no revoca** individualmente el scope concedido en Google.
2. Si el usuario necesita retirar realmente un permiso concedido, debe revocarse la autorización completa de Google y autorizar nuevamente solo los servicios deseados.
3. La revocación completa puede desconectar temporalmente Gmail y Calendar.
4. La UI debe informar esto claramente antes de ejecutar una revocación completa.
5. No afirmar que una reconexión incremental con menos scopes elimina permisos anteriores.

### Autorización incremental

El diseño debe soportar incremental auth:

1. Usuario conecta Gmail: se piden scopes de Gmail + identidad.
2. Luego conecta Calendar: se pide el scope adicional de Calendar, manteniendo el mismo `provider=google`.
3. El refresh token nuevo solo reemplaza al anterior si Google devuelve uno. Si no devuelve refresh token, se conserva el refresh token existente.
4. Nunca se borra el refresh token válido solo porque una autorización incremental no devolvió uno.
5. La autorización incremental se usa para **agregar** permisos, no para prometer retirada individual de permisos.

### Integraciones antiguas `gmail` / `google_calendar`

Antes de crear una migración para registros antiguos:

1. Verificar si existen datos reales:

```sql
select provider, owner_id, count(*)
from integration_accounts
where provider in ('gmail', 'google_calendar')
group by provider, owner_id;
```

1. Si no existen datos reales, no crear migración innecesaria.
2. Si existen, presentar previamente:
   - cantidad de registros;
   - usuarios afectados;
   - servicios conectados;
   - estrategia de consolidación;
   - respaldo;
   - rollback.
3. No combinar ni eliminar tokens sin estrategia aprobada.

## 5. Restricción de una conexión Google activa por usuario

La regla “una conexión Google por usuario” debe protegerse en base de datos, no solo en código.

### Estrategia recomendada para MySQL/MariaDB compatible con soft deletes

Agregar una columna generada o equivalente que solo tenga valor para conexiones Google activas no eliminadas:

```text
google_active_owner_id = owner_id cuando provider = 'google' y deleted_at is null
                         null en cualquier otro caso
```

Luego crear un índice unique sobre esa columna:

```text
unique(google_active_owner_id)
```

Como los motores SQL permiten múltiples `NULL` en índices únicos, esto permite:

- muchos providers no-Google;
- filas Google soft-deleted;
- reconectar después de soft-delete;
- impedir dos conexiones Google activas simultáneas para el mismo `owner_id`.

Si el motor soporta índices parciales, alternativa equivalente:

```sql
unique(owner_id) where provider = 'google' and deleted_at is null
```

### Manejo de reconexión

1. Si existe fila Google activa del usuario, actualizarla.
2. Si existe fila soft-deleted y la política lo permite, crear nueva fila o restaurar con auditoría explícita.
3. Si la fila está `disconnected` pero no soft-deleted, reutilizarla.
4. El índice debe impedir carreras que creen dos filas activas.
5. Ante colisión unique por concurrencia, recargar la fila existente y continuar de forma idempotente.

## 6. OAuth 2.0 y seguridad

### Flujo OAuth

1. Usuario abre `Mi cuenta → Integraciones`.
2. Click en `Conectar Gmail`, `Conectar Google Calendar` o `Conectar Google`.
3. El CRM crea `oauth_states` con provider `google`, state único, expiración y payload:
   - usuario;
   - sesión o nonce asociado;
   - servicio solicitado;
   - scopes requeridos;
   - redirect posterior.
4. Redirección a Google con:
   - `access_type=offline`;
   - `prompt=consent` solo cuando se necesita forzar refresh token;
   - scopes mínimos;
   - state opaco.
5. Callback valida y consume state atómicamente. Ver §6.4.
6. Intercambia code por tokens.
7. Lee email de identidad Google mediante `openid email profile`.
8. Crea o actualiza `integration_accounts.provider=google, owner_id=user`.
9. Persiste tokens cifrados.
10. Actualiza scopes y servicios habilitados.
11. Audita conexión/reconexión.

### Prohibido guardar o exponer

- Contraseñas de Google.
- Access tokens sin cifrar.
- Refresh tokens sin cifrar.
- Client secret en código.
- Tokens en logs.
- Tokens en respuestas frontend.
- Contenido sensible completo de correos o errores.

### Variables de entorno

Las credenciales del proyecto Google deben vivir en env/config, nunca hardcodeadas:

```text
GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_REDIRECT_URI
GOOGLE_OAUTH_PROJECT_ID
```

### Consumo de `oauth_states` de un solo uso

La tabla actual no tiene `consumed_at`. Decisión: agregar `consumed_at` para mantener trazabilidad y bloquear reuso.

Callback OAuth debe hacer una operación atómica:

1. Buscar state por `provider = google`, `state`, `consumed_at is null`, `expires_at > now()`.
2. Validar payload de usuario/sesión.
3. Marcar `consumed_at = now()` dentro de transacción o mediante `update ... where consumed_at is null`.
4. Continuar solo si exactamente una fila fue consumida.
5. Rechazar cualquier segundo uso.

Pruebas requeridas:

- callback exitoso consume state;
- segundo callback con mismo state falla;
- dos callbacks concurrentes: solo uno avanza;
- state expirado falla;
- provider incorrecto falla;
- state de otro usuario/sesión falla.

## 7. Permisos mínimos

### Identidad base

| Scope | Uso |
|---|---|
| `openid` | Identidad OAuth/OIDC. |
| `email` | Confirmar cuenta Google conectada. |
| `profile` | Mostrar nombre/email de cuenta conectada. |

### Gmail MVP

```text
https://www.googleapis.com/auth/gmail.send
```

Uso: enviar cotizaciones por Gmail API. Si el usuario no concede este permiso, `Enviar por Gmail` no funciona y debe mostrarse CTA de reconexión/autorización.

No se solicita lectura de Gmail en el MVP, ni siquiera para resolver estados `send_unconfirmed`.

### Calendar MVP

```text
https://www.googleapis.com/auth/calendar.events
```

Uso: crear/actualizar eventos administrados por el CRM. Si el usuario no lo concede, Calendar sync queda deshabilitado; el CRM debe seguir guardando actividades.

### Incremental auth

Sí: se debe usar autorización incremental para agregar Gmail o Calendar posteriormente sin duplicar conexiones ni romper el refresh token existente. No se usa para retirar permisos previamente concedidos.

## 8. Flujo Gmail para cotizaciones

### Acciones separadas

#### `Enviar por Gmail`

Realiza envío real con Gmail API desde la cuenta conectada del usuario autenticado.

#### `Marcar como enviada`

Solo registra envío externo/manual. Casos:

- WhatsApp.
- Correo manual.
- PDF descargado y enviado fuera del CRM.
- Entrega física.

No llama Gmail API.

### Confirmación antes de enviar

Antes de encolar el envío, mostrar confirmación editable:

```text
Desde: asesor@empresa.com
Para: cliente@empresa.com
CC:
CCO:
Asunto: Cotización COT-001 – Empresa SAC
Mensaje: ...
Adjunto: Cotizacion-COT-001.pdf
```

Debe incluir:

- remitente;
- destinatario;
- CC;
- CCO;
- asunto;
- mensaje;
- nombre de adjunto;
- botón confirmar;
- botón cancelar.

El usuario debe poder revisar y modificar destinatario/CC/CCO/asunto/cuerpo antes de confirmar, respetando validaciones del CRM.

### Plantilla predeterminada

Asunto:

```text
Cotización {numero} – {empresa}
```

Cuerpo:

```text
Hola {nombre_contacto}:

Adjuntamos la cotización {numero} para su revisión.

Quedamos atentos a cualquier consulta.

Saludos,
{nombre_usuario}
```

Reglas:

- Asunto y cuerpo se muestran antes del envío y son editables.
- Si un dato opcional no existe, no deben quedar variables sin reemplazar.
- No deben quedar textos incompletos, separadores sobrantes ni espacios incorrectos.
- Si `{empresa}` no existe, el asunto queda `Cotización {numero}` sin guion final.
- Si `{nombre_contacto}` no existe, usar saludo neutro `Hola:` o exigir selección de contacto según regla de destinatario.

### CC y CCO

No hay CC ni CCO predeterminados.

El usuario puede agregarlos manualmente en la ventana de confirmación.

### Regla de destinatario

No inventar una regla silenciosa. Regla conservadora para MVP:

1. Si la cotización tiene contacto responsable con email válido, sugerirlo como `Para`.
2. Si no, y el cliente/lead tiene un único email válido inequívoco, sugerirlo.
3. Si hay varios contactos o varios emails posibles, obligar al usuario a elegir.
4. Si no hay email válido, bloquear `Enviar por Gmail` y pedir registrar o escribir un destinatario válido.
5. Si el usuario desea copia, permitir CC/CCO explícitos.

Casos:

| Caso | Decisión MVP |
|---|---|
| Cliente con varios contactos | Usuario elige destinatario. |
| Varios correos posibles | Usuario elige. |
| Sin correo registrado | Bloquear y pedir correo válido. |
| Correo inválido | Bloquear y mostrar validación. |
| Contacto principal | Sugerir, no enviar sin confirmación. |
| Cotización de empresa con contacto responsable | Sugerir contacto responsable. |
| Usuario desea copia | Permitir CC/CCO en confirmación. |

### Nombre del PDF

Usar:

```text
Cotizacion-{numero}.pdf
```

El número debe sanearse para producir un nombre seguro:

- remover separadores de ruta;
- permitir solo caracteres seguros (`A-Z`, `a-z`, `0-9`, guion, guion bajo, punto si aplica);
- limitar longitud;
- asegurar extensión `.pdf`.

### Validación del PDF

Antes de crear/enviar el job:

- Validar permiso del usuario sobre la cotización.
- Generar o localizar PDF de la cotización seleccionada.
- Confirmar que corresponde a esa cotización.
- Confirmar que el archivo existe.
- Confirmar MIME/tipo válido (`application/pdf`).
- Confirmar nombre seguro.
- Confirmar tamaño menor al límite admitido por Gmail.
- Confirmar que puede adjuntarse al MIME.

Si falla:

- no crear envío;
- no cambiar cotización a `sent`;
- mostrar mensaje claro;
- registrar error técnico sin secretos.

### Estados Gmail de `EmailMessage`

Estados mínimos para el flujo Gmail:

```text
pending
processing
sent
failed
send_unconfirmed
```

Compatibilidad con modelo actual:

- Puede mapearse `queued` como alias legado de `pending`.
- Recomendación: agregar constantes `STATUS_PENDING`, `STATUS_PROCESSING` y `STATUS_SEND_UNCONFIRMED`, conservando compatibilidad con `STATUS_QUEUED`.

### Job de envío

Flujo obligatorio:

1. Usuario confirma envío.
2. Controller valida permisos, cuenta Google del usuario, destinatario y PDF.
3. Crear `EmailMessage` con estado `pending`.
4. Crear `EmailAttachment` del PDF.
5. Crear idempotency key local.
6. Encolar job.
7. Antes de llamar a Gmail, job cambia mensaje a `processing`.
8. Job construye MIME con adjunto PDF.
9. Job llama Gmail API.
10. Si Gmail devuelve éxito, guardar `provider_message_id` y `thread_id`.
11. Cambiar `EmailMessage` a `sent`.
12. Cambiar cotización a `sent`.
13. Registrar historial y auditoría.

### Idempotencia Gmail y `send_unconfirmed`

Con el scope MVP `gmail.send`, el CRM **no puede consultar Gmail posteriormente** para comprobar si un mensaje fue procesado. Por lo tanto, la idempotencia es local y no absoluta.

La clave de idempotencia local previene:

- doble clic del usuario;
- creación simultánea de dos jobs;
- repetición de un envío cuya respuesta ya fue guardada;
- reprocesamiento de un job que ya terminó correctamente.

No promete resolver el caso donde Gmail aceptó el mensaje pero el CRM no recibió o no logró guardar la respuesta.

Política:

1. Antes de llamar a Gmail, `EmailMessage.status = processing`.
2. Si Gmail devuelve éxito, guardar IDs y pasar a `sent`.
3. Si Gmail devuelve error explícito antes de aceptar el mensaje, aplicar reintentos según tipo de error.
4. Si el resultado es indeterminado por timeout, pérdida de conexión o interrupción después de realizar la solicitud, **no reenviar automáticamente**.
5. Cambiar `EmailMessage.status = send_unconfirmed`.
6. Mostrar al usuario que el CRM no pudo confirmar el resultado.
7. Permitir revisión y reintento manual con advertencia clara de posible duplicado.
8. No cambiar la cotización a `sent` mientras el resultado permanezca `send_unconfirmed`.

Agregar una clave estable, por ejemplo:

```text
sha256(account_id|quotation_id|pdf_sha256|to|cc|bcc|subject|body_hash)
```

Recomendación de datos:

- agregar `idempotency_key` único en `email_messages`, o
- crear tabla específica `quotation_email_sends` con unique por intención de envío.

### Significado de `sent`

Una cotización cambia a `sent` cuando Gmail API confirma que el mensaje fue aceptado para envío.

No significa:

- recibido;
- leído;
- entregado;
- no spam;
- no rebote futuro.

No implementar `delivered` ni `read` en este MVP.

## 9. Flujo Google Calendar

### Sync unidireccional

```text
CRM → Google Calendar
```

| Acción en CRM | Acción Google Calendar |
|---|---|
| Crear actividad | Crear evento. |
| Reprogramar actividad | Actualizar el mismo evento. |
| Cambiar título/descripción | Actualizar el mismo evento. |
| Cancelar actividad | Marcar/actualizar evento como cancelado, sin eliminarlo. |
| Soft-delete actividad | Marcar evento como cancelado; vínculo `cancelled` o `not_syncable`; no eliminar evento. |
| Eliminar definitivamente | Fuera del MVP; requiere aprobación específica. |

No importar eventos Google hacia CRM en MVP.

### Duración predeterminada

Cuando una actividad no tenga hora final ni duración:

```text
Duración predeterminada: 60 minutos
```

Si posteriormente el CRM incorpora duración configurable, usar primero:

1. duración específica de la actividad;
2. configuración del usuario/empresa/sistema si se define;
3. fallback de 60 minutos.

### Sync inicial al conectar Calendar

Al conectar Google Calendar, sincronizar solo actividades que cumplan todas estas condiciones:

- pertenecen al usuario conectado;
- están pendientes o programadas;
- tienen fecha actual o futura;
- no tienen todavía evento externo vinculado;
- el usuario tiene permiso para consultarlas;
- no están canceladas ni eliminadas.

No sincronizar:

- históricas;
- completadas;
- canceladas;
- eliminadas;
- de otros usuarios.

Antes de ejecutar esta sincronización inicial, mostrar confirmación:

```text
Se crearán N eventos futuros en Google Calendar. ¿Querés continuar?
```

### Política de cancelación y soft-delete

MVP conservador:

- Cancelar una actividad actualiza el evento para indicar cancelación.
- Soft-delete de actividad también actualiza el evento para indicar cancelación.
- No eliminar automáticamente el evento.
- Mantener vínculo para auditoría.
- Marcar vínculo como `cancelled` o `not_syncable`.
- Evitar posteriores actualizaciones automáticas.
- No exponer información sensible adicional en el título.
- Eliminación definitiva del evento Google queda fuera del MVP y requiere aprobación específica.

### Tabla `activity_calendar_links`

Crear relación persistente:

```text
activity_calendar_links
```

Campos mínimos:

```text
id
activity_id
integration_account_id
provider
external_calendar_id
external_event_id
sync_hash
sync_status
last_synced_at
last_attempt_at
error_class
error_message
created_at
updated_at
```

Índices/restricciones:

- unique `(activity_id, integration_account_id, external_calendar_id)` para evitar duplicados por actividad/cuenta/calendario;
- index `(integration_account_id, sync_status)`;
- index `external_event_id`;
- FK a `activities` y `integration_accounts`.

Estados sugeridos de link:

```text
pending
syncing
synced
temporary_error
failed
cancelled
not_syncable
external_event_missing
```

### Propiedades extendidas en Google

Guardar metadata en el evento:

```text
crm_instance_id
crm_activity_id
crm_activity_url
```

Uso:

- identificar eventos creados por el CRM;
- evitar duplicados;
- recuperar vínculo local si se pierde;
- distinguir instalaciones diferentes del CRM.

No identificar eventos por título, fecha o correo.

### Zona horaria

No usar zona horaria del servidor por defecto.

Orden de resolución:

1. Configuración del usuario.
2. Configuración de la empresa.
3. Configuración general del sistema.

El evento debe enviar:

- fecha;
- hora inicial;
- hora final calculada;
- zona horaria;
- si es día completo;
- cambios por reprogramación.

### Job de sync Calendar

Flujo:

1. Actividad se guarda correctamente en CRM.
2. Se encola job de sync.
3. La respuesta HTTP al usuario no depende de Google.
4. Job crea o actualiza evento.
5. Guarda `external_event_id`.
6. Actualiza `sync_status`.
7. Si falla, guarda error y reintenta con backoff.

Si Calendar no está conectado:

- guardar actividad normalmente;
- no bloquear;
- mostrar aviso no bloqueante;
- permitir conectar Calendar después;
- al conectar, usar la sync inicial filtrada descrita arriba.

### Evento eliminado manualmente en Google

Si Google responde que el evento vinculado no existe:

1. No recrear silenciosamente.
2. Marcar link como `external_event_missing`.
3. Mostrar estado.
4. Permitir al usuario elegir:
   - `Recrear evento`;
   - `Desvincular actividad`.
5. Auditar la decisión.

## 10. Jobs, colas e idempotencia

Patrón recomendado:

- `tries = 3`.
- backoff exponencial compatible con patrones existentes (`[30, 120, 600]` o similar).
- jobs idempotentes localmente.
- estados `pending → processing → sent/synced|failed|send_unconfirmed` según flujo.
- short-circuit si el trabajo ya fue completado.
- no reintentar automáticamente estados `send_unconfirmed`.
- guardar `last_attempt_at`, `error_class`, `error_message`.
- no repetir indefinidamente ante refresh token revocado: marcar `reconnection_required`.

## 11. Estados de integración

No mostrar `Token vencido` como estado normal. El access token vence por diseño y se renueva internamente.

Estados funcionales internos:

| Estado | Etiqueta UI | Significado |
|---|---|---|
| `not_connected` | No conectado | El usuario nunca conectó Google o no hay cuenta activa. |
| `connected` | Conectado | Hay refresh token válido y al menos un servicio habilitado. |
| `sync_pending` | Sincronización pendiente | Hay cambios esperando job. |
| `syncing` | Sincronizando | Hay job activo. |
| `temporary_error` | Error temporal | Error recuperable; habrá reintentos limitados. |
| `reconnection_required` | Requiere reconexión | Refresh token revocado, inválido o insuficiente. |
| `disconnected` | Desconectado | El usuario desconectó Google o se inutilizaron tokens locales. |

Regla:

- Si falla el refresh token, marcar `reconnection_required`, detener reintentos infinitos y mostrar `Reconectar Google`.
- El usuario nunca ve tokens ni errores técnicos crudos.

## 12. Desconexión y revocación

Al desconectar Google completo:

1. Informar que se revocará la autorización completa y que Gmail/Calendar pueden quedar desconectados temporalmente.
2. Intentar revocar acceso en Google.
3. Inutilizar tokens locales aunque la revocación externa falle.
4. Vaciar `credentials_encrypted` o reemplazarlo por marcador no sensible.
5. `is_active = false`.
6. `config_json.status = disconnected`.
7. Guardar `disconnected_at`.
8. Conservar auditoría no sensible.
9. No eliminar correos históricos.
10. No eliminar actividades históricas.
11. No eliminar eventos existentes de Google Calendar.
12. Marcar links Calendar como `not_syncable`.
13. Permitir reconexión posterior.

Si la revocación externa falla:

- registrar `error_class` / `error_message` sin tokens;
- informar al usuario que puede retirar acceso manualmente desde Google Account.

## 13. Auditoría

Registrar como mínimo:

- usuario que conectó Google;
- cuenta Google conectada;
- servicios autorizados;
- servicios desactivados localmente;
- fecha de conexión;
- scopes autorizados;
- fecha de desconexión;
- intentos de renovación;
- refresh fallido;
- reconexión requerida;
- envío de cotización;
- resultado del envío;
- estado `send_unconfirmed` y causa segura;
- reintentos;
- reintento manual con advertencia de duplicado;
- creación de evento;
- actualización de evento;
- cancelación;
- soft-delete sincronizado como cancelación;
- evento externo faltante;
- decisión de recrear/desvincular;
- revocación.

No registrar:

- tokens;
- client secret;
- contenido completo sensible;
- adjuntos;
- cuerpos completos de email si no son necesarios.

## 14. Seguridad

Requisitos:

- tokens cifrados con cast `encrypted:array` o `CredentialCipher`;
- separación estricta por `owner_id`;
- restricción DB para una conexión Google activa por usuario;
- `oauth_states` de un solo uso;
- policies/gates antes de enviar o sincronizar;
- no fallback a cuentas globales;
- no enviar desde otro usuario;
- sanitizar nombres de adjuntos;
- limitar tamaño de PDF;
- errores sin secretos;
- logs con redacción PII cuando aplique;
- env vars para credenciales Google.

## 15. Manejo de errores

| Caso | Respuesta |
|---|---|
| Gmail no conectado | Mostrar `Conectá Gmail para enviar esta cotización desde el sistema.` |
| Calendar no conectado | Guardar actividad; mostrar aviso no bloqueante. |
| Refresh token revocado | `reconnection_required`; detener reintentos infinitos. |
| Destinatario ausente | Bloquear envío y pedir selección/email válido. |
| Destinatario inválido | Bloquear envío. |
| PDF inválido/inexistente | No enviar ni cambiar estado. |
| Gmail error explícito antes de aceptar | Reintentar según política y tipo de error. |
| Gmail éxito confirmado | `EmailMessage.sent`; cotización pasa a `sent`. |
| Gmail resultado indeterminado | `EmailMessage.send_unconfirmed`; no reenviar automático; cotización no pasa a `sent`. |
| Gmail falla definitivamente | `EmailMessage.failed`; cotización sigue sin pasar a `sent`. |
| Calendar falla | Actividad queda guardada; link `temporary_error`/`failed`. |
| Evento Google eliminado | Link `external_event_missing`; usuario decide. |

## 16. Correcciones obligatorias antes de conectar Gmail/Calendar

### GmailProvider

Reemplazar stub por implementación real:

- construir MIME;
- adjuntar PDF;
- Gmail API `users.messages.send`;
- guardar `provider_message_id` y `thread_id`;
- distinguir éxito, error explícito e indeterminado;
- idempotencia local;
- `send_unconfirmed`;
- pruebas.

### Selección de cuenta

Eliminar selección global.

Resolución obligatoria:

```text
IntegrationAccount provider=google
owner_id=usuario autenticado
is_active=true
config_json.services.gmail=true
```

Si no existe:

```text
Conectá Gmail para enviar esta cotización desde el sistema.
```

### `QuotationService::send`

Corregir código inalcanzable y separar acciones:

- `markAsSentManually()` para envío externo/manual.
- `markAsSentFromGmail()` o equivalente, invocado solo tras job Gmail exitoso.
- eventos de dominio emitidos después de commit.
- historial/auditoría consistente.
- nunca marcar `sent` si `EmailMessage.status = send_unconfirmed`.

### Calendar config/factory

Normalizar flags de `config/integrations.php` para que `AdapterFactory::calendar()` use el canal correcto (`calendar`) y provider `google`.

### OAuthState

Agregar `consumed_at` y consumo atómico de un solo uso.

### IntegrationAccount uniqueness

Agregar restricción DB compatible con soft deletes para impedir conexiones Google activas duplicadas por usuario.

## 17. Fases de implementación

### Fase 1 — OAuth Google por usuario

Archivos probables:

- `routes/web.php`
- nuevo controller de integraciones del usuario
- `app/Models/IntegrationAccount.php`
- `app/Models/OAuthState.php`
- migración `oauth_states.consumed_at`
- migración restricción conexión Google activa única
- `config/integrations.php`
- nuevas vistas `resources/views/account/integrations/*`
- tests OAuth

Entregables:

- conectar Gmail;
- conectar Calendar;
- incremental auth;
- reconectar;
- desconectar;
- estados funcionales;
- `oauth_states` one-shot;
- una conexión Google activa por usuario garantizada en DB.

### Fase 2 — Gmail para cotizaciones

Archivos probables:

- `app/Http/Controllers/QuotationController.php`
- `resources/views/quotations/show.blade.php`
- `app/Services/QuotationService.php`
- `app/Services/Email/GmailProvider.php`
- `app/Services/Email/EmailService.php`
- `app/Jobs/V2/SendEmailMessage.php` o nuevo job específico
- modelos/migraciones para idempotencia si aplica
- `resources/views/quotations/send-gmail-confirm.blade.php`
- tests Gmail/cotizaciones

### Fase 3 — Calendar CRM → Google

Archivos probables:

- nueva migración `activity_calendar_links`
- nuevo modelo `ActivityCalendarLink`
- `app/Services/ActivityService.php`
- nuevo job `SyncGoogleCalendarActivity`
- provider/adapter Google Calendar
- vistas de estado/reintento
- tests Calendar

### Fase 4 — Mejoras posteriores

- lectura Gmail;
- threads asociados a CRM;
- webhooks/watch;
- sync bidireccional Calendar;
- política de conflictos.

## 18. Pruebas obligatorias

### OAuth

- conexión exitosa;
- cancelación de autorización;
- `state` inválido;
- `state` expirado;
- callback repetido con mismo state;
- concurrencia de callbacks: solo uno consume state;
- refresh automático;
- refresh token revocado;
- desconexión;
- separación entre usuarios;
- imposibilidad de usar cuenta de otro vendedor;
- incremental auth sin perder refresh token;
- una sola conexión Google activa por usuario;
- reconexión después de soft-delete no bloqueada por índice.

### Gmail

- usuario con Gmail conectado;
- usuario sin Gmail conectado;
- destinatario ausente;
- destinatario inválido;
- varios destinatarios posibles;
- contacto principal sugerido;
- asunto/cuerpo renderizan sin variables huérfanas;
- CC/CCO vacíos por defecto y editables;
- PDF inexistente;
- PDF inválido;
- PDF demasiado grande;
- nombre PDF saneado `Cotizacion-{numero}.pdf`;
- envío exitoso;
- error temporal explícito;
- error definitivo;
- timeout/resultado indeterminado produce `send_unconfirmed`;
- `send_unconfirmed` no reintenta automáticamente;
- reintento manual advierte posible duplicado;
- prevención de doble envío;
- persistencia de `provider_message_id` y `thread_id` cuando hay éxito;
- cotización cambia a `sent` solo tras confirmación Gmail;
- cotización no cambia a `sent` en `send_unconfirmed`;
- `Marcar como enviada` no llama Gmail.

### Calendar

- crear actividad genera evento;
- reprogramar actualiza el mismo evento;
- cambiar título actualiza el mismo evento;
- actividad sin duración usa 60 minutos;
- sync inicial solo incluye actividades futuras/pendientes propias;
- sync inicial muestra cantidad y requiere confirmación;
- cancelar no crea duplicados ni elimina silenciosamente;
- soft-delete marca evento como cancelado y vínculo `cancelled`/`not_syncable`;
- error Google no impide guardar actividad;
- Calendar no conectado no bloquea CRM;
- zona horaria correcta;
- evento externo eliminado;
- reintento idempotente;
- propiedades extendidas correctas;
- usuario no modifica eventos de otro.

## 19. Criterios de aceptación

- [ ] Cada usuario conecta Google con OAuth propio.
- [ ] Gmail y Calendar se habilitan sobre la identidad Google del usuario.
- [ ] No hay selección global de cuenta.
- [ ] Hay una sola conexión Google activa por usuario protegida en DB.
- [ ] Tokens están cifrados.
- [ ] Access token vencido se refresca internamente.
- [ ] Refresh inválido marca `reconnection_required`.
- [ ] `oauth_states` se consume una sola vez.
- [ ] `Enviar por Gmail` muestra confirmación completa y editable.
- [ ] Plantilla Gmail inicial usa asunto/cuerpo definidos y no deja variables huérfanas.
- [ ] CC/CCO no tienen defaults.
- [ ] PDF se llama `Cotizacion-{numero}.pdf` y se valida antes del job.
- [ ] Envío Gmail usa cola e idempotencia local.
- [ ] Resultado indeterminado usa `send_unconfirmed` y no reenvía automáticamente.
- [ ] Cotización pasa a `sent` solo tras aceptación Gmail confirmada.
- [ ] Cotización no pasa a `sent` en `send_unconfirmed`.
- [ ] `Marcar como enviada` no llama APIs externas.
- [ ] Actividades sincronizan CRM → Google Calendar por job.
- [ ] Actividad sin duración usa 60 minutos.
- [ ] Sync inicial Calendar filtra y pide confirmación de cantidad.
- [ ] Cancelar o soft-delete de actividad no elimina evento silenciosamente.
- [ ] Evento externo eliminado se marca `external_event_missing`.
- [ ] Desactivar Gmail/Calendar localmente no promete revocar scopes individuales.
- [ ] Desconexión completa advierte impacto y no borra historial ni eventos externos.
- [ ] Auditoría no contiene secretos.

## 20. Decisiones pendientes para aprobación humana

Las decisiones funcionales pendientes del diseño anterior quedan cerradas así:

| Decisión | Resolución |
|---|---|
| Duración por defecto de eventos Calendar | 60 minutos. |
| Sync al conectar Calendar | Solo actividades futuras/pendientes propias, sin vínculo externo, con confirmación previa de cantidad. |
| Plantilla Gmail | Asunto/cuerpo definidos en §8.3, editables antes del envío. |
| CC/CCO | Ninguno por defecto; usuario agrega manualmente. |
| Nombre PDF | `Cotizacion-{numero}.pdf`, saneado. |
| Soft-delete de actividad | Actualizar evento como cancelado; no eliminar; vínculo `cancelled` o `not_syncable`. |
| Integraciones antiguas | Verificar datos reales antes de cualquier migración; no consolidar sin aprobación. |

Queda pendiente solo la **aprobación explícita para iniciar implementación de Fase 1**. Si se detectan datos reales antiguos `provider=gmail` o `provider=google_calendar`, se debe presentar una estrategia de consolidación antes de migrar.

## 21. Riesgos técnicos

| Riesgo | Mitigación |
|---|---|
| Duplicar correos por doble clic/job duplicado | Idempotency key + estado `processing` + short-circuit. |
| Gmail acepta pero CRM no confirma | `send_unconfirmed`, sin reenvío automático, reintento manual con advertencia. |
| Duplicar eventos Calendar | Unique link + extended properties. |
| Refresh token perdido por incremental auth | Conservar refresh token anterior si Google no devuelve uno nuevo. |
| Usuario cree que desactivar Gmail revoca scope Google | UI debe explicar diferencia entre desactivar localmente y revocar Google completo. |
| Selección de cuenta incorrecta | Resolver siempre por `owner_id` + DB unique. |
| PDF pesado o inválido | Validación previa al job. |
| Calendar externo eliminado | `external_event_missing`, decisión manual. |
| Scopes excesivos | Solicitar solo scopes MVP e incremental auth para agregar, no para retirar. |
| Reuso OAuth state | `consumed_at` + consumo atómico + pruebas de concurrencia. |

## 22. Resultado de esta revisión

El diseño queda actualizado y conceptualmente cerrado para el MVP, pero **no autoriza implementación todavía**.

Próximo paso permitido solo con aprobación explícita:

```text
Fase 1 — OAuth Google por usuario
```

La implementación de Gmail y Calendar queda fuera hasta completar y validar Fase 1.
