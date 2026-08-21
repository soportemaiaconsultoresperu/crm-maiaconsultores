# Diseño de Base de Datos

Estado: **v1 (B00)**. Deriva de las decisiones ADR-001 a ADR-013 de `DECISIONES.md`. Cualquier cambio posterior requiere una versión nueva de este documento y su ADR.

---

## 1. Convenciones generales

- Motor: MySQL 8.0, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
- Clave primaria interna: `id BIGINT UNSIGNED AUTO_INCREMENT` en todas las tablas.
- Auditoría de fila: `created_by` (FK → users.id, nullable), `updated_by` (FK → users.id, nullable), `created_at`, `updated_at`.
- Borrado lógico: `deleted_at TIMESTAMP NULL` + trait `SoftDeletes` solo en las tablas indicadas en la sección 6.
- Montos: `DECIMAL(14,2)`. Porcentajes: `DECIMAL(5,2)`. Cantidades: `DECIMAL(12,2)`. **Nunca FLOAT/DOUBLE.**
- Catálogos: nunca se eliminan físicamente; se desactivan con `is_active TINYINT(1)`.
- Moneda: `currency_code CHAR(3)` (ISO 4217), FK → `currencies.code`.
- Ubicación: `ubigeo_code CHAR(6)` FK → `ubigeo.code` + columna de dirección detallada por entidad.

## 2. Diagrama lógico (resumen)

```
users ──< leads (owner_id)                    leads ──0..1── customers (converted_from_lead_id)
users ──< customers (owner_id)                customers ──< contacts
users ──< opportunities (owner_id)            customers ──< opportunities
users ──< quotations (owner_id)               opportunities ──< quotations
users ──< activities (owner_id)               leads ──< opportunities
users ──< documents (uploaded_by)             pipeline_stages ──< opportunities
users ──< opportunity_stage_histories         activity_types ──< activities
users ──1── teams (supervisor_id)             products ──< quotation_items
users >──< team_user >── teams                 quotations ──< quotation_items
roles >──< permissions (Spatie)               taxes ──< products (default)
users >──< roles (Spatie)                      currencies ──< opportunities / quotations / products
lead_sources ──< leads                        ubigeo (auto-referencial dep→prov→dist)
lead_statuses ──< leads                       documents: morph a lead/customer/contact/opportunity/quotation/activity
loss_reasons ──< opportunities                activities: morph subject a lead/customer/opportunity
product_categories ──< products               activity_log (Spatie) morph auditoría
code_sequences (correlativos, ADR-002)        settings (parámetros)
notifications (Laravel nativo)
```

## 3. Tablas por dominio

### 3.1 Seguridad y usuarios

#### users (extiende la base de Laravel)

| Campo | Tipo | Notas |
|---|---|---|
| name, email, password, remember_token | base Laravel | email único |
| is_active | TINYINT(1) DEFAULT 1 | usuario desactivado no puede iniciar sesión (gate en login) |
| last_login_at | TIMESTAMP NULL | para "consultar último acceso" |
| timestamps | | |

Índices: `email (único)`, `is_active`.

#### teams / team_user (ADR-006)

- `teams`: id, name, supervisor_id FK→users, is_active, auditoría de fila.
- `team_user`: team_id FK→teams (CASCADE), user_id FK→users (CASCADE), unique(team_id, user_id).

Índices: `teams(supervisor_id)`, `team_user(user_id)`, `team_user(team_id)`.

#### Spatie (paquete): roles, permissions, model_has_roles, model_has_permissions, role_has_permissions

Generadas por el vendor. Sin customization de estructura.

#### activity_log (Spatie activitylog v4, ADR-008)

Estructura del vendor (`log_name`, `description`, `subject morph`, `causer morph`, `properties JSON`, IPs si se habilita). Extensiones solo por ADR posterior.

#### settings

| Campo | Tipo |
|---|---|
| key | VARCHAR(100) UNIQUE |
| value | TEXT NULL |
| type | ENUM(string,integer,decimal,boolean,json) |
| group | VARCHAR(50) |

