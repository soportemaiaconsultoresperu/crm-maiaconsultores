# B17-Notifications — Permissions + HTTP gate spec

> **Upstream artifacts** (authoritative):
>
> - `openspec/changes/b17-notifications/proposal.md` §4 (4 B17 permissions + role grants), §10 (UI routing).
> - `app/Providers/NotificationServiceProvider.php` (canonical permission registration + listener wiring).
> - `database/seeders/AdditionalNotificationPermissionsSeeder.php` (canonical idempotent seeder).
> - `app/Http/Controllers/Admin/NotificationController.php` (canonical Gate::authorize usage).
> - `docs/v2/01-roadmap.md` §10 D-21a..D-21g.

This spec covers the 4 B17 permissions (registration + role grants), the canonical `Gate::authorize` first-statement pattern in the controller, and the idempotent seeder. The controller's HTTP routes and JSON response shape are documented here; the Livewire views are B17.x.

## Requirements

### NOTIF-PERM-01 — 4 server-side gates per the matrix

The `NotificationController` enforces the 4 B17 permissions as `Gate::authorize(...)` first statements of every method. The canonical matrix:

| Method | Permission | Route | HTTP verb |
|---|---|---|---|
| `preferences` | `notifications.view` | `admin.notifications.preferences.index` | GET |
| `updatePreference` | `notifications.manage` | `admin.notifications.preferences.update` | PATCH |
| `deliveries` | `notifications.view` | `admin.notifications.deliveries.index` | GET |
| `showDelivery` | `notifications.view` | `admin.notifications.deliveries.show` | GET |
| `retry` | `notifications.manage` | `admin.notifications.deliveries.retry` | POST |
| `dispatchNow` | `notifications.send` (also checked at the route level via `can:notifications.send` middleware) | `admin.notifications.dispatch` | POST |

**Scenarios**:

- `SCN-PERM-01-A`: user without `notifications.view` calls `GET admin.notifications.preferences.index` → 403. Test: `AdminNotificationControllerTest::test_preferences_index_requires_view_permission`.
- `SCN-PERM-01-B`: user with `notifications.view` (no `manage`) calls `POST admin.notifications.deliveries.retry` → 403. Test: deferred to B17.x.
- `SCN-PERM-01-C`: user without `notifications.send` calls `POST admin.notifications.dispatch` → 403. Test: `AdminNotificationControllerTest::test_dispatch_requires_send_permission`.

### NOTIF-PERM-02 — admin role gets all 4 B17 permissions

The `NotificationServiceProvider::registerNotificationPermissions()` method grants the full `PERMISSIONS` constant to the `admin` role via `syncPermissions([..., self::ADMIN_GRANTS])` after firstOrCreate. The seeder (`AdditionalNotificationPermissionsSeeder`) re-applies the same grants idempotently.

**Scenarios**:

- `SCN-PERM-02-A`: after `php artisan db:seed --class=AdditionalNotificationPermissionsSeeder`, the `admin` role has all 4 B17 permissions. Test: deferred to B17.x.
- `SCN-PERM-02-B`: running the seeder twice does not duplicate permissions. Test: code-verified (`firstOrCreate` + `syncPermissions` are idempotent).

### NOTIF-PERM-03 — supervisor role gets only `notifications.view` + `notifications.audit`

The `NotificationServiceProvider::SUPERVISOR_GRANTS` constant lists `['notifications.view', 'notifications.audit']`. The supervisor role does NOT receive `notifications.manage` (admin-only for v1 per the proposal) or `notifications.send` (admin-only for v1).

**Scenarios**:

- `SCN-PERM-03-A`: after seeding, the `supervisor` role has exactly 2 B17 permissions (`view` + `audit`). Test: deferred to B17.x.
- `SCN-PERM-03-B`: supervisor calling `POST admin.notifications.deliveries.retry` → 403 (no `manage`). Test: covered by the `Gate::authorize` first-statement guarantee.

### NOTIF-PERM-04 — Gate::authorize is the FIRST statement of every method

The `NotificationController` enforces the permission gate as the first statement of every public method, following the same pattern as `AutomationController` (B12-UI) and `WhatsAppController` (B14). This is the canonical V1 pattern: even if the route-level `can:` middleware also enforces the gate, the controller's `Gate::authorize` is the per-method authoritative check.

**Scenarios**:

- `SCN-PERM-04-A`: every public method of `NotificationController` starts with `Gate::authorize(...)`. Test: code review verification.
- `SCN-PERM-04-B`: a unit test that uses reflection to confirm the first statement. Test: deferred to B17.x.

### NOTIF-PERM-05 — engine regression guard

After B17 lands, the B12 automation engine tests must remain green. `php artisan test --filter=AutomationEngineTest` returns 10/10 / 21 assertions. The B17 change touches no engine code; the engine regressions guard is a structural invariant.

**Scenarios**:

- `SCN-PERM-05-A`: `php artisan test --filter=AutomationEngineTest` → 10/10 / 21 assertions green. Test: shell command check in sdd-verify-report.
- `SCN-PERM-05-B`: `php artisan test` returns 642/642 / 2237 assertions / ~80-263s. Test: full-suite regression check.

## Cross-references

- **Proposal §4**: 4 permissions table.
- **Proposal §7.8**: `NotificationServiceProvider` registration.
- **Proposal §10**: role grants (D-21).
- **Spec `admin-notifications-dispatch.md`**: NOTIF-DISPATCH-* — the dispatch service is registered as a singleton via `NotificationServiceProvider::register()`.
- **Spec `admin-notifications-triggers.md`**: NOTIF-TRIG-* — the 3 listener wirings live in `boot()`.

## Test seams (canonical coverage)

| REQ-id | Test method(s) | Status |
|---|---|---|
| NOTIF-PERM-01 | `AdminNotificationControllerTest::test_preferences_index_requires_view_permission`, `..._test_dispatch_requires_send_permission` | RED-first green |
| NOTIF-PERM-02 | (deferred to B17.x; code-verified via seeder + firstOrCreate pattern) | green-via-server-integration |
| NOTIF-PERM-03 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-PERM-04 | (code review + reflection test deferred to B17.x) | green-via-server-integration |
| NOTIF-PERM-05 | `HardeningCrossCutTest` covers engine regression (B12-UI), `php artisan test` covers B17 baseline | green-via-server-integration |

## Deferred items (B17.x follow-up)

- `NotificationPermissionTest` (seeder idempotency + role grants).
- Reflection-based assertion that every controller method starts with `Gate::authorize()`.
- B11 stub `IntegrationAccount` lifecycle hook emitting `IntegrationAccountDisconnected` (D-21b wiring).
- B12 engine `CycleDetector` integration emitting `AutomationCycleDetected` (D-21c wiring).
- B13 `SendEmailMessage::failed()` emitting `IntegrationFailedPermanently` (D-21a wiring).
- B14 `SendWhatsAppMessage::failed()` emitting `IntegrationFailedPermanently` (D-21a wiring).
- Listener for `NotificationFailedPermanently` (D-21d follow-up).
- Livewire `NotificationPreferenceList` component (UI surface).
- Dedicated admin views (preferences/index, deliveries/index, deliveries/show) replacing the JSON responses.
- B13 `EmailTemplateRenderer` for the `mail` channel subject/body.
- Retention policy for `outbound_deliveries` (90-day purge for `status in ('sent', 'delivered')`).
- Spam-detection (B17.x hardening): warn if the same `(subject_type, related_entity_id, bucket)` fires > 5× / 1h.
- `ksort($payload)` before `idempotency_key` hash (B17.x hardening; non-determinism in payload key order is theoretical).
