# B17-Notifications — Notification infrastructure (PRD / sdd-proposal)

> **Phase**: sdd-proposal (PRD only — no technical spec, design, or tasks here).
> **Upstream artifacts** (authoritative):
>
> - `docs/v2/01-roadmap.md` §2.7 (B17 schema), §10 decisions D-21a..D-21g.
> - `docs/v2/01-roadmap.md` §11 (B17 in the implementation plan).
> - B13 (Email) + B14 (WhatsApp) implementation patterns (EmailService, MetaWhatsAppProvider, NotifyAdminsOnIntegrationEvent).
>
> **Product decisions**: 4 mandatory administrative / security triggers (D-21a..D-21d) are in scope; D-21f (new-device detection) and D-21g (SLA) are explicitly NO V2.

---

## 1. Resumen ejecutivo

**B17-Notifications es la capa de infraestructura de notificaciones del CRM**: el motor que persiste, despacha, registra y permite reintentar cualquier comunicación saliente del sistema (mail + database + webhook + whatsapp), atado a 4 triggers administrativos obligatorios y a un sistema de preferencias por usuario. Hoy las notificaciones se hacen ad-hoc o no se hacen; B17 centraliza el pipeline con un modelo de OutboundDelivery, una cola async (`tries=3, backoff=[60,300,900]`) y un audit surface completo. B17 ships `notification_preferences` (per-user, per-channel toggle) y `outbound_deliveries` (audit log + retry surface); el entregable es funcional end-to-end desde el primer slice.

## 2. Problema de negocio

- **Pain operacional**: hoy los admin / supervisor del CRM no tienen un canal centralizado para enterarse de eventos críticos (integración falló, cuenta desconectada, automation cycle, errores permanentes). Cada vez que algo falla, hay que entrar a la DB a buscar `error_class` / `error_message` en tablas que cambian según el módulo.
- **Riesgo de deriva**: las notificaciones comerciales (e.g. un vendedor recibe un correo cuando su lead cumple una condición) están dispersas o son manuales; sin un sistema central, las notificaciones se duplican, se pierden o se personalizan en cada implementación.
- **Coste de soporte**: hoy si una notification sale mal, no hay retry surface ni audit log; el operador tiene que adivinar si llegó o no.
- **Oportunidad**: B13 (Email) y B14 (WhatsApp) ya cablean providers reales. B17 los orquesta detrás de un único pipeline, con preferencias por usuario y triggers administrativos garantizados.

## 3. Usuarios objetivo y situaciones

| Persona | Necesita | Flujo concreto |
|---|---|---|
| **Admin** (rol con `notifications.manage` + `notifications.send`) | Configurar preferencias de cualquier usuario; forzar dispatch manual; ver audit de toda la cola. | Abre `admin.notifications.preferences.index` → ve lista por usuario/canal; toggle `enabled`; abre `admin.notifications.deliveries.index` → filtra por `status=failed`, hace `retry`. |
| **Supervisor** (rol con `notifications.view` + `notifications.audit`) | Ver la cola y los audit log sin poder tocar. | Abre `admin.notifications.deliveries.index` → solo lectura. |
| **Operador del sistema** (rol con `notifications.audit` + módulo) | Diagnosticar por qué un email se quedó en `status='failed'` con `attempts=3`; copiar el `idempotency_key` para pegar en un bug-report. | Filtra por `channel=mail, status=failed` → ve delivery → abre detail → `idempotency_key` visible. |

## 4. Reglas de negocio y permisos

El catálogo `notifications.*` se registra en `NotificationServiceProvider::registerNotificationPermissions()` al boot. B17 introduce 4 permissions:

| Permiso Spatie | UI action | Página / componente | Comportamiento |
|---|---|---|---|
| `notifications.view` | Listar preferences + deliveries + ver detail. | `admin.notifications.preferences.index`, `admin.notifications.deliveries.index`, `admin.notifications.deliveries.show` | Sin este permiso, los 3 endpoints devuelven 403. |
| `notifications.manage` | Toggle `enabled` en preferences, retry en deliveries, dispatch manual. | Las 3 endpoints anteriores + `admin.notifications.dispatch`, `admin.notifications.deliveries.retry` | Sin este permiso, los endpoints `PATCH/POST` devuelven 403. |
| `notifications.audit` | Ver la cola completa + audit fields. | Mismo que `notifications.view` (read-only). | Admin-only en v1. |
| `notifications.send` | Forzar `admin.notifications.dispatch` con cualquier `channel/recipient_ref/payload`. | El endpoint `POST admin.notifications.dispatch`. | Admin-only en v1. |

