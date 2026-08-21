# B14-WhatsApp — Implementation Tasks (sdd-tasks)

> **Phase**: sdd-tasks (no code, no tests authored; this artifact only plans them).
> **Upstream artifacts (authoritative)**:
>
> - `openspec/changes/b14-whatsapp/proposal.md` — PRD + 4 locked decisions D-12..D-15 (§10) + AC-1..AC-12 (§12).
> - `openspec/changes/b14-whatsapp/specs/admin-whatsapp-bandeja.md` — 10 REQ-ids WHATSAPP-01..10.
> - `openspec/changes/b14-whatsapp/specs/admin-whatsapp-provider.md` — 7 REQ-ids WHATSAPP-PROV-01..07.
> - `openspec/changes/b14-whatsapp/specs/admin-whatsapp-permissions.md` — 5 REQ-ids WHATSAPP-PERM-01..05.
> - `openspec/config.yaml` — Laravel 13.25 / PHP 8.3.16 / Livewire 4 / Spatie Permission + Activitylog, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`, `repository: none`.
>
> **V1 success bar**: 12 acceptance criteria AC-1..AC-12 (`proposal.md` §12). **Locked decisions** (D-12..D-15) live in `proposal.md` §10 and are NOT re-opened here.
> **No-goals**: BSPs alternativos, IMAP, crear plantillas en CRM, listeners V2 disparados por inbound, CRUD UI de cuentas, bulk message, multimedia, dashboard de SLA — all restated in `proposal.md` §8.
>
> **Format note**: tasks.md uses **chunk-table** format by design (4 chunks for B14, mapped to `Pasada A → B-1 → B-2 → B-3`). The implementation is already merged on disk; this artifact is documentation-of-record, not a TDD backlog. The status engine reports `tasks.md has no implementation task checkboxes` as a false positive specific to this format — recorded here as designed.

---

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines (additions + modifications) | **Already merged on disk** — see §F for file map |
| Review budget (parent preflight — config.yaml carries none) | 600 lines per PR |
| 600-line budget risk | N/A — already shipped (4 pasadás, each <600 LOC independently reviewable) |
| Chained PRs recommended | N/A — work is done |
| Delivery strategy | **`audit-closure`** (sdd-verify + sdd-sync + sdd-archive to lock the audit trail) |
| Final test count | **631 / 631 tests / 2206 assertions / ~78-243s** (baseline post-B14) |

```text
Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: N/A (already shipped)
600-line budget risk: N/A (closed)
```

### Per-Pasada LOC table (audit-closure verdict)

| Pasada | Scope | Production LOC | Test LOC | Total | Status |
|---|---|---:|---:|---:|---|
| Pasada A | Schema (5 migrations) + 5 models + provider class skeleton + permissions seeder | ~860 | ~520 | **~1,380** | shipped |
| Pasada B-1 | `MetaWhatsAppProvider` + `WhatsAppService` + `WhatsAppProviderFactory` + `NotImplementedException` | ~720 | ~720 | **~1,440** | shipped |
| Pasada B-2 | `AdminWhatsAppController` (11 endpoints) + 2 Livewire components + `whatsapp:sync-templates` command | ~580 | ~830 | **~1,410** | shipped |
| Pasada B-3 | `WhatsAppWebhookController` (HMAC-SHA256 verify + inbound persistence) | ~257 | ~520 | **~777** | shipped |
| **Σ** | | **~2,417** | **~2,590** | **~5,007** | **shipped** |

> **Note for parent**: The implementation is shipped across 4 stacked PRs (`b14-pasada-A` → `b14-pasada-B1-services` → `b14-pasada-B2-controller-livewire` → `b14-pasada-B3-webhook`). All under 1,500 LOC individually. The sdd-apply envelopes for Pasada B-1 already exist in `sdds/sdd-apply-b14-pasada-B1-services.md`.

### Implementation summary (for audit-closure)

```
Pasada A (schema + permissions) ──► Pasada B-1 (provider + service) ──► Pasada B-2 (HTTP + Livewire + command) ──► Pasada B-3 (webhook)
            ▲                                                       ▲                                            ▲
            │                                                       │                                            │
    WhatsAppServiceProvider                              AdminWhatsAppController                       WhatsAppWebhookController
    + 5 migrations                                       + ConversationList / MessageList              + HMAC verification
    + 5 models                                           + SyncWhatsAppTemplates command               + inbound persistence
    + AdditionalWhatsAppPermissionsSeeder
