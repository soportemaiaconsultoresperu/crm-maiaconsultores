# Auditoría de aserciones y defectos ocultos

> **Fecha**: 2026-09-12
> **Origen**: al cerrar el módulo `course-talks-management` aparecieron **nueve defectos reales
> que convivían con 484 tests en verde**. Ninguno lo encontró la suite: los encontraron dos rondas
> de review independiente. Este documento audita el resto del repositorio buscando el mismo patrón.
> **Método**: tres auditores independientes (dinero, autorización, envíos) en modo lectura, con el
> catálogo de los nueve patrones como guía. Los hallazgos marcados **[verificado]** los confirmó el
> responsable releyendo el código; los demás provienen del auditor con su evidencia citada.
> **Estado de la suite al auditar**: 1.288 tests, **25 rojos** (13 fallos + 12 errores). "Verde" no
> es hoy un invariante de este repositorio.
>
> **Corrección medida (2026-09-12)**: re-medido dejando el árbol en su estado original, la suite
> son **1.299 tests / 23 rojos** (11 fallos + 12 errores). El conteo de 1.288/25 quedó viejo; los
> 12 errores son, en los dos números, los mismos `setUp()` de campaña. Los 11 fallos restantes son
> los de `b12-ui` / `HistoryAndAudit` / `SettingsService` / `GmailProvider` / `GoogleCalendarWebhook`
> y no pertenecen a este arreglo.

## El patrón que se buscaba

Cada entrada del catálogo ocurrió de verdad en el módulo de cursos:

| # | Patrón | Forma real que tomó |
|---|---|---|
| 1 | Aserta el estado **inicial**, no el final | Un ciclo de entrega se asertaba en `queued`, justo donde el defecto empieza |
| 2 | Aserta **markup** en vez del efecto | "El botón no está en el HTML" cubría que la generación no funcionaba |
| 3 | El fixture **se regala** el permiso que dice probar ausente | El usuario "responsable" ya tenía el permiso del módulo |
| 4 | Siembra a mano lo que el deploy **no** siembra | Seis tests sembraban permisos que `DatabaseSeeder` nunca creaba |
| 5 | Aserta el **ledger**, no el contenido | El email se asertaba por su fila; el email no llevaba el documento |
| 6 | `env()` directo | Devuelve null con la config cacheada y la aserción pasa vacía |
| 7 | Aserción **tautológica** | Pasaría igual si el código no hiciera nada |
| 8 | Aserta el hueco como **intencional** | Un test afirmaba exactamente lo que el spec prohibía |
| 9 | Divergencia **SQLite/MySQL** | La colación CI hacía que el mensaje mintiera sólo en producción |

---

## Área: DINERO

### D-1 · BLOCKER · [verificado] La automatización de cotizaciones nunca se dispara

`app/Services/QuotationService.php`

```php
line 58:   return DB::transaction(...)      // el método SALE acá
line 99:   event(new QuotationCreated(...))  // código MUERTO, inalcanzable
```

Idéntico en `accept()` (262 → 287). `QuotationCreated` y `QuotationAccepted` **nunca se emiten**, así
que toda regla de automatización sobre cotizaciones (asignar responsable, notificar, crear
seguimiento, webhooks) está muerta. El patrón ya estaba corregido en los servicios hermanos
(`OpportunityService` lo documenta explícitamente) y cotizaciones quedó sin el fix.

**Por qué la suite no lo ve**: el único test que toca el tema **emite el evento a mano**
(`AutomationEngineTest`), así que nunca pasa por el servicio.

**Estado**: ✅ **ARREGLADO** en `1671a4f`, con un test que ahora conduce el evento por el servicio.

### D-2 · CRITICAL · El email de cotización nunca se asertó por su contenido

`tests/Feature/QuotationGmailSendTest.php:124-160` — aserta la fila del adjunto y el estado del
mensaje, pero **ningún test decodifica el payload enviado**. El helper fabrica el adjunto a mano
(`'size' => 10`, `%PDF-1.4` falso), así que los bytes reales del PDF nunca se verifican.

**Riesgo**: un adjunto vacío, una ruta rota o un cambio que omita el `foreach` de adjuntos llega al
cliente sin que nada falle. Es el mismo defecto que se encontró en cursos.

