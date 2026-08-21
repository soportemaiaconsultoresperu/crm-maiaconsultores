# customer-payments-invoices — Diseño técnico

## 1. Resumen

Implementar el primer slice de **Pagos** en la ficha de cliente agregando:

- un campo simple `customers.payment_modality`;
- una entidad manual `CustomerInvoice` asociada al cliente;
- un catálogo administrable `InvoiceStatus` integrado al módulo Catálogos;
- permisos financieros explícitos para ver/escribir información de pagos;
- alertas internas en Calendario como **proyección de consulta**, no como registros físicos;
- tratamiento automático de `Vencida` persistido en BD mediante servicio idempotente + comando Artisan programable, sin escrituras ocultas desde GETs puros.

El diseño sigue los patrones Laravel actuales: controladores delgados, FormRequests para validación, Services para reglas de negocio, Policies/Gates con permisos Spatie, modelos con `HasAuditColumns`, `LogsActivity` y no hard-delete desde UI.

## 2. Hallazgos del código existente

- `app/Http/Controllers/CustomerController.php` carga la ficha en `show()` y pasa relaciones/historial/actividades a `resources/views/customers/show.blade.php`.
- `resources/views/customers/show.blade.php` contiene tarjetas Bootstrap en dos columnas; ya existen tarjetas inline para Datos del cliente, Contactos, Historial, Actividades, Cotizaciones y `customers._products_card`.
- `app/Models/Customer.php` usa `SoftDeletes`, `HasAuditColumns`, `LogsActivity` y relaciones `contacts()`, `quotations()`, `activities()`, `documents()`, `products()`.
- Catálogos son tablas/modelos concretos por tipo (`LeadStatus`, `ActivityType`, etc.) sembrados por `database/seeders/CatalogSeeder.php` e integrados con `CatalogController`, `CatalogStoreRequest`, `CatalogUpdateRequest`, `CatalogService` y rutas `/admin/catalogs/{kind}`.
- Permisos usan Spatie y convención `{module}.{action}.{scope}` para módulos owner-scoped, más permisos simples como `calendar.view`, `catalogs.view`, `catalogs.manage`; ver `database/seeders/RolesAndPermissionsSeeder.php` y `app/Policies/ModulePolicy.php`.
- Calendario actual sólo proyecta `Activity` desde `CalendarController` + `ActivityService::calendarEvents()` y las vistas acceden a propiedades de actividad (`scheduled_at`, `title`, `status`, `type`, `subject`, route `activities.show`). No hay infraestructura de scheduler para eventos calendario persistidos.
- Auditoría usa Spatie activitylog (`LogsActivity` en modelos) y eventos manuales con `activity()` en servicios como `app/Services/QuotationService.php`.
- Tests de feature usan `RefreshDatabase`, seeders explícitos y aserciones HTTP/servicio (`tests/Feature/CustomerHttpTest.php`, `tests/Feature/CalendarQueryTest.php`).

## 3. Modelo de datos y migraciones

### 3.1 `customers.payment_modality`

Agregar migración:

- `payment_modality` nullable string(100) después de campos comerciales existentes.

Actualizar:

- `app/Models/Customer.php`: añadir a `$fillable` y activitylog existente lo registrará por `logAll()`.
- `database/factories/CustomerFactory.php`: valor `null` por defecto.

### 3.2 `invoice_statuses`

Crear tabla catálogo concreta para mantener el patrón actual:

- `id` big integer PK;
- `name` string(100);
- `slug` string(100) unique;
- `sort` unsigned integer default 0;
- `is_active` boolean default true;
- `created_by`, `updated_by` nullable FKs a users;
- timestamps.

Modelo `App\Models\InvoiceStatus` con `HasFactory`, `HasAuditColumns`, `LogsActivity`; `$fillable = ['name','slug','sort','is_active']`.

Valores iniciales en `CatalogSeeder` con `updateOrCreate`:

| name | slug | sort |
| --- | --- | --- |
| Pagado | pagado | 1 |
| Vencida | vencida | 2 |
| En proceso | en-proceso | 3 |
| Nota de crédito | nota-de-credito | 4 |

Constantes recomendadas en el modelo para slugs de negocio (`SLUG_PAID`, `SLUG_OVERDUE`, etc.) para evitar comparar labels traducibles.

### 3.3 `customer_invoices`

Crear tabla:

