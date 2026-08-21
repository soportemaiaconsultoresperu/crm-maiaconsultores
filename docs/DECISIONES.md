# Registro de Decisiones (ADR)

Estado del documento: B00 — Análisis y decisiones.
Cada decisión se registra como ADR (Architecture Decision Record). Ningún ADR se modifica silenciosamente: los cambios derivan en un nuevo ADR que referencia al anterior.

---

## ADR-001 — Conversión de lead a cliente: entidad nueva

- **Decisión**: la conversión crea un registro nuevo en `customers`. El lead original se conserva y pasa al estado `convertido`.
- **Relación**: `customers.converted_from_lead_id` (nullable, FK → `leads.id`). No se usa `lead_origin_id` para esta relación; ese nombre queda reservado al origen comercial del lead (web, referido, campaña, llamada) vía `leads.source_id`.
- **Transaccionalidad**: la conversión (creación de cliente + creación de contacto inicial opcional + cambio de estado del lead + registro de auditoría) se ejecuta en una sola transacción de base de datos.
- **Historial**: actividades y documentos del lead permanecen vinculados al lead y se muestran en la ficha del cliente a través de la relación `converted_from_lead_id`.
- **Fecha**: B00.

## ADR-002 — Códigos correlativos

- **Formato**: `LEAD-2026-00001`, `CLI-2026-00001`, `OPP-2026-00001`, `COT-2026-00001`.
- **Secuencia**: independiente por tipo de entidad y por año calendario.
- **Generación**: transacción con bloqueo de fila (`SELECT ... FOR UPDATE`) sobre la tabla `code_sequences` para evitar duplicados ante registros simultáneos.
- **Configurabilidad**: prefijo y cantidad de dígitos configurables por tipo en `code_sequences` (valores por defecto en seed, sobreescribibles desde administración).
- **Fecha**: B00.

## ADR-003 — Duplicados de leads: advertencia, no bloqueo

- **Creación manual**:
  - Documento idéntico → advertencia crítica.
  - Correo o teléfono idénticos → advertencia de posible duplicado.
  - El usuario puede continuar únicamente con confirmación explícita; la confirmación queda registrada en auditoría (usuario, fecha, campos que coincidieron).
- **Importación Excel**: nunca actualiza automáticamente. La fila posiblemente duplicada se omite y se incluye en un reporte de errores con el motivo y el registro coincidente, para revisión manual.
- **Normalización**: teléfonos, documentos y correos se normalizan antes de comparar (columnas `*_norm` mantenidas por el servicio de leads e indexadas).
- **Fecha**: B00.

## ADR-004 — Multimoneda sin conversión

- Oportunidades y cotizaciones registran moneda propia (código ISO: PEN, USD, EUR, ...). PEN es el valor por defecto.
- Los reportes totalizan por moneda; no hay consolidación a PEN ni tipo de cambio en esta versión.
- Catálogo `currencies` configurable.
- **Fecha**: B00.

## ADR-005 — Impuestos configurables por línea

- Catálogo `taxes` con valores iniciales: Gravado IGV 18%, Exonerado 0%, Inafecto 0%, Gratuito 0% (solo presentación).
- Cada producto tiene una afectación predeterminada (`products.tax_id`), modificable en la cotización según permiso.
- **Copia histórica**: `quotation_items` copia `tax_id`, `tax_name` y `tax_rate` al momento de crear la línea, igual que el precio unitario. Un cambio posterior del catálogo no altera cotizaciones históricas.
- Los precios se configuran a nivel general del sistema como "incluyen impuestos" o "no incluyen impuestos" (`settings`). Valor inicial: **precios sin IGV**.
- Los cálculos se realizan y validan en el servidor, con `DECIMAL(14,2)`; sin float.
- **Fecha**: B00.

## ADR-006 — Visibilidad por alcance de datos con equipos propios

- Vendedor: solo registros asignados a él. Supervisor: registros de los vendedores de su equipo. Administrador: todo.
- Se crean tablas `teams` y `team_user`. **Los equipos no se modelan con Spatie Permission**; Spatie queda para roles y permisos, las tablas de equipos definen el alcance de datos.
- Diseño: `teams(id, name, supervisor_id FK users, ...)` y `team_user(team_id, user_id)` para miembros vendedores. Un vendedor puede pertenecer a más de un equipo.
- El alcance se resuelve en un `DataScopeService` consumido por Policies, queries y reportes.
- **Fecha**: B00.

## ADR-007 — Cotización aceptada y oportunidad ganada: confirmación explícita

- Al aceptar una cotización vinculada a una oportunidad abierta, el sistema solicita confirmación para marcar la oportunidad como ganada.
- Al confirmar: monto final = total de la cotización, moneda de la cotización, fecha de cierre = fecha de aceptación. Todo en auditoría.
- Es posible aceptar la cotización **sin** cerrar la oportunidad (múltiples cotizaciones o etapas pendientes).
- La operación no es automática ni silenciosa.
- **Fecha**: B00.

## ADR-008 — Auditoría con spatie/laravel-activitylog

- Auditoría basada en `spatie/laravel-activitylog` v4: usuario, acción, entidad afectada, fecha/hora, valores anteriores y nuevos, IP, y propiedades extra (motivo en operaciones críticas) mediante el atributo `properties`.
- Vista administrativa de consulta de auditoría.
- Si surgiera un dato que el paquete no cubra, se extiende su estructura (columnas propias en `activity_log`); no se crea una segunda tabla de auditoría paralela.
- **Fecha**: B00.

