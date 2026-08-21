# Checklist de implementación — CRM Maia Consultores

> Checklist imprimible para guiar la carga inicial del CRM, fase por fase.
> Usar como hoja de ruta operativa. Marcá cada ítem a medida que lo completás.

---

## Fase 1 — Andamiaje (una sola vez, antes de operar)

### Settings / Parámetros (tabla `settings`, 13 campos)

> Estos son los únicos campos configurables desde `/admin/settings`. Lo que **no** aparece en esta pantalla se configura en otros lugares — ver nota al final.

#### Grupo `general` (4 campos)

- [ ] `currency_default` = `PEN` (string). Moneda por defecto para nuevas cotizaciones.
- [ ] `date_format` = `d/m/Y` (string). Formato de fecha que verá el usuario.
- [ ] `pagination_size` = `25` (integer). Tamaño de página en listados (recomendado 10–50).
- [ ] `prices_include_tax` = `0` (boolean, checkbox). Si los precios del catálogo de productos ya incluyen IGV.

#### Grupo `quotations` (1 campo)

- [ ] `quote_validity_days` = `15` (integer). Días de validez por defecto al emitir una cotización.

#### Grupo `sequences` (8 campos — prefijos y padding de correlativos)

> Modificar con cuidado: cambiar un prefijo o el padding **no** renumera los correlativos ya emitidos.

- [ ] `seq.lead.prefix` = `LEAD` (string). Prefijo del correlativo de prospectos.
- [ ] `seq.customer.prefix` = `CLI` (string). Prefijo del correlativo de clientes.
- [ ] `seq.opportunity.prefix` = `OPP` (string). Prefijo del correlativo de oportunidades.
- [ ] `seq.quotation.prefix` = `COT` (string). Prefijo del correlativo de cotizaciones.
- [ ] `seq.quotation.pad_length` = `5` (integer). Dígitos del correlativo de cotizaciones (ej: `COT-2026-00001`).
- [ ] `seq.product.prefix` = `PROD` (string). Prefijo del correlativo de productos.
- [ ] `seq.product.pad_length` = `5` (integer). Dígitos del correlativo de productos.
- [ ] `seq.pad_length` = `5` (integer). Padding global por defecto para los demás correlativos.

#### Grupo `company` (6 campos — datos de la empresa)

- [ ] `company.name` = `Maia Consultores` (string). Razón social que aparece en la cabecera del PDF de cotización.
- [ ] `company.tax_id` = (string, vacío por defecto). RUC de la empresa (11 dígitos).
- [ ] `company.address` = (string, vacío por defecto). Dirección fiscal.
- [ ] `company.phone` = (string, vacío por defecto). Teléfono de contacto.
- [ ] `company.email` = (string, vacío por defecto). Email de contacto.
- [ ] `company.logo_path` = (string, vacío por defecto). **Se llena subiendo una imagen JPG/PNG/WEBP (máx. 2 MB) con el widget de upload que está en este mismo card.** El archivo se guarda en `storage/app/private/company/` (disco privado, sin symlink). Al subir uno nuevo, el anterior se borra automáticamente. Aparece en la cabecera del PDF de cotización.

#### Grupo `notifications` (2 campos — toggles globales de canales)

> Estos toggles actúan como gate previo: cuando están en `0`, el `NotificationService::isEnabled()` retorna `false` para ese canal **antes** de consultar las preferencias individuales del usuario. El canal `database` (campana del navbar) siempre está activo porque es el núcleo del sistema.

- [ ] `notifications.mail.enabled` = `0` (boolean). Cuando está `1`, se permite el envío de emails (requiere credenciales SMTP/OAuth configuradas en `config/mail.php` y `config/services.php`).
- [ ] `notifications.whatsapp.enabled` = `0` (boolean). Cuando está `1`, se permite el envío por WhatsApp (requiere `WhatsAppService` y `MetaWhatsAppProvider` configurados).

#### Lo que **no** está en Settings (configurar en otros lugares)

- [ ] **Zona horaria** `America/Lima`: está en `.env` → `APP_TIMEZONE=America/Lima`. No es editable desde la UI.

### Catálogos — revisión de los pre-cargados