**Estado**: ✅ **ARREGLADO** (2026-09-12) — el hueco era de test, no del código. Se midió la cadena
completa de punta a punta con un test nuevo (`QuotationGmailSendTest::test_gmail_outgoing_payload_carries_the_real_generated_quotation_pdf`):
el controlador genera el PDF real con dompdf, lo persiste en disco y crea la fila del adjunto con
`size`/`sha256` reales; el job corre `GmailProvider::buildMime()`, codifica en base64url y lo publica
como campo `raw` de la API. El test decodifica el `raw`, extrae la parte MIME del adjunto y aserta
que sus bytes son **idénticos al PDF generado**, que el nombre real coincide, que `size` y `sha256`
coinciden con el archivo generado y que empieza con `%PDF`. La cadena estaba **correcta**: el
adjunto viaja con los bytes reales. La ceguera era del test viejo: se mutó `buildMime()` quitando el
`foreach` de adjuntos y **la aserción vieja siguió en verde** (sólo mira la fila) mientras la nueva
se puso roja — el hueco que este hallazgo describe, ahora cerrado.

### D-3 · CRITICAL · `prices_include_tax` es configuración muerta

El checklist la documenta ("si los precios del catálogo ya incluyen IGV") y **ningún cálculo la lee**:
`QuotationService:427-430` **siempre suma** el impuesto. Nadie prueba una cotización con el flag en
`true`.

**Riesgo**: un admin activa un switch documentado y toda cotización sale con 18% de más, al PDF y al
mail.

**Estado**: ⏳ pendiente (es una decisión de producto: soportarlo o eliminarlo).

### D-4 · CRITICAL · Descuento mayor al subtotal produce IGV y total negativos

`_line_form.blade.php:55` no tiene `max`; `QuotationStoreRequest:54` valida `min:0` y nada más; el
preview del formulario **clampa a 0** (`Math.max(subtotal - discount, 0)`) y el servidor **no**:

```
quantity 1 × unit_price 100, discount 1000, IGV 18%
preview muestra:  0.00
se persiste:      line_tax = -162.00, total = -1062.00
```

**Riesgo**: el vendedor ve un número y el cliente recibe otro; al aceptar, `markWon` rechaza
`final_amount <= 0` y el cierre falla de forma incomprensible.

**Estado**: ✅ **ARREGLADO** (2026-09-12). Decisión: **rechazar** la línea cuyo descuento supera su
propio subtotal, no clampear en silencio. Clampear habría cambiado el descuento que el vendedor
tipeó (1000 → 100) sin decírselo: es la misma clase de deshonestidad que el total negativo, porque
el número guardado no es el que se escribió. El borde es **inclusivo**: un descuento exactamente
igual al subtotal es una línea bonificada al 100 % (subtotal 100, descuento 100, IGV 0, total 0) y se
acepta; solo un descuento **mayor** se rechaza.

La regla quedó en **una sola fuente de verdad**, `app/Support/Quotations/LineDiscountRule.php`, y sus
tres consumidores —los que antes divergían— la leen de ahí:

| Consumidor | Efecto |
|---|---|
| `QuotationStoreRequest` (POST /quotations) | error visible `items.N.discount_amount`: «El descuento de la línea N no puede superar su subtotal (X).» |
| `QuotationUpdateRequest` (PUT /quotations/{id}) | el mismo error: el defecto era alcanzable por **ambas** rutas de escritura y arreglar una sola habría sido mentira |
| `QuotationService::create()/update()` | `InvalidArgumentException` para quien no pasa por el HTTP request (tests, importaciones, futura API) |

Y `replaceItems()` **capea** el descuento al subtotal como última barrera: `duplicate()` reinyecta
filas guardadas, y las filas corruptas que el defecto dejó ya no pueden volver a escribir IGV ni
totales negativos.

El formulario dejó de mostrar un número que el servidor no honra: `_line_form.blade.php` publica
`max` por línea (el subtotal de esa línea), el preview **capea el descuento** en lugar de la base
(`Math.max(subtotal - discount, 0)` mostraba IGV/total que el servidor no escribía), la suma del
preview usa el descuento capeado, y el input queda marcado (`is-invalid` + `setCustomValidity` con el
texto del servidor) además del `@error` que renderiza el mensaje del request. La comparación es en
**centavos enteros** en los tres lados, para que `7 × 0.29 = 2.03` con descuento exacto 2.03 no se
rechace por ruido de float.

Downstream: la aceptación sigue rechazando `final_amount <= 0` en `OpportunityService::markWon`
(verificado en `OpportunityService:240`), igual que antes. El cambio reemplaza los negativos por
`0.00`, así que una cotización bonificada al 100 % sigue sin poder cerrarse como ganada con
`total = 0`: es una decisión de producto **preexistente** y fuera de este hallazgo, no un efecto
nuevo.

