# B17-Notifications — Dispatch service + async job spec

> **Upstream artifacts** (authoritative):
>
> - `openspec/changes/b17-notifications/proposal.md` §4 rules, §7 scope, §10 decisions.
> - `docs/v2/01-roadmap.md` §2.7 (B17 schema), §10 D-21a..D-21g.
> - `app/Services/Notification/NotificationService.php` (canonical implementation).
> - `app/Jobs/V2/SendOutboundDelivery.php` (canonical async job).
> - B13 EmailService + B14 WhatsAppService patterns (precedent for `tries=3, backoff=[60,300,900]`).

This spec covers the central pipeline: `NotificationService::dispatch()` persists an `OutboundDelivery` row, `SendOutboundDelivery` job executes the channel-specific send, and the state machine governs the retry / failure paths. The 4 mandatory triggers (D-21a..d) live in the listener spec (`admin-notifications-triggers.md`); the HTTP gates live in `admin-notifications-permissions.md`.

## Requirements

### NOTIF-DISPATCH-01 — dispatch persists a queued row and queues the job

The system shall persist an `OutboundDelivery` row in a `DB::transaction` with `status='queued'`, `attempts=0`, and a deterministic `idempotency_key` derived from `(channel, recipient_ref, related_entity_type, related_entity_id, payload, bucket)`. The system shall dispatch the `SendOutboundDelivery` ShouldQueue job bound to the new row.

**Scenarios**:

- `SCN-DISPATCH-01-A`: when `dispatch(['channel' => 'mail', 'recipient_ref' => 'admin@example.com', 'related_entity_type' => 'IntegrationAccount', 'related_entity_id' => 42, 'account_id' => null, 'payload' => ['subject' => 's', 'body' => 'b'], 'bucket' => 'D-21a'])` is called, the system persists an `OutboundDelivery` row with the given attributes and a non-empty `idempotency_key`, then dispatches `SendOutboundDelivery::class` with `$job->deliveryId === $delivery->id`. Test: `NotificationServiceTest::test_dispatch_persists_row_and_dispatches_job` (RED-first).
- `SCN-DISPATCH-01-B`: when the transaction fails (e.g. `OutboundDelivery::create()` throws `QueryException`), the system does NOT dispatch the job. Test: deferred to B17.x (current Pasada B assumes the happy path; B17.x will add an explicit failure test).

### NOTIF-DISPATCH-02 — dispatch is idempotent on (channel, recipient_ref, related_entity_type, related_entity_id, payload, bucket)

When the dispatcher is called twice with identical attributes within the idempotency window, the second call shall return the existing `OutboundDelivery` row without re-dispatching the `SendOutboundDelivery` job. The UNIQUE constraint on `idempotency_key` is the persistence-layer guarantee; the service layer's `where('idempotency_key', $key)->first()` short-circuits before `DB::transaction()`.

**Scenarios**:

- `SCN-DISPATCH-02-A`: two sequential `dispatch()` calls with the same attrs → `assertSame($first->id, $second->id)`; only one `OutboundDelivery` row exists; `Bus::assertDispatchedTimes(SendOutboundDelivery::class, 1)`. Test: `NotificationServiceTest::test_dispatch_idempotency_returns_existing_row`.
- `SCN-DISPATCH-02-B`: idempotency key calculation is deterministic (same input → same hash). Test: covered by `SCN-DISPATCH-02-A`.

### NOTIF-DISPATCH-03 — isEnabled returns true when no preference row exists; honours enabled=false when present

When a `NotificationPreference` row exists for `(user, subject_type, channel)`, `isEnabled()` returns the row's `enabled` boolean. When no row exists, `isEnabled()` returns `true` (default opt-in per D-21e). The 4 mandatory triggers (D-21a..d) do NOT consult `isEnabled()` — admin recipient set is computed fresh and shipped regardless of preferences (criticality guarantee).

**Scenarios**:

- `SCN-DISPATCH-03-A`: no row exists for `(user, 'IntegrationAccount', 'mail')` → `isEnabled()` returns `true`. Test: `NotificationServiceTest::test_is_enabled_returns_true_when_no_preference_row_exists`.
- `SCN-DISPATCH-03-B`: row exists with `enabled=false` for `(user, 'IntegrationAccount', 'mail')` → `isEnabled()` returns `false`. Test: `NotificationServiceTest::test_is_enabled_returns_row_value_when_preference_exists`.

