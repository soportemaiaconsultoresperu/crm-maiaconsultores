# B10 — Línea base V1 (CRM Maia Consultores)

> **Estado**: BORRADOR preaprobado por la dirección. Ninguna modificación de V1 se aplicó durante B10.
> **Fecha de auditoría**: línea base cerrada al cierre de B10.
> **Alcance**: `crm-maia-consultores` — Laravel 13.25.0 + PHP 8.3.16 + MySQL + AdminLTE 4.

---

## 1. Versiones exactas

| Capa | Versión |
|---|---|
| PHP CLI | 8.3.16 (ZTS Visual C++ 2019 x64) |
| Laravel Framework | 13.25.0 |
| Node | (no verificado en B10 — fuera de scope) |
| Base de datos | MySQL (driver `mysql` activo por `config/database.php`) |

### Composer (`require` directos)

| Paquete | Constraint |
|---|---|
| `laravel/framework` | `^13.17` |
| `spatie/laravel-permission` | `^8.3` |
| `spatie/laravel-activitylog` | `^4.12` |
| `livewire/livewire` | `^4.4` |
| `maatwebsite/excel` | `^4.0` |
| `barryvdh/laravel-dompdf` | `^3.1` |
| `guzzlehttp/guzzle` | **no declarado** — presente vía transitivo |

### Node (`package.json`)

| Paquete | Constraint |
|---|---|
| `admin-lte` | `^4.3.1` |
| `bootstrap-icons` | `^1.13.1` |
| `vite` | `^8.0.0` |

---

## 2. Resultados de la suite de pruebas

Comando: `C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test`

| Métrica | Valor |
|---|---|
| Tests corridos | 372 |
| Tests pasados | **372** |
| Tests fallados | **0** |
| Aserciones | 1 495 |
| Duración | 171 283 ms (~2 min 51 s) |

**Veredicto**: la línea base V1 es **estable en verde**. No hay regresiones derivadas de la auditoría.

---

## 3. Inventario de módulos y áreas de código

| Área | Archivos | Observación |
|---|---|---|
| `app/Http/Controllers` | 21 | Recursos REST + acciones custom |
| `app/Services` | 19 | Toda la lógica de dominio |
| `app/Models` | 23 | Lead, Customer, Contact, Opportunity, Quotation, QuotationItem, Product, Activity, Document, User, Team, Setting, catálogos |
| `app/Policies` | 10 | `ModulePolicy` base + 9 políticas específicas |
| `app/Notifications` | 5 | `via() = ['database']` exclusivamente |
| `app/Console/Commands` | 3 | Scheduler ya configurado en `routes/console.php` |
| `resources/views/*.blade.php` | 93 | Server-rendered con `@extends('layouts.app')` |
| `database/migrations` | 23 | 13 columnas ENUM enumeradas abajo |
| `tests/Feature` | 60 | Cobertura real de V1 |
| `tests/Unit` | 1 | Solo `ExampleTest.php` (boilerplate) |

### Directorios esperados para V2 — **no existen aún**

| Directorio | Estado |
|---|---|
| `app/Events` | ausente |
| `app/Listeners` | ausente |
| `app/Observers` | ausente |
| `app/Jobs` | ausente |
| `app/Livewire` | ausente |

**Nota**: Livewire aparece en `composer.json` pero no se usa en ninguna vista. Decisión `C-05`: **conservarlo** y darle uso real en V2.

### `.env` — claves básicas presentes

| Clave | Presente |
|---|---|
| `APP_KEY` | ✅ (no se lee el valor) |
| `ADMIN_EMAIL` | ✅ |
| `ADMIN_PASSWORD` | ✅ |
| `DB_HOST` | ✅ |

`APP_KEY` figura en `.env` pero **no en `.gitignore`** (no hay git todavía). Esto se aborda en P0 §5.

---

## 4. ENUM nativos de MySQL en V1

13 columnas ENUM distintas en migraciones (15 ocurrencias). V2 no debe modificar las existentes salvo justificación explícita (corrección `C-03`).

| Columna | Valores | Default | Nullable |
|---|---|---|---|
| `code_sequences.entity` | `lead, customer, opportunity, quotation` | — | — |
| `settings.type` | `string, integer, decimal, boolean, json` | — | — |
| `ubigeo.level` | `departamento, provincia, distrito` | — | — |
| `pipeline_stages.stage_type` | `open, won, lost` | — | — |
| `leads.person_type` | `natural, juridica` | — | — |
| `customers.person_type` | `natural, juridica` | — | — |
| `doc_type` (lead/customer) | `dni, ruc, ce, pasaporte, otro` | — | sí |
| `leads.interest_level` | `bajo, medio, alto` | — | sí |
| `customers.status` | `activo, inactivo` | `activo` | — |
| `opportunities.priority` | `baja, media, alta` | `media` | — |
| `activities.status` | `pending, in_process, completed, cancelled, overdue` | `pending` | — |
| `activities.priority` | `baja, media, alta` | `media` | — |
| `products.product_type` | `producto, servicio` | — | — |
| `quotations.status` | `draft, sent, accepted, rejected, expired, voided` | `draft` | — |