Reglas de dominio adicionales que la UI / service reflejan:

1. **Default opt-in**: si no existe `NotificationPreference` para (user, subject_type, channel), `isEnabled()` retorna `true` (D-21e — el usuario opt-out debe ser explícito). Los 4 triggers administrativos obligatorios (D-21a..D-21d) **siempre** se envían a todos los admin, sin chequear preference — son críticos.
2. **Idempotency**: `OutboundDelivery.idempotency_key = sha1(channel . '|' . recipient_ref . '|' . related_entity_type . '|' . related_entity_id . '|' . payload_json . '|' . bucket)`. UNIQUE column. Re-dispatch dentro de la ventana → devuelve la fila existente sin re-encolar.
3. **Retry policy**: `tries=3, backoff=[60, 300, 900]`. Mientras `attempts <= MAX_ATTEMPTS (3)`, la fila queda en `status='queued'` con `next_attempt_at` programado. Cuando `attempts > MAX_ATTEMPTS`, la fila flips a `status='failed'` (terminal) y se emite `NotificationFailedPermanently` event.
4. **Channel dispatch paths**: `database` → Laravel `DatabaseNotification`; `mail` → `Mail::raw()`; `whatsapp` → `MetaWhatsAppProvider::sendFreeFormMessage` (stub-mode retorna `NotImplementedException`); `webhook` → `Http::post()`.
5. **Admin recipient set**: los 4 triggers obligatorios despachan a `User::query()->where('is_active', true)->whereHas('roles', fn($q) => $q->where('name', 'admin'))->pluck('email')` — fresh en cada evento (permisos otorgados recién se reflejan en el próximo evento).

## 5. Resultado esperado (outcome)

- **T1 — Cero eventos críticos perdidos**: cualquier `IntegrationAccountDisconnected` o `AutomationCycleDetected` o `IntegrationFailedPermanently` se entrega a todos los admin (con retry + audit). Operador nunca pierde un evento crítico.
- **T2 — Audit surface completo**: cualquier `OutboundDelivery` queda persistido con `last_error`, `last_response_code`, `attempts`, `idempotency_key`, `created_at`. Operador puede copiar el `idempotency_key` para correlacionar con logs externos.
- **T3 — Retry surface manual**: deliveries en `status='failed'` tienen botón de retry que resetea `attempts=0` y re-encola el job. Operador no necesita esperar al backoff.
- **T4 — Opt-out granular por usuario**: cada usuario puede deshabilitar `notifications.audit` para un subject_type sin perder las 4 obligatorias (esas no consultan preference). Default opt-in mantiene el comportamiento histórico.
- **T5 — Idempotencia estricta**: doble-dispatch dentro de la ventana (mismo bucket + mismo payload + mismo recipient) retorna la fila existente sin re-encolar. Sin race conditions en retry concurrente.

## 6. Brecha actual

- **No hay audit surface**: no existe `OutboundDelivery` ni `NotificationPreference`. Si una integración falla, no hay registro centralizado — el operador revisa DB ad-hoc.
- **No hay retry policy**: cualquier failure de mail/webhook hoy es terminal. Si el SMTP está down 2 minutos, se pierden todos los correos.
- **No hay 4 triggers obligatorios**: `IntegrationAccountDisconnected`, `AutomationCycleDetected`, `IntegrationFailedPermanently`, `NotificationFailedPermanently` no se emiten. Los admin no se enteran de eventos críticos.
- **No hay default de opt-in explícito**: cuando un módulo nuevo necesita notificar, re-implementa el envío.

## 7. Scope del primer slice (B17 v1)

Incluye:

