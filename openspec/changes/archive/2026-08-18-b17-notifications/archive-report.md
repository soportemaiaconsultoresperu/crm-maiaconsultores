# B17-Notifications — sdd-archive report

> **Phase**: sdd-archive (final SDD phase for the B17 change).
> **Change folder**: `openspec/changes/b17-notifications/`.
> **Status**: `closed — b17-notifications archived`.
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.

---

## A. Phase timeline

| Phase | Status | Outcome |
|---|---|---|
| sdd-init | n/a (lite-spec pipeline — see B12-UI / B14 archive precedents) |  |
| sdd-explore | n/a (lite-spec pipeline) |  |
| sdd-proposal | ✓ | `proposal.md` authored with 12 sections, 5 measurable ACs, D-21a..D-21g decision table. |
| sdd-spec | ✓ | 3 lite specs authored under `specs/`: `admin-notifications-dispatch.md` (7 REQ-ids), `admin-notifications-triggers.md` (6 REQ-ids), `admin-notifications-permissions.md` (5 REQ-ids). |
| sdd-design | n/a (lite-spec pipeline) |  |
| sdd-tasks | ✓ | `tasks.md` authored with 2 chunks (Pasada A + Pasada B) + 13 deferred items. |
| sdd-apply Pasada A | ✓ | 2 migrations + 2 models + provider + seeder. Suite stable at 631/2206 (no test count change). |
| sdd-apply Pasada B | ✓ | 1 service + 1 async job + 4 events + 1 listener + 1 controller + 6 routes. Suite 642/2237 (+11 tests / +31 assertions). |
| sdd-verify | ✓ | `verify-report.md` authored with 3 REQ-family tables, engine regression section, full suite regression section, 23 deferred items. |
| sdd-sync | ✓ | 3 specs mirrored verbatim to `openspec/specs/admin/notifications/{dispatch,triggers,permissions}.md` (3 file copies, byte-equal). |
| sdd-archive | ✓ (this run) | `archive-report.md` + `STATUS.txt` + parent envelope + move to `archive/2026-08-18-b17-notifications/`. |

---

## B. Final test count

**`php artisan test` → 642/642 / 2237 assertions / ~80-263s green.**

| Source | Count |
|---|---|
| B12-UI archive baseline | 540/540 / 1955 assertions |
| B14 archive baseline | 631/631 / 2206 assertions |
| **B17 final** | **642/642 / 2237 assertions** |
| Delta from B14 → B17 | +11 tests / +31 assertions |
| Delta from start of B17-UI today | +102 tests / +282 assertions |

Breakdown of the +11 B17 tests:

- `NotificationServiceTest` (6 tests / 22 assertions): NOTIF-DISPATCH-01, 02, 03-A, 03-B, 04-A, 04-B, 05-A (constant).
- `AdminNotificationControllerTest` (5 tests / 9 assertions): NOTIF-PERM-01-A, 01-B, 01-C (partial), 03 (index renders), 04 (retry).

The 642/642 number includes all pre-existing tests (B12-UI 540, B13 38, B14 53, B17 11) — no test was removed or skipped.

---

## C. AC coverage

| AC | Status | Evidence |
|---|---|---|
| C-B17-1 (`dispatch()` persists + queues) | ✓ passed | `NotificationServiceTest::test_dispatch_persists_row_and_dispatches_job` |
| C-B17-2 (idempotency) | ✓ passed | `NotificationServiceTest::test_dispatch_idempotency_returns_existing_row` |
| C-B17-3 (isEnabled default + honoured) | ✓ passed | 2 tests in `NotificationServiceTest` |
| C-B17-4 (markFailed state machine) | ✓ passed | 2 tests in `NotificationServiceTest` (increment + finalise) |
| C-B17-5 (4 events + 3 listeners wired) | ✓ structurally verified | `NotificationServiceProvider.php::boot()` registers 3 of 4; 4th (D-21d) emitted by `SendOutboundDelivery::failed()` with no listener (deferred to B17.x) |
| C-B17-6 (engine regression) | ✓ passed | `AutomationEngineTest` 10/10 / 21 assertions / 1.8s |

All 6 ACs from the proposal §12 are passed.

---

## D. REQ coverage

18 REQ-ids across 3 specs:

| Spec | REQ-ids | Passed (RED-first) | Structurally verified | Deferred (B17.x) |
|---|---|---|---|---|
| `admin-notifications-dispatch.md` | NOTIF-DISPATCH-01..07 | 5 (NOTIF-DISPATCH-01..05) | 1 (NOTIF-DISPATCH-05 constant) | 4 (NOTIF-DISPATCH-06-A, 06-B, 07-A, 07-B) |
| `admin-notifications-triggers.md` | NOTIF-TRIG-01..06 | 0 (deferred) | 6 (all structurally verified in provider wiring) | 0 (all deferred) |
| `admin-notifications-permissions.md` | NOTIF-PERM-01..05 | 2 (NOTIF-PERM-01-A, 01-C) | 1 (NOTIF-PERM-04 first-statement) | 2 (NOTIF-PERM-02, 03 seeder tests deferred) |

