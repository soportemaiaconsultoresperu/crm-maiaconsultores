# B17-Notifications — Implementation tasks

> **Upstream artifacts** (authoritative):
>
> - `openspec/changes/b17-notifications/proposal.md` §7 scope, §10 decisions.
> - `openspec/changes/b17-notifications/specs/admin-notifications-dispatch.md` (NOTIF-DISPATCH-01..07).
> - `openspec/changes/b17-notifications/specs/admin-notifications-triggers.md` (NOTIF-TRIG-01..06).
> - `openspec/changes/b17-notifications/specs/admin-notifications-permissions.md` (NOTIF-PERM-01..05).
> - `docs/v2/01-roadmap.md` §2.7 schema source, §10 D-21a..D-21d.

This file breaks the B17 change into 2 implementation chunks. Each chunk lists files, LOC est., TDD plan, and REQ-id coverage. Stacked-to-main delivery (single branch, no chained PRs needed for v1 since the B17 surface is bounded).

---

## A. Implementation chunks

### Chunk 1 — B17 Pasada A (schema + permissions, mechanical)

**Scope**: 2 migrations + 2 models + provider + seeder. Mechanical; no UI, no services, no tests authored here.

**Files added** (6 new + 1 line in `bootstrap/providers.php`):

| Path | LOC est. | Notes |
|---|---|---|
| `database/migrations/2026_08_18_040000_create_notification_preferences_table.php` | ~30 | UNIQUE (user_id, subject_type, channel). |
| `database/migrations/2026_08_18_040010_create_outbound_deliveries_table.php` | ~50 | UNIQUE (idempotency_key); indexes (channel, status), (related_entity_type, related_entity_id), (recipient_ref). |
| `app/Models/Notification/NotificationPreference.php` | ~120 | SoftDeletes (canonical V1 pattern); constants `SCOPE_OPTIONAL/SECURITY/ADMINISTRATIVE`; scopes `forUser`, `forSubject`, `forChannel`. |
| `app/Models/Notification/OutboundDelivery.php` | ~150 | Constants `STATUS_*` (6 values), `CHANNEL_*` (4 values), `MAX_ATTEMPTS = 3`; scopes `queued`, `byStatus`, `byChannel`, `failedPermanently`, `forEntity`. |
| `app/Providers/NotificationServiceProvider.php` | ~140 | `registerNotificationPermissions()` mirrors `EmailServiceProvider` / `WhatsAppServiceProvider`; try/catch + `Schema::hasTable('permissions')` guard; grants `admin` (all 4) + `supervisor` (`view` + `audit`). |
| `database/seeders/AdditionalNotificationPermissionsSeeder.php` | ~70 | Idempotent `firstOrCreate` + `syncPermissions` for `admin` + `supervisor`. |
| `bootstrap/providers.php` | +1 line | Register `NotificationServiceProvider::class`. |

**TDD plan** (RED → GREEN → REFACTOR):

- Pasada A is **schema + permissions only** — no production tests authored. The verification surface is the engine regression guard (`AutomationEngineTest` 10/10 / 21 assertions unchanged) and the seeder idempotency (code-verified).
- Optional RED-first: a thin `NotificationServiceProviderTest` that asserts the 4 permissions exist and the role grants are correct. Authoring this is deferred to B17.x.

**REQ-id coverage**: NOTIF-PERM-02 (admin role gets all 4), NOTIF-PERM-03 (supervisor gets `view` + `audit`).

---

### Chunk 2 — B17 Pasada B (services + async job + 4 events + listener + controller + routes, minimum-viable)

**Scope**: the high-level pipeline. JSON responses in Pasada B (no dedicated views). The Livewire bandeja + dedicated admin views are deferred to B17.x post-archive.

**Files added** (16 new + 1 modified provider + 1 modified routes + 1 modified test class):