Evidencia RED (antes del arreglo): `QuotationMathTest` 2 fallos —`-162.0 is equal to 0 or is greater
than 0` en la línea duplicada y el servicio aceptando el descuento desmedido— y `QuotationHttpTest`
3 fallos —store y update aceptando el payload y el `max` ausente en el formulario—. Después:
`--filter=Quotation` 60/60 (236 aserciones).

### D-5 a D-8 · WARNING · Precisión, config muerta, exports y ownership

| # | Hallazgo | Evidencia |
|---|---|---|
| D-5 | Los importes de cotización no tienen cotas de precisión (`decimal:0,2`, `max`), a diferencia de facturas | `QuotationStoreRequest:51-54` vs `CustomerInvoiceStoreRequest:32` |
| D-6 | `quote_validity_days` es config muerta y **toda la cadena de vencimiento está sin tests** (el comando `EmitQuotationWillExpire` no tiene ninguno) | grep en `app/` → 0 lecturas; grep en `tests/` → 0 |
| D-7 | Los exports de dinero solo asertan el `Content-Type`, nunca los importes — aunque el repo ya tiene el patrón correcto en `ReportHttpTest:278-303` | `QuotationHttpTest:302-320` |
| D-8 | La cláusula de ownership de cotizaciones solo se ejerce en el listado; no hay 403 por registro ajeno | `ModulePolicy:38-53` |

---

## Área: AUTORIZACIÓN

### A-1 · CRITICAL · [verificado] El módulo de campañas es admin-only en producción

Verificado en runtime:

```
Gate::getPolicyFor(App\Models\CampaignActionItem::class) → NULL
Gate::has("schedule")                                    → false
```

`CampaignItemPolicy` existe pero **no está registrada** en `AuthServiceProvider` (`:73-81`), y su
modelo es `CampaignActionItem` mientras la clase se llama `CampaignItemPolicy` — el auto-discovery
busca `CampaignActionItemPolicy`. Además, las **siete** abilities de ciclo de vida (`schedule`,
`pause`, `start`, `cancel`, `complete`, `duplicate`, `reschedule`) **no existen en ninguna policy**:
`CampaignRunPolicy` y `CampaignTemplatePolicy` heredan de `ModulePolicy`, que solo define
`viewAny/view/create/update/delete`.

**Resultado**: supervisores y vendedores reciben **403 en todo** el ciclo de vida de campañas. Solo
el admin pasa, por el bypass de rol del `Gate::before`.

**Por qué la suite no lo ve**: los tres archivos que tocan esas rutas **siempre actúan como admin**, y
el bypass devuelve `true` para cualquier ability. Encima, los 3 archivos **erroran** por el fixture
`env('ADMIN_EMAIL')` que devuelve null — y ese error tapa el 403 real: arreglar el fixture pone los
tests en rojo, que es el defecto saliendo a la luz.

**Estado**: ✅ **ARREGLADO** (2026-09-12). `CampaignItemPolicy` pasó a llamarse
`CampaignActionItemPolicy` (clase y archivo) para que el auto-discovery de Laravel lo
encuentre por convención — el mismo mecanismo que ya usaban sus pares
`CampaignRunPolicy` y `CampaignTemplatePolicy`, que tampoco figuran en el array
`$policies` de `AuthServiceProvider`. Se implementaron las siete abilities que faltaban
(`schedule`, `pause`, `start`, `cancel`, `complete`, `duplicate`, `reschedule` en
`CampaignRunPolicy`; `duplicate` en `CampaignTemplatePolicy`), cada una mapeada 1:1 a
una fila de permiso que la migración de campañas ya creaba — sin inventar nombres y sin
migración nueva. Las tres abilities de item (`markRealized`, `cancel`, `reschedule`) ya
existían y ahora sí se resuelven. `ModulePolicy` **no** se tocó: los tres policies de
campaña sobrescriben `viewAny()` porque el vocabulario de permisos del módulo es plano
(`campaigns.view`), no la forma de tres partes de ADR-006. Evidencia: 17 tests nuevos en
`tests/Feature/CampaignAuthorizationTest.php` que arrancaron en 0/17 (16 fallos + 1 error)
y hoy pasan 17/17, con un ALLOW por endpoint actuado por un actor NO admin que posee el
permiso mapeado (aserción sobre el efecto, no sobre el redirect) y su DENY 403.

**Hallazgo adicional que este arreglo destapó**: corregir el fixture no destapó solo el 403,
destapó también un **500**. `campaign_item_reschedules` no tenía `created_by`/`updated_by`
mientras `CampaignItemReschedule` usa `HasAuditColumns`, así que **ambas** rutas de
reprogramación (`rescheduleIndividual` y `rescheduleAll`) devolvían 500 — y el admin las
alcanzaba por el bypass de rol, o sea que el módulo no era "solo admin": era admin con un
crash. Ver `R-1` más abajo.

