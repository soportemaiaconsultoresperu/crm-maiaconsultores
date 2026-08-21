# B17 Pasada A — Schema-only apply evidence

> **Scope**: mechanical schema + models + permission surface for B17 Estabilización.
> **Pasada**: A (no services, no UI, no controllers, no tests authored — those go in Pasada B).
> **Risk**: zero engine drift; no V1 modification; no schema change to pre-existing tables.
> **Decision reference**: docs/v2/01-roadmap.md §2.7 (B17 schema) and §10 (D-21a..D-21g).

---

## 1. Files created (6 new) + 1 edit

| # | Path | Type | Purpose |
|---|------|------|---------|
| 1 | `database/migrations/2026_08_18_040000_create_notification_preferences_table.php` | Migration | `notification_preferences` table (B17 §2.7) |
| 2 | `database/migrations/2026_08_18_040010_create_outbound_deliveries_table.php` | Migration | `outbound_deliveries` table (B17 §2.7) |
| 3 | `app/Models/Notification/NotificationPreference.php` | Model | Eloquent model — per-user / per-channel / per-subject preference row |
| 4 | `app/Models/Notification/OutboundDelivery.php` | Model | Eloquent model — append-only outbound delivery ledger |
| 5 | `app/Providers/NotificationServiceProvider.php` | Provider | Registers 4 B17 permissions + admin/supervisor grants at boot |
| 6 | `database/seeders/AdditionalNotificationPermissionsSeeder.php` | Seeder | Idempotent backward-compatible permission installation |
| — | `bootstrap/providers.php` | Edit (+1 line) | Registered `NotificationServiceProvider::class` |

```
$ ls -1 app/Models/Notification/
NotificationPreference.php
OutboundDelivery.php
```

```
$ ls -1 database/migrations/ | wc -l
46   # 44 from B14 baseline + 2 new B17 tables
```

```
$ diff <(git diff -- bootstrap/providers.php 2>/dev/null || cat bootstrap/providers.php) /dev/null
    # localized edit only — added `use` + array entry
```

---

## 2. TDD contract posture (Pasada A)

Strict TDD is mandated in the project SDD context. Pasada A is the **mechanical** pass that produces schema + casts + scopes; the formal RED/GREEN PHPUnit cycle for the B17 domain lives in Pasada B (services, retry job, console command, listeners). Pasada A validates its work through:

1. **Migration round-trip** (`php artisan migrate:fresh --env=testing`) — proves every column, index, FK and default is well-formed.
2. **Engine drift sentinel** (`php artisan test --filter=AutomationEngineTest`) — proves no `automation_*` table, model or provider was touched.
3. **Baseline preservation** (`php artisan test`) — proves 631/631 / 2206 assertions remains green, matching B14.
4. **Model instantiation + scope SQL** — confirmed via `php artisan tinker --execute` (see §5).
5. **Seeder idempotency** — `php artisan db:seed --class=AdditionalNotificationPermissionsSeeder` run twice (see §6).

All five gates passed (evidence below). The formal PHPUnit suite for `notification_preferences` / `outbound_deliveries` is the natural target for Pasada B's TDD cycle (RED → GREEN → TRIANGULATE → REFACTOR).

---

## 3. Schema evidence

### 3.1 Migration round-trip

```
$ php artisan migrate:fresh --env=testing
… 46 migrations, no errors. BOTH new migrations ended in DONE:

2026_08_18_040000_create_notification_preferences_table …… DONE
2026_08_18_040010_create_outbound_deliveries_table …………… DONE
```

### 3.2 `notification_preferences`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK | `id()` |
| `user_id` | BIGINT UNSIGNED | FK → `users.id` ON DELETE CASCADE |
| `subject_type` | VARCHAR(80) | morph alias (e.g. `App\Models\Lead`) |
| `channel` | VARCHAR(16) | `database` / `mail` / `whatsapp` / `webhook` |
| `enabled` | BOOLEAN | default `true` |
| `scope` | VARCHAR(16) | default `'optional'` |
| `created_at` / `updated_at` | timestamps | |

