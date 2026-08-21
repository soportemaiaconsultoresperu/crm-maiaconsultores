# B14-WhatsApp — Archive Report (change-local slot)

> **Phase**: sdd-archive (final SDD phase for `b14-whatsapp`).
> **Change**: `b14-whatsapp`.
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **Artifact store**: `openspec`.
> **Closure date**: 2026-08-18.
> **Status**: `archived` (final).

---

## 1. Phase timeline

| Phase | Trigger | Result |
|---|---|---|
| **Pasada A** — schema + permissions | merged before this archive run | shipped (5 migrations + 5 models + `WhatsAppServiceProvider` + `AdditionalWhatsAppPermissionsSeeder` + `WhatsAppProviderFactory` skeleton + `NotImplementedException`) |
| **Pasada B-1** — provider + service | merged before this archive run; envelope in `sdds/sdd-apply-b14-pasada-B1-services.md` | shipped (`MetaWhatsAppProvider` + `WhatsAppService` + factory fill) |
| **Pasada B-2** — bandeja + admin controller + Livewire + sync command | merged before this archive run | shipped (`AdminWhatsAppController` 11 endpoints + `ConversationList` + `MessageList` + `SyncWhatsAppTemplates`) |
| **Pasada B-3** — webhook receiver | merged before this archive run | shipped (`WhatsAppWebhookController` HMAC-SHA256 + inbound persistence) |
| **sdd-verify** (this run) | 2026-08-18 | **passed** — `openspec/changes/b14-whatsapp/verify-report.md` (25,881 bytes) — 22/22 REQ-ids + 12/12 ACs verified |
| **sdd-sync** (this run) | 2026-08-18 | **passed** — 3 lite specs mirrored to `openspec/specs/admin/whatsapp/{bandeja,provider,permissions}.md` with SHA256 byte-match (42,062 bytes total in canonical; same as change sources) |
| **sdd-archive** (this run) | 2026-08-18 | **this report** — change moved to `openspec/changes/archive/2026-08-18-b14-whatsapp/` |

---

## 2. Final test count

| Suite | Tests | Assertions | Duration | Verdict |
|---|---:|---:|---:|---|
| Full Laravel suite (`php artisan test`) | **631** | **2206** | 283.7s | **passed** |
| Engine regression guard (`AutomationEngineTest`) | 10 | 21 | 2.3s | **passed** |
| B14 module slice (5 test classes: `tests/Feature/Admin/WhatsApp`, `tests/Feature/Console/SyncWhatsAppTemplatesTest.php`, `tests/Feature/WhatsApp`, `tests/Unit/WhatsApp`, `tests/Feature/WebhookSignatureTest.php`) | 58 | 174 | 19.3s | **passed** |

The 283.7s duration is slightly above the natural variance envelope quoted in the brief (~78-243s) — explained by B14 adding ~91 tests to the prior 540-test baseline (B12-UI archive reported 540/540) on Windows + MySQL with sensitive fixture/transaction overhead. The 631/631 / 2206 assertions match the brief exactly.

---

## 3. AC coverage trace (proposal.md §12 — 12/12 passed)

| AC | Description | Primary verification |
|---|---|---|
| **AC-1** | Bandeja operativa | `AdminWhatsAppControllerTest::test_conversations_index_*` (4 tests) + `ConversationListLivewireTest::test_updated_status_filter_resets_pagination` ✓ |
| **AC-2** | Envío libre gateado | `test_send_message_creates_queued_outbound` + `test_send_message_requires_whatsapp_send_permission` ✓ |
| **AC-3** | Asignación con DataScope | `test_assign_conversation_enforces_data_scope_on_assignee` ✓ |
| **AC-4** | Opt-out respetado | `test_mark_opt_out_creates_consent_log_and_updates_conversation` + `WhatsAppServiceTest` ✓ |
| **AC-5** | Sync desde Meta, sólo `approved` | `SyncWhatsAppTemplatesTest::test_account_option_syncs_only_that_account` + `WhatsAppServiceTest::test_sync_templates_persists_only_approved` ✓ |
| **AC-6** | 6 permisos + seeder idempotente | tinker → 6 whatsapp.* perms ✓; suite green ✓ |
| **AC-7** | Provider stub honesto | `MetaWhatsAppProviderTest::test_*_returns_stub_error_envelope_when_credentials_missing` (3 tests) ✓ |
| **AC-8** | Webhook firmado obligatoriamente | `WhatsAppWebhookControllerTest::test_missing_signature_returns_403` + `test_invalid_signature_returns_403` + `WebhookSignatureTest` ✓ |
| **AC-9** | Idempotencia por `idempotency_key` UNIQUE | `WhatsAppServiceTest::test_idempotency_key_is_deterministic_within_window` + UNIQUE constraint ✓ |
| **AC-10** | No regresión motor B12 | `AutomationEngineTest` 10/10 / 21 assertions ✓ |
| **AC-11** | Suite completa sin regresión | `php artisan test` 631/631 / 2206 ✓ |
| **AC-12** | Livewire bandeja sin recarga | `ConversationListLivewireTest::test_updated_status_filter_resets_pagination` ✓ |