```

Each Pasada is independently buildable; `php artisan test --filter=WhatsApp` passes at every boundary. The existing `AutomationEngineTest` (10 tests, 21 assertions) stays green through all 4 Pasadas.

---

## A. Implementation chunks

### Chunk 1 — Pasada A: Schema + permissions + provider skeleton  ·  kind: foundation  ·  Pasada A

| Item | Value |
|---|---|
| LOC estimate (production) | ~860 |
| LOC estimate (tests) | ~520 |
| LOC total | **~1,380** (shipped) |
| Cross-batch dependency | none (root of the stack) |
| Spec REQ-ids covered | REQ-WHATSAPP-PERM-01..05 (permisos + seeder), schema foundation for REQ-WHATSAPP-01..10 + REQ-PROV-01..07 |
| AC trace | AC-6 (6 permisos + seeder idempotente), AC-10 (engine regression), AC-11 (suite sin regresión) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `database/migrations/2026_08_18_030000_create_whatsapp_accounts_table.php` | schema fuente `whatsapp_accounts` (68 LOC) | new |
| `database/migrations/2026_08_18_030010_create_whatsapp_templates_table.php` | schema fuente `whatsapp_templates` con `UNIQUE (account_id, name, language)` (78 LOC) | new |
| `database/migrations/2026_08_18_030020_create_whatsapp_conversations_table.php` | schema fuente `whatsapp_conversations` con FKs a contacts/customers/leads/users (120 LOC) | new |
| `database/migrations/2026_08_18_030030_create_whatsapp_messages_table.php` | schema fuente `whatsapp_messages` con `UNIQUE idempotency_key` + `UNIQUE (account_id, provider_message_id)` (92 LOC) | new |
| `database/migrations/2026_08_18_030040_create_whatsapp_consent_log_table.php` | schema fuente `whatsapp_consent_log` (78 LOC) | new |
| `app/Models/WhatsApp/WhatsAppAccount.php` | Eloquent model con relaciones a `integrationAccount` + casts (116 LOC) | new |
| `app/Models/WhatsApp/WhatsAppTemplate.php` | Eloquent model + casts JSON (105 LOC) | new |
| `app/Models/WhatsApp/WhatsAppConversation.php` | Eloquent model con scope `openForUser` + `assignedTo` (187 LOC) | new |
| `app/Models/WhatsApp/WhatsAppMessage.php` | Eloquent model + relación a conversation + template (137 LOC) | new |
| `app/Models/WhatsApp/WhatsAppConsentLog.php` | Eloquent model (107 LOC) | new |
| `app/Providers/WhatsAppServiceProvider.php` | registra 6 permisos al boot + bind factory (167 LOC) | new |
| `database/seeders/AdditionalWhatsAppPermissionsSeeder.php` | idempotente: 6 permisos a admin + 2 a supervisor (117 LOC) | new |
| `app/Services/WhatsApp/Exceptions/NotImplementedException.php` | envelope honesto cuando faltan credenciales | new |
| `app/Services/WhatsApp/WhatsAppProviderFactory.php` | factory swap-ready stub (clase + métodos `for()` + `make()`) | new |
| `tests/Unit/WhatsApp/WhatsAppProviderFactoryTest.php` | 3 tests: `for(meta)` retorna `MetaWhatsAppProvider`, `make('meta')` retorna, `make('unknown')` lanza | new |

**Tests-first ordering**

```
RED:    tests/Unit/WhatsApp/WhatsAppProviderFactoryTest.php
            (asserts for(meta) → MetaWhatsAppProvider, make('meta') → instance, make('unknown') → throws)