### A-2 · CRITICAL · Los 20 permisos de campañas no los tiene ningún rol

`database/migrations/2026_08_20_000008_seed_campaign_permissions.php:48-54` los crea y los otorga,
pero `RolesAndPermissionsSeeder:50` usa `syncPermissions($all)` con **su propia lista**, que no los
incluye: `syncPermissions()` desasocia todo lo que no esté en la lista. Verificado tras
`migrate:fresh --seed`:

```
admin campaigns.* = 0    supervisor = 0    vendedor = 0
total de filas de permisos de campaña = 20   ← existen, no los tiene nadie
```

Y hay una segunda divergencia: las policies exigen la convención ADR-006 (`campaigns.view.any`)
mientras la migración crea `campaigns.view` — nombres que no existen como fila.

**Por qué la suite no lo ve**: los tests de seeders cuentan **filas** (`assertSame(143, Permission::count())`),
nunca **qué rol tiene qué**. Que 20 permisos existan sin dueño es literalmente invisible.

**Estado**: ✅ **ARREGLADO** (2026-09-12). Los 20 permisos de campaña se agregaron a la
lista canónica de `RolesAndPermissionsSeeder` (`CAMPAIGN_PERMISSIONS`), que es la única
declaración de qué rol tiene qué, y que además usa `syncPermissions` y por eso desasociaba
todo lo que no figurara en ella. `admin` y `supervisor` reciben los 20, replicando lo que
la migración ya intentaba; `vendedor` recibe los cuatro de campo
(`campaigns.view`, `campaigns.reschedule`, `campaigns.mark_realized`, `campaigns.view_reports`),
exactamente el subconjunto que la migración le otorgaba. **No** se cambiaron las
semánticas de `syncPermissions` (el arreglo global que se decidió no hacer): el total de
filas de permisos NO se mueve, porque las 20 filas ya existían por migración — lo único
que se mueve es cuántos permisos TIENE el admin (70 → 90), que es precisamente lo que el
defecto consistía. La segunda divergencia se resolvió del lado de las policies, que ahora
piden el nombre plano que sí existe.

### A-3 · CRITICAL · El test de CSRF no puede fallar nunca

`tests/Feature/SecurityHardeningTest.php:94-124` postea al login con **credenciales válidas** y luego:

```php
$this->assertContains($status, [419, 302], "Missing CSRF must produce 419 or 302; got {$status}.");
```

Con CSRF activo → 419; **desactivado** → el login entra y devuelve 302. **Ambos están en la lista
aceptada**, así que la aserción se satisface en los dos mundos. Y en el harness el chequeo ni corre:
`PreventRequestForgery` tiene un cortocircuito para `runningUnitTests()`, así que la rama 419 es
código muerto en tests.

**Riesgo**: agregar una ruta a `except` o quitar el middleware deja la app vulnerable con la suite
verde y un test que dice "CSRF verificado".

**Estado**: ✅ **ARREGLADO** (2026-09-12). El test ya no aserta un status HTTP: hay dos resultados
posibles (419 con CSRF, 302 sin él) y en el harness el rechazo ni corre (`runningUnitTests()`), así
que cualquier aserción sobre el código de respuesta nacía ambigua. El objetivo honesto es la
**configuración** que decide si la protección existe, y eso es lo que pinea ahora
`SecurityHardeningTest::test_csrf_protection_is_configured_on_the_web_group_and_not_excluded_for_state_changing_routes`:

1. el grupo `web` resuelto por el Router contiene `PreventRequestForgery` (o una subclase);
2. `allowSameSite` no está habilitado (dejaría pasar un request same-site sin token);
3. nueve rutas que cambian estado (`login.store`, `logout`, `leads.store`, `customers.store`,
   `products.store`, `quotations.store`, `quotations.update`, `admin.users.store`,
   `admin.settings.update`) **corren** el middleware de verdad: se consulta
   `Router::gatherRouteMiddleware()`, que resuelve alias/grupos y **resta** el middleware excluido de
   la ruta, así que detecta tanto salir del grupo `web` como un `withoutMiddleware(...)` en la ruta;
4. ninguna de esas URIs cae en la lista de exclusión, consultada con el **propio predicado del
   middleware** (`inExceptArray()` expuesto por una subclase anónima en el test, porque `handle()`
   cortocircuita bajo tests).

La fallabilidad se **demostró** mutando la configuración y observando cada fallo, no se afirmó:

| Mutación aplicada | Resultado observado |
|---|---|
| `$middleware->web(remove: [PreventRequestForgery::class])` | falla (1): «The `web` middleware group must include …PreventRequestForgery» (1 aserción de 29) |
| `$middleware->preventRequestForgery(allowSameSite: true)` | falla (2): «PreventRequestForgery::allowSameSite() weakens CSRF verification…» (2 de 29) |
| `$middleware->validateCsrfTokens(except: ['login', 'login.store'])` | falla (4): «POST /login (route [login.store]) must not be excluded from CSRF verification» (5 de 29) |
| `->withoutMiddleware('web')` en `products.store` | falla (3): «Route [products.store] must run …PreventRequestForgery (via the `web` group): []» (16 de 29) |

Las cuatro mutaciones se revirtieron (`git diff bootstrap/app.php` y `git diff routes/web.php`
vacíos) y con los archivos prístinos el test pasa con **29 aserciones**.

### A-4 a A-8 · WARNING · Ownership, tautologías y dos fuentes de verdad

| # | Hallazgo | Evidencia |
|---|---|---|
| A-4 | El test de XSS verifica el escape de Blade, **no** la validación de entrada; y el único sink crudo real (`{!! $previewHtml !!}` en el preview de plantillas de email) queda sin test | `SecurityHardeningTest:126-145`; `template-form.blade.php:130` |
| A-5 | La cláusula de ownership de `CustomerInvoicePolicy` no la ejerce ningún test: los actores que escriben son admin (bypass) y los DENY cortan en el primer término del `&&` | `CustomerInvoiceCrudTest:245-247` |
| A-6 | `assertTrue($admin->can(...))` es tautológico: pasa aunque el permiso no exista, por el bypass de rol | `CustomerInvoiceCrudTest:33-34` |
| A-7 | `assertGatePassed()` acepta un 500 como "gate passed" (solo verifica que **no** sea 403/404) | `AdminAutomationPermissionsTest:81-96` |
| A-8 | La baja de documentos corre por un camino distinto al de la policy registrada: `DocumentService` da borrado a quien tenga `documents.view.any`, **sin** `documents.delete` ni scope | `DocumentService:322-329` vs `DocumentPolicy:36` |

---

## Área: CAMPAÑAS — hallazgos destapados al arreglar A-1 y A-2

> Los tres siguientes estaban **detrás del mismo fixture roto**. Los tres archivos de campaña
erroraban en `setUp()` por `env('ADMIN_EMAIL')` devolviendo null, así que ninguna de estas
rutas se ejecutó jamás en un test. Arreglar el fixture no destapó **un** defecto (el 403):
destapó **cuatro**. Es el argumento más fuerte de este documento a favor de la tesis final:
un fixture roto no protege, esconde.

### R-1 · BLOCKER · [verificado] Las dos rutas de reprogramación de campañas devuelven 500

`App\Models\CampaignItemReschedule` usa `HasAuditColumns`, que escribe `created_by` en cada
INSERT, pero su tabla **no tenía esa columna**: `2026_08_20_000008_add_missing_audit_columns`
lista `campaign_participants`, `campaign_action_items`, `documents` e `integration_accounts`,
y omitió `campaign_item_reschedules`.

```
SQLSTATE[HY000]: General error: 1 table campaign_item_reschedules has no column named created_by
  app/Services/CampaignRescheduleService.php:41   (rescheduleIndividual)
  app/Services/CampaignRescheduleService.php:126  (rescheduleAll)
```

**Por qué la suite no lo ve**: A-1 hacía que las rutas devolvieran 403 a todo no-admin. Y como
el `Gate::before` da bypass por rol, **el admin sí llegaba… y crasheaba**. El módulo no era
"admin-only": era "admin con 500". Ni un test tocaba `CampaignRescheduleService`.

**Estado**: ✅ **ARREGLADO** en `2026_09_12_000001_add_audit_columns_to_campaign_item_reschedules`
(migración aditiva nueva: dos columnas NULLABLE con FK a `users`; las filas existentes quedan
válidas con NULL). No se editó la migración ya aplicada, porque eso dejaría rota toda base
existente. `down()` fue verificado en SQLite: revierte y vuelve a aplicar limpio. El `down()`
hermano de `add_missing_audit_columns` sigue sin poder ejecutarse en SQLite (ver R-3).

### R-2 · CRITICAL · Dos tests de campaña asertaban validación que no existía