- `id`;
- `customer_id` FK `customers.id` restrict/cascade según política existente; para conservar trazabilidad, preferir `restrictOnDelete()` o `nullOnDelete` no aplica porque la factura debe pertenecer a cliente; como `customers` usa soft deletes, el delete físico no ocurre en UI;
- `invoice_number` string(60), visible;
- `due_date` date required;
- `total_amount` decimal(14,2) required;
- `status_id` FK `invoice_statuses.id` restrict;
- `notes` text nullable;
- `retired_at` timestamp nullable;
- `retired_by` FK users nullable;
- `retire_reason` string(255) nullable;
- `created_by`, `updated_by` nullable FKs users;
- timestamps;
- `softDeletes()` para borrados técnicos/admin, no expuestos en UI.

Índices:

- unique `customer_id, invoice_number` para evitar identificadores duplicados por cliente;
- index `customer_id, due_date` para tarjeta y calendario;
- index `status_id`;
- index `retired_at`;
- opcional compuesto `due_date, status_id, retired_at` para calendario.

## 4. Relaciones Eloquent

- `Customer::invoices(): HasMany` hacia `CustomerInvoice`, orden sugerido `due_date desc, id desc` en consultas, no en relación base.
- `CustomerInvoice::customer(): BelongsTo`.
- `CustomerInvoice::status(): BelongsTo` a `InvoiceStatus`.
- `CustomerInvoice::retiredBy(): BelongsTo` a `User`.
- Scopes en `CustomerInvoice`:
  - `active()` => `whereNull('retired_at')->whereNull('deleted_at')`;
  - `dueBetween($start, $end)`;
  - `chargeable()` usando status slugs no `pagado` ni `nota-de-credito`;
  - `forVisibleCustomers(User $user)` delegando a `DataScopeService` sobre `customers.owner_id` mediante `whereHas('customer', ...)`.

## 5. Estrategia automática para `Vencida`

### Decisión

Persistir `Vencida` con un servicio de escritura idempotente `OverdueInvoiceProcessor` invocado por un comando Artisan programable `invoices:mark-overdue`. No usar estado efectivo sólo calculado como fuente de verdad y no escribir desde GETs puros de ficha/calendario.

Contrato propuesto:

```php
final class OverdueInvoiceProcessor
{
    public function process(?CarbonInterface $today = null, ?User $actor = null): OverdueInvoiceResult;

    public function processInvoice(CustomerInvoice $invoice, ?CarbonInterface $today = null, ?User $actor = null): bool;
}
```

Comando:

```bash
php artisan invoices:mark-overdue
php artisan invoices:mark-overdue --date=2026-09-16
```

Scheduling en `app/Console/Kernel.php` o patrón equivalente del proyecto:

```php
$schedule->command('invoices:mark-overdue')->dailyAt('00:10')->withoutOverlapping();
```

Regla de selección:

- `customer_invoices.retired_at IS NULL` y sin soft delete;
- `due_date < today`;
- `status.slug NOT IN ('pagado', 'nota-de-credito', 'vencida')`;
- actualizar `status_id` al id del catálogo `vencida`.

La exclusión de `vencida` hace el proceso idempotente: una factura ya marcada no vuelve a escribirse ni vuelve a auditarse.

### Invocación en flujos locales/tests

- El comando Artisan es el mecanismo principal y testeable localmente con `php artisan invoices:mark-overdue --date=...`.
- La ficha de cliente y el Calendario deben leer el `status_id` persistido; no deben mutar facturas durante un GET.
- Para confiabilidad sin depender de cron en entornos locales, los servicios de escritura de facturas pueden invocar `processInvoice()` después de crear/editar una factura o cambiar su fecha/estado, porque esas rutas ya son escrituras explícitas y auditables. Esto cubre el caso de guardar hoy una factura ya vencida.
- Si el proyecto ya tiene middleware/hook de mantenimiento para comandos programados, puede llamar al comando; evitar request-time writes desde rutas de lectura.

### Auditoría e idempotencia

- Registrar una única actividad manual `customer-invoice-marked-overdue` por transición real de `old_status_slug != 'vencida'` a `new_status_slug = 'vencida'`.
- Actor: usar actor sistema si el proyecto tiene usuario/causer de sistema; si no, registrar `causer_id = null` y propiedad `actor_type = system_command` / `system_action = invoices:mark-overdue`.
- Propiedades: invoice id/number, customer id/code, old/new status slug, due date, processed date.
- Repetir el comando no duplica logs porque la query excluye facturas ya `vencida` y `processInvoice()` retorna `false` si no hay transición.

## 6. Calendario e idempotencia