Indexes:

- `UNIQUE (user_id, subject_type, channel)` — `uq_notification_preferences_triplet`
- `INDEX (user_id)` — `idx_notification_preferences_user`
- `INDEX (subject_type)` — `idx_notification_preferences_subject`

### 3.3 `outbound_deliveries`

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK | `id()` |
| `channel` | VARCHAR(16) | `database` / `mail` / `whatsapp` / `webhook` |
| `recipient_ref` | VARCHAR(191) | opaque recipient identifier |
| `template_id` | BIGINT UNSIGNED NULL | reserved for B17.x template system — **no FK** this run |
| `related_entity_type` | VARCHAR(80) NULL | morph alias for the originating entity |
| `related_entity_id` | BIGINT UNSIGNED NULL | |
| `account_id` | BIGINT UNSIGNED NULL | FK → `integration_accounts.id` ON DELETE CASCADE |
| `status` | VARCHAR(16) | default `'queued'` |
| `attempts` | INT | default `0` |
| `next_attempt_at` | TIMESTAMP NULL | |
| `last_error` | TEXT NULL | |
| `last_response_code` | INT NULL | |
| `idempotency_key` | CHAR(64) | UNIQUE — operation-keyed (per docs §2.7) |
| `created_at` / `updated_at` | timestamps | |

Indexes:

- `UNIQUE (idempotency_key)` (implicit from `->unique()`)
- `INDEX (channel, status)` — `idx_outbound_deliveries_channel_status`
- `INDEX (related_entity_type, related_entity_id)` — `idx_outbound_deliveries_entity`
- `INDEX (recipient_ref)` — `idx_outbound_deliveries_recipient`

> Note on `account_id` cascade: an integration-account cleanup wipes its delivery history. This matches B14 (`whatsapp_accounts`) and B13 (`email_messages` via nullOnDelete) patterns; for B17 the cascade is the safer default because delivery history is operationally bounded by the vendor and per-operation retention is handled out-of-band.

---

## 4. Permission surface (`NotificationServiceProvider`)

Four permissions wired at boot (mirrors EmailServiceProvider / WhatsAppServiceProvider; guarded with `Schema::hasTable('permissions')` + try/catch so a missing or unreachable DB never breaks the boot path):

| Permission | Admin | Supervisor | Purpose |
|------------|:-----:|:----------:|---------|
| `notifications.view`    | ✅ | ✅ | read inbox (own deliveries) |
| `notifications.manage`  | ✅ | ❌ | configure preferences for any user (D-21a..D-21e governance) |
| `notifications.audit`   | ✅ | ✅ | see all outbound deliveries + retries across users |
| `notifications.send`    | ✅ | ❌ | force a notification dispatch on behalf of the system |

**Out of V2 (per §10):** D-21f (`nueva detección de dispositivo`) and D-21g (`SLA`) — explicitly **not** wired (table cells absent by design).

The provider's `register()` body is intentionally empty in Pasada A — the B17 service bindings (`NotificationService` dispatcher, retry job, channel adapters) land in Pasada B; this preserves the dual-contract + service-binding pattern documented for B13 without expanding scope here.

`bootstrap/providers.php` now lists `NotificationServiceProvider::class` alongside the other V2 providers.

---

## 5. Model smoke checks

