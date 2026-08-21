# B12-UI — Automation Engine Administration UI (PRD / sdd-proposal)

> **Phase**: sdd-proposal (PRD only — no technical spec, design, or tasks).
> **Upstream artifacts** (authoritative):
>
> - `openspec/config.yaml` — project context (`crm-maia` / `b12-ui`, Laravel 13.25, Livewire 4, Spatie Permission + Activitylog, AdminLTE/Bootstrap 5, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`).
> - `openspec/changes/b12-ui/explore.md` — engine and placeholder surface map. Every path, seam, and gotcha referenced below traces to that document.
>
> **Product decisions**: 12 locked decisions (4 explicit + 8 firmadas) are restated in §10. They are not re-opened in this proposal.

---

## 1. Resumen ejecutivo

**B12-UI es la capa de administración que cierra el ciclo del motor de automatización B12**: el motor existe, está probado por `AutomationEngineTest` (10 tests, 21 assertions — `openspec/changes/b12-ui/explore.md` Quick cross-reference) y ya se ejecuta contra los 19 eventos del dominio, pero hoy sólo se puede crear y editar reglas vía seeders/migrations porque las rutas y vistas actuales (`routes/web.php` líneas 375–380, `AutomationController` con tres acciones read-only, y tres placeholder Blade de 51/57/58 líneas) son explícitamente el "B12 placeholder" según el comentario en `routes/web.php`. B12-UI entrega el editor Livewire 4 con el CRUD completo de reglas, builder visual de condiciones AND/OR sobre `automation_condition_groups` + `automation_conditions`, formularios por tipo para los 11 `ActionContract`, "Simular ahora" gateado por permiso, historial filtrable de ejecuciones, papelera con restaurar, e integración del `Spatie\Activitylog` en la vista de detalle. Esto convierte al motor B12 de un sistema "deploy-only" en un sistema operable por el equipo de operaciones sin tocar código.

---

## 2. Problema de negocio

- **Pain operacional**: el motor B12 está vivo y procesando eventos desde `B12` (doc `docs/v2/01-roadmap.md`), pero `AutomationController` (`app/Http/Controllers/Admin/AutomationController.php`) sólo expone tres rutas read-only. Cualquier cambio en una regla (alta, baja, ajuste de condición, cambio de modo, reordenamiento, fix de `recipient_strategy`) requiere un seeder + deploy. Esto bloquea al equipo de operaciones y soporte cuando detecta un comportamiento inesperado en producción, por ejemplo un `AssignOwnerAction` que apunta a un usuario fuera del `DataScope` del creador.
- **Riesgo de deriva documental**: las reglas "vivas" hoy existen como fixtures SQL/seeders; sin un editor visual, las reglas se desactualizan respecto al dominio (se añaden nuevos `App\Events\V2\*` — actualmente 16 eventos de dominio + 3 time-driven según `explore.md` §5.1) y nadie puede cubrirlas a tiempo.
- **Coste de soporte**: cuando un agente reporta "no me llegó la notificación", el supervisor no tiene cómo inspeccionar la ejecución ni filtrar por estado desde la UI; tiene que entrar a la base de datos. `AutomationExecution` ya trae `status`, `error_class`, `error_message`, `started_at`, `finished_at` y `idempotency_key` — los datos están, sólo falta la UI.
- **Oportunidad**: el motor ya garantiza idempotencia (UNIQUE `idempotency_key` — `explore.md` §3), ciclo de detección 30 s (`CycleDetector::DEFAULT_WINDOW_SECONDS` — `explore.md` §8 punto 3), registro de `AutomationCycleBreak`, y modo `test` con `response_json` que contiene el payload que se habría enviado (`explore.md` §8 punto 1). Toda esa telemetría se desperdicia si no hay forma de leerla desde la UI.

---

## 3. Usuarios objetivo y situaciones

| Persona | Necesita | Flujo concreto |
|---|---|---|
| **Admin** (rol con `automations.manage`) | Crear, clonar, editar, activar/desactivar, mover en el orden, enviar a papelera y restaurar reglas; cubrir nuevos eventos del dominio sin tocar código. | Abre `admin.automations.create` → elige `trigger_event` del catálogo (19 FQCN), agrupa condiciones AND/OR con builder visual, encadena acciones (1..N), guarda en modo `test`, ve el badge morado "Modo test", pulsa "Simular ahora" en cada acción, ve el payload simulado en `response_json`, promueve a `live` cuando está conforme. |
| **Supervisor** (rol con `automations.view` + `automations.test`) | Diagnosticar por qué falló una ejecución, identificar re-entry / cycle-breaks, validar que la regla está bien armada antes de que el admin la promueva a `live`. | Abre `admin.automations.show` → ve historial paginado, filtra por estado (`failed`/`partial`/`circuit-broken`) y fecha, abre una ejecución → ve los pasos con su `response_json`, ve el `idempotency_key` monospace con botón copiar. |
| **Support agent** (rol con `automations.view`) | Responder al cliente "por qué no se creó la actividad / no se asignó", sin pedirle al admin que entre a la DB. | Mismo flujo que el supervisor pero sólo lectura; el gate `automations.manage` lo bloquea en cualquier acción de escritura y devuelve `403`. |
| **Operaciones (CLI / futuro B13/B14)** | No entra a la UI — usa `automation:emit-activity-overdue`, `automation:emit-quotation-will-expire`, `automation:emit-customer-idle`, `automation:dispatch-due-steps`, `automation:reconcile-failed-steps` (`explore.md` §1.7). B12-UI **no expone** ninguno de estos comandos. |

Los tres flujos tocan las rutas `admin.automations.index` / `show` / `executions.show` que ya existen y los nuevos endpoints `create`, `store`, `edit`, `update`, `destroy`, `restore`, `reorder`, `simulate` que se registrarán dentro del mismo grupo `auth`/`admin` en `routes/web.php`.

---

## 4. Reglas de negocio y permisos

El catálogo `automations.*` se registra en `AutomationServiceProvider::registerAutomationPermissions()` al boot (no por seeder — `explore.md` §8 punto 11). B12-UI **enforces** las 5 en los lugares listados:

| Permiso Spatie | UI action | Página / componente | Comportamiento |
|---|---|---|---|
| `automations.view` | Listar reglas, ver regla, ver ejecución, ver papelera, filtrar historial. | `admin.automations.index`, `show`, `executions.show`, papelera. | Ya enforced por `Gate::authorize('automations.view')` en el controller actual; B12-UI lo mantiene y lo replica en los nuevos métodos read-only. |
| `automations.manage` | Crear, clonar, editar (`name`, `description`, `trigger_event`, `is_active`, `order`, `mode`, `owner_id`, condition groups, conditions, actions), activar/desactivar, drag-to-reorder, soft-delete, restaurar desde papelera. | `create` / `store` / `edit` / `update` / `destroy` / `restore` / `reorder`. | Sin este permiso, los botones "Editar", "Clonar", "Mover", "Papelera", "Restaurar" no se renderizan (`@can('automations.manage')`) y la server-side devuelve `403`. |
| `automations.test` | Botón "Simular ahora" por acción (`ActionContract::simulate()`); switch a `mode='test'` desde la edición. | Editor de acciones en el Livewire form; badge morado "Modo test: simuló, no ejecutó acciones reales" (`explore.md` §8 punto 1 y decisión 10). | El botón llama al motor síncronamente y renderiza el `response_json`; sin permiso, el botón se oculta y el endpoint devuelve `403`. |
| `automations.audit` | Ver la pestaña / bloque de `Spatie\Activitylog` dentro del detalle de la regla (cambios a la regla listados ahí, **no** en `audit.view` global — decisión 9). | `admin.automations.show` → sección "Cambios". | Sin permiso, la sección no se renderiza. B12-UI no introduce `audit.view`; respeta la decisión de producto de mantener la auditoría contextual dentro del módulo de automatizaciones. |
| `automations.webhook.execute` | **Reservado / no usado en v1**. | — | Permiso registrado por el provider; ningún endpoint B12-UI lo enforza todavía. Decisión 8: no hay trigger manual desde UI; queda para un eventual "replay" futuro que está fuera del scope v1 (ver §8 No-goals). |

Reglas de dominio adicionales que la UI debe reflejar (no son permisos pero acotan comportamiento):

1. **Modo `test` nunca encola `RunAutomationAction`** — el listener marca cada paso como `simulated` y la ejecución termina como `success` (`explore.md` §3). La UI debe etiquetarlo claramente para no engañar al admin.
2. **`idempotency_key` es diagnóstico, no editable** — la columna es UNIQUE en `automation_executions` (`explore.md` §3). La UI la muestra monospace + copy-to-clipboard (decisión 12).
3. **`recipient_strategy` debe estar sincronizado entre la columna `automation_actions.recipient_strategy` y la key `payload_json.recipient_strategy`** porque `AssignOwnerAction` lee ambos (`explore.md` §8 punto 13). La UI los trata como un único control.
4. **`payload_json` se valida por tipo de acción antes de guardar** — el motor sólo lanza `InvalidArgumentException` al ejecutar (`explore.md` §7). La UI hace la validación tempranamente para no acumular errores en historial.

---

## 5. Resultado esperado (outcome)

- **T1 — Autoría end-to-end sin código**: un admin puede crear una regla `trigger_event=App\Events\V2\LeadCreated`, con 1 grupo AND de 2 condiciones y 1 acción `assign_owner`, **sin escribir JSON ni SQL**, en menos de 5 minutos desde que abre `admin.automations.create`. (Medible: tiempo desde GET `/admin/automations/create` hasta POST `/admin/automations` exitoso en sesión de prueba.)
- **T2 — Primera regla creada vía UI en producción dentro de la primera semana post-deploy**. El usuario no necesita esperar a que operaciones corra un seeder.
- **T3 — Diagnóstico sin DB**: un supervisor responde a un ticket de soporte abriendo `admin.automations.executions.show`, filtrando por `status=failed` y por rango de fechas, y copiando el `idempotency_key` para pegarlo en el bug-report — todo sin abrir un cliente SQL.
- **T4 — Papelera sin escalación a ingeniería**: una regla enviada a papelera por error se restaura desde la pestaña "Papelera" sin intervención de operaciones (decisión 7).
- **T5 — Simulación previa al promote**: antes de cambiar una regla de `test` a `live`, el admin pulsa "Simular ahora" en cada acción y ve el payload que el motor habría enviado; si la simulación lanza `WebhookNotAuthorizedException` o `NotImplementedException`, lo ve en pantalla sin ensuciar el historial de ejecuciones reales.
- **T6 — `DataScope` honrado**: ningún admin puede asignar `assign_owner` a un usuario fuera de `DataScopeService::visibleOwnerIds($creator)`. La UI pre-filtra los pickers de user/team (`explore.md` §7 + §8 punto 5) — el bug operator-precedence de `AssignOwnerAction::execute` queda compensado en UI hasta que el motor lo arregle.

---

## 6. Brecha actual

- **Routes placeholder**: `routes/web.php` líneas 375–380 sólo declara `index`, `show`, `showExecution`. Falta toda acción de escritura (`create`, `store`, `edit`, `update`, `destroy`, `restore`, `reorder`, `simulate`).
- **Controller thin**: `app/Http/Controllers/Admin/AutomationController.php` autoriza `automations.view` y punto. Ningún otro permiso se enforce.
- **Vistas placeholder**: `resources/views/admin/automations/{index,show,execution}.blade.php` suman 166 líneas (51/57/58 — `explore.md` §2.3). Sólo leen `id`, `name`, `mode`, `executions_count`, `steps`, `subject_type`. No hay formularios, no hay modales reactivos, no hay drag-to-reorder.
- **Sin Livewire**: `app/Livewire` y `resources/views/livewire` no existen todavía (`explore.md` §6). B12-UI es el primer bloque en introducir componentes Livewire 4 (`composer.json` pin `livewire/livewire: ^4.4`).
- **Sidebar ya enlazado pero muerto en cuanto a profundidad**: `resources/views/layouts/partials/sidebar.blade.php` línea ~92 ya tiene `<a href="admin.automations.index">` bajo `@can('automations.view')`, así que la entrada no necesita tocarse — pero los hijos (Editar / Papelera) no existen.
- **Permisos registrados sin enforcement**: `automations.manage`, `automations.test`, `automations.audit`, `automations.webhook.execute` existen en el provider pero no se chequean en ningún controlador, vista ni middleware (`explore.md` §2.2 + §8 punto 11).
- **Stubs sin señal visual**: `WebhookAction` y `SendWhatsAppTemplateAction` lanzan `NotImplementedException` (`explore.md` §1.5); en la UI placeholder no hay manera de saberlo sin leer el código.
- **Webhook allow-list invisible**: `config('integrations.webhooks.allowed_destinations')` (`explore.md` §8 punto 4) no se surface en ningún formulario — el admin puede tipear una URL y descubirlo sólo cuando la ejecución reviente.

---

## 7. Scope del primer slice (B12-UI v1)

Incluye:

1. **CRUD completo de reglas** vía Livewire 4 con persistencia directa en `automation_rules` (campos: `name`, `description`, `trigger_event`, `is_active`, `order`, `mode`, `owner_id`, más `created_by` auto-asignado a `auth()->id()`).
2. **Clone de regla** (duplica regla + condition groups + conditions + actions con nuevos `id`).
3. **Activate/deactivate** inline en el index (toggle de `is_active` con gate `automations.manage`).
4. **Drag-to-reorder** que persiste `order` con `automations.manage`. **Sin bulk ops** (decisión 6).
5. **Soft-delete + Papelera** (decisión 7): la pestaña "Papelera" lista reglas con `deleted_at` no nulo; "Restaurar" hace `restore()` y vuelve al index por defecto. Las reglas en papelera **no** aparecen en el index por defecto.
6. **Builder visual de condiciones AND/OR** sobre `automation_condition_groups` + `automation_conditions`. Operadores dropdown desde `ConditionOperator::values()` (16 — `explore.md` §1.2). `value_type` se infiere del valor salvo override explícito. Autocompletado del `field` desde el payload del trigger elegido (fuente: `$event::payload()` como referencia estática; para time-driven, exponer los campos contextuales — `explore.md` §5.3).
7. **Formularios por tipo de acción** (decisión 3). Para los 11 tipos: `assign_owner` con pickers filtrados por `DataScopeService::visibleOwnerIds($creator)`; `change_status`/`change_stage` con selectores subject-aware (`LeadStatus`, `Customer.status` enum, `PipelineStage`); `add_tag` con checkbox "crear si no existe"; `create_activity`/`create_follow_up_activity` con selector de `ActivityType` y, en el segundo, `next_scheduled_at` obligatorio; `send_notification` con `level ∈ info|warning|error`; `send_email` con toggle `queue`; `webhook` con dropdown desde `config('integrations.webhooks.allowed_destinations')`; `send_whatsapp_template` con `account_id` y variables como `key=value` repetibles.
8. **Stubs claramente etiquetados** (decisión 3 + §8 punto 6): los formularios de `webhook` y `send_whatsapp_template` muestran un banner "Pendiente (B14) — la acción fallará con `NotImplementedException` hasta B14".
9. **Simulate-now por acción** (decisión 4): botón "Simular ahora" por acción que llama `ActionContract::simulate($payload)` y muestra el `response_json` en un modal. Gate `automations.test`.
10. **Badge de modo test** morado con tooltip "Modo test: simuló, no ejecutó acciones reales" (decisión 10).
11. **Historial filtrable** (decisión 5): `admin.automations.show` lista ejecuciones paginadas con filtros `status`, `date_from`/`date_to`, y `subject_type`/`subject_id` (search libre sobre `subject_type` + `id`).
12. **Detalle de ejecución** con: pasos (`steps.action`), `response_json` por paso en monospace, `idempotency_key` monospace con copy-to-clipboard (decisión 12), `error_class` + `error_message` cuando `status ∈ {failed, partial, circuit-broken}`.
13. **Audit contextual** (decisión 9): bloque "Cambios" en `admin.automations.show` que lista entradas `Spatie\Activitylog` para la regla; gate `automations.audit`.
14. **`retry_policy_json` oculto** (decisión 11) — la columna existe (`explore.md` §4) pero el motor no la lee; no se renderiza el campo hasta que el engine lo respete.
15. **Gates server-side en cada endpoint nuevo** + botones renderizados bajo `@can(...)`.

Fuera de scope (ver §8).

---

## 8. No-goals (explícitos)

- **Bulk operations** sobre reglas (activate/deactivate en masa, reorden masivo, delete masivo). Decisión 6.
- **Replay de ejecuciones fallidas** desde la UI. Decisión 5: history es read-only.
- **Manual trigger / event emission** desde la UI. Decisión 8: los `automation:emit-*` y `automation:dispatch-due-steps` siguen siendo operacionales vía CLI.
- **Edición de `retry_policy_json`** desde la UI. Decisión 11: la columna está reservada pero el motor no la lee; el campo se oculta.
- **Schedule preview** ("cuándo va a correr esta regla time-driven"). El listener es event-driven y los time-driven vienen de comandos programados — no hay tabla de cron a editar.
- **Inbound webhook trigger**. El motor sólo expone `WebhookAction` saliente (`explore.md` §8 punto 10); no hay listener de webhooks entrantes.
- **Editor de `idempotency_key`**. La columna es UNIQUE y la fórmula `sha1(rule_id|event_class|subject_type|subject_id|payload_hash)` es del motor (`explore.md` §3 + §8 punto 2).
- **Migraciones / cambios al schema B12**. Las 7 tablas y sus indexes (incluido UNIQUE `idempotency_key`) son authoritative bajo `database/migrations/2026_08_18_0100{00..60}_*.php` (`explore.md` §3). B12-UI sólo escribe a través de los modelos existentes.
- **`audit.view` global**. La auditoría es contextual dentro de `admin.automations.show` (decisión 9); no se reutiliza la vista global de `audit.view`.
- **Edición del `trigger_event` a nivel catálogo**. La lista de 19 eventos vive en `AutomationServiceProvider::TRIGGER_EVENTS` (`explore.md` §7) — el form expone esa lista fija, no la modifica.
- **Editor JSON crudo como alternativa principal**. El form es por-tipo; sólo se cae a JSON si el admin edita un `payload_json` de un tipo sin form dedicado (futuro). En v1 los 11 tipos tienen form dedicado.
- **Internacionalización del catálogo de triggers / acciones**. Las labels vienen de `class_basename($fqcn)` y de `AutomationExecutionStatus::label()` / `AutomationStepStatus::label()` en español (`explore.md` §7). No se introduce i18n stack en v1.
- **Fix del bug operator-precedence de `AssignOwnerAction::execute`** (`explore.md` §8 punto 5). B12-UI compensa en UI; el fix de engine queda como ticket independiente.

---

## 9. Edge cases que la UI debe resolver

1. **Sin reglas** (`automation_rules` vacío o todas filtradas): empty state con CTA "Crear primera regla" gated por `automations.manage`.
2. **Sin ejecuciones** (`AutomationRule::executions_count === 0`): empty state "Aún no hay ejecuciones registradas para esta regla" en `show`.
3. **Papelera vacía**: empty state específico "No hay reglas en papelera".
4. **`automations.manage` ausente**: el admin con sólo `view` ve los botones de escritura ocultos; click en URL directa de `edit`/`store` devuelve `403`. B12-UI no degrada a "modo lectura" — responde el gate.
5. **`automations.test` ausente**: el botón "Simular ahora" no se renderiza; el endpoint dedicado devuelve `403`.
6. **Restore cycle break**: una regla en papelera puede tener `conditionGroups`, `conditions` y `actions` activos; el restore debe respetar `SoftDeletes` en cascada (si alguno de los hijos lo usara) y re-vincular `rule_id` correctamente. Si un FK quedó huérfana por un delete manual en DB, el `restore()` falla con FK violation — la UI muestra el error sin corromper otras reglas.
7. **`idempotency_key` collision visible**: dos eventos concurrentes sobre el mismo `(rule, subject)` con el mismo payload → la segunda inserción revienta por UNIQUE y el listener la swallowea (`explore.md` §3). El admin ve **una sola** ejecución en el historial; la segunda no aparece nunca, ni siquiera como "duplicate". B12-UI no expone la race window porque ya está mitigada por el motor; pero **debe documentar** este comportamiento en la ayuda contextual del detalle.
8. **Race window de cycle-break visible**: si dos eventos del mismo `(rule, subject)` entran dentro de 30 s (`CycleDetector::DEFAULT_WINDOW_SECONDS` — `explore.md` §3), el segundo queda `status=circuit-broken` y crea una fila en `automation_cycle_breaks`. La UI muestra la fila con badge específico (`AutomationExecutionStatus::label()` ya tiene la traducción al español — `explore.md` §1.2) y tooltip "Re-entry detectado dentro de 30 s".
9. **Trigger eliminado del catálogo**: si una regla guardada referencia un `trigger_event` que ya no está en `AutomationServiceProvider::TRIGGER_EVENTS` (futuro refactor), el form debe permitir verla y deshabilitar el guardado con un error claro, no crashear.
10. **`AssignOwnerAction` con target fuera de `DataScope`**: la UI **previene** elegirlo porque pre-filtra con `DataScopeService::visibleOwnerIds($creator)`. Si igualmente llega un payload inválido (por ejemplo porque cambió el scope entre save y execute), la ejecución queda `failed` con `error_class` legible y la UI lo muestra en el detalle sin engañar al admin.
11. **Webhook URL fuera del allow-list**: `WebhookAction::isAuthorized` lanza `WebhookNotAuthorizedException` (`explore.md` §8 punto 4). La UI previene el error porque el dropdown sólo ofrece URLs de `config('integrations.webhooks.allowed_destinations')`; si la config se vacía después del save, la ejecución falla y se ve como `failed`.
12. **Stubs (`webhook`, `send_whatsapp_template`)**: la UI advierte visualmente antes de guardar y permite guardar igual; la ejecución revienta con `NotImplementedException` y el detalle lo muestra.
13. **Soft-delete + trigger activo**: el listener ya filtra por `scope active()` (`explore.md` §5.2) — reglas con `is_active=false` o en papelera **no** disparan. La UI lo refleja en la ayuda del toggle "Activa".
14. **`mode='test'` con acciones que mutan**: el listener nunca encola `RunAutomationAction` en modo test, pero las condiciones y groups siguen evaluándose (`explore.md` §8 punto 1). B12-UI debe dejar claro que el badge morado significa "no se ejecutó nada real", no "se ejecutó algo seguro".

---

## 10. Decisiones de producto tomadas (locked)

Las 12 decisiones fueron firmadas por el usuario en dos rondas previas; este proposal las **re-state** y las refleja en el scope (§7), los no-goals (§8) y los edge cases (§9). **No se re-abren.**

### Ronda 1 — 4 decisiones explícitas

1. **CRUD completo** (create, clone, edit, activate/deactivate, trash, drag-to-reorder, dry-run preview). Campos editables: `name`, `description`, `trigger_event`, `is_active`, `mode`, `order`, `owner_id`, `conditions` (builder), `actions` (por tipo). → Razón: cubre todo el ciclo de vida de una regla desde la UI.
2. **Builder UI completo AND/OR** sobre `automation_condition_groups` + `automation_conditions`. Operadores desde `ConditionOperator::values()` (16); `value_type` por campo. → Razón: no obligar al admin a escribir SQL/JSON.
3. **Forms per-type para los 11 action types**. Stubs `webhook` y `send_whatsapp_template` etiquetados "Pendiente (B14)". → Razón: el motor ya tiene schema por tipo en PHPDoc (`explore.md` §1.5) — la UI lo refleja.
4. **Simulate-now por acción** vía `ActionContract::simulate()`, gated con `automations.test`. → Razón: el permiso ya estaba registrado y el método existe — sólo faltaba la UI.

### Ronda 2 — 8 assumptions firmadas

1. **History read-only con filtros + search** (status, date, subject). NO replay, NO manual trigger. → Razón: el motor ya graba `AutomationExecution` y `AutomationExecutionStep` con suficiente telemetría; la UI sólo necesita leerla.
2. **Bulk operations NOT in v1**. Drag-to-reorder por regla. → Razón: minimiza superficie de error en la primera iteración.
3. **Soft-delete UX con pestaña "Papelera"** + "Restaurar". Deleted rules no aparecen en index por defecto. → Razón: alineado con el patrón de papelera que ya usa el módulo de roles (referencia `resources/views/admin/roles/index.blade.php` — `explore.md` §2.4).
4. **Manual event emission NOT exposed**. `automation:emit-*` siguen operacionales. → Razón: separar UI de operator-only tooling; reduce permisos y blast radius.
5. **Audit en la vista de detalle** vía `Spatie\Activitylog`. NO se reusa `audit.view` global. → Razón: mantener la auditoría contextual al módulo, no contaminar el audit log global del B10.
6. **Test-mode badge morado** con tooltip "Modo test: simuló, no ejecutó acciones reales". → Razón: contraste fuerte contra el verde de `live` y el rojo de fallo; copy literal para no engañar al admin.
7. **Retry override oculto** hasta que `retry_policy_json` sea leído por el engine. → Razón: no exponer knobs muertos (decisión "dead UI surfaces" — `explore.md` §4).
8. **`idempotency_key` visible** en execution detail como diagnóstico, monospace + copy-to-clipboard. → Razón: la columna es UNIQUE y útil para correlación con logs externos; no es editable.

---

## 11. Riesgos y rollback

### Riesgos de producto

- **R1 — Drift del catálogo de triggers**: `AutomationServiceProvider::TRIGGER_EVENTS` puede crecer en B13/B14. La UI debe tratar la lista como fuente de verdad y degradar con mensaje claro si una regla guardada referencia un trigger ya retirado (edge case §9 punto 9).
- **R2 — Stubs `webhook` y `send_whatsapp_template` etiquetados pero ejecutables**: si el admin los guarda y los deja `is_active=true`, las ejecuciones reales revientan y llenan el historial de `failed`. Mitigación: el banner "Pendiente (B14)" en el form + tooltip en el index; ningún otro guardrail se aplica en v1.
- **R3 — `AssignOwnerAction` operator-precedence bug**: el check `DataScope` en el motor es dead code (`explore.md` §8 punto 5). Si B12-UI filtra en UI pero operaciones carga un payload pre-construido que bypassea la UI (futuro B13), la ejecución falla con `error_class` no amigable. Mitigación: la UI pre-filtra + el motor registra `error_class=InvalidArgumentException` y la UI lo muestra.
- **R4 — `retry_policy_json` visible en JSON crudo vía API pero oculto en UI**: si un power-user exporta reglas, el campo está presente. Aceptado: la columna existe y la UI no la inventa.
- **R5 — Concurrencia sobre `order`**: el drag-to-reorder persiste `order` con `automations.manage`. Dos admins reordenando a la vez pueden pisarse (last-write-wins). Mitigación: aceptable para v1; el motor evalúa `ordered()` por `id` como tie-breaker (`explore.md` §5.2) así que ninguna regla queda huérfana del orden.

### Dependencias futuras

- **B13 (email template catalog)**: `SendEmailAction` actualmente usa `Mail::raw` / `Mail::queue` con `subject` y `body` literales (`explore.md` §1.5). B13 introducirá el catálogo y el form de B12-UI quedará como UI legacy hasta que se actualice. No bloquea B12-UI v1.
- **B14 (webhook + WhatsApp)**: los stubs saldrán de stubs en B14. Hasta entonces, los banners "Pendiente (B14)" siguen siendo la verdad visible.
- **B13/B14 pueden introducir nuevos action types**: el form de B12-UI debe seguir el patrón `ActionRegistry::registered()` (`explore.md` §7) para que nuevos tipos se rendericen automáticamente sin tocar el componente.

### Rollback path

- **Borrar el change** (`openspec/changes/b12-ui/` + `sdds/sdd-proposal-b12-ui.md`).
- **Revertir las rutas nuevas** registradas en `routes/web.php` (las tres originales placeholder deben quedar intactas).
- **Revertir los permisos**: como `AutomationServiceProvider::registerAutomationPermissions()` corre al boot y registra los 5 permisos (`automations.view`, `automations.manage`, `automations.test`, `automations.webhook.execute`, `automations.audit`), basta con **no deployar** el provider modificado o restaurar el provider original; los 4 permisos no-enforced vuelven a su estado registrado-sin-uso, idéntico al pre-B12-UI.
- **Revertir las vistas** bajo `resources/views/admin/automations/` y `resources/views/livewire/` (si se introdujeron).
- **Revertir la entrada de sidebar**: si B12-UI modificara el sidebar (no es necesario — `explore.md` §6 confirma que el link ya existe), restaurar `resources/views/layouts/partials/sidebar.blade.php`.
- **No tocar**: las 7 tablas `automation_*` y sus migraciones (`database/migrations/2026_08_18_0100{00..60}_*.php` — `explore.md` §3). El motor y sus contratos son out-of-scope para rollback.

El rollback es **transaccional**: ningún DDL, ningún cambio de contrato del engine, ningún cambio en seeders. Revertir las rutas, vistas y permisos es suficiente para volver al estado placeholder.

---

## 12. Criterios de éxito (V1 acceptance bar)

Estos son los criterios que sdd-verify debe poder marcar `passed`. **No son tests** — son condiciones de aceptación de producto que el slice debe satisfacer.

- **AC-1 — Regla mínima autorable vía UI**: un admin con `automations.manage` puede crear una regla con `trigger_event=App\Events\V2\LeadCreated`, 1 grupo AND de 1 condición (`field=status_id`, `operator=eq`, `value=2`), y 1 acción `assign_owner` apuntando a un usuario visible por `DataScopeService::visibleOwnerIds($creator)`, todo desde la UI sin escribir JSON. La regla queda en `automation_rules` con sus filas hijas en `automation_condition_groups`, `automation_conditions` y `automation_actions`.
- **AC-2 — Ejecución real disparada y observable en historial**: al emitirse `LeadCreated` con payload compatible, el listener (`DispatchAutomationRule`) crea `AutomationExecution`, la UI la lista en `admin.automations.show` con su `status` correcto, y al abrir el detalle se ven los pasos con `response_json` (vacío en live, poblado en test).
- **AC-3 — Simulate preview retorna el would-be payload**: el botón "Simular ahora" sobre la acción `assign_owner` de AC-1, con un payload de prueba, llama `AssignOwnerAction::simulate()` y muestra el array resultado en un modal; sin permiso `automations.test`, el botón no se renderiza y el endpoint devuelve `403`.
- **AC-4 — Papelera restaura regla soft-deleted**: una regla enviada a papelera desde `admin.automations.index` desaparece del index por defecto, aparece en la pestaña "Papelera", y al pulsar "Restaurar" vuelve al index con todas sus condition groups, conditions y actions intactas.
- **AC-5 — UI honra `DataScope`**: el dropdown de users de la acción `assign_owner` excluye cualquier user que no esté en `DataScopeService::visibleOwnerIds($rule->created_by)`. Verificable intentando asignar a un user fuera del scope — el usuario no aparece en el picker.
- **AC-6 — Stubs etiquetados**: los formularios de `webhook` y `send_whatsapp_template` muestran el banner "Pendiente (B14)" antes de guardar; el índice los marca visualmente distinto.
- **AC-7 — Test-mode badge morado**: una regla guardada con `mode='test'` muestra el badge morado con tooltip exacto "Modo test: simuló, no ejecutó acciones reales" en `index`, `show` y los listados de ejecuciones.
- **AC-8 — Idempotency key visible y copiable**: el detalle de ejecución muestra `idempotency_key` en monospace + botón "Copiar"; el valor es el mismo string que vive en `automation_executions.idempotency_key`.
- **AC-9 — Audit contextual gated**: un usuario con `automations.audit` ve el bloque "Cambios" en `admin.automations.show`; sin ese permiso, el bloque no se renderiza (verificable con dos usuarios de prueba).
- **AC-10 — Retry override oculto**: ningún formulario de B12-UI v1 expone `retry_policy_json`; verificable con grep sobre las vistas.
- **AC-11 — Reorder funciona**: el drag-to-reorder persiste `order` en `automation_rules` y el índice reordena sin recarga (`Livewire` 4 + `#[On]` / `wire:sort`).
- **AC-12 — Bulk ops NO presentes**: ningún botón de activación/desactivación/reorden/delete masivo en el index; verificable con grep sobre las vistas.

---

## Quick cross-reference

- **Upstream explore**: `openspec/changes/b12-ui/explore.md` (35.6 KB) — engine surface, placeholder admin, data model, action surface, triggers, Livewire conventions, reusable services, 15 gotchas, 12 product-round questions (resueltas en §10 de este proposal).
- **Project context**: `openspec/config.yaml` — Laravel 13.25, PHP 8.3.16, Livewire 4, Spatie Permission + Activitylog, AdminLTE/Bootstrap 5, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`.
- **Engine service provider**: `app/Providers/AutomationServiceProvider.php` — registra `ActionRegistry`, `ACTION_TYPES`, `TRIGGER_EVENTS`, y las 5 `automations.*` permissions al boot.
- **Engine test**: `tests/Feature/AutomationEngineTest.php` (10 tests, 21 assertions, pasa con `php artisan test --filter=AutomationEngineTest`).
- **Routes placeholder**: `routes/web.php` líneas 375–380.
- **Sidebar ya enlazado**: `resources/views/layouts/partials/sidebar.blade.php` línea ~92 bajo `@can('automations.view')`.
- **Vistas placeholder**: `resources/views/admin/automations/{index,show,execution}.blade.php` (51/57/58 líneas).
- **Componentes UI reutilizables**: `resources/views/components/{table,text-input,select,modal,alert,label,badge-status,validation-error}.blade.php`.
- **Roadmap V2**: `docs/v2/01-roadmap.md` §1.3 menciona `Cache::lock` para race window como follow-up (B12-UI no lo aborda).