### Decisión

No crear filas físicas de calendario para facturas. Extender el calendario a una proyección común de eventos internos:

- Crear DTO/view model `App\Support\Calendar\CalendarEventItem` o similar con propiedades usadas por vistas:
  - `kind` (`activity` | `invoice_due`),
  - `dateTime`/`scheduled_at` compatible,
  - `title`,
  - `status`,
  - `typeLabel`,
  - `subjectLabel`,
  - `ownerName`,
  - `url`,
  - `amount` nullable,
  - `isSensitive`/`requiresFinancialRead`.
- Crear `CalendarEventService` que combina:
  - actividades existentes desde `ActivityService::calendarEvents()`;
  - facturas activas/cobrables con `due_date` dentro del rango y cliente visible al usuario, sólo si `user->can('customer-payments.view')`.

La idempotencia queda garantizada por diseño: una factura produce como máximo un DTO por su `id` y `due_date` en cada consulta. Cambiar `due_date`, `status` o `retired_at` cambia inmediatamente la proyección y no deja huérfanos.

### Cambios UI de calendario

Refactor mínimo de vistas `resources/views/calendar/month.blade.php`, `week.blade.php`, `day.blade.php`, `list.blade.php` para no asumir `Activity` directamente:

- agrupar por `$event->scheduled_at->toDateString()` o accessor equivalente;
- usar `$event->url` en lugar de `route('activities.show', $event)`;
- usar `$event->title`, `$event->status`, `$event->typeLabel`, `$event->subjectLabel`, `$event->ownerName`;
- marcar `invoice_due` con icono/badge “Factura” y no mostrar importe a usuarios sin `customer-payments.view` (aunque el servicio debe preferir no incluir el evento si falta permiso financiero).

Los filtros existentes de tipo de actividad (`type_id`) aplican sólo a actividades. Si `type_id` está presente, se recomienda ocultar facturas para no mezclar semánticas. Los filtros `owner_id` y `subject_type=customer` sí pueden aplicarse a facturas por `customers.owner_id` y cliente.

## 7. Rutas, controladores, servicios y vistas

### 7.1 Ficha de cliente

En `CustomerController::show()`:

- cargar `invoices.status` sólo si `Gate::allows('customer-payments.view')`;
- pasar `invoiceStatuses` activos si puede gestionar pagos;
- pasar flags `canViewPayments`, `canManagePayments`.

Crear parcial `resources/views/customers/_payments_card.blade.php` e incluirlo en `customers/show.blade.php`, preferiblemente en columna izquierda debajo de Cotizaciones o antes de Productos para mantener contexto financiero junto a ventas.

Contenido:

- modalidad de pago: valor o empty state;
- botón/modal “Editar modalidad” sólo con `customer-payments.manage`;
- tabla de facturas: número, vencimiento, importe total, estado persistido, notas resumidas, acciones;
- empty state para cliente sin facturas;
- CTAs “Nueva factura”, “Editar”, “Marcar pagada”, “Anular/retirar” sólo con permiso de escritura.

### 7.2 Controladores y rutas

Crear `CustomerInvoiceController` con endpoints bajo auth/active:

- `POST customers/{customer}/payment-modality` -> update modality;
- `POST customers/{customer}/invoices` -> store;
- `PUT customer-invoices/{invoice}` -> update;
- `POST customer-invoices/{invoice}/mark-paid` -> status `Pagado`;
- `POST customer-invoices/{invoice}/retire` -> set `retired_at/by/reason`.

No exponer destroy hard-delete. Redirigir siempre a `customers.show` con flash status.

Crear `CustomerInvoiceService`:

- valida invariantes de negocio además de FormRequest;
- resuelve estados por slug (`pagado`, `vencida`, etc.);
- setea actor/audit columns;
- escribe activitylog manual para create/update/status/retire;
- evita modificar factura retirada salvo acción explícita permitida;
- invoca `OverdueInvoiceProcessor::processInvoice()` sólo después de escrituras explícitas que puedan dejar una factura elegible ya vencida, nunca durante GETs puros.

## 8. Validación

FormRequests:

### `CustomerPaymentModalityUpdateRequest`

- `payment_modality`: nullable|string|max:100; trim; normalizar vacío a null.
- Authorization: customer visible + `customer-payments.manage`.

### `CustomerInvoiceStoreRequest` / `CustomerInvoiceUpdateRequest`