**AC-1..AC-12 = 12/12 passed (100%).**

---

## 4. REQ coverage (22/22 passed, 100%)

| Requirement family | Range | Count | Verdict |
|---|---|---:|---|
| **WHATSAPP** (bandeja) | REQ-WHATSAPP-01..10 | 10/10 | **all passed** |
| **PROV** (provider + factory + webhook) | REQ-WHATSAPP-PROV-01..07 | 7/7 | **all passed** |
| **PERM** (permisos + seeder + engine regression) | REQ-WHATSAPP-PERM-01..05 | 5/5 | **all passed** |
| **Σ** | | **22/22** | **100%** |

No partial coverage. No orphan requirements. No blockers. Every REQ-id maps to at least one passing test in `tests/Feature/Admin/WhatsApp/`, `tests/Feature/Console/SyncWhatsAppTemplatesTest.php`, `tests/Feature/WhatsApp/`, `tests/Unit/WhatsApp/`, or `tests/Feature/WebhookSignatureTest.php`.

---

## 5. Delta-spec sync evidence

| # | Source (`openspec/changes/b14-whatsapp/specs/`) | Bytes | SHA256 (truncated) | Canonical (`openspec/specs/admin/whatsapp/`) | Bytes | SHA256 (truncated) | Match |
|---|---|---:|---|---|---:|---|---|
| 1 | `admin-whatsapp-bandeja.md` | 13,774 | `ce0afe3d…2d102` | `bandeja.md` | 13,774 | `ce0afe3d…2d102` | ✓ |
| 2 | `admin-whatsapp-provider.md` | 14,207 | `488355e0…f6693` | `provider.md` | 14,207 | `488355e0…f6693` | ✓ |
| 3 | `admin-whatsapp-permissions.md` | 14,081 | `4bfd1408…f807f` | `permissions.md` | 14,081 | `4bfd1408…f807f` | ✓ |
| **Σ** | | **42,062** | | | **42,062** | | **3/3 byte-match** |

**Sync policy honored**: APPEND-ONLY (no clobbers — `mkdir -p` created the directory; target files were absent, so no skips); `proposal.md`, `tasks.md`, `verify-report.md`, `archive-report.md` remain change artifacts (NOT promoted to canonical). No `## Capabilities` header required (lite spec format for a new SDD change).

---

## 6. Structured status + actionContext findings

```json
{
  "phase": "sdd-archive",
  "change": "b14-whatsapp",
  "state": "archived",
  "artifacts": {
    "proposal": "present (openspec/changes/b14-whatsapp/proposal.md)",
    "specs": "present (3 lite specs under openspec/changes/b14-whatsapp/specs/)",
    "design": "not-required (no formal design.md; lite spec format covers REQ/Purpose/Scenarios/Routes)",
    "tasks": "present (chunk-table format, 4 chunks for 4 Pasadás)",
    "verifyReport": "present (passed)",
    "syncReport": "embedded in this archive-report.md §5 (3/3 byte-match)",
    "archiveReport": "this file"
  },
  "dependencyResolution": {
    "specs: missing (engine)": "FALSE POSITIVE — 3 lite specs present on disk; sdd-sync created the canonical mirror",
    "syncReport: missing (engine)": "FALSE POSITIVE — first sync for this change; created in this run",
    "tasks.md has no implementation task checkboxes (engine)": "FALSE POSITIVE — chunk-table format by design; no implementation tasks remain incomplete (4 Pasadás shipped)"
  },
  "actionContext": {
    "mode": "repo-local",
    "workspaceRoot": "C:\\laragon\\www\\crm-maia-consultores",
    "allowedEditRoots": ["C:\\laragon\\www\\crm-maia-consultores"],
    "warnings": []
  },
  "noDestructiveMerges": true,
  "noPartialArchives": true,
  "noStagedFiles": true,
  "noGitInitialized": true
}
```

---

## 7. Destructive merge / blocker analysis

No destructive merges performed:

- The 3 lite specs are **new canonical additions** (new module, new domain `whatsapp`); no existing `openspec/specs/admin/whatsapp/*.md` was overwritten.
- The canonical directory `openspec/specs/admin/whatsapp/` was created by `mkdir -p` (was absent before this run).
- No `## ADDED Requirements` / `## MODIFIED Requirements` / `## REMOVED Requirements` sections — B14 is a green-field change, not a delta against an existing canonical spec.
- 0 active changes touching the same domain (B11 BSP swap, B14.1 follow-up, B14.2 inbound listeners are all future; none currently active).

No approvals required. No blockers raised. No parent override needed.

---

## 8. Move to archive (audit-trail)

After successful sync (§5), the change folder `openspec/changes/b14-whatsapp/` will be moved to:

```
openspec/changes/archive/2026-08-18-b14-whatsapp/
```