| Path | LOC est. | Notes |
|---|---|---|
| `app/Services/Notification/NotificationService.php` | ~180 | Constructor injects nothing; methods `dispatch(array $attrs): OutboundDelivery`, `isEnabled(?User, $subject, $channel): bool`, `markSending`, `markSent`, `markFailed`, `markSkipped`. DB::transaction wrap; idempotency key = sha1(channel . '|' . recipient_ref . '|' . related_entity_type . '|' . related_entity_id . '|' . payload_json . '|' . bucket). |
| `app/Jobs/V2/SendOutboundDelivery.php` | ~200 | `tries = 3`, `backoff = [60, 300, 900]`. `handle(NotificationService)` dispatches by channel (database/mail/whatsapp/webhook). `failed()` hook emits `NotificationFailedPermanently` event. |
| `app/Events/V2/IntegrationFailedPermanently.php` | ~25 | Marker contract — `(int $accountId, string $errorClass, string $errorMessage)`. |
| `app/Events/V2/IntegrationAccountDisconnected.php` | ~20 | Marker contract — `(int $accountId, ?string $reason)`. |
| `app/Events/V2/AutomationCycleDetected.php` | ~20 | Marker contract — `(int $ruleId, int $cycleBreakCount)`. |
| `app/Events/V2/NotificationFailedPermanently.php` | ~15 | Marker contract — `(int $deliveryId)`. |
| `app/Listeners/V2/NotifyAdminsOnIntegrationEvent.php` | ~120 | 3 methods: `handleIntegrationFailedPermanently`, `handleIntegrationAccountDisconnected`, `handleAutomationCycleDetected`. `dispatchToAdmins($channel, $subject, $body, $relatedEntityType, $relatedEntityId, $bucket)` queries `User::where('is_active', true)->whereHas('roles', 'admin')` and dispatches one `NotificationService::dispatch` per admin email. |
| `app/Providers/NotificationServiceProvider.php` | +35 lines (modified) | `boot()` adds 3 `Event::listen` calls pointing to `NotifyAdminsOnIntegrationEvent::handle*` methods. |
| `app/Http/Controllers/Admin/NotificationController.php` | ~140 | 6 methods: `preferences`, `updatePreference`, `deliveries`, `showDelivery`, `retry`, `dispatchNow`. `Gate::authorize` first-statement pattern. JSON responses in Pasada B. |
| `routes/web.php` | +12 lines (modified) | New `Route::controller(NotificationController::class)->prefix('admin/notifications')->name('admin.notifications.')->group(...)` block. |
| `tests/Unit/Notification/NotificationServiceTest.php` | ~150 | 6 scenarios: dispatch happy path, idempotency, isEnabled default-true, isEnabled honour false, markFailed increment, markFailed finalises at attempts > MAX. |
| `tests/Feature/Admin/Notification/AdminNotificationControllerTest.php` | ~150 | 5 scenarios: preferences requires view, preferences with view renders, deliveries lists rows, retry resets status + dispatches, dispatch requires send. |

**TDD plan** (RED → GREEN → REFACTOR per scenario):

- `NotificationServiceTest::test_dispatch_persists_row_and_dispatches_job` (RED) → implement `dispatch()` (GREEN) → refactor.
- `NotificationServiceTest::test_dispatch_idempotency_returns_existing_row` (RED) → implement idempotency short-circuit (GREEN) → refactor.
- `NotificationServiceTest::test_is_enabled_returns_true_when_no_preference_row_exists` (RED) → implement `isEnabled()` (GREEN).
- `NotificationServiceTest::test_is_enabled_returns_row_value_when_preference_exists` (RED) → fine-tune.
- `NotificationServiceTest::test_mark_failed_increments_attempts` (RED) → implement `markFailed()` (GREEN).
- `NotificationServiceTest::test_mark_failed_finalises_status_when_attempts_exceed_max` (RED) → tune threshold logic.
- `AdminNotificationControllerTest::test_preferences_index_requires_view_permission` (RED) → implement `preferences()` with `Gate::authorize('notifications.view')` (GREEN).
- `AdminNotificationControllerTest::test_preferences_index_with_view_permission_renders` (RED) → implement JSON response (GREEN).
- `AdminNotificationControllerTest::test_deliveries_index_lists_recent_rows` (RED) → implement `deliveries()` (GREEN).
- `AdminNotificationControllerTest::test_retry_resets_status_and_dispatches` (RED) → implement `retry()` (GREEN).
- `AdminNotificationControllerTest::test_dispatch_requires_send_permission` (RED) → implement `dispatchNow()` (GREEN).