Claves iniciales: `prices_include_tax=false`, `date_format=d/m/Y`, `currency_default=PEN`, `pagination_size=25`, `quote_validity_days`.

### 3.2 Prospectos

#### leads

| Campo | Tipo / FK | Notas |
|---|---|---|
| code | VARCHAR(20) UNIQUE | LEAD-2026-00001 (ADR-002) |
| person_type | ENUM(natural,juridica) | |
| first_name / last_name | VARCHAR(100) | |
| company_name | VARCHAR(150) NULL | |
| position | VARCHAR(100) NULL | cargo |
| doc_type | ENUM(dni,ruc,ce,pasaporte,otro) NULL | |
| doc_number | VARCHAR(20) NULL | |
| doc_number_norm | VARCHAR(20) NULL | indexado, ADR-003 |
| phone / phone_norm | VARCHAR(30) / VARCHAR(20) NULL | indexado |
| whatsapp / whatsapp_norm | VARCHAR(30) / VARCHAR(20) NULL | |
| email / email_norm | VARCHAR(150) / VARCHAR(150) NULL | indexado (lowercase) |
| address | VARCHAR(255) NULL | dirección detallada |
| ubigeo_code | CHAR(6) NULL FK→ubigeo | |
| source_id | FK→lead_sources | origen comercial |
| status_id | FK→lead_statuses | |
| interest_level | ENUM(bajo,medio,alto) NULL | |
| owner_id | FK→users | vendedor responsable |
| entered_at | DATETIME | fecha de ingreso |
| observations | TEXT NULL | |
| soft delete + auditoría de fila | | |

**Sin columnas de próxima acción** (ADR-012).

Índices: `code (uniq)`, `owner_id`, `status_id`, `source_id`, `doc_number_norm`, `email_norm`, `phone_norm`, `entered_at`, `ubigeo_code`.

#### lead_sources / lead_statuses / loss_reasons (catálogos)

- id, name, slug UNIQUE, sort, is_active, auditoría de fila.
- `lead_statuses` adicional: `is_final TINYINT(1)` (convertido/perdido son finales).

#### code_sequences (ADR-002)

| Campo | Tipo |
|---|---|
| entity | ENUM(lead,customer,opportunity,quotation) |
| year | SMALLINT |
| prefix | VARCHAR(10) |
| next_number | INT UNSIGNED |
| pad_length | TINYINT DEFAULT 5 |

Restricción: `UNIQUE(entity, year)`. Generación: transacción + `SELECT ... FOR UPDATE` + UPDATE + lectura.

### 3.3 Clientes y contactos

#### customers

| Campo | Tipo / FK | Notas |
|---|---|---|
| code | VARCHAR(20) UNIQUE | CLI-2026-00001 |
| person_type | ENUM(natural,juridica) | |
| legal_name | VARCHAR(180) | razón social o nombre completo |
| trade_name | VARCHAR(180) NULL | nombre comercial |
| doc_type / doc_number / doc_number_norm | como leads | |
| phone / whatsapp (+ _norm) , email (+_norm) | | |
| website | VARCHAR(150) NULL | |
| fiscal_address | VARCHAR(255) NULL | |
| ubigeo_code | CHAR(6) NULL FK→ubigeo | |
| sector | VARCHAR(100) NULL | sector comercial (catálogo futuro si se requiere) |
| owner_id | FK→users | |
| status | ENUM(activo,inactivo) | desactivación lógica |
| converted_from_lead_id | FK→leads NULL | ADR-001 |
| converted_at | DATETIME NULL | |
| observations | TEXT NULL | |
| soft delete + auditoría | | |

Índices: `code (uniq)`, `doc_number_norm`, `owner_id`, `converted_from_lead_id`.

#### contacts