- [ ] **Monedas**: PEN, USD, EUR. Confirmar que las tres están activas.
- [ ] **Impuestos**: Gravado IGV 18%, Exonerado, Inafecto, Gratuito.
- [ ] **Estados de prospecto**: Nuevo, Contactado, Calificado, No calificado, Convertido, Perdido.
- [ ] **Orígenes de prospecto**: Web, Referido, Campaña, Llamada, Feria, Redes sociales, Otro.
- [ ] **Tipos de actividad**: Llamada, WhatsApp, Correo, Reunión, Visita, Tarea, Nota.
- [ ] **Etapas del pipeline**: Nueva oportunidad, Contacto realizado, Reunión programada, Propuesta enviada, Negociación, Ganada, Perdida.
- [ ] **Motivos de pérdida**: Precio, Competencia, Sin respuesta, Sin presupuesto.
- [ ] **Categorías de producto**: revisar que existan las de tu negocio; agregar las que falten.

### Equipos

- [ ] Definir estructura de equipos comerciales (ej: Equipo Norte, Equipo Sur, Equipo Corporativo).
- [ ] Crear cada equipo con nombre y, si aplica, supervisor responsable.
- [ ] Documentar qué vendedor pertenece a qué equipo.

### Usuarios

- [ ] Crear usuario admin principal (si no existe del seeder).
- [ ] Crear usuarios supervisores (uno por equipo).
- [ ] Crear usuarios vendedores.
- [ ] Asignar cada vendedor a su equipo.
- [ ] Asignar rol a cada usuario.
- [ ] Verificar último acceso y reset de password inicial.

### Roles y permisos

- [ ] Revisar los 62 permisos granulares disponibles.
- [ ] Definir los 3 roles base:
  - [ ] **Admin**: todos los permisos.
  - [ ] **Supervisor**: permisos de su equipo (lectura global del equipo, escritura acotada).
  - [ ] **Vendedor**: permisos acotados a sus propios registros.
- [ ] Asignar permisos finos según necesidad.

> ✅ **Punto de control Fase 1**: antes de seguir, un admin debe poder:
>
> - [ ] Loguearse y ver `/admin/settings` con todos los labels en español y los 21 campos (5 grupos).
> - [ ] Editar un setting cualquiera y guardar (verificar flash verde "Parámetros guardados correctamente").
> - [ ] Subir un logo de empresa y verlo en preview.
> - [ ] Crear un nuevo registro en `/admin/catalogs/currencies` y otro en `/admin/catalogs/lead-sources` (validar que el CRUD POST/PUT funciona para catálogos con PK string y con PK int).
> - [ ] Verificar que `/admin/audit` muestra los cambios recién hechos (settings, catalogos, logo).

---

## Fase 2 — Operación diaria (orden del embudo real)

### Productos

> **Desde el 19/08/2026 el CRUD de productos ya tiene import y export de Excel.** Los botones "Importar Excel" y "Plantilla" están en `/products` para usuarios con permiso `products.import`; el botón "Exportar" sigue disponible con permiso `products.export`.

- [ ] Descargar la plantilla desde el botón "Plantilla" en `/products` para ver las columnas esperadas.
- [ ] Cargar productos con: nombre, tipo (producto / servicio), precio base, moneda, impuesto predeterminado, categoría.
- [ ] Cargar servicios con la misma estructura.
- [ ] Marcar como inactivos los productos que ya no se ofrezcan (nunca borrar — quedan en la papelera).
- [ ] Exportar catálogo inicial a Excel como respaldo.
- [ ] **Importar masivamente** (opcional, si tenés volumen histórico):
  - [ ] Botón "Importar Excel" en `/products` abre un modal con upload.
  - [ ] Columnas (en este orden): `Código, Tipo, Nombre, Categoría, Descripción, Precio, Moneda, Impuesto, Activo`.
  - [ ] `Categoría`, `Moneda` e `Impuesto` se resuelven por nombre (case-insensitive). Categoría y moneda son obligatorios; impuesto es opcional.
  - [ ] `Activo` acepta `Sí/No/1/0/true/false`. Default: activo.
  - [ ] Códigos duplicados (en la BD o dentro del mismo archivo) se omiten y se reportan en el flash verde/amarillo con la fila exacta.
  - [ ] Filas inválidas se reportan con la razón (categoría inexistente, campos faltantes, etc.). Las válidas se crean en chunks transaccionados.

### Prospectos (Leads)

- [ ] Definir criterios de captura (web, referido, campaña, etc.).
- [ ] Cargar primer lote de prospectos manualmente si los hay.
- [ ] Configurar importador Excel si hay volumen histórico.
- [ ] Asignar responsable y origen a cada prospecto desde el alta.
- [ ] Verificar que la detección de duplicados funcione (documento / email / teléfono normalizado).
- [ ] Definir SLA interno: tiempo máximo entre alta y primer contacto.