**Implicación V2**: los nuevos estados de ejecuciones de automatizaciones, canales de notificación, modalidades de pago, estado de web submissions, etc. **deben ir como VARCHAR + clase `enum` de PHP + índice**, no como ENUM MySQL nuevo.

---

## 5. Deuda técnica clasificada

### P0 — Bloquea V2

| # | Hallazgo | Archivo / área | Detalle |
|---|---|---|---|
| P0-1 | **No hay bus de eventos Laravel** | `app/Events/`, `app/Listeners/` | V1 sólo usa `spatie/activitylog` (`activity()->event()->log()`) y un único `event(new Lockout(...))` en `LoginRequest`. V2 debe introducir `app/Events/V2/*` y `app/Listeners/V2/*` sin tocar V1. |
| P0-2 | **No existen Jobs** | `app/Jobs/` ausente | `config/queue.php` ya está preparado (`default = database`, `jobs` + `failed_jobs` existen), pero no se ejecuta `queue:work`. B11 introduce el primer Job. |
| P0-3 | **No existen Observers** | `app/Observers/` ausente, `AppServiceProvider::register()` vacío | V1 no usa `eloquent.saved`/`created` para proyección. V2 va a agregar observadores para Lead/Customer/Opportunity/Quotation que disparen eventos Laravel. |
| P0-4 | **No existen endpoints públicos** | `routes/web.php` | Toda la aplicación está tras `auth+active`. No hay `routes/api.php` ni `routes/webhooks.php`. V2 necesita rutas sin auth + middleware de firma/rate-limit. |
| P0-5 | **`APP_KEY` no está bajo control de git** | `.env` presente, sin `.gitignore` | El proyecto no tiene git todavía. Al inicializarlo B10→B11, `.env` debe quedar en `.gitignore` y rotar la key. No se modifica `.env` en B10. |
| P0-6 | **No hay infraestructura de idempotencia** | n/a | No existe `webhook_events`, no hay `idempotency_key` en ningún servicio. V2 la introduce desde B11. |
| P0-7 | **No hay contratos de proveedor** | `app/Providers/` no los define | Meta, Google, Outlook, SMTP no tienen abstracción. V2 introduce `app/Integrations/Contracts/*`. |

### P1 — Deben resolverse en B11..B17

| # | Hallazgo | Archivos | Detalle |
|---|---|---|---|
| P1-1 | **Sistema "mono-canal"** | `app/Notifications/*` | Los 5 `via()` son `['database']`. Necesita extenderse por canal (database / mail / whatsapp / webhook). |
| P1-2 | **`Settings` global estricto** | `app/Models/Setting.php` | Sin columna `user_id`/`team_id`. No se puede usar para credenciales por usuario. V2 debe introducir `integration_accounts` para tokens y mantener `settings` para flags/parámetros. |
| P1-3 | **No hay verificación de firma en webhooks** | n/a | No hay middleware `verify.webhook.signature`. V2 lo crea en B11. |
| P1-4 | **No hay rate-limit/anti-spam centralizado** | n/a | B15 introduce `throttle.webform` y honeypot. |
| P1-5 | **No hay redacción de PII en logs** | `config/logging.php` | V2 añade un `RedactPiiProcessor` que enmascare email, números y nombres. |
| P1-6 | **No hay pruebas de cola, mail, webhook ni idempotencia** | `tests/Feature/` | V2 debe añadir `Mail::fake()`, `Queue::fake()`, `Bus::fake()` (B11 en adelante). |
| P1-7 | **Livewire declarado y no usado** | `composer.json` req Livewire | `C-05`: permanece instalado; V2 lo usa en automatizaciones, bandeja WhatsApp, webforms, filtros, preferencias. |
| P1-8 | **Cifrado de archivos privados en disco `local`** | `config/filesystems.php` | V2 sólo guarda adjuntos en `storage/app/private` hasta B17 cuando se pase a S3. |
| P1-9 | **No hay `socialite` ni cliente HTTP dedicado para OAuth** | `composer.json` | Para Gmail/Outlook se necesita `socialiteproviders/google` y `socialiteproviders/microsoft`, o un cliente HTTP manual con `Guzzle`. Decisión en §7. |
| P1-10 | **No hay `laravel/socialite`** | `composer.json` | Imposible reusar la abstracción de Laravel para OAuth. |

### P2 — Mejoras de fondo