1. **2 migrations** — `notification_preferences`, `outbound_deliveries` (ver `docs/v2/01-roadmap.md` §2.7).
2. **2 models Eloquent** — `App\Models\Notification\NotificationPreference`, `App\Models\Notification\OutboundDelivery` con constantes `STATUS_*`, `CHANNEL_*`, `SCOPE_*`, `MAX_ATTEMPTS` y scopes canónicos.
3. **`NotificationService`** — el dispatcher central; persist + async job dispatch + idempotency + mark states (sending/sent/failed/skipped).
4. **`SendOutboundDelivery` ShouldQueue job** — channel-specific send paths (database/mail/whatsapp/webhook) con `tries=3, backoff=[60,300,900]`.
5. **4 event classes** — `IntegrationFailedPermanently`, `IntegrationAccountDisconnected`, `AutomationCycleDetected`, `NotificationFailedPermanently` (marker contracts para los triggers D-21a..d).
6. **`NotifyAdminsOnIntegrationEvent` listener** — único listener que materializa los 3 triggers D-21a..c (D-21d solo emite event, listener deferred a B17.x).
7. **`NotificationController` admin** — 6 endpoints (`preferences`, `updatePreference`, `deliveries`, `showDelivery`, `retry`, `dispatchNow`) con `Gate::authorize` como primera statement. JSON responses en Pasada B (las vistas Blade dedicadas quedan para B17.x; el patrón API-style es suficiente para archive + smoke HTTP).
8. **`NotificationServiceProvider`** — registra los 4 permisos + los 3 listeners en `boot()`.
9. **`AdditionalNotificationPermissionsSeeder`** — idempotent firstOrCreate + syncPermissions.

Fuera de scope (ver §8).

## 8. No-goals (explícitos)

- **D-21f — Detección de nuevo dispositivo**: NO V2. La audit surface se alimenta del login log de Laravel (`auth()->user()->last_login_at` ya existente) si la dirección lo pide; sin embargo, no es un trigger B17.
- **D-21g — SLA de notificación**: NO V2. Sin timers que disparen escalación. La retry policy de B17 es la única garantía temporal.
- **Full Livewire bandeja + dedicated admin views**: la B17 Pasada B es "minimum-viable" con JSON responses para que archive verifique; la bandeja Livewire completa con 2 views (`preferences/index`, `deliveries/index`, `deliveries/show`) queda para un follow-up **B17.x** (post-archive).
- **B11 stub migration** (`App\Integrations\Contracts\EmailProvider` / `WhatsAppProvider` que el B11 dejó como placeholder) — la integración se hace via los nuevos `App\Contracts\Email\EmailProvider` (B13) y `App\Contracts\WhatsApp\WhatsAppProvider` (B14). El B11 stub queda intacto.
- **Slack / Telegram channels** — solo `database`, `mail`, `whatsapp`, `webhook` en v1.
- **Plantilla de email rica (B13 EmailTemplateRenderer)** — el `mail` channel en v1 usa `Mail::raw()` con un subject genérico. B17.x puede wirear el `EmailTemplateRenderer` cuando se necesite.
- **NotificationPreferenceList Livewire component** — deferred a B17.x.

## 9. Edge cases

1. **Provider opt-out**: si un admin deshabilita `notifications.audit` para `IntegrationAccount` y subject_type=`IntegrationAccount`, los triggers D-21a..c **ignoran** su preference (son críticos). Confirmado en `NotifyAdminsOnIntegrationEvent::dispatchToAdmins()`: itera admin users pero **no** consulta `isEnabled()`. Doc contract.
2. **Multi-channel preference**: si un usuario tiene preferences para `(subject=IntegrationAccount, channel=mail, enabled=true)` y para `(subject=IntegrationAccount, channel=database, enabled=false)`, el sistema envía mail pero no database. Confirmado en `NotificationService::isEnabled()`.
3. **Exhausted retries**: `attempts > MAX_ATTEMPTS (3)` → `status='failed'` (terminal). `last_error` se preserva. Se emite `NotificationFailedPermanently` event. Confirmado en `markFailed()`.
4. **Missing IntegrationAccount FK**: si `outbound_deliveries.account_id` apunta a un account eliminado (FK violation), la inserción falla. Catch at dispatcher level + log warning + status='skipped'. Deferred a B17.x.
5. **Admin user deactivated**: si un admin está `is_active=false`, `dispatchToAdmins()` lo omite (filtra `where('is_active', true)`). El resto de admins recibe la notification.
6. **Idempotency race**: dos dispatches concurrentes con el mismo bucket+payload pueden ambos leer "no existe fila" antes de que cualquiera inserte. La UNIQUE constraint de `idempotency_key` rechaza el segundo INSERT con `QueryException` que el dispatcher captura y trata como "fila existente" (return existing via `firstOrCreate` semantics).
7. **Malformed webhook body**: si el operador configura un `webhook` channel con un `recipient_ref` no-URL, el `Http::post` falla con `RequestException` que `markFailed` captura y reintenta.
8. **Repeat-bucket in same minute**: el `idempotency_key` incluye `bucket + payload_json + time()` no — pero el `dispatched_at` no es parte del hash. Si dos eventos idénticos se disparan con la misma bucket en la misma ventana, la segunda llamada retorna la fila existente sin re-encolar (test `test_dispatch_idempotency_returns_existing_row` lo cubre).