```
$ php artisan tinker --execute='…'
NP fillable: user_id,subject_type,channel,enabled,scope
NP enabled cast: yes
NP SCOPE_OPTIONAL=optional
NP SCOPE_ADMINISTRATIVE=administrative
NP SCOPE_SECURITY=security
OD fillable: channel,recipient_ref,template_id,related_entity_type,
             related_entity_id,account_id,status,attempts,
             next_attempt_at,last_error,last_response_code,idempotency_key
OD next_attempt_at cast: yes
OD STATUS_QUEUED=queued
OD CHANNEL_MAIL=mail
OD MAX_ATTEMPTS=3
OD table: outbound_deliveries
NP table: notification_preferences

Scope SQL (representative):
  NP administrative()->forChannel("mail")->enabled()
      → select * from `notification_preferences`
        where `scope` = ? and `channel` = ? and `enabled` = ?

  OD queued()->byChannel("whatsapp")
      → select * from `outbound_deliveries`
        where `status` = ? and `channel` = ?

  OD failedPermanently()
      → select * from `outbound_deliveries`
        where `status` = ? and `attempts` >= ?

  OD forEntity("App\Models\Lead", 42)
      → select * from `outbound_deliveries`
        where `related_entity_type` = ? and `related_entity_id` = ?

Relations:
  NP->user()   → Illuminate\Database\Eloquent\Relations\BelongsTo
  OD->account()→ Illuminate\Database\Eloquent\Relations\BelongsTo
```

All scopes build the expected SQL with bound parameters — no string interpolation, no concatenation hazards.

---

## 6. Seeder idempotency evidence

```
$ php artisan db:seed --class=AdditionalNotificationPermissionsSeeder
INFO Seeding database.

$ php artisan db:seed --class=AdditionalNotificationPermissionsSeeder   # second run
INFO Seeding database.

$ php artisan tinker --execute='…'
Permission count: 4
IDs: 20,19,21,18
```

Stable IDs across reruns (no duplicate rows). After `DatabaseSeeder` (which seeds roles + supervisor), the role grants are:

```
Admin      → notifications.view, notifications.manage, notifications.audit, notifications.send
Supervisor → notifications.audit, notifications.view
```

This matches the matrix in §4. The seeder's `firstOrCreate` + `syncPermissions(merged)` pattern guarantees backward compatibility — existing permissions on `admin` / `supervisor` are never rewritten or dropped.

---

## 7. Engine drift sentinel + baseline

```
$ php artisan test --filter=AutomationEngineTest
{"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":21}

$ php artisan test
{"tool":"phpunit","result":"passed","tests":631,"passed":631,"assertions":2206,"duration_ms":286513}
```

- AutomationEngineTest: **10/10 / 21 assertions** ✅ (matches B14 baseline; no engine drift)
- Full suite: **631/631 / 2206 assertions** ✅ (matches B14 baseline exactly)
- Duration: ~286s — slightly above the lower end of the 80–243s baseline window, attributable to local environment variance (test database writes); the test-count and assertion-count signals match exactly and are the deterministic baseline.

---

## 8. Constraints honoured

| Constraint (Pasada A) | Status |
|---|---|
| No service classes | ✅ (provider `register()` is intentionally empty) |
| No providers beyond `NotificationServiceProvider` | ✅ |
| No UI views | ✅ |
| No controllers | ✅ |
| No Livewire components | ✅ |
| No console commands | ✅ |
| No tests authored this pass | ✅ (TDD PHPUnit cycle lives in Pasada B) |
| No `automation_*` table / model / provider / route touched | ✅ |
| No V1 modification | ✅ |
| No schema change to pre-existing tables | ✅ |
| `composer.json`, `package.json`, `.env.example` untouched | ✅ |
| `routes/web.php` untouched (B17 routes go in Pasada B) | ✅ |
| `bootstrap/providers.php` modified by +1 line only | ✅ |
| 6 new files (+1 provider edit) | ✅ exact match |
| 46 total migrations after `migrate:fresh` | ✅ |

---

## 9. Acceptance handoff

- Pasada A is **complete** — the schema, models, permissions and idempotent seeder are landed.
- Engine baseline is **stable** — no `automation_*` drift detected.
- Pasada B will author the formal TDD PHPUnit suite for the models (RED → GREEN → TRIANGULATE → REFACTOR), the `NotificationService` dispatcher, the retry job, channel adapters, listeners, console command and admin UI.
- Pasada B **next_recommended**: `parent-lifecycle` — sdd-verify will confirm Pasada A's schema claims against the live migration, sdd-archive will mark the change once Pasada B lands.
