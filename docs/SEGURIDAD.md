# Seguridad

Estado: **v1 (B01)**.

## 1. Autenticación

- Login manual Blade (sin Breeze): `GET/POST /login`, `POST /logout`.
- `LoginRequest` valida credenciales y exige `is_active=1`: **un usuario desactivado no puede iniciar sesión aunque la contraseña sea correcta** (RF-USR-002).
- Middleware `EnsureUserIsActive` (`active`, registrado en `bootstrap/app.php` sobre todos los grupos web): si un usuario activo es desactivado mientras navega, su sesión es rechazada en el siguiente request (probado en `AuthTest`).
- Throttle: 5 intentos fallidos → lockout temporal (probado).
- `last_login_at` se actualiza en cada login (RF-USR-006 base).

## 2. Autorización

- 62 permisos granulares Spatie (`modulo.accion[.alcance]`); roles admin/supervisor/vendedor solo agrupan permisos. **Ningún código consulta el nombre del rol** (requisito del prompt maestro; cubierto por `RolesAndPermissionsTest::test_permission_checks_work_without_role_names`).
- Policies por módulo + alcance por registro vía `DataScopeService` (ADR-006): vendedor solo lo suyo, supervisor su equipo, admin todo.
- Próximos bloques: cada ruta/controlador Livewire debe usar `can:`/Policy correspondiente; sin excepciones.

## 3. Protecciones web

- CSRF: todos los formularios Blade con `@csrf` (middleware global de Laravel).
- Escapado: todas las vistas usan sintaxis `{{ }}` de Blade; prohibido `{!! !!}` salvo HTML generado y saneado en servidor (revisar por bloque).
- Validación server-side total: Form Requests; datos de montos y estados recalculados en Services (nunca confiados del cliente).

## 4. Datos sensibles

- Logs: nivel por entorno; nunca loguear contraseñas ni documentos. Revisión final en B09 (RNF-SEG-003).
- Credenciales solo por `.env` (gitignored). `.env.example` con placeholders vacíos. Usuario admin nace de `ADMIN_NAME/ADMIN_EMAIL/ADMIN_PASSWORD` del entorno; el seeder lanza error si faltan (fail-fast, sin defaults en repositorio).
- Montos `DECIMAL(14,2)`, sin float (RNF-DAT-002).

## 5. Archivos (ADR-011, se materializa en B06)

- Disco privado configurable (local dev / S3 prod por env). Descarga solo autorizada o URL temporal. Validación de extensión, MIME y tamaño. Documentación completa al llegar B06.

## 6. Auditoría (ADR-008)

- activitylog sobre entidades core con old/new values; acciones sensibles registran motivo en `properties`. Vista administrativa en B08.

## 7. Cobertura de pruebas de seguridad (B01)

`tests/Feature/AuthTest.php`: login válido/inválido, usuario inactivo rechazado en login y por middleware, logout, throttle. `RolesAndPermissionsTest`: matriz de permisos por rol. `DataScopeTest`: aislamiento de datos por rol/equipo (incluye equipo desactivado excluido del alcance del supervisor).

## 8. Hallazgos de seguridad corregidos

A lo largo de B04 / B07 / B08 / B09 se detectaron y corrigieron tres hallazgos de seguridad. Esta sección los documenta con la corrección aplicada y la prueba de regresión que los protege.

### H-01 — `LeadsExport` no aplicaba el alcance de datos (B04)

**Síntoma**: un vendedor con permiso `leads.export` podía exportar prospectos de otros vendedores. `LeadsExport` no recibía el actor ni llamaba a `DataScopeService::appliesTo`, así que el query salía con el alcance global.

**Causa raíz**: omisión de la inyección de actor en el constructor de `LeadsExport`; el query interno no filtraba por `owner_id`.

**Fix**:

- `App\Exports\LeadsExport` ahora recibe el `User` actor en el constructor y aplica `DataScopeService::appliesTo(..., owner_id)` dentro de su query.
- `CustomersExport` y `OpportunitiesExport` se auditaron en paralelo y **ya estaban correctos** (no requirieron cambios).

**Test de regresión**: `tests/Feature/LeadsExportScopeTest.php` — `test_vendedor_export_contains_only_own_leads` y `test_admin_export_is_unrestricted`.

### H-02 — `QuotationController` y `ReportController` fuera del grupo `auth + active` (B07)

**Síntoma**: las rutas de cotizaciones y reportes estaban declaradas con `Route::controller()->group(...)` **fuera** del grupo `Route::middleware(['auth', 'active'])`. Un usuario no autenticado podía enviar un POST a `/quotations/1/accept` o `GET /reports/valor-embudo` si encontraba la URL. La suite PHPUnit seguía verde porque `actingAs()` bypasea middleware.

**Causa raíz**: dos `Route::controller(QuotationController::class)->group(...)` y `Route::controller(ReportController::class)->group(...)` quedaron a nivel raíz en `routes/web.php` cuando se agregaron en B06 / B07; solo heredaban el middleware global `web`, no el `auth + active` que sí envolvía a `LeadController`, `ActivityController`, etc.

**Fix**:

- `routes/web.php`: envolver ambos `Route::controller(...)` con `Route::middleware(['auth', 'active'])->controller()->group(...)`.
- `ReportController` además valida `reports.view` por instancia; `QuotationController` valida permisos por `QuotationPolicy`.

**Test de regresión**: `tests/Feature/ReportHttpTest.php` — `test_user_without_reports_view_receives_403` verifica el extremo de permisos; `tests/Feature/AdminHttpTest.php` y `tests/Feature/CustomerHttpTest.php` ejercitan rutas equivalentes con sesión real. La cobertura de la capa de transporte está reforzada por `tests/Feature/SecurityHardeningTest.php` (H-03).

### H-03 — Búsqueda de otras fugas de autorización (B09)

**Síntoma**: necesitábamos un set de regex / contract tests que aseguren que vendedor no alcanza `/admin/*` ni registros de otro vendedor, y que la app no filtra stack traces con `APP_DEBUG=false`.

**Fix**: `tests/Feature/SecurityHardeningTest.php` (5 tests):

1. `test_password_and_remember_token_are_masked_in_logs` — `User::$hidden` impide que la contraseña plana y el `remember_token` aparezcan en los logs (RNF-SEG-003 base).
2. `test_app_debug_false_hides_stack_traces_from_error_responses` — con `APP_DEBUG=false`, las respuestas de error no incluyen frames `vendor/` ni rutas absolutas del filesystem.
3. `test_csrf_token_is_required_on_post_forms` — POST a `/login` sin `_token` produce 419 o redirige a login.
4. `test_input_validation_rejects_xss_payload` — Blade escapa `<script>` a `&lt;script&gt;`.
5. `test_vendedor_cannot_reach_admin_routes_or_other_vendor_resources` — vendedor recibe 403 en `/admin/{users,teams,settings,audit}` y en la ficha de un lead ajeno; sigue viendo la ficha de su propio lead.

### Estado al cierre de B09

Pendientes honestos de la matriz de seguridad (no se cerraron en silencio):

- **RNF-SEG-002**: validación de archivos cargados está cubierta en backend por `DocumentServiceTest::test_upload_validates_extension_mime_and_size`; la auditoría de la configuración del runtime (HHVM / open_basedir / etc.) queda fuera.
- **RNF-SEG-003**: solo el camino más obvio está cubierto por el test H-03 #1. Un pass manual del resto del código de logging sería necesario para cerrar la fila.
- **RF-DOC-001..005**: backend completo, pendiente la verificación de la UI en la ficha de cada sujeto.
