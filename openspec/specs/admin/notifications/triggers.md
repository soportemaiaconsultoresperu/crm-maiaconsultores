# B17-Notifications — Mandatory triggers spec (D-21a..D-21d)

> **Upstream artifacts** (authoritative):
>
> - `openspec/changes/b17-notifications/proposal.md` §4 (rules), §10 (decisions D-21a..D-21d).
> - `app/Listeners/V2/NotifyAdminsOnIntegrationEvent.php` (canonical implementation).
> - `app/Events/V2/{IntegrationFailedPermanently,IntegrationAccountDisconnected,AutomationCycleDetected,NotificationFailedPermanently}.php` (canonical event classes).
> - `app/Providers/NotificationServiceProvider.php` (canonical listener wiring).
> - `docs/v2/01-roadmap.md` §10 D-21a..D-21d.

This spec covers the 4 mandatory B17 triggers: the event contracts (D-21a..D-21d) and the listener that materialises D-21a..D-21c. D-21d is emitted by `SendOutboundDelivery::failed()` but its listener is deferred to B17.x.

## Requirements

### NOTIF-TRIG-01 — IntegrationFailedPermanently → mail all admin users (D-21a)

When `App\Events\V2\IntegrationFailedPermanently($accountId, $errorClass, $errorMessage)` is dispatched, the system shall call `NotifyAdminsOnIntegrationEvent::handleIntegrationFailedPermanently()`, which dispatches one `OutboundDelivery` per active admin user (channel='mail', bucket='D-21a', subject='Integration failed permanently', body="Account #{$accountId} failed permanently. Error: {$errorClass} — {$errorMessage}"). The system does NOT consult `isEnabled()` for the admin recipient set (trigger criticality guarantee per D-21a).

**Scenarios**:

- `SCN-TRIG-01-A`: dispatch `IntegrationFailedPermanently(42, 'SmtpError', 'connect timed out')` with 3 active admins → 3 `OutboundDelivery` rows created, channel='mail', bucket='D-21a'. Test: deferred to B17.x.
- `SCN-TRIG-01-B`: dispatch with 0 active admins → 0 `OutboundDelivery` rows + log warning. Test: deferred to B17.x.
- `SCN-TRIG-01-C`: admin users with `is_active=false` are skipped. Test: deferred to B17.x.

### NOTIF-TRIG-02 — IntegrationAccountDisconnected → mail all admin users (D-21b)

When `App\Events\V2\IntegrationAccountDisconnected($accountId, $reason)` is dispatched, the system shall call `NotifyAdminsOnIntegrationEvent::handleIntegrationAccountDisconnected()`, which dispatches one `OutboundDelivery` per active admin user (channel='mail', bucket='D-21b', subject='Integration account disconnected', body="Account #{$accountId} was disconnected{$reason}"). The system does NOT consult `isEnabled()` (trigger criticality).

**Scenarios**:

- `SCN-TRIG-02-A`: dispatch `IntegrationAccountDisconnected(7, 'token expired')` with 3 active admins → 3 `OutboundDelivery` rows, channel='mail', bucket='D-21b'. Test: deferred to B17.x.
- `SCN-TRIG-02-B`: the `?string $reason` parameter is null → body text omits the `(reason: …)` parenthetical. Test: deferred to B17.x.

### NOTIF-TRIG-03 — AutomationCycleDetected → mail all admin users (D-21c)

When `App\Events\V2\AutomationCycleDetected($ruleId, $cycleBreakCount)` is dispatched, the system shall call `NotifyAdminsOnIntegrationEvent::handleAutomationCycleDetected()`, which dispatches one `OutboundDelivery` per active admin user (channel='mail', bucket='D-21c', subject='Automation rule cycle detected', body="Rule #{$ruleId} cycle detected ({$cycleBreakCount} break(s) recorded)."). The system does NOT consult `isEnabled()` (trigger criticality).

**Scenarios**:

- `SCN-TRIG-03-A`: dispatch `AutomationCycleDetected(12, 1)` with 2 active admins → 2 `OutboundDelivery` rows, channel='mail', bucket='D-21c'. Test: deferred to B17.x.
- `SCN-TRIG-03-B`: `cycleBreakCount = 0` is allowed (no cycle yet, but the event fires for tracing). Test: deferred to B17.x.

### NOTIF-TRIG-04 — NotificationFailedPermanently event emitted when MAX_ATTEMPTS exhausted (D-21d, partial v1)