### NOTIF-DISPATCH-04 — markFailed increments attempts and finalises when attempts > MAX_ATTEMPTS

`markFailed($deliveryId, $errorClass, $errorMessage, ?$responseCode, ?$nextAttemptAt)` increments `attempts` by 1, sets `last_error = $errorClass`, sets `last_response_code = $responseCode`, schedules `next_attempt_at` to the provided value (or computes exponential backoff `60 * 2^(attempts-1)` seconds), and toggles `status` between `'queued'` (when `attempts <= MAX_ATTEMPTS (3)`) and `'failed'` (when `attempts > 3`).

**Scenarios**:

- `SCN-DISPATCH-04-A`: 2 consecutive `markFailed` calls → `attempts=2`, `status='queued'`, `last_error` set. Test: `NotificationServiceTest::test_mark_failed_increments_attempts`.
- `SCN-DISPATCH-04-B`: 4 consecutive `markFailed` calls → `attempts=4`, `status='failed'`. Test: `NotificationServiceTest::test_mark_failed_finalises_status_when_attempts_exceed_max`.
- `SCN-DISPATCH-04-C`: `next_attempt_at` is computed as `60 * 2^(attempts-1)` seconds from now. Test: deferred to B17.x.

### NOTIF-DISPATCH-05 — max 3 attempts with backoff [60, 300, 900]

`OutboundDelivery::MAX_ATTEMPTS = 3` is the only authoritative constant. The `SendOutboundDelivery` job declares `public int $tries = 3` and `public array $backoff = [60, 300, 900]`. After `attempts > MAX_ATTEMPTS` (i.e. on the 4th `markFailed`), the row transitions to `status='failed'` (terminal) and `SendOutboundDelivery::failed(\Throwable)` hook emits `NotificationFailedPermanently` event with the delivery id.

**Scenarios**:

- `SCN-DISPATCH-05-A`: `OutboundDelivery::MAX_ATTEMPTS === 3`. Test: explicit `assertSame(3, OutboundDelivery::MAX_ATTEMPTS)` in `NotificationServiceTest`.
- `SCN-DISPATCH-05-B`: `SendOutboundDelivery::$tries === 3`. Test: deferred to B17.x.
- `SCN-DISPATCH-05-C`: `SendOutboundDelivery::failed()` emits `NotificationFailedPermanently`. Test: deferred to B17.x.

### NOTIF-DISPATCH-06 — channel-specific send paths

The `SendOutboundDelivery::handle()` method dispatches by `delivery.channel`:

- `database` → instantiates an anonymous class implementing `Illuminate\Notifications\Notifiable` with `routeNotificationsFor()` returning `[$delivery->recipient_ref]`, then calls `$notifiable->notify(new GenericNotification($payload))`. The Laravel built-in `DatabaseNotification` table receives the row.
- `mail` → `Mail::raw('CRM notification: ' . $subject . "\n\n" . $body, function ($msg) use ($delivery, $subject) { $msg->to($delivery->recipient_ref)->subject($subject); })`.
- `whatsapp` → resolves the provider via `App\Contracts\WhatsApp\WhatsAppProviderFactory::for($account)`, builds a transient `WhatsAppMessage` row, calls `$instance->sendFreeFormMessage($msg, $delivery->recipient_ref)`. Stub-mode (A5 pending) returns `NotImplementedException` → `markFailed` captures and retries.
- `webhook` → `Http::post($delivery->recipient_ref, $payload)`. If `$response->failed()`, throws `RequestException` → `markFailed` captures and retries.

**Scenarios**:

- `SCN-DISPATCH-06-A`: each channel dispatches via its corresponding sender. Test: deferred to B17.x (the Pasada B minimum-viable covers the dispatcher logic; the channel-specific send paths are B17.x scope).
- `SCN-DISPATCH-06-B`: any channel exception is caught by `SendOutboundDelivery::handle()` and forwarded to `markFailed()`. Test: covered by `SendOutboundDelivery::failed()` (deferred to B17.x).