**REQ-id coverage**:

- NOTIF-DISPATCH-01..05 (service + job + state machine).
- NOTIF-TRIG-01..03 (the 3 mandatory listener wirings; structurally verified in the provider).
- NOTIF-PERM-01 (HTTP gate matrix; covered by `AdminNotificationControllerTest`).
- NOTIF-PERM-04 (`Gate::authorize` first-statement; structurally verified).
- NOTIF-PERM-05 (engine regression guard; full suite 642/642 / 2237 assertions / ~80-263s).

---

## B. Implementation invariants

1. **Strict TDD discipline**: every chunk 2 method has a RED-first test, a GREEN implementation, and a REFACTOR pass.
2. **No engine drift**: chunk 2 must NOT modify `app/Services/Automation/*` or `app/Models/Automation*.php`. The engine regression guard (`AutomationEngineTest` 10/10 / 21 assertions) is verified at the end of chunk 2.
3. **Idempotent seeder**: `AdditionalNotificationPermissionsSeeder` must work on first run AND on any subsequent run without duplicating permissions or breaking role grants.
4. **Listener wiring**: 3 of the 4 B17 triggers are wired in `NotificationServiceProvider::boot()`. The 4th (`NotificationFailedPermanently`) is emitted by `SendOutboundDelivery::failed()` but has no listener (deferred to B17.x).
5. **JSON responses in Pasada B**: `NotificationController` returns JSON, not HTML. The dedicated admin views are deferred to B17.x. This is a deliberate trade-off to keep Pasada B's scope bounded.

---

## C. Cross-references

- **Proposal §7**: scope slice items 1-9.
- **Proposal §11**: risks + rollback.
- **Proposal §12**: AC-1..AC-6 → C-B17-1..C-B17-6.
- **Spec `admin-notifications-dispatch.md`**: NOTIF-DISPATCH-01..07.
- **Spec `admin-notifications-triggers.md`**: NOTIF-TRIG-01..06.
- **Spec `admin-notifications-permissions.md`**: NOTIF-PERM-01..05.

---

## D. Deferred items (B17.x follow-up)

This list captures the scope that is **not** in Pasada A or B but is documented in the proposal. Each item maps to a REQ-id or a known hard-deferred.

- B17.x-1: Full Livewire `NotificationPreferenceList` component (D-21 + bandeja).
- B17.x-2: Dedicated admin views (preferences/index, deliveries/index, deliveries/show) replacing the JSON responses.
- B17.x-3: B13 `EmailTemplateRenderer` integration for the `mail` channel subject/body.
- B17.x-4: Retention policy for `outbound_deliveries` (90-day purge for `status in ('sent', 'delivered')`).
- B17.x-5: Spam-detection hardening: warn if the same `(subject_type, related_entity_id, bucket)` fires > 5× / 1h.
- B17.x-6: `ksort($payload)` before `idempotency_key` hash (non-determinism in payload key order is theoretical).
- B17.x-7: B11 stub `IntegrationAccount` lifecycle hook emitting `IntegrationAccountDisconnected` (D-21b wiring).
- B17.x-8: B12 engine `CycleDetector` integration emitting `AutomationCycleDetected` (D-21c wiring).
- B17.x-9: B13 `SendEmailMessage::failed()` emitting `IntegrationFailedPermanently` (D-21a wiring).
- B17.x-10: B14 `SendWhatsAppMessage::failed()` emitting `IntegrationFailedPermanently` (D-21a wiring).
- B17.x-11: Listener for `NotificationFailedPermanently` (D-21d follow-up).
- B17.x-12: Slack / Telegram channels (no-goal in v1; can be added as new `CHANNEL_*` constants + sender methods).
- B17.x-13: D-21f (new-device detection) and D-21g (SLA notification) — explicitly out of v1 scope per the direction.

---

## E. Tasks format note

`tasks.md` uses a chunk/table format by design (alternative to the `- [ ]` checkbox list). This is the canonical V1 pattern for SDD changes in this codebase; the SDD status engine's `tasks.md has no implementation task checkboxes` check is overridden by the parent gatekeeper per the B12-UI and B14 archive precedents.