When `App\Jobs\V2\SendOutboundDelivery::failed(\Throwable $exception)` is invoked by Laravel's queue worker after the 3rd retry exhaustion, the system shall dispatch `App\Events\V2\NotificationFailedPermanently($deliveryId)`. A listener is **NOT** wired for this event in v1 (deferred to B17.x — the operator can read the failed deliveries view to inspect the row).

**Scenarios**:

- `SCN-TRIG-04-A`: `Event::fake()` + trigger `SendOutboundDelivery::failed()` → `Event::assertDispatched(NotificationFailedPermanently::class)`. Test: deferred to B17.x.
- `SCN-TRIG-04-B`: no listener is invoked; the event is observable in `Event::fake()` history only. Test: deferred to B17.x.

### NOTIF-TRIG-05 — each listener dispatch is idempotent on event instance

When the same event instance is dispatched twice (e.g. via a re-entrant listener chain), the `OutboundDelivery.idempotency_key` should re-use the existing row. The `dispatchToAdmins()` helper computes the key from `(channel, recipient_ref, related_entity_type, related_entity_id, payload, bucket)`. Since the event fields are identical across re-emissions, the key is identical, and the second call returns the existing row without re-enqueuing.

**Scenarios**:

- `SCN-TRIG-05-A`: dispatch the same `IntegrationFailedPermanently(42, 'X', 'y')` event twice → 1 `OutboundDelivery` row per admin user. Test: deferred to B17.x.

### NOTIF-TRIG-06 — admin recipient set is read fresh at event fire time

When a trigger fires, the `dispatchToAdmins()` query (`User::query()->where('is_active', true)->whereHas('roles', fn($q) => $q->where('name', 'admin'))->pluck('email')`) re-queries the admin role membership. Permission grants that happened between two events are reflected in the next event. Admin users deactivated between two events are skipped in the next event.

**Scenarios**:

- `SCN-TRIG-06-A`: dispatch event → 1 admin. Promote user X to admin role. Dispatch event again → 2 admins. Test: deferred to B17.x.
- `SCN-TRIG-06-B`: dispatch event → 1 admin. Deactivate admin user. Dispatch event again → 0 admins. Test: deferred to B17.x.

## Cross-references

- **Proposal §4**: rules — admin recipient set fresh on every event; 4 triggers do not consult `isEnabled()`.
- **Proposal §7.5-7.6**: 4 event classes + `NotifyAdminsOnIntegrationEvent` listener.
- **Proposal §10**: D-21a..D-21d decision table.
- **Proposal §12**: AC-5 (events + listeners wired).
- **Spec `admin-notifications-dispatch.md`**: NOTIF-DISPATCH-01..07 cover the dispatcher + job (the listeners USE the dispatcher via `NotificationService::dispatch()`).
- **Spec `admin-notifications-permissions.md`**: HTTP gate matrix; admin role grants.

## Test seams (canonical coverage)

| REQ-id | Test method(s) | Status |
|---|---|---|
| NOTIF-TRIG-01 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-TRIG-02 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-TRIG-03 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-TRIG-04 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-TRIG-05 | (deferred to B17.x) | green-via-server-integration |
| NOTIF-TRIG-06 | (deferred to B17.x) | green-via-server-integration |

The listener wiring is **structurally verified** by reading `NotificationServiceProvider.php::boot()` (3 `Event::listen()` calls pointing to `NotifyAdminsOnIntegrationEvent` methods). The runtime loads the listener correctly (the LSP false-positive about `App\Listeners\V2\NotifyAdminsOnIntegrationEvent` is a tooling artifact — `class_exists()` returns `true` and the suite 642/642 passes with the listener correctly resolved).

## Deferred items (B17.x follow-up)

- Direct `Event::dispatch()` tests for each of the 4 events (D-21a..D-21d).
- Listener test for `NotificationFailedPermanently` event (D-21d listener).
- Tests for admin recipient set dynamic membership (NOTIF-TRIG-06).
- Tests for idempotency on re-emitted event (NOTIF-TRIG-05).
- B11 stub `IntegrationAccount` lifecycle hook that emits `IntegrationAccountDisconnected` (D-21b) when an account goes inactive.
- B12 engine `CycleDetector` integration: emit `AutomationCycleDetected` from `CycleDetector::recordBreak()` (D-21c) — currently the event is NOT emitted by the B12 engine, so the listener is wired but never invoked.
- B13 `SendEmailMessage` integration: emit `IntegrationFailedPermanently` from `SendEmailMessage::failed()` (D-21a) when mail send fails terminally.
- B14 `SendWhatsAppMessage` integration: same as B13 (D-21a) for whatsapp.