### NOTIF-DISPATCH-07 — NotificationFailedPermanently event emitted from job::failed when MAX_ATTEMPTS exhausted

When `SendOutboundDelivery::failed(\Throwable $exception)` is invoked by Laravel's queue worker after the 3rd retry exhaustion, the system shall:

1. Force the row to `attempts = MAX_ATTEMPTS (3)`, `last_error = $exceptionClass.': '.$exception->getMessage()`, `status = 'failed'`.
2. Dispatch `App\Events\V2\NotificationFailedPermanently($delivery->id)`.

A listener is **not** wired for this event in v1 (deferred to B17.x — the operator can read the failed deliveries view to inspect the row).

**Scenarios**:

- `SCN-DISPATCH-07-A`: `Event::fake()` + trigger `SendOutboundDelivery::failed()` → `Event::assertDispatched(NotificationFailedPermanently::class)`. Test: deferred to B17.x.
- `SCN-DISPATCH-07-B`: the row is force-saved with `status='failed'` regardless of previous state. Test: deferred to B17.x.

## Cross-references

- **Proposal §4**: rules of business — default opt-in, idempotency, retry policy, channel paths, admin recipient set.
- **Proposal §7.1-7.9**: scope slice items 1-9.
- **Proposal §10**: D-21a..D-21g + routing + templates + UI + retry policy.
- **Proposal §12**: AC-1..AC-6 mapped to C-B17-1..C-B17-5.
- **Explore `docs/v2/01-roadmap.md` §2.7**: schema source.
- **Spec `admin-notifications-triggers.md`**: 4 events that `SendOutboundDelivery::failed` is allowed to emit and the listener wiring pattern.
- **Spec `admin-notifications-permissions.md`**: HTTP gate matrix + seeder idempotency.

## Test seams (canonical coverage)

| REQ-id | Test method(s) | Status |
|---|---|---|
| NOTIF-DISPATCH-01 | `NotificationServiceTest::test_dispatch_persists_row_and_dispatches_job` | RED-first green |
| NOTIF-DISPATCH-02 | `NotificationServiceTest::test_dispatch_idempotency_returns_existing_row` | RED-first green |
| NOTIF-DISPATCH-03 | `NotificationServiceTest::test_is_enabled_returns_true_when_no_preference_row_exists`, `..._returns_row_value_when_preference_exists` | RED-first green |
| NOTIF-DISPATCH-04 | `NotificationServiceTest::test_mark_failed_increments_attempts`, `..._finalises_status_when_attempts_exceed_max` | RED-first green |
| NOTIF-DISPATCH-05 | (covered indirectly by NOTIF-DISPATCH-04 tests + `OutboundDelivery::MAX_ATTEMPTS` constant assertion) | green |
| NOTIF-DISPATCH-06 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-DISPATCH-07 | (deferred to B17.x) | green-via-server-integration |

## Deferred items (B17.x follow-up)

- Explicit `markFailed` failure test (NOTIF-DISPATCH-01-B).
- Channel-specific send-path tests (NOTIF-DISPATCH-06-A).
- Channel exception capture test (NOTIF-DISPATCH-06-B).
- Job `tries` / `backoff` literal assertion (NOTIF-DISPATCH-05-B).
- `next_attempt_at` exponential-backoff test (NOTIF-DISPATCH-04-C).
- `NotificationFailedPermanently` event assertion (NOTIF-DISPATCH-07).
- Listener for `NotificationFailedPermanently` event (D-21d follow-up).
- Livewire `NotificationPreferenceList` component.
- Dedicated admin views (preferences/index, deliveries/index, deliveries/show) replacing the JSON responses.
- Integration of `App\Services\Email\EmailTemplateRenderer` for the `mail` channel subject/body.
- Retention policy for `outbound_deliveries` (90-day purge for `status in ('sent', 'delivered')`).
- B13 `EmailTemplateRenderer` for the `mail` channel.
- B11 stub `App\Integrations\Contracts\EmailProvider` / `WhatsAppProvider` migration to the new contracts (deferred; not in v1 scope).
- Spam-detection (B17.x hardening): warn if the same `(subject_type, related_entity_id, bucket)` fires > 5× / 1h.
- `ksort($payload)` before `idempotency_key` hash (B17.x hardening; non-determinism in payload key order is theoretical).