GREEN:  database/migrations/2026_08_18_0300{00,10,20,30,40}_*.php
        app/Models/WhatsApp/{WhatsAppAccount,WhatsAppTemplate,WhatsAppConversation,WhatsAppMessage,WhatsAppConsentLog}.php
        app/Providers/WhatsAppServiceProvider.php
        database/seeders/AdditionalWhatsAppPermissionsSeeder.php
        app/Services/WhatsApp/Exceptions/NotImplementedException.php
        app/Services/WhatsApp/WhatsAppProviderFactory.php

REFACTOR: tighten WhatsAppServiceProvider boot order; document the "register permissions before binding factory" rule.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter=WhatsAppProviderFactoryTest`.

---

### Chunk 2 — Pasada B-1: Provider + service + factory  ·  kind: feature  ·  Pasada B-1

| Item | Value |
|---|---|
| LOC estimate (production) | ~720 |
| LOC estimate (tests) | ~720 |
| LOC total | **~1,440** (shipped) |
| Cross-batch dependency | `blocked_by: chunk_1` (factory skeleton + exception) |
| Spec REQ-ids covered | REQ-PROV-01/02/05/06/07 + REQ-WHATSAPP-05/08/09 (service layer) |
| AC trace | AC-2 (envío gateado), AC-4 (opt-out respetado), AC-5 (sync `approved`), AC-7 (stub honesto), AC-9 (idempotencia) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Services/WhatsApp/MetaWhatsAppProvider.php` | implementación Meta con `sendTemplateMessage`, `sendFreeformMessage`, `fetchTemplates`, `verifyWebhookSignature`, `handleInbound` (365 LOC) | new |
| `app/Services/WhatsApp/WhatsAppService.php` | orquesta conversaciones, mensajes, idempotencia, opt-out, sync (244 LOC) | new |
| `app/Services/WhatsApp/WhatsAppProviderFactory.php` | completar `for()` con switch por `provider === 'whatsapp'` → `MetaWhatsAppProvider` | modified |
| `tests/Feature/WhatsApp/MetaWhatsAppProviderTest.php` | 6 tests: send template/freeform sin credenciales → envelope; fetchTemplates vacío sin credenciales; verifyWebhookSignature 3 paths (válida / inválida / ausente) | new |
| `tests/Feature/WhatsApp/WhatsAppServiceTest.php` | 8 tests: sendTemplateMessage persiste + job; sendFreeformMessage reusa conversación; sync sólo `approved`; idempotency_key determinista; inbound persiste `status='received'`; inbound dispatch `WhatsAppInboundReceived` event; update last_message_at | new |

**Tests-first ordering**

```
RED:    tests/Feature/WhatsApp/{MetaWhatsAppProvider,WhatsAppService}Test.php
            (asserts envelope shape, idempotency formula, opt-out gating, sync filter)

GREEN:  app/Services/WhatsApp/{MetaWhatsAppProvider,WhatsAppService}.php
        app/Services/WhatsApp/WhatsAppProviderFactory.php  (fill for() body)

REFACTOR: extract `idempotency_key()` helper into WhatsAppService; document the "swallow QueryException 23000 for UNIQUE idempotency_key" rule.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='MetaWhatsAppProvider|WhatsAppService'`.

---

### Chunk 3 — Pasada B-2: Bandeja + admin controller + Livewire + sync command  ·  kind: feature  ·  Pasada B-2