| # | Hallazgo | Detalle |
|---|---|---|
| P2-1 | **Colores CSS hardcoded** | El bloque Zoho está en `resources/css/app.css` con valores literales. V2 debería poder refactorizar a `resources/css/v2.css` con variables. |
| P2-2 | **Layout único para todo** | `layouts/app.blade.php` no tiene variante pública. V2 introduce `layouts/public.blade.php` para webforms. |
| P2-3 | **No hay documentación viva** | No existe `docs/` todavía (B10 lo crea). Por lo demás, los README son implícitos. |
| P2-4 | **No hay `composer.json` con `extra.docs`** | V2 lo aprovecha para `infection/loophole` (P1-5) o `openapi-php`. |
| P2-5 | **No hay `LICENSE` ni `CHANGELOG`** | Sin proceso de release aún. |
| P2-6 | **Scheduler en horario Lima** | `routes/console.php` usa `timezone('America/Lima')`. V2 debe respetarlo. |

---

## 6. Puntos de extensión confirmados para V2

Decisión `C-01`: los servicios de V1 deben emitir **eventos Laravel explícitos** después de la transacción de dominio. Listado de puntos donde V2 insertará un `event(new XxxEvent(...))` Sin tocar comportamiento funcional:

| Servicio V1 | Disparador V2 | Evento a emitir |
|---|---|---|
| `app/Services/LeadService.php` | `create()` tras `assign()` | `LeadCreated`, `LeadAssigned` |
| `app/Services/LeadService.php` | cambio de `status_id` | `LeadStatusChanged` |
| `app/Services/OpportunityService.php` | `changeStage()`, `win()`, `lose()` | `OpportunityStageChanged`, `OpportunityWon`, `OpportunityLost` |
| `app/Services/QuotationService.php` | `accept()`, `send()`, `create()` | `QuotationCreated`, `QuotationSent`, `QuotationAccepted` |
| `app/Services/ActivityService.php` | `complete()` | `ActivityCompleted` |
| `app/Services/ContactService.php` | `setPrimary()`, `deactivate()` | `ContactPrimaryChanged`, `ContactDeactivated` |
| `app/Services/CustomerService.php` | probable deactivación | `CustomerDeactivated` |
| `app/Services/LeadConversionService.php` | conversión | `LeadConverted`, `CustomerCreatedFromLead` |
| `app/Http/Controllers/QuotationController.php` | cálculo de `expires_at` | `QuotationWillExpire` (scheduler-driven, no en servicio) |

`spatie/activitylog` sigue siendo la **auditoría** y no dispara automatizaciones. La proyección de cada evento para que las condiciones de las reglas tengan datos ya disponibles: leer del propio modelo recargado, **no** de `activity_log.properties`.

---

## 7. Dependencias propuestas para B11 (sin aplicar todavía)

Orden lógico, no de instalación. Justificación por propuesta.

| Capa | Recomendación | Justificación |
|---|---|---|
| Cliente HTTP | `guzzlehttp/guzzle` (sólo declarar en `require` para que sea de nivel-1) | Ya está transitivo. Declararlo permite pin de versión y lo deja explícito. |
| OAuth Google | `laravel/socialite` + `socialiteproviders/google` | Laravel Socialite es la abstracción canónica. `socialiteproviders/google` añade provider Google. |
| OAuth Microsoft | `socialiteproviders/microsoft` | Misma familia para mantener un único patrón de OAuth. |

> **Corrección aplicada durante B11 (2025)**: `socialiteproviders/google` y `socialiteproviders/microsoft` aún **no publicaron la rama 5.x** al cierre de B10; las versiones publicadas más recientes son `4.1.0` y `4.10.0` respectivamente. Para no bloquear la entrega de B11 se **relajaron ambos constraints a `^4.0`** (rama estable y vigente). La razón original de proponer `^5.0` quedó desactualizada; lo importante es la **funcionalidad**, no la versión. Las decisiones funcionales de §4 (`D-22..D-24`) y los contratos de B11 no cambian.
| Cifrado de credenciales | `laravel/crypt` (núcleo Laravel) | `Crypt::encryptString` ya existe. **No requiere añadir paquete**. |
| Cola | `database` (dev) / `redis` (prod) — driver nativo Laravel | Sin paquete adicional. |
| Almacenamiento | `league/flysystem-aws-s3-v3` (sólo en prod) | Cliente S3 estándar de Laravel. |
| Calendario externo | **sin paquete dedicado** — cliente HTTP manual sobre `Http::client()` | La API de Google Calendar y Microsoft Graph se puede consumir con 1 cliente HTTP por proveedor. Evita dependencias innecesarias. |
| WhatsApp | **sin paquete dedicado** — cliente HTTP manual sobre Meta Cloud API | Igual criterio. La lógica se aísla en `app/Integrations/WhatsApp/MetaProvider`. |
| CAPTCHA | **sin paquete** — `Http::post()` al endpoint `challenges.cloudflare.com/turnstile/v0/siteverify` | Turnstile son 2 campos (`sitekey` + `secret`) y un POST. Un paquete agrega peso innecesario. |
| Webhooks | `sysupointo/php-github-webhook` (rechazado) — usar middleware propio | La firma cambia por proveedor (Meta `X-Hub-Signature-256`, Google `X-Goog-Signature`, Microsoft `X-Microsoft-Webhook-Signature`). Mejor un middleware dedicado por proveedor. |
| Idempotencia | `illuminate/cache` (núcleo Laravel) | `Cache::lock()` + restricción UNIQUE en `webhook_events.idempotency_key`. |
| Redis | `predis/predis` (sólo en prod) | Driver alternativo a `phpredis`. Sin paquete si tienen `phpredis` instalado. |