- `invoice_number`: required|string|max:60; unique por `customer_id` ignorando la factura actual en update.
- `due_date`: required|date.
- `total_amount`: required|numeric|min:0.01|max:999999999999.99.
- `status_id`: required|integer|exists:invoice_statuses,id + activo (`Rule::exists(...)->where('is_active', true)`).
- `notes`: nullable|string|max:2000.
- Prohibir campos fuera de scope: no aceptar metadata de pago, parcialidades, moneda, líneas, impuestos o adjuntos.

### `CustomerInvoiceRetireRequest`

- `reason`: required|string|max:255.

### `mark-paid`

- Sin payload requerido; el servicio resuelve status `pagado`. Si falta el catálogo, devolver error controlado indicando revisar Catálogos.

## 9. Permisos, policies y gates

Permisos nuevos:

- `customer-payments.view`: ver tarjeta Pagos, importes, estados y alertas de factura.
- `customer-payments.manage`: crear/editar facturas, modificar modalidad, marcar pagada, retirar/anular.

Registro en `RolesAndPermissionsSeeder`:

- admin: asignar `customer-payments.view` y `customer-payments.manage`.
- supervisor: asignar `customer-payments.view` y `customer-payments.manage`.
- vendedor: no asignar permisos financieros por defecto por sensibilidad de importes.

Decisión confirmada antes de apply: **Admin + supervisor** ven y gestionan Pagos/facturas; vendedor queda sin acceso financiero por defecto.

Policy `CustomerInvoicePolicy`:

- `view`: usuario tiene `customer-payments.view` y puede `view` el cliente asociado.
- `create(Customer $customer)`: `customer-payments.manage` y puede `view`/estar en scope del cliente.
- `update`, `markPaid`, `retire`: `customer-payments.manage`, factura activa, cliente en scope.
- No `delete` normal.

La tarjeta puede usar `@can('customer-payments.view')` para visibilidad y políticas para acciones por registro.

## 10. Catálogos

Integrar `InvoiceStatus` en:

- `app/Http/Controllers/CatalogController.php`: `CATALOG_MAP`, labels, icon, description, `configFor` simple sin extras.
- `app/Http/Requests/CatalogStoreRequest.php` y `CatalogUpdateRequest.php`: allowed map.
- `app/Services/CatalogService.php`: administrable/keyed por `slug`.
- `routes/web.php`: ampliar regex de `{kind}` con `invoice-statuses`.
- `resources/views/admin/catalogs/landing.blade.php` hereda cards desde `CatalogController`.
- `database/seeders/CatalogSeeder.php`: valores iniciales idempotentes.

Regla de seguridad: si un status usado por facturas se desactiva, las facturas históricas lo conservan por FK, pero formularios sólo listan activos. El servicio debe bloquear creación/actualización con status inactivo. `Pagado`, `Vencida`, `En proceso`, `Nota de crédito` deberían considerarse valores base; si faltan, UI debe mostrar alerta operativa en lugar de aceptar texto libre.

## 11. Auditoría

- `CustomerInvoice` usará `LogsActivity`, `HasAuditColumns`, `SoftDeletes`.
- En `CustomerInvoiceService`, registrar eventos manuales:
  - `customer-invoice-created`,
  - `customer-invoice-updated`,
  - `customer-invoice-marked-paid`,
  - `customer-invoice-retired`,
  - opcional `customer-payment-modality-updated` sobre `Customer`.
- Incluir propiedades: invoice id/number, customer id/code, old/new status slug cuando aplique, due date, total amount, actor.
- Registrar `customer-invoice-marked-overdue` cuando el sistema persiste `status_id = Vencida` por vencimiento automático.
- No registrar logs cuando el procesador se ejecuta y no cambia filas; esto evita duplicados por corridas repetidas.

## 12. Plan de pruebas TDD para sdd-apply

Crear/actualizar tests antes de implementación:

1. `tests/Feature/CustomerPaymentsCardTest.php`
   - usuario con `customer-payments.view` ve tarjeta Pagos;
   - usuario sin permiso no ve importes/status;
   - modalidad se muestra, empty state sin modalidad;
   - writer actualiza modalidad; read-only recibe 403.
2. `tests/Feature/CustomerInvoiceCrudTest.php`
   - crea factura desde cliente y la asocia correctamente;
   - rechaza sin due date;
   - rechaza monto cero/negativo;
   - rechaza status inexistente/inactivo;
   - update mantiene unicidad de `invoice_number` por cliente;
   - mark-paid cambia sólo `status_id` a `Pagado`;
   - retire setea `retired_at/by/reason` y no hard-deletes.