### Actividades (transversal — usar desde el día 1)

- [ ] Para cada prospecto nuevo: agendar al menos una actividad de primer contacto.
- [ ] Registrar toda llamada, WhatsApp o reunión como actividad, no como nota suelta.
- [ ] Definir estados por defecto: `pending` al agendar, `completed` al cerrar, `cancelled` si no procede.
- [ ] Revisar el calendario diariamente para detectar vencidas y próximas.
- [ ] Verificar que el scheduler diario marca vencidas y notifica.

### Clientes y Contactos

- [ ] No cargar clientes directos — siempre desde conversión de prospecto.
- [ ] Al convertir un prospecto: revisar datos del cliente resultante.
- [ ] Agregar contactos adicionales por cliente (teléfono, email, cargo).
- [ ] Marcar un único contacto como **principal activo** por cliente.
- [ ] Completar la ficha 360°: oportunidades, cotizaciones, actividades, documentos.

### Oportunidades (Kanban)

- [ ] Crear primera oportunidad sobre un cliente existente.
- [ ] Definir monto estimado, moneda y fecha estimada de cierre.
- [ ] Recorrer las 7 etapas del pipeline:
  - [ ] Nueva oportunidad
  - [ ] Contacto realizado
  - [ ] Reunión programada
  - [ ] Propuesta enviada
  - [ ] Negociación
  - [ ] Ganada (requiere monto final + fecha) **o** Perdida (requiere motivo)
- [ ] Para cada oportunidad asignada: verificar que se generó notificación.
- [ ] Para cada cambio de etapa: verificar que queda en el historial append-only.
- [ ] Para oportunidades perdidas: cargar motivo obligatorio.

### Cotizaciones

- [ ] Generar primera cotización desde una oportunidad en "Propuesta enviada" o superior.
- [ ] Verificar numeración automática con formato `COT-AAAA-NNNNN`.
- [ ] Adjuntar ítems desde el catálogo de **Productos** (no cargar ítems sueltos).
- [ ] Confirmar cálculo server-side validado (subtotal, impuesto, total).
- [ ] Generar PDF y verificar maquetación profesional.
- [ ] Al aceptar la cotización: confirmar el cierre de oportunidad asociado.
- [ ] Si se necesita variante: usar duplicación, no edición directa.

> ✅ **Punto de control Fase 2**: el primer ciclo completo Prospecto → Cliente → Oportunidad → Cotización → PDF debe estar cerrado y validado.

---

## Fase 3 — Análisis (cuando ya hay volumen)

### Dashboard

- [ ] Verificar que se muestran los 12 KPIs según el alcance del usuario.
- [ ] Confirmar que un vendedor solo ve sus propios números.
- [ ] Confirmar que un supervisor ve los números de su equipo.
- [ ] Confirmar que un admin ve la visión global.
- [ ] Identificar KPIs críticos para revisión semanal.

### Reportes

- [ ] Listar los 12 reportes disponibles y mapearlos con las preguntas de negocio que responden.
- [ ] Definir los 3 o 4 reportes de uso frecuente.
- [ ] Probar exportación Excel (`?export=xlsx`) en cada uno.
- [ ] Validar filtros: fecha, usuario, estado, etapa, moneda.
- [ ] Confirmar comportamiento multimoneda (cada moneda se reporta por separado, sin consolidar).

> ✅ **Punto de control Fase 3**: el equipo debe poder responder semanalmente "¿cómo vamos?" solo con Dashboard + Reportes.

---

## Fase 4 — Soporte transversal (uso continuo)

### Documentos

- [ ] Subir primeros documentos sobre clientes existentes.
- [ ] Validar formato, extensión, MIME y tamaño máximo.
- [ ] Confirmar que el disco es privado y la descarga está autorizada.
- [ ] Usar adjuntos polimórficos: ¿el documento va sobre cliente, oportunidad, cotización o actividad? Elegir el correcto.

### Auditoría

- [ ] Revisar el visor de auditoría semanalmente.
- [ ] Confirmar que las acciones sensibles quedan registradas (creación, edición, eliminación lógica, cambios de etapa, aceptaciones de cotización).
- [ ] Documentar el procedimiento de respuesta ante anomalías.

### Notificaciones