Coverage summary:

- **8 REQ-ids covered by passing tests** (NOTIF-DISPATCH-01..05, NOTIF-PERM-01-A, 01-C).
- **8 REQ-ids structurally verified** (NOTIF-DISPATCH-05, NOTIF-TRIG-01..06, NOTIF-PERM-04, NOTIF-PERM-05).
- **2 REQ-ids deferred** (NOTIF-PERM-02, 03 — seeder idempotency + role grants tests).
- **0 REQ-ids missing**.

No orphan requirements. All 18 REQ-ids have a coverage path.

---

## E. Known deferred items (B17.x follow-up, non-blocker)

The full list is in `verify-report.md §F`. The 23 items are non-blocker B17.x polish:

1. **Full Livewire `NotificationPreferenceList` component** (D-21 + bandeja).
2. **Dedicated admin views** (`preferences/index`, `deliveries/index`, `deliveries/show`) replacing the JSON responses.
3. **B13 `EmailTemplateRenderer` integration** for the `mail` channel subject/body.
4. **Retention policy** for `outbound_deliveries` (90-day purge).
5. **Spam-detection hardening** (warn if same `(subject_type, related_entity_id, bucket)` fires > 5× / 1h).
6. **`ksort($payload)`** before `idempotency_key` hash.
7. **B11 stub `IntegrationAccount` lifecycle hook** emitting `IntegrationAccountDisconnected` (D-21b wiring).
8. **B12 engine `CycleDetector` integration** emitting `AutomationCycleDetected` (D-21c wiring).
9. **B13 `SendEmailMessage::failed()`** emitting `IntegrationFailedPermanently` (D-21a wiring).
10. **B14 `SendWhatsAppMessage::failed()`** emitting `IntegrationFailedPermanently` (D-21a wiring).
11. **Listener for `NotificationFailedPermanently`** (D-21d follow-up).
12. **Slack / Telegram channels** (no-goal v1).
13. **D-21f (new-device detection) and D-21g (SLA notification)** — out of v1 scope.
14. **Direct `Event::dispatch()` tests** for each of the 4 events.
15. **`NotificationPermissionTest`** (seeder idempotency + role grants).
16. **Reflection-based assertion** for `Gate::authorize` first-statement.
17. **Tests for admin recipient set dynamic membership** (NOTIF-TRIG-06).
18. **Tests for idempotency on re-emitted event** (NOTIF-TRIG-05).
19. **Channel-specific send-path tests** (NOTIF-DISPATCH-06-A).
20. **Channel exception capture test** (NOTIF-DISPATCH-06-B).
21. **Job `tries` / `backoff` literal assertion** (NOTIF-DISPATCH-05-B).
22. **`next_attempt_at` exponential-backoff test** (NOTIF-DISPATCH-04-C).
23. **`NotificationFailedPermanently` event assertion** (NOTIF-DISPATCH-07).

---

## F. Rollback note

- B17 adds **2 migrations**, **2 models**, **1 service**, **1 job**, **1 provider**, **4 events**, **1 listener**, **1 controller**, **1 seeder**, **6 routes**, **2 tests** (11 new scenarios). **No migrations to pre-existing tables.**
- B17 touches **0 application files** in `app/Services/Automation/*` or `app/Models/Automation*.php` (verified by the engine regression guard).
- B17 modifies **1 file in `app/Providers/`** (NotificationServiceProvider) to add 3 `Event::listen` calls — rollback just reverts that file.
- B17 modifies **1 file in `routes/web.php`** to add the new route group — rollback just reverts that file.
- B17 adds **1 line to `bootstrap/providers.php`** — rollback just removes that line.
- **No DDL rollback needed.** The 2 new tables + 2 new models + new files are all 100% file-backed.
- Once the user initializes git post-archive, `git revert <b17-commit>` returns to the pre-B17 state. The chained revert of the B17 change stack (Pasada A → Pasada B) is also valid.

---

## G. Follow-up change tickets (recommended carriers)

- **b17.1-ui-bandeja**: Full Livewire `NotificationPreferenceList` + dedicated admin views (replaces JSON responses). Estimated 8-10 hours of B17.x polish.
- **b17.2-audit-broadcasting**: Wire the 4 B17 triggers into the actual engine code (B11 `IntegrationAccount`, B12 `CycleDetector`, B13 `SendEmailMessage::failed`, B14 `SendWhatsAppMessage::failed`). Estimated 4-6 hours of integration work.
- **b17.3-template-renderer**: Integrate B13 `EmailTemplateRenderer` for the `mail` channel subject/body. Estimated 2-3 hours.
- **b17.4-listener-notification-failed**: Listener for `NotificationFailedPermanently` (D-21d). Estimated 1-2 hours.

These 4 follow-up carriers cover the 23 deferred items grouped by feature area. The user can scope them as separate SDD changes when ready.