## 10. Decisiones de producto tomadas

| ID | Decisión | Razón |
|---|---|---|
| D-21a | `IntegrationFailedPermanently` → mail todos los admin (no preference check). | Trigger crítico; admin no puede opt-out. |
| D-21b | `IntegrationAccountDisconnected` → mail todos los admin. | Idem. |
| D-21c | `AutomationCycleDetected` → mail todos los admin. | Idem. |
| D-21d | `NotificationFailedPermanently` event emitted; listener deferred to B17.x. | El operator puede leer la cola `status='failed'` desde la UI; un listener que re-dispatch sería spam. |
| D-21e | Default opt-in: si no existe preference row, `isEnabled()=true`. | El usuario debe ser explícito en opt-out. Los 4 triggers D-21a..d no consultan preference. |
| D-21f | NO V2. | Decisión de la dirección: defer. |
| D-21g | NO V2. | Decisión de la dirección: defer. |
| **Routing** | `database` + `mail` + `whatsapp` + `webhook` only. NO Slack/Telegram. | Cobertura del CRM v1 suficiente. |
| **Templates** | `mail` channel usa `Mail::raw()` con subject genérico; `B13\EmailTemplateRenderer` se wirea en B17.x si se necesita. | B17 Pasada B es minimum-viable; el editor de plantillas ya existe (B13) y se puede reusar. |
| **UI** | `NotificationController` retorna JSON en Pasada B; vistas Livewire + dedicated admin views son B17.x. | B17 archive verifica los gates + el pipeline; UI rica es follow-up. |
| **Retry policy** | `tries=3, backoff=[60, 300, 900]`. | Mirror del patrón B13 + B14. |

## 11. Riesgos y rollback

| # | Riesgo | Mitigación |
|---|---|---|
| R-B17-1 | Si `outbound_deliveries` crece sin rotación, la tabla se infla. | Política de retención: 90 días para `status='sent'` o `status='delivered'` (B17.x). |
| R-B17-2 | Admin recipient set se evalúa al momento del evento. Si no hay admin activo, el evento se pierde silenciosamente. | Log warning si `dispatchToAdmins()` retorna 0 deliveries. |
| R-B17-3 | Si el listener se cuelga (e.g. SMTP timeout en mail channel), `markFailed` puede no ejecutarse a tiempo. | `tries=3` en el job + `failed()` hook que fuerza `status='failed'`. |
| R-B17-4 | Los 4 triggers pueden generar spam si una integración está en loop. La idempotency key mitiga duplicados dentro de la ventana, pero eventos distintos pueden seguir llegando. | Log warning si el mismo `(subject_type, related_entity_id, bucket)` se dispara > 5 veces en 1h (B17.x). |
| R-B17-5 | El `idempotency_key` se calcula con `json_encode($payload)` que puede no ser determinista si el payload tiene claves en orden distinto. | `ksort` en `dispatch()` antes de hashear (B17.x hardening). |

**Rollback note**: B17 no agrega migrations a tablas pre-existentes. Las 2 nuevas tablas + 2 modelos + 1 service + 1 job + 1 provider + 4 events + 1 listener + 1 controller + 6 routes son 100% file-backed. No DDL rollback needed. Una vez que el usuario initialice git, `git revert <b17-commit>` devuelve al estado pre-B17.

## 12. Criterios de éxito

- **C-B17-1**: `NotificationService::dispatch()` persiste una fila `OutboundDelivery` y encola `SendOutboundDelivery` job. (AC-1)
- **C-B17-2**: `dispatch()` es idempotente — segunda llamada con mismos args retorna la fila existente sin re-encolar. (AC-2)
- **C-B17-3**: `isEnabled()` retorna `true` cuando no existe preference row; retorna el valor del row cuando existe. (AC-3)
- **C-B17-4**: `markFailed()` incrementa `attempts` y mantiene `status='queued'` mientras `attempts <= 3`; flips a `status='failed'` cuando `attempts > 3`. (AC-4)
- **C-B17-5**: Los 4 events existen como marker contracts (`IntegrationFailedPermanently`, `IntegrationAccountDisconnected`, `AutomationCycleDetected`, `NotificationFailedPermanently`) y los 3 listeners D-21a..c están wired en `NotificationServiceProvider::boot()`. (AC-5)
- **Engine regression guard**: `php artisan test --filter=AutomationEngineTest` returns 10/10 / 21 assertions (AC-6).