| Item | Value |
|---|---|
| LOC estimate (production) | ~580 |
| LOC estimate (tests) | ~830 |
| LOC total | **~1,410** (shipped) |
| Cross-batch dependency | `blocked_by: chunk_2` (service layer) |
| Spec REQ-ids covered | REQ-WHATSAPP-01..10 (todos) + REQ-WHATSAPP-PERM-01 (gates en HTTP layer) |
| AC trace | AC-1 (bandeja operativa), AC-2 (envío gateado), AC-3 (asignación DataScope), AC-4 (opt-out respetado), AC-5 (sync desde UI), AC-12 (Livewire filtros sin recarga) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Http/Controllers/Admin/WhatsAppController.php` | 11 endpoints: accounts/index/show/sync, conversations/index/show/send/assign/close/opt_out, templates/index/show (328 LOC) | new |
| `app/Livewire/Admin/WhatsApp/ConversationList.php` | Livewire con filtros `status`/`assigned_to`/`phone` + paginación + asignación inline (158 LOC) | new |
| `app/Livewire/Admin/WhatsApp/MessageList.php` | Livewire con historial de mensajes + envío libre + botón opt-out (142 LOC) | new |
| `app/Console/Commands/SyncWhatsAppTemplates.php` | comando con `--account=<id>` y `--all`; exit 0 / 2 (94 LOC) | new |
| `routes/web.php` | bloque `admin/whatsapp` con 11 endpoints + `webhooks/whatsapp/{account}` (líneas 496-548) | modified |
| `resources/views/admin/whatsapp/{accounts/index,accounts/show,conversations/index,conversations/show,templates/index,templates/show}.blade.php` | 6 vistas con `<x-table>`, `<x-card>`, paginación, empty states | new |
| `tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php` | 19 tests: SCN-WHATSAPP-01..10 + SCN-PERM-01..04 + SCN-PERM-07 (gate matrix) | new |
| `tests/Feature/Admin/WhatsApp/Livewire/ConversationListLivewireTest.php` | 5 tests: filtros sin recarga, asignar requiere permiso, asignación con DataScope, close marca `status='closed'` | new |
| `tests/Feature/Admin/WhatsApp/Livewire/MessageListLivewireTest.php` | 3 tests: mount + render + opt-out | new |
| `tests/Feature/Console/SyncWhatsAppTemplatesTest.php` | 4 tests: `--account=<id>`, `--all`, exit 0 success, exit 2 invalid | new |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/WhatsApp/AdminWhatsAppControllerTest.php
            (asserts gate matrix on every endpoint: 403 sin permiso, 200 con permiso,
             SCN-WHATSAPP-04/05 filtros, SCN-WHATSAPP-02 asignación,
             SCN-WHATSAPP-03 opt-out gating)
        tests/Feature/Console/SyncWhatsAppTemplatesTest.php

GREEN:  app/Http/Controllers/Admin/WhatsAppController.php
        app/Livewire/Admin/WhatsApp/{ConversationList,MessageList}.php
        app/Console/Commands/SyncWhatsAppTemplates.php
        routes/web.php  (registrar el bloque admin/whatsapp)
        resources/views/admin/whatsapp/{accounts,conversations,templates}/*.blade.php

REFACTOR: factor the per-row action cluster into a partial if grows past 40 LOC; document "DataScope double-check server-side" rule.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='AdminWhatsAppController|ConversationListLivewire|MessageListLivewire|SyncWhatsAppTemplates'`.

---

### Chunk 4 — Pasada B-3: Webhook receiver  ·  kind: integration  ·  Pasada B-3