> Las notificaciones tienen **dos capas** de control: los toggles globales de canal en `settings` (grupo `notifications`, ver Fase 1) y las preferencias individuales por usuario/canal/subject (`NotificationPreference`). El gate global se consulta **antes** que la preferencia individual.

- [ ] Confirmar que los toggles globales están configurados en `/admin/settings` (grupo `notifications`):
  - [ ] `notifications.mail.enabled` y `notifications.whatsapp.enabled` están en `0` hasta que se carguen credenciales reales en `config/mail.php` / `config/services.php`.
  - [ ] Entender que el canal `database` (campana del navbar) **siempre** está activo.
- [ ] Confirmar que la campana del navbar muestra:
  - [ ] Asignación de oportunidad.
  - [ ] Cambio de etapa.
  - [ ] Asignación de actividad.
  - [ ] Próxima actividad.
  - [ ] Actividad vencida.
- [ ] Definir rutina de revisión de notificaciones (mañana / tarde).

---

## Resumen — una línea por fase

```
[ ] FASE 1  Settings → Catálogos → Equipos → Usuarios → Roles
[ ] FASE 2  Productos → Prospectos → Actividades
                              ↓ (Convertir)
                         Clientes → Contactos → Oportunidades → Cotizaciones → PDF
[ ] FASE 3  Dashboard → Reportes
[ ] FASE 4  Documentos → Auditoría → Notificaciones (transversal)
```

---

## Próximo paso sugerido

**Fase 1 está parcialmente cerrada**: Settings (con labels en español + upload de logo + toggles de notificación) y Catálogos funcionan. Faltan Equipos, Usuarios y Roles para cerrar Fase 1 por completo.

Una vez cerrada la Fase 1, seguir con **Fase 2 → Productos + Prospectos + Actividades** en paralelo, ya que son los módulos que generan volumen operativo desde el primer día.

---

## Bugfixes y mejoras aplicados (transversal)

> Esta sección documenta los arreglos y mejoras que se hicieron durante la implementación y que aplican transversalmente al sistema. Si tocás alguno de los archivos listados, leé primero el ADR / commit asociado.

### Logger (enmascaraba todos los 500)

- **Archivo**: `config/logging.php`
- **Problema**: `RedactPiiProcessor` (que redacta PII de los logs) estaba registrado en la clave `tap` con la firma de un tapper de Laravel (`__invoke(Logger)`), pero el código implementaba la firma de un processor de Monolog (`__invoke(LogRecord)`). Cada logueo reventaba con `TypeError`, lo que enmascaraba cualquier excepción.
- **Fix**: cambiar `'tap' => [...]` por `'processors' => [...]` en los canales `single` y `daily`. Dos líneas.

### Auditoría polimórfica con PK string (ocasionaba 500 en Settings y Currency)

- **Archivos**: `app/Services/SettingsService.php`, `app/Services/CatalogService.php`
- **Problema**: `Setting` (PK = `key` string) y `Currency` (PK = `code` string) intentan guardar el subject_id en `activity_log.subject_id` (BIGINT). SQLSTATE `[HY000]: 1366 Incorrect integer value`.
- **Fix**: cuando el PK no es convertible a int, `subject_id` se guarda como `null` y la clave queda en `properties.key` para preservar la trazabilidad. El visor de auditoría muestra la clave en el detalle.

### i18n en Settings (labels en español)

- **Archivos**: `lang/es/settings.php` (nuevo), `resources/views/admin/settings/index.blade.php`
- **Mejora**: cada setting tiene label en español + help text. La vista navega el array de traducciones con `Arr::get()` para soportar keys con puntos literales.

### Upload de logo de empresa

- **Archivos**: `routes/web.php`, `app/Http/Controllers/SettingController.php` (3 métodos nuevos), `resources/views/admin/settings/partials/logo-upload.blade.php` (nuevo).
- **Comportamiento**: sube JPG/PNG/WEBP (máx. 2 MB) al disco privado `local` (`storage/app/private/company/`), borra el archivo anterior al subir uno nuevo, sirve preview inline autorizable, deja el setting limpio al quitar.

### Toggles globales de canales de notificación

- **Archivos**: `database/seeders/SettingsSeeder.php`, `app/Services/Notification/NotificationService.php`
- **Comportamiento**: dos settings boolean (`notifications.mail.enabled`, `notifications.whatsapp.enabled`) actúan como gate previo a las preferencias individuales. Cuando están en `0`, ningún email o WhatsApp sale. El canal `database` (campana) siempre está activo.