`CampaignItemActionHttpTest::test_mark_realized_requires_result` asertaba un error de
validación de `result`, pero `CampaignItemActionRequest` lo declaraba `nullable`: un resultado
vacío **completaba el item igual**. Y `test_reschedule_requires_future_date` asertaba un error
de `new_scheduled_at`, pero `CampaignItemController::reschedule` recibía un `Request` pelado,
así que el rechazo del servicio (`InvalidArgumentException: La nueva fecha debe ser futura.`)
escapaba como **500**, no como 422.

**Estado**: ✅ **ARREGLADO**. La regla correcta resultó ser: el discriminador `action` que las
reglas condicionales ya esperaban se deriva de la ruta en `prepareForValidation()` (nunca del
cliente, para que no se pueda degradar un campo requerido omitiéndolo), `result` es requerido
solo en `mark_realized`, y la reprogramación individual pasó a validarse en el borde
(`new_scheduled_at` `date|after:now` requerido, `reason` requerido).

### R-3 · WARNING · `down()` de `add_missing_audit_columns` no puede ejecutarse en SQLite

`dropForeign(['created_by', 'updated_by'])` **no coincide con ninguna constraint**: Laravel
compara `columns` por igualdad exacta y cada FK tiene una sola columna, así que SQLite queda
con la definición de FK intacta y rechaza el `DROP COLUMN`
(`unknown column "created_by" in foreign key definition`). Se comprobó ejecutando
`migrate:rollback` sobre una base SQLite descartable. La solución es llamar `dropForeign`
**por columna**; la migración nueva de R-1 lo hace así. El `down()` preexistente de
`add_missing_audit_columns` no está en el alcance de este arreglo y queda pendiente.

### R-4 · WARNING · `CampaignReschedulePolicy` es código muerto

Declara reglas para un modelo `CampaignReschedule` que **no existe** (el modelo real es
`CampaignRun`), así que no resuelve nada y su regla documentada de `campaigns.reschedule`
nunca corre. La reprogramación global se autoriza por `CampaignRunPolicy::reschedule`, que sí
se implementó, así que el comportamiento es correcto; la clase queda como limpieza pendiente.

## Área: ENVÍOS

### E-1 · BLOCKER · El email por SMTP no puede renderizarse

`app/Mail/GenericEmail.php:51` llama `$this->text('plain.text', ...)` y **esa vista no existía**.
Con transporte real, `ViewFactory` lanza `ViewNotFoundException`, `SmtpProvider` la captura y reporta
fallo — mientras el test pasa porque **`Mail::fake()` nunca renderiza**. El `ok: true` que el test
aserta era inalcanzable en producción.

**Estado**: ✅ **ARREGLADO** en `1671a4f` (la vista existe y los tests renderizan de verdad).

### E-2 · BLOCKER · [verificado] Ningún adjunto viajaba con el email

`GenericEmail` guardaba `$attachments` y luego asignaba el shape crudo a `Mailable::$attachments`,
cuyas entradas deben ser `['file' => ..., 'options' => [...]]`. `buildAttachments()` leía una key
`file` inexistente y **ningún adjunto llegaba al mensaje**: ningún PDF viajó nunca por SMTP.

**Estado**: ✅ **ARREGLADO** en `1671a4f` (los adjuntos se registran por la API del Mailable).

### E-3 · BLOCKER · Los mensajes del inbox de WhatsApp nunca se envían

`WhatsAppController:174-188` crea el mensaje como `freeform`, **sin `template_id`**, en `queued`, y
despacha el job. El job exige template:

```php
if ($message->template === null) { $this->markFailed($message, 'NoTemplate', ...); return; }
```