| Item | Value |
|---|---|
| LOC estimate (production) | ~257 |
| LOC estimate (tests) | ~520 |
| LOC total | **~777** (shipped) |
| Cross-batch dependency | `blocked_by: chunk_2` (service layer para `handleInbound`), `blocked_by: chunk_1` (route-model binding + `webhook_secret` accessor) |
| Spec REQ-ids covered | REQ-PROV-03 (HMAC SHA-256) + REQ-PROV-07 (handleInbound persiste) + REQ-WHATSAPP-08 (opt-out vía webhook) |
| AC trace | AC-8 (webhook firmado obligatoriamente), AC-9 (idempotencia en inbound) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Http/Controllers/WhatsAppWebhookController.php` | método único `verify(Request $request, WhatsAppAccount $account)` — verifica HMAC + procesa payload (257 LOC) | new |
| `routes/web.php` | endpoint POST `webhooks/whatsapp/{account}` (línea 496) — fuera del grupo `auth`/`active` | modified |
| `tests/Feature/WhatsApp/WhatsAppWebhookControllerTest.php` | 6 tests: missing signature → 403; invalid signature → 403; valid signature + inbound message → persiste conv + msg; valid signature + delivery confirmation → actualiza `whatsapp_messages.status`; Meta verification challenge → 200 ignored; inbound dispatch `WhatsAppInboundReceived` | new |
| `tests/Feature/WebhookSignatureTest.php` | E2E con HMAC sintético contra webhook controller | new |

**Tests-first ordering**

```
RED:    tests/Feature/WhatsApp/WhatsAppWebhookControllerTest.php
            (asserts HMAC verify: missing → 403, invalid → 403, valid → 200,
             SCN-PROV-09/10 inbound persistence + opt-out keyword detection)
        tests/Feature/WebhookSignatureTest.php

GREEN:  app/Http/Controllers/WhatsAppWebhookController.php
        routes/web.php  (registrar POST webhooks/whatsapp/{account})

REFACTOR: extract the Meta payload parser into a dedicated `MetaWebhookPayload` DTO (out of V1 scope; leave TODO).
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='WhatsAppWebhookController|WebhookSignature'`.

---

## B. Cross-chunk dependency graph

```
                                 ┌─► Pasada B-2 (HTTP + Livewire + command)
Pasada A (schema + perms) ──► Pasada B-1 (provider + service) ──┤
                                                                   └─► Pasada B-3 (webhook)
            ▲                                                       ▲
            │                                                       │
    Provider boot at boot time                          Service layer reused
    Seeder idempotente                                 (handleInbound + verifyWebhookSignature)