## ADR-009 — Ubigeo oficial como seed

- Catálogo completo de departamentos, provincias y distritos del Perú cargado por seeder, con **códigos oficiales de ubigeo** (tabla `ubigeo`: código 6 dígitos, nivel, padre).
- Selectores dependientes en la interfaz. Columna adicional para dirección detallada en las entidades.
- Se registra fuente y fecha del dataset en el seeder y en `docs/BASE_DATOS.md`.
- Nunca se almacena ubicación como texto libre. El catálogo puede actualizarse sin afectar clientes existentes (relación por código).
- **Fecha**: B00. Fuente tentativa: INEI / dataset abierto con códigos oficiales; se confirmará en B01 al ejecutar el seed.

## ADR-010 — Frontend: AdminLTE 4 + Bootstrap 5

- AdminLTE 4 con Bootstrap 5. Blade + Livewire 3. Sin jQuery salvo que una librería concreta lo requiera.
- Se integran solo los componentes necesarios; componentes reutilizables propios para formularios, tablas, filtros, modales y alertas.
- No se usa AdminLTE 3 ni Bootstrap 4.
- **Riesgo registrado**: si AdminLTE 4 no alcanzara una versión estable utilizable al momento de B01, se decide entre fijar la beta o migrar a Tabler/BS5 con layout equivalente; el cambio se registraría como ADR nuevo antes de tocar código.
- **Fecha**: B00.

## ADR-011 — Documentos en disco privado

- Disco privado configurable vía Laravel Filesystem (`FILESYSTEM_DISK`/`docs` disk). Desarrollo: local privado. Producción: local privado o S3 por variables de entorno.
- Sin enlaces públicos: descargas pasan por autorización, o URL temporal firmada cuando el driver lo permite.
- Validación de extensión, MIME, tamaño y permisos. Metadatos en `documents`; archivos nunca en la base de datos.
- **Fecha**: B00.

## ADR-012 — Próximas acciones: fuente única en actividades

- La única fuente de verdad de la próxima acción es el módulo de actividades.
- La próxima acción de un lead, cliente u oportunidad es su actividad futura pendiente más próxima. `leads` y `opportunities` **no** tienen columnas de próxima acción; la interfaz las muestra como relación/campo calculado.
- Al completar una actividad, el sistema ofrece crear inmediatamente el siguiente seguimiento.
- Sin actividad futura → se muestra "Sin próximo seguimiento".
- **Fecha**: B00.

## ADR-013 — Stack de versiones

| Componente | Versión | Notas |
|---|---|---|
| PHP | 8.3 | mínimo 8.2, objetivo 8.3 |
| Laravel | 12.x | última estable LTS-alineada |
| Livewire | 3.x | |
| MySQL | 8.0 | utf8mb4 / utf8mb4_unicode_ci |
| spatie/laravel-permission | ^6 | roles y permisos granulares |
| spatie/laravel-activitylog | ^4 | auditoría (ADR-008) |
| maatwebsite/excel | ^3.1 | import/export |
| barryvdh/laravel-dompdf | ^3 | PDF de cotizaciones |
| PHPUnit | 12.x | nativo de Laravel 12 |
| AdminLTE | 4.x | Bootstrap 5 (ADR-010) |
| Docker | compose | app, mysql, nginx, queue, scheduler |

Verificación real de resolución de dependencias (`composer create-project` + `composer require`) se ejecuta en B01; cualquier conflicto se registra como ADR correctivo.

## ADR-014 — Gestión documental del proyecto (sin OpenSpec)

- El proyecto se documenta con la estructura `docs/` definida en el prompt maestro (ARQUITECTURA, BASE_DATOS, REQUISITOS, DECISIONES, SEGURIDAD, PRUEBAS, AVANCE). No se adopta OpenSpec/SDD como capa adicional salvo decisión explícita posterior del cliente.
- **Fecha**: B00.

## ADR-015 — Corrección de versiones del stack (supersede ADR-013 en versiones)

- En el scaffold real (agosto 2026), `composer create-project laravel/laravel` instala **Laravel 13.17**, no 12.x. Dependencias resueltas por composer sin conflictos: **spatie/laravel-permission v8.3**, **spatie/laravel-activitylog v4.12**, **maatwebsite/excel v4.0**, **barryvdh/laravel-dompdf v3.1**, **Livewire 4.4**.
- Sin impacto funcional: las APIs usadas (permission, activitylog, excel, dompdf) son las mismas que planificamos. MySQL dev 8.4 (Laragon) y 8.0 (Docker), ambas compatibles.
- La tabla de ADR-013 queda como intención original; esta es la versión real registrada con evidencia de resolución de composer.
- **Fecha**: B01.

## ADR-016 — Distribución de AdminLTE 4

- AdminLTE 4 se consume como paquete npm **`admin-lte@^4.3.1`** (distribución oficial del proyecto para v4), compilado por Vite. Su dist CSS ya incluye Bootstrap 5 compilado; su JS es vanilla (dropdowns, collapse, push-menu), **sin jQuery**.
- No se usa CDN ni copia manual de assets; `npm run build` reproduce el bundle.
- **Fecha**: B01.