| Campo | Tipo / FK |
|---|---|
| customer_id | FK→customers |
| first_name / last_name | VARCHAR(100) |
| position / area | VARCHAR(100) NULL |
| phone / whatsapp / email (+ _norm email) | |
| is_primary | TINYINT(1) |
| is_active | TINYINT(1) |
| observations | TEXT NULL |
| soft delete + auditoría | |

Regla: **un solo contacto principal activo por cliente** (índice unique funcional: `UNIQUE(customer_id) WHERE is_primary=1` no existe en MySQL → se garantiza en servicio transaccional + índice redundante `customer_id, is_primary` para detección; el servicio reasigna principalidad en la misma transacción).

### 3.4 Embudo y oportunidades

#### pipeline_stages

- id, name, slug UNIQUE, stage_type ENUM(open,won,lost), default_probability DECIMAL(5,2), sort, is_active, auditoría de fila.
- Seed inicial: Nueva oportunidad, Contacto realizado, Reunión programada, Propuesta enviada, Negociación (open); Ganada (won); Perdida (lost).

#### opportunities

| Campo | Tipo / FK | Notas |
|---|---|---|
| code | VARCHAR(20) UNIQUE | OPP-2026-00001 |
| title | VARCHAR(200) | |
| lead_id | FK→leads NULL | exactamente uno de lead_id/customer_id |
| customer_id | FK→customers NULL | |
| contact_id | FK→contacts NULL | |
| owner_id | FK→users | |
| stage_id | FK→pipeline_stages | |
| estimated_amount | DECIMAL(14,2) | |
| currency_code | CHAR(3) FK→currencies | ADR-004 |
| probability | DECIMAL(5,2) | default por etapa |
| expected_close_at | DATE NULL | |
| source_id | FK→lead_sources NULL | |
| priority | ENUM(baja,media,alta) | |
| description | TEXT NULL | |
| loss_reason_id | FK→loss_reasons NULL | obligatorio si stage_type=lost |
| closed_at | DATETIME NULL | obligatorio si ganada/perdida |
| final_amount | DECIMAL(14,2) NULL | obligatorio si ganada (ADR-007) |
| soft delete + auditoría | | |

**Sin próxima acción** (ADR-012). Índices: `code (uniq)`, `owner_id`, `stage_id`, `(lead_id)`, `(customer_id)`, `expected_close_at`, `closed_at`.

Restricción "uno de lead/customer": sin CHECK multi-tabla en MySQL → validada en FormRequest + servicio; índices en ambas FK para detección.

#### opportunity_stage_histories

- id, opportunity_id FK→opportunities, from_stage_id FK NULL (NULL en creación), to_stage_id FK, user_id FK→users, changed_at, note VARCHAR(255) NULL.
- Índice: `opportunity_id, changed_at`.

### 3.5 Actividades (ADR-012)

#### activity_types (catálogo)

- id, name, slug UNIQUE (llamada, whatsapp, correo, reunion, visita, tarea, nota), sort, is_active.

#### activities

| Campo | Tipo / FK | Notas |
|---|---|---|
| type_id | FK→activity_types | |
| subject_type | VARCHAR(50) | morph: Lead, Customer, Opportunity |
| subject_id | BIGINT | |
| owner_id | FK→users | responsable |
| title | VARCHAR(200) | |
| description | TEXT NULL | |
| scheduled_at | DATETIME | programada |
| executed_at | DATETIME NULL | ejecución |
| result | VARCHAR(255) NULL | |
| status | ENUM(pending,in_process,completed,cancelled,overdue) | |
| priority | ENUM(baja,media,alta) | |
| reminder_at | DATETIME NULL | |
| soft delete + auditoría | | completadas se conservan siempre |

Índices: `(subject_type, subject_id)`, `owner_id`, `status`, `scheduled_at`, `(status, scheduled_at)` — este último alimenta "próxima acción" (ADR-012: actividad futura pendiente más próxima por subject).

El estado `overdue` lo marca el scheduler diario (y se re-deriva al consultar) — doble fuente resuelta: scheduler persiste para filtros, scope de consulta re-deriva si el registro no fue procesado.