3. `tests/Feature/InvoiceStatusCatalogTest.php`
   - `CatalogSeeder` crea cuatro statuses requeridos;
   - aparecen en `/admin/catalogs/invoice-statuses` para admin;
   - tarjeta/form no permite crear status inline.
4. `tests/Feature/OverdueInvoiceProcessorTest.php` o `tests/Unit/OverdueInvoiceProcessorTest.php`
   - comando/procesador cambia `En proceso` vencida a `status_id = Vencida` persistido;
   - `Pagado` vencida permanece `Pagado` y no audita transición;
   - `Nota de crédito` vencida permanece no alertable y no audita transición;
   - retirada/anulada vencida no cambia a `Vencida`;
   - ejecutar el procesador dos veces no duplica activitylog ni cambia filas adicionales;
   - `--date` permite pruebas determinísticas locales.
5. `tests/Feature/InvoiceCalendarAlertsTest.php`
   - factura cobrable aparece en Calendario global en due date;
   - después de correr `invoices:mark-overdue`, calendario lee el estado persistido `Vencida`;
   - guardados repetidos no duplican eventos;
   - cambio de due date mueve la alerta;
   - `Pagado`, `Nota de crédito` y retirada no aparecen;
   - usuario con `calendar.view` pero sin `customer-payments.view` no ve detalles/evento financiero.
6. Actualizar tests existentes:
   - `CustomerHttpTest::test_show_renders_contact_table_and_quotations_card` para asegurar que la tarjeta no degrada contenido existente y sólo aparece con permiso financiero.
   - `CalendarQueryTest` o nuevo test de `CalendarEventService` para conservar comportamiento de actividades.

Comandos esperados en apply/verify:

- `php artisan test --filter=CustomerPaymentsCardTest`
- `php artisan test --filter=CustomerInvoiceCrudTest`
- `php artisan test --filter=InvoiceStatusCatalogTest`
- `php artisan test --filter=OverdueInvoiceProcessorTest`
- `php artisan test --filter=InvoiceCalendarAlertsTest`
- suite completa si el presupuesto lo permite.

## 13. Rollout y rollback

Rollout:

1. Migrar tablas/campo.
2. Ejecutar seeders para permisos y catálogo.
3. Validar que roles con acceso financiero estén definidos.
4. Desplegar UI con tarjeta oculta para quien no tenga permiso.

Rollback funcional:

- Remover/inhibir inclusión de `_payments_card` y rutas de facturas.
- Mantener datos migrados salvo aprobación explícita para pérdida de datos.
- Al ser calendario por proyección, no hay eventos físicos huérfanos que limpiar.
- Los valores `invoice_statuses` pueden permanecer; no afectan otros módulos si las rutas quedan ocultas.

Rollback de base en migración sólo es seguro antes de uso productivo; después, preferir rollback funcional.

## 14. Riesgos y asuntos abiertos

- **Bajo**: matriz inicial de roles financieros confirmada como admin+supervisor con acceso de gestión; vendedor sin acceso por defecto. Riesgo residual: cambios futuros de negocio podrían requerir migrar permisos de roles existentes.
- **Medio**: refactor de calendario de `Activity` a DTO común toca varias vistas; acotar cambios y cubrir con tests para no romper actividades.
- **Medio**: la persistencia automática de `Vencida` depende de que el comando programado corra en producción; mitigar con scheduler configurado, `withoutOverlapping()` y ejecución del procesador desde escrituras explícitas relevantes.
- **Bajo**: desactivar un status base desde Catálogos podría degradar formularios; servicio/UI deben mostrar error claro y orientar a Catálogos.
- **Bajo**: no hay moneda por factura en v1; se mostrará importe total sin multimoneda o usando moneda base del CRM si existe preferencia global futura.

## 15. Contratos principales

- Fuente de verdad de estados: `invoice_statuses.slug`, no strings libres.
- Factura activa/cobrable: `retired_at IS NULL`, `deleted_at IS NULL`, status persistido no `pagado` ni `nota-de-credito`.
- `Vencida`: status persistido automáticamente cuando `due_date < today()` y factura activa/cobrable no está `pagado`, `nota-de-credito` ni retirada/anulada.
- Calendario: proyección idempotente por `customer_invoices.id`; no persistir eventos de factura.
- Escrituras financieras: siempre `customer-payments.manage` + cliente dentro de scope.
- Lectura financiera: siempre `customer-payments.view` + cliente dentro de scope.