**Resumen**: las dependencias mínimas **declarables en composer.json** para B11 son sólo:

```jsonc
"require": {
  "guzzlehttp/guzzle": "^7.9",
  "laravel/socialite": "^5.17",
  "socialiteproviders/google": "^4.0",
  "socialiteproviders/microsoft": "^4.0"
}
```

> **Nota B11**: los constraints originales eran `^5.0`; se ajustaron a `^4.0` porque los paquetes `socialiteproviders/*` aún no publicaron rama 5.x. Ver la nota en §7 superior.

PHP nativo cubre cifrado, cola, idempotencia y caché. Los proveedores de calendario, WhatsApp y CAPTCHA no requieren paquetes adicionales.

**No se aplica este cambio hasta aprobación expresa del cierre de B10.**

---

## 8. Riesgos pendientes de B10

| # | Riesgo | Mitigación |
|---|---|---|
| R-1 | `APP_KEY` está en `.env` y **`APP_ENV` no se verifica** | Documentar como `B11-INFRA-1`; rotar la key al cerrar B10 si la dirección lo aprueba. |
| R-2 | Existe `livewire/livewire` declarado pero no usado | Conservar por `C-05`. V2 le da uso real. |
| R-3 | La cobertura de tests llega a V1, pero no a colas, mail, webhooks | Esperado. B11..B17 añaden las pruebas. |
| R-4 | El scheduler actual corre in-line; V2 introduce Jobs con `tries=3` y backoff | B11 documenta `supervisor`/`systemd` para `queue:work`. |
| R-5 | Hay 13 columnas ENUM en V1; las nuevas no deben sumarse a esa lista | V2 usa VARCHAR + clase PHP. |
| R-6 | Los servicios de V1 no llaman `event()` de Laravel | C-01 obliga a **agregar** las llamadas al final de cada método de servicio, después del `DB::commit`. No cambiar lógica. |
| R-7 | El proyecto no tiene git | B10 no la inicializa. Se deja como decisión previa a B11. |
| R-8 | No hay backup automatizado | B10 no lo crea (no autorizado). El usuario decide cuándo. |

---

## 9. Criterios de aceptación de B10 — verificación

| # | Criterio | Estado |
|---|---|---|
| 1 | V2-Hoja-de-ruta aprobada por dirección | ✅ |
| 2 | Respaldo externo del estado de V1 | ⏸️ **no ejecutado** — no autorizado en el bloque de correcciones |
| 3 | `docs/v2/00-baseline.md` creado (este documento) | ✅ |
| 4 | `php artisan test` en verde | ✅ 372/372 |
| 5 | Mapa de integración (este documento + correcciones aplicadas) | ✅ §6 |
| 6 | Decisiones pendientes §8 convertidas en tarjetas | ✅ ya incorporadas en correcciones |
| 7 | **No se modifica código de V1** | ✅ confirmado |

> **Nota sobre (2)**: la dirección autorizó explícitamente "no realices el respaldo sobreescribiendo archivos existentes". B10 deja al usuario la ejecución del respaldo externo antes de pasar a B11. No se creó git ni se inicializó un commit.

---

## 10. Confirmación de no-modificación de V1

B10 **no modificó** ningún archivo de V1:

- `app/Models/`, `app/Services/`, `app/Http/Controllers/`, `app/Policies/`, `app/Notifications/`, `app/Console/Commands/`, `resources/`, `routes/`, `database/`, `tests/`, `config/`, `composer.json`, `package.json` — **sin cambios**.
- Únicos archivos nuevos: `docs/v2/00-baseline.md` (este) y `docs/v2/01-roadmap.md` (hoja de ruta actualizada).
- La suite de tests pasó en verde antes y después de B10.

→ **B10 cumple los criterios de aceptación pendientes del bloque de autorización, a excepción del respaldo, que debe ejecutarlo la dirección cuando lo considere.**

---

*Fin del Informe de Línea Base. Esperando aprobación para cerrar B10.*