### 3.6 Productos e impuestos

#### currencies (catálogo)

- code CHAR(3) PK (PEN, USD, EUR), name, symbol (S/, $, €), decimals TINYINT, is_active.

#### taxes (catálogo, ADR-005)

- id, name (Gravado IGV, Exonerado, Inafecto, Gratuito), slug, rate DECIMAL(5,2), is_active.

#### product_categories (catálogo)

- id, name, slug, is_active, auditoría.

#### products

| Campo | Tipo / FK |
|---|---|
| code | VARCHAR(20) UNIQUE |
| product_type | ENUM(producto,servicio) |
| name | VARCHAR(150) |
| category_id | FK→product_categories NULL |
| description | TEXT NULL |
| price | DECIMAL(14,2) |
| currency_code | CHAR(3) FK→currencies |
| tax_id | FK→taxes (afectación predeterminada) |
| is_active | TINYINT(1) |
| soft delete + auditoría | |

### 3.7 Cotizaciones

#### quotations

| Campo | Tipo / FK | Notas |
|---|---|---|
| number | VARCHAR(20) UNIQUE | COT-2026-00001 |
| lead_id / customer_id | FK NULL / FK | uno de los dos |
| contact_id | FK→contacts NULL | |
| opportunity_id | FK→opportunities NULL | |
| owner_id | FK→users | |
| issued_at | DATE | |
| expires_at | DATE NULL | |
| currency_code | CHAR(3) FK→currencies | |
| terms | TEXT NULL | condiciones comerciales |
| observations | TEXT NULL | |
| status | ENUM(draft,sent,accepted,rejected,expired,voided) | |
| subtotal / discount_total / tax_total / total | DECIMAL(14,2) | persistidos y recalculados/validados en servidor |
| accepted_at | DATETIME NULL | ADR-007 |
| soft delete + auditoría | | |

Índices: `number (uniq)`, `customer_id`, `opportunity_id`, `owner_id`, `status`, `expires_at`.

#### quotation_items

| Campo | Tipo / FK | Notas |
|---|---|---|
| quotation_id | FK→quotations ON DELETE CASCADE | |
| product_id | FK→products NULL | NULL si línea libre |
| description | VARCHAR(255) | |
| quantity | DECIMAL(12,2) | |
| unit | VARCHAR(30) NULL | |
| unit_price | DECIMAL(14,2) | **copia histórica** (ADR-005) |
| discount_amount | DECIMAL(14,2) DEFAULT 0 | |
| tax_id | FK→taxes NULL | copia |
| tax_name | VARCHAR(50) | copia histórica |
| tax_rate | DECIMAL(5,2) | copia histórica |
| line_subtotal / line_tax / line_total | DECIMAL(14,2) | |

Índice: `quotation_id`.

### 3.8 Documentos (ADR-011)

#### documents

| Campo | Tipo / FK |
|---|---|
| docable_type / docable_id | morph (Lead, Customer, Contact, Opportunity, Quotation, Activity) |
| name | VARCHAR(150) |
| disk | VARCHAR(50) |
| path | VARCHAR(255) |
| mime_type | VARCHAR(100) |
| extension | VARCHAR(10) |
| size_bytes | INT UNSIGNED |
| uploaded_by | FK→users |
| uploaded_at / timestamps + auditoría |

Índice: `(docable_type, docable_id)`. Borrado lógico: el registro se marca y el archivo físico se retiene.

### 3.9 Ubigeo (ADR-009)

#### ubigeo

| Campo | Tipo | Notas |
|---|---|---|
| code | CHAR(6) PK | código oficial INEI (dep 2, prov 4, dist 6 dígitos) |
| name | VARCHAR(100) | |
| level | ENUM(departamento,provincia,distrito) | |
| parent_code | CHAR(6) NULL FK→ubigeo.code | NULL en departamento |

Índice: `parent_code`. Seed: ~1960 registros; fuente y fecha del dataset documentadas en el seeder.