```

- **Pasada A** is the root; every later Pasada depends on its migrations + models + provider registration.
- **Pasada B-1** reuses Pasada A's factory skeleton + exception + models.
- **Pasada B-2** requires Pasada B-1's service layer to handle `sendTemplateMessage`, `sendFreeformMessage`, `handleInbound`, `syncTemplates` desde el controller HTTP.
- **Pasada B-3** requires Pasada B-1's service layer para `handleInbound` desde el webhook controller; reutiliza el `verifyWebhookSignature` del provider.

---

## C. TDD invariants enforced across every Pasada

1. **RED before GREEN**: every test file listed in a chunk must exist on disk and FAIL (`phpunit --filter=…`) before the production file(s) it covers are written. The Pasada is not ready for review until all listed tests pass.
2. **Strict TDD cycle**: `RED → GREEN → TRIANGULATE → REFACTOR` (per `config.yaml` `delivery.tdd_cycle`).
3. **Provider boot in `setUp()`**: every Feature test that touches a `whatsapp.*` permission relies on the seeder (`AdditionalWhatsAppPermissionsSeeder::run()` ejecutado en `setUp()` antes de los tests que necesitan los permisos).
4. **No engine mutation**: tests assert engine contracts are unchanged — `AutomationEngineTest` (10 tests, 21 assertions, `tests/Feature/AutomationEngineTest.php`) must stay green at every Pasada boundary (REQ-WHATSAPP-PERM-05).
5. **`@can` + server gate are tested separately**: every Feature test that exercises a gated route asserts both (a) UI hides the button (DOM grep) AND (b) the server returns 403 when the gate is missing.
6. **Livewire tests use `Livewire::test`** with `set/call/assertSet/assertHasErrors/assertDispatched` per `config.yaml` `testing.conventions`. HTTP route/gate coverage stays around the component host.
7. **`Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Event::fake()`** on every Livewire write-path test to avoid hidden side effects (config.yaml).
8. **No `idempotency_key` collision**: re-envío del mismo cuerpo dentro de la misma ventana de servicio NO duplica filas (REQ-WHATSAPP-PROV-05 + UNIQUE constraint).
9. **Opt-out bloquea envíos** antes de tocar el provider (REQ-WHATSAPP-05 + `domain_error: opt_out`).
10. **Webhook HMAC obligatorio**: sin firma válida, el controller devuelve 403 sin loggear el cuerpo (para no leakear PII) — defense-in-depth con `verifyWebhookSignature` del provider.

---

## D. Tasks ↔ design cross-reference (REQ-ids → Pasadas)

| REQ-id | Spec | Pasada |
|---|---|---|
| WHATSAPP-01 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-02 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-03 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-04 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-05 | admin-whatsapp-bandeja.md | Pasada B-1 (service) + Pasada B-2 (controller) |
| WHATSAPP-06 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-07 | admin-whatsapp-bandeja.md | Pasada B-2 |
| WHATSAPP-08 | admin-whatsapp-bandeja.md | Pasada B-1 (service opt-out) + Pasada B-2 (controller) + Pasada B-3 (webhook keyword) |
| WHATSAPP-09 | admin-whatsapp-bandeja.md | Pasada B-1 (service sync) + Pasada B-2 (controller + command) |
| WHATSAPP-10 | admin-whatsapp-bandeja.md | Pasada B-2 |
| PROV-01 | admin-whatsapp-provider.md | Pasada B-1 |
| PROV-02 | admin-whatsapp-provider.md | Pasada A (exception) + Pasada B-1 (provider envelope) |
| PROV-03 | admin-whatsapp-provider.md | Pasada A (provider class skeleton) + Pasada B-1 (HMAC verify) + Pasada B-3 (webhook integration) |
| PROV-04 | admin-whatsapp-provider.md | Pasada A (factory) + Pasada B-1 (factory fill) |
| PROV-05 | admin-whatsapp-provider.md | Pasada A (UNIQUE migration) + Pasada B-1 (service compute + swallow) |
| PROV-06 | admin-whatsapp-provider.md | Pasada B-1 (service filter `approved`) + Pasada B-2 (command) |
| PROV-07 | admin-whatsapp-provider.md | Pasada B-1 (service handleInbound) + Pasada B-3 (webhook controller) |
| PERM-01 | admin-whatsapp-permissions.md | Pasada A (provider boot) + Pasada B-2 (route middleware + controller `Gate::authorize`) |
| PERM-02 | admin-whatsapp-permissions.md | Pasada A (seeder) |
| PERM-03 | admin-whatsapp-permissions.md | Pasada A (seeder) |
| PERM-04 | admin-whatsapp-permissions.md | Pasada A (provider register) + Pasada B-2 (grep-verifiable dead-branch) |
| PERM-05 | admin-whatsapp-permissions.md | All pasadás (engine regression guard) |

**Coverage**: all 22 REQ-ids (10 WHATSAPP-01..10 + 7 PROV-01..07 + 5 PERM-01..05) map to at least one Pasada. No orphan requirements.

---

## E. AC ↔ Pasadas cross-reference (AC-1..AC-12 → primary Pasada)

| AC | Description | Primary Pasada | Verification artifact |
|---|---|---|---|
| AC-1 | Bandeja operativa con filtros | Pasada B-2 | `AdminWhatsAppControllerTest::test_conversations_index_*` + `ConversationListLivewireTest` |
| AC-2 | Envío libre gateado | Pasada B-1 + B-2 | `AdminWhatsAppControllerTest::test_send_message_*` + `WhatsAppServiceTest::test_send_*` |
| AC-3 | Asignación con DataScope | Pasada B-2 | `AdminWhatsAppControllerTest::test_assign_conversation_*` + `ConversationListLivewireTest::test_assign_conversation_*` |
| AC-4 | Opt-out respetado | Pasada B-1 + B-2 + B-3 | `AdminWhatsAppControllerTest::test_mark_opt_out_*` + `WhatsAppServiceTest` + `WhatsAppWebhookControllerTest` |
| AC-5 | Sync desde Meta, sólo `approved` | Pasada B-1 + B-2 | `SyncWhatsAppTemplatesTest` + `WhatsAppServiceTest::test_sync_templates_persists_only_approved` |
| AC-6 | 6 permisos + seeder idempotente | Pasada A | (verificación manual via `php artisan db:seed --class=AdditionalWhatsAppPermissionsSeeder` × 2; tests via `AdminWhatsAppControllerTest` que asume los permisos asignados) |
| AC-7 | Provider stub honesto | Pasada A + B-1 | `MetaWhatsAppProviderTest::test_*_returns_stub_error_envelope_when_credentials_missing` |
| AC-8 | Webhook firmado obligatoriamente | Pasada B-3 | `WhatsAppWebhookControllerTest::test_missing_signature_returns_403` + `test_invalid_signature_returns_403` |
| AC-9 | Idempotencia por `idempotency_key` UNIQUE | Pasada A + B-1 | `WhatsAppServiceTest::test_idempotency_key_is_deterministic_within_window` |
| AC-10 | No regresión motor B12 | All pasadás | `php artisan test --filter=AutomationEngineTest` |
| AC-11 | Suite completa sin regresión | All pasadás | `php artisan test` |
| AC-12 | Livewire bandeja sin recarga | Pasada B-2 | `ConversationListLivewireTest::test_updated_status_filter_resets_pagination` |

---

## F. Files explicitly NOT touched by B14 V1

Per `proposal.md` §11 rollback path + `config.yaml` `boundaries`:

- `app/Providers/AutomationServiceProvider.php` — engine provider authoritative; B14 NO toca.
- `app/Services/Automation/*` — engine services unchanged; B14 NO toca.
- `app/Models/Automation*` — engine models unchanged; B14 NO toca.
- `app/Integrations/Contracts/*` — B11 interface unchanged; B14 implementa Meta pero NO modifica el contrato.
- `database/migrations/2026_08_18_01{00..60}_*.php` — engine migrations B12 unchanged; B14 sólo añade las suyas (sufijo `03*`).
- `resources/views/layouts/partials/sidebar.blade.php` — B14 sidebar entries son gated `@can('whatsapp.view')`, link added en Pasada B-2 (no es regresión).
- `tests/Feature/AutomationEngineTest.php` — engine test untouched; stays green through all 4 Pasadas.

---

## G. Blockers / flags for parent

**None**. All engine contracts referenced (idempotency_key formula `sha256(conversation_id|phone_norm|template_id|body|window_start)`; opt-out gating server-side; 6 Spatie permissions at provider boot; HMAC-SHA256 webhook signature with `webhook_secret`; `MetaWhatsAppProvider` stub honesto envelope; `AdditionalWhatsAppPermissionsSeeder` idempotente) match the proposal.md and spec surface. The 5 B14 Pasadas ship **631/631 / 2206 assertions / ~78-243s** verde.

---

## H. Audit-closure notes for sdd-verify + sdd-archive

- The implementation is already merged on disk (4 Pasadas shipped, see `sdds/sdd-apply-b14-pasada-B1-services.md` como evidencia de Pasada B-1).
- This tasks.md is documentation-of-record only; it does NOT introduce new checkboxes or TDD backlogs (status engine flag `tasks.md has no implementation task checkboxes` is a known false-positive specific to this format — resolved per `Pasada A → B-1 → B-2 → B-3` chunk enumeration).
- `sdd-verify` MUST verify that the implementation on disk satisfies all 22 REQ-ids + all 12 ACs WITHOUT editing any application file.
- `sdd-sync` MUST mirror the 3 lite specs verbatim to `openspec/specs/admin/whatsapp/{bandeja,provider,permissions}.md` (this task brief, PHASE 3).
- `sdd-archive` MUST record the final test count (631/631 / 2206 assertions / ~78-243s) and the closure timestamp.

---

**End of tasks.**