Los tests asertan `queued` + `Bus::assertDispatched` — o sea, **asertan justo donde arranca el
defecto** (patrón #1). El job **no se ejecuta en ningún test del repo**.

**Estado**: ✅ **ARREGLADO** (2026-09-12). Un mensaje sin template pasa por
`WhatsAppProvider::sendFreeFormMessage` y el sobre del proveedor decide el estado terminal: `sent`
+ `wamid` si Meta acepta, `failed` con `error_class`/`error_message` reales si rechaza. Antes de
llamar se verifican dos precondiciones que el job no miraba: opt-out de la conversación y ventana de
24 h (el bloqueo por ventana es **inerte** hasta que algo popule
`whatsapp_conversations.window_closes_at`, cosa que hoy no hace nadie — ver R-7 —, así que la
autoridad sigue siendo Meta y su rechazo literal queda persistido). Los dos tests que asertaban
`queued` + `assertDispatched` ahora **ejecutan el job** y asertan el estado final, y
`SendWhatsAppMessageJobTest` (8 tests, archivo nuevo) cubre entrega, rechazo del proveedor, stub sin
credenciales, ventana cerrada, opt-out, plantilla, short-circuit de idempotencia y cuenta
 deshabilitada.

### E-4 · CRITICAL · Las notificaciones se envían con un body placeholder

`NotificationService` persiste canal, destinatario, estado y key… y el `payload` **solo se usa para
calcular la idempotencia**; la tabla no tiene columna de contenido. Entonces `SendOutboundDelivery`
fabrica:

```php
'subject' => 'CRM notification',
'body'    => 'Delivery #'.$delivery->id.' ('.$delivery->channel.', status='.$delivery->status.')',
```

y **eso** es lo que se envía por mail y por WhatsApp, descartando el contenido real que el listener
sí construye. Los tests asertan ledger, no contenido.

**Estado**: ✅ **ARREGLADO** (2026-09-12). `NotificationService::dispatch()` persiste el contenido
(`subject`/`body` whitelisteados) en una columna `payload` JSON nueva y **aditiva**
(`2026_09_12_000100_add_payload_to_outbound_deliveries`), y `SendOutboundDelivery::payload()` lo lee:
el contenido sobrevive el re-despacho **por id** de los reintentos y del botón "Reintentar". El
placeholder sólo sobrevive como aviso explícito para filas legacy sin contenido, en vez de fabricar
un cuerpo que parece real. La rama WhatsApp del job ya no descarta el sobre del proveedor: un envío
rechazado lanza y el ledger queda `queued`/`failed` con el motivo, nunca `delivered`, y
`last_response_code` deja de ser `0` cuando no hubo respuesta. `SendOutboundDeliveryJobTest` (6
tests, archivo nuevo) ejecuta el job y aserta el contenido que sale y el estado que queda.

### R-5 · WARNING · `last_error` guardaba la clase del error y tiraba el motivo

`NotificationService::markFailed()` recibía `$errorClass` y `$errorMessage` y persistía **sólo la
clase**: el operador veía que una entrega falló, nunca por qué. Es el mismo patrón que E-4 (el dato
existe y se descarta). Ahora persiste `Clase: mensaje`, el mismo formato que ya usaba
`SendOutboundDelivery::failed()`. Se actualizó la única aserción que fijaba el valor viejo
(`NotificationServiceTest:145`), **reforzándola**: antes asertaba la clase exacta, ahora aserta clase
y motivo.

### R-6 · WARNING · `sendWhatsApp()` busca la cuenta en la tabla equivocada

`outbound_deliveries.account_id` es FK a `integration_accounts`, pero
`SendOutboundDelivery::sendWhatsApp()` hace `WhatsAppAccount::find($delivery->account_id)`. Sólo
funciona si ambos ids coinciden por casualidad; si no, la rama WhatsApp falla siempre con "No active
WhatsApp account for delivery". **No se tocó**: cambiarlo redefine el significado de la columna y
de la FK para todos los canales, fuera del alcance de E-3/E-4. Los tests de la rama WhatsApp crean
ambas filas con el mismo id y lo dejan documentado en el helper.

### R-7 · WARNING · Nadie escribe la ventana de 24 h

`whatsapp_conversations.window_opens_at` / `window_closes_at` existen desde B14 y **ningún** código
las escribe: ni el webhook de entrada ni el envío. Por eso el bloqueo por ventana agregado en E-3 es
inerte y la autoridad real es la respuesta de Meta. El lugar correcto para poblarlas es el webhook de
entrada, fuera de las superficies de este arreglo. Se decidió **no** derivar la ventana de las fechas
de los mensajes: una ventana de Meta también puede abrirse por interacciones que el CRM no persiste
(click-to-WhatsApp) y bloquear un envío que Meta aceptaría sería otro defecto.

### R-8 · SUGGESTION · El motivo del fallo no llega a la pantalla

`WhatsAppMessage.error_class` / `error_message` quedan persistidos, pero
`resources/views/livewire/admin/whatsapp/message-list.blade.php` sólo imprime `$message->status`: un
rechazo real se lee "failed" y nada más. La vista está fuera de las superficies autorizadas de este
arreglo; mostrar el motivo queda pendiente.

### E-5 a E-8 · WARNING

| # | Hallazgo | Evidencia |
|---|---|---|
| E-5 | ✅ **ARREGLADO** (2026-09-12). Los secretos de webhook de WhatsApp se leían de una clave de config **que no existía**, con fallback a `env()` (null con config cacheada): con el secreto vacío **todo webhook entrante daba 403**, y los tests lo inyectaban por vías que el deploy no puede usar. Ahora `config/integrations.php` define `integrations.whatsapp.webhook_secret` (y `integrations.email.gmail.webhook_secret` / `integrations.email.outlook.webhook_secret` para los proveedores Gmail/Outlook, que tenían el mismo `env()` directo); se eliminó el atributo fantasma `webhook_secret` (sin columna) y el fallback a `env()`; y el webhook rechazado ahora se loguea con motivo (`Log::error` si falta configurar el secreto, `Log::warning` si la firma no verifica). Documentado en `.env.example`. | `MetaWhatsAppProvider::resolveWebhookSecret()`; `WhatsAppWebhookController::verify()`; `GmailProvider`/`OutlookProvider::verifyWebhookSignature()`; `config/integrations.php` |
| E-6 | `ConversationList::assignConversation` (Livewire) saltea el DataScope que su gemelo HTTP sí aplica; el test corre como admin (scope global) | `ConversationList:74-90` vs `WhatsAppController:213-231` |
| E-7 | `SendEmailAction` llama `Mail::queue([], [], $closure)`, que en Laravel 13 **siempre** lanza ("Only mailables may be queued"); cero tests de ejecución | `SendEmailAction:38-43` |
| E-8 | No hay CI, hooks ni política anti-`.only`: nada obliga a correr la suite, y un test rojo sobrevive indefinidamente | sin `.github/`, sin `.husky/` |

---

## Prioridad sugerida

1. ✅ **Campañas (A-1 + A-2)** — **ARREGLADO** (2026-09-12). Módulo inutilizable para todo no-admin,
y sus permisos sin dueño. Misma firma que el defecto de cursos: bypass del admin + fixture que se
siembra a sí mismo. El arreglo destapó además `R-1` (las dos rutas de reprogramación devolvían 500
por columnas de auditoría faltantes), `R-2`, `R-3` y `R-4`.
2. ✅ **WhatsApp free-form (E-3) y notificaciones sin contenido (E-4)** — **ARREGLADO**
   (2026-09-12): funcionalidad que no funcionaba con tests que asertaban el estado equivocado. El
   arreglo destapó R-5 (el motivo del fallo se descartaba), R-6 (la rama WhatsApp busca la cuenta en
   la tabla equivocada), R-7 (nadie escribe la ventana de 24 h) y R-8 (el motivo no llega a la
   pantalla).
3. ✅ **CSRF (A-3)** — **ARREGLADO** (2026-09-12). Un test de seguridad que no puede fallar es peor
   que no tenerlo: ahora pinea la configuración que decide si la protección existe, y se verificó
   mutando la configuración cuatro veces (quitar el middleware del grupo `web`, `allowSameSite`,
   `except: ['login']`, `withoutMiddleware('web')` en una ruta) y observando que el test cae en cada
   una.
4. ✅ **Descuentos negativos (D-4)** — **ARREGLADO** (2026-09-12). Se rechaza el descuento mayor al
   subtotal (borde inclusivo: igual se acepta) en **una** regla compartida por las dos rutas de
   escritura y el servicio, con el preview del formulario capeando el descuento y marcando la línea
   en vez de mostrar un IGV/total que el servidor nunca escribiría.
5. **El resto**: D-3, D-5 a D-8, A-4 a A-8, E-6 a E-8.
6. **Aparte**: decidir qué se hace con los rojos que quedan. Medido el 2026-09-12 después de arreglar
   campañas: **11 rojos** (11 fallos, 0 errores) sobre 1.316 tests, y son exactamente los 11 fallos
   externos ya documentados (`b12-ui` / `HistoryAndAudit` / `SettingsService` / `GmailProvider` /
   `GoogleCalendarWebhook`). Re-medido el 2026-09-12 al cerrar E-3/E-4: **11 rojos** (11 fallos, 0
   errores) sobre **1.330 tests / 1.319 en verde** — los mismos 11 por nombre, más 14 tests nuevos.
   Mientras sigan ahí, "la suite pasa" no significa nada, y cada verde nuevo es más difícil de
   interpretar. Y re-medido al cerrar A-3/D-4: **11 rojos** (11 fallos, 0 errores) sobre
   **1.338 tests / 1.327 en verde** — los mismos 11 por nombre, más 8 tests nuevos (3 en
   `QuotationMathTest`, 5 en `QuotationHttpTest`).

## La conclusión que importa

Ninguno de estos defectos lo encontró la suite; los encontró una auditoría que leía **qué asertaba
cada test**. La cobertura no es evidencia: un test verde prueba únicamente el borde de lo que
asertó. Cuando este repositorio agregue tests, la pregunta útil no es *¿hay test?* sino *¿qué
afirma exactamente este test, y qué se rompería si esto dejara de funcionar?*