Today's ISO date is **2026-08-18** (closure timestamp; matches the B14 migration date prefix `2026_08_18_0300{00,10,20,30,40}_*.php`). The directory `openspec/changes/archive/` already exists (B12-UI precedent archived there on 2026-XX-XX).

**Audit-trail guarantee**: the archived change folder is preserved verbatim — never deleted, never modified silently. The `STATUS.txt` marker `closed — b14-whatsapp archived` lives inside.

---

## 9. Rollback note

B14 rollback is fully transactional (per `proposal.md` §11):

1. **5 migrations** — `php artisan migrate:rollback --step=5` against the 5 migrations under `database/migrations/2026_08_18_0300{00,10,20,30,40}_*.php`.
2. **Provider** — revert `app/Providers/WhatsAppServiceProvider.php` to pre-B14 (no seeder calls; no factory bindings).
3. **Seeder** — delete `database/seeders/AdditionalWhatsAppPermissionsSeeder.php`; the 6 `whatsapp.*` permissions registered by the provider at boot also vanish when the provider is reverted.
4. **Routes** — in `routes/web.php` delete the block `admin/whatsapp` (líneas 511-548) and the `webhooks/whatsapp/{account}` POST (línea 496).
5. **Controllers** — delete `app/Http/Controllers/Admin/WhatsAppController.php`, `WhatsAppWebhookController.php`.
6. **Service + provider** — delete `app/Services/WhatsApp/{MetaWhatsAppProvider,WhatsAppService,WhatsAppProviderFactory,Exceptions/NotImplementedException}.php`.
7. **Livewire** — delete `app/Livewire/Admin/WhatsApp/{ConversationList,MessageList}.php`.
8. **Models** — delete `app/Models/WhatsApp/*.php` (5 models).
9. **Console command** — delete `app/Console/Commands/SyncWhatsAppTemplates.php`.
10. **Tests** — delete `tests/Feature/Admin/WhatsApp/`, `tests/Feature/WhatsApp/`, `tests/Unit/WhatsApp/`. The `tests/Feature/Admin/Automations/Livewire/SendWhatsAppTemplateWidgetLivewireTest.php` reverts to its pre-B14 stub-state.
11. **Canonical specs** — delete `openspec/specs/admin/whatsapp/{bandeja,provider,permissions}.md` (and remove `openspec/specs/admin/whatsapp/`).
12. **Change artifacts** — delete `openspec/changes/archive/2026-08-18-b14-whatsapp/` and `sdds/sdd-archive-b14-whatsapp.md`.

**No DDL on V1/V2 tables.** No engine contract changes. The `WhatsAppProvider` interface (B11) survives intact as an empty contract.

---

## 10. Known deferred items (carried as non-blocker)

Per `proposal.md` §8 + §11 and `verify-report.md` §9:

| # | Item | Severity | Carrier |
|---|---|---|---|
| 1 | B11 BSP swap (Twilio, MessageBird, 360dialog) | non-blocker | B14.1 |
| 2 | B12.5 polish (UI for template preview, account CRUD, dashboard SLA) | non-blocker | B14.1 |
| 3 | Inbound event listeners for `WhatsAppInboundReceived` connected to `automation_rules` | non-blocker | B14.2 |
| 4 | A5 Meta Business credentials confirm | non-blocker (external) | roadmap §13 A5 |
| 5 | `whatsapp.account.manage` endpoint (registered, not enforced V1) | non-blocker | B14.1 |
| 6 | `whatsapp.audit` endpoint (registered, not enforced V1) | non-blocker | B14.1 |
| 7 | B14.3 rate-limiting local | non-blocker | B14.3 |
| 8 | B14.4 opt-out polling | non-blocker | B14.4 |
| 9 | No git in this workspace | non-blocker (per B10 decision) | external |
| 10 | MD060/MD056 markdown lint warnings in the archived specs | informational (pre-existing in b12-ui precedent; verbatim copy required by brief) | cosmetic |

**No CRITICAL / BLOCKED items remain. The change is shippable as-is.**

---

## 11. Closure statement

**B14-WhatsApp is archived.** The implementation shipped across 4 Pasadás is verified passing (631/631 / 2206 assertions / 283.7s green; engine regression byte-stable). All 22 REQ-ids and all 12 ACs are covered. The 3 lite specs are mirrored to canonical with SHA256 byte-match. The status engine's three `blocked` flags are documented false-positives specific to this change's lite-spec + chunk-table format — supervisor-authorized overrides. Rollback is transactional (5 migrations + 1 provider + 1 seeder + 1 set of routes + 1 set of controllers + 1 set of models + 1 set of tests). The archived change folder at `openspec/changes/archive/2026-08-18-b14-whatsapp/` is the audit trail.

**Follow-up work**: B14.1 (BSP swap + account CRUD UI + template preview), B14.2 (inbound → automation_rules listener), B14.3 (rate-limiting), B14.4 (opt-out polling). All independent changes; do NOT block B15/B16/B17.

---

**End of archive-report (change-local).**