### 3.10 Notificaciones

- `notifications`: tabla nativa de Laravel (notificaciones internas). Sin tablas adicionales en v1.
- Arquitectura de canal: se emiten Notifications de Laravel (database channel) para permitir agregar `mail`/whatsapp driver propio en el futuro sin tocar el dominio.

### 3.11 Migraciones del framework

`jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions`, `password_reset_tokens` — estándar de Laravel 12.

## 4. Relaciones principales (resumen de integridad)

- users 1—N leads / customers / opportunities / quotations / activities (owner) — RESTRICT al borrar usuario (además nunca se borra: se desactiva).
- leads 1—0..1 customers (converted_from_lead_id) — RESTRICT; el lead no se elimina (ADR-001).
- customers 1—N contacts / opportunities / quotations — RESTRICT.
- pipeline_stages 1—N opportunities — RESTRICT.
- opportunities 1—N quotations / opportunity_stage_histories — RESTRICT / CASCADE (histories).
- quotations 1—N quotation_items — CASCADE.
- activities/documents: polimórficas — se resuelven por registro lógico; sin FK de BD sobre morph (se documenta y cubre con servicios + pruebas).
- Catálogos (sources, statuses, stages, types, taxes, currencies, categories, loss_reasons): RESTRICT al eliminar; en la práctica solo se desactivan.

## 5. Estrategia de correlativos (ADR-002)

1. `BEGIN; SELECT next_number, prefix, pad_length FROM code_sequences WHERE entity=? AND year=? FOR UPDATE;` (INSERT si no existe la fila del año, dentro de la misma transacción con lock por unique key).
2. Formatear `PREFIX-YEAR-STR_PAD(next_number)`, `UPDATE code_sequences SET next_number = next_number + 1`.
3. Insertar la entidad con el código. `COMMIT` junto con el resto de la operación.

## 6. Estrategia de borrado lógico

| Tratamiento | Tablas |
|---|---|
| Soft delete (`deleted_at`) | leads, customers, contacts, opportunities, products, quotations, quotation_items (vía cascada de la cabecera), activities, documents |
| Solo desactivación (`is_active`), nunca delete físico | users, catálogos (lead_sources, lead_statuses, pipeline_stages, activity_types, product_categories, taxes, currencies, loss_reasons, teams) |
| Sin borrado | code_sequences, settings, ubigeo, opportunity_stage_histories (append-only), activity_log (append-only), notifications (limpieza por retención configurada) |

Ninguna entidad con historial comercial admite DELETE físico desde la aplicación.

## 7. Reglas de integridad y transacciones

1. **Unicidad de negocio**: `code`/`number` únicos por tabla; duplicados de leads/documento por advertencia confirmada (ADR-003) — la unicidad dura de documento NO se fuerza con unique index (se permite duplicado confirmado); la detección usa columnas `*_norm` indexadas.
2. **Unicidad de catálogos**: `slug` único por catálogo; `unique(team_id, user_id)`; `unique(entity, year)` en sequences; `unique(key)` en settings.
3. **Transacciones obligatorias**: conversión lead→cliente (ADR-001), generación de correlativos (ADR-002), cambio de etapa con historial, aceptación de cotización→cierre de oportunidad (ADR-007), recálculo de totales de cotización, reasignación de principal de contactos, importación Excel (por lote).
4. **Validación de negocio en servidor**: totales de cotización recalculados y comparados contra lo enviado; ganada exige `final_amount` + `closed_at`; perdida exige `loss_reason_id`; contacto principal único por cliente.
5. **Prevención N+1**: eager loading declarado por módulo (`with(owner, stage, currency, ...)`) y verificado con pruebas de conteo de queries donde aplique.
6. **FKs RESTRICT por defecto**; CASCADE solo en quotation_items y tablas pivote de equipos.
7. **Auditoría**: toda acción sensible pasa por activitylog con old/new values (ADR-008); los servicios escriben el log dentro de la misma transacción cuando es crítico.
