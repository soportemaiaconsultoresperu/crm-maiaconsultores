# B12-UI — archive-report (sdd-archive)

> **Phase**: sdd-archive (final SDD phase — read-only on application surfaces; no code, no test, no route/view edits).
> **Change**: `b12-ui` — Automation Engine Administration UI.
> **Authoritative upstream**: `verify-report.md` §0 (`status: passed`), `tasks.md` (7 chained chunks, 0 unchecked `- [ ]` markers — verified), `apply-progress.md` (cumulative evidence), `config.yaml` (artifact store `openspec`, execution mode `interactive`).
> **Workspace**: `C:\laragon\www\crm-maia-consultores`.
> **No git in this workspace** (per B10 decision — out of scope for this archive).

---

## §0 Verdict

**status: `archived`**

- All 12 acceptance criteria (`AC-1`..`AC-12`) **passed** per `verify-report.md` §1.
- Engine regression guard (`AutomationEngineTest`) **10/10 / 21 assertions** green (re-run by this phase — no drift).
- Full Laravel suite **540/540 / 1955 assertions / 70.1 s** green (re-run by this phase — byte-stable vs. verify baseline 69.6 s).
- 0 unchecked `- [ ]` implementation task markers in `tasks.md` (verified by `grep -cE '^\s*- \[ \]' openspec/changes/b12-ui/tasks.md` → `0`); the file uses a chunk/table forecast format (7 chunks across PR 1..7) per `delivery_strategy: chained_prs_recommended` documented in `tasks.md` Review Workload Forecast.
- Delta-spec sync (`sdd-sync`) executed verbatim — 6 module specs mirrored from `openspec/changes/b12-ui/specs/admin-automations-{crud,conditions,actions,history,permissions,ui-conventions}.md` → `openspec/specs/admin/automations/{crud,conditions,actions,history,permissions,ui-conventions}.md` with **byte-for-byte SHA256 match** for every file.
- 5 non-blocker partial REQ-id coverages documented below (§4); none affect the AC-level verdict.
- The change directory `openspec/changes/b12-ui/` is left in place as the change's audit trail. A single-line `STATUS.txt` marker records the closure.

---

## §1 Phase timeline

```
sdd-init      →  openspec/config.yaml project context (crm-maia / b12-ui, Laravel 13.25, Livewire 4, Spatie, strict_tdd: true)
sdd-explore   →  openspec/changes/b12-ui/explore.md  (engine surface + placeholder map + 15 gotchas + 12 product-round questions)
sdd-proposal  →  openspec/changes/b12-ui/proposal.md (PRD + AC-1..AC-12 + 12 locked decisions in §10)
sdd-spec      →  openspec/changes/b12-ui/specs/admin-automations-{crud,conditions,actions,history,permissions,ui-conventions}.md
                  (6 module specs, 58 REQ-ids)
sdd-design    →  openspec/changes/b12-ui/design.md  (13 architectural decisions + Livewire tree + test seams + spec↔test cross-ref §14)
sdd-tasks     →  openspec/changes/b12-ui/tasks.md  (7 chained chunks, stacked-to-main, ≈ 5,620 LOC forecast)
sdd-apply     →  PR 1 (Chunk 1: permissions + routing skeleton)
                  PR 2 (Chunk 2: index + papelera + toggle)
                  PR 3 (Chunk 3: condition builder + RuleForm)
                  PR 4 (Chunk 4a: ActionEditor + 9 widgets)
                  PR 5 (Chunk 4b: notifications + stubs + simulate + recipient sync + retry hidden)
                  PR 6 (Chunk 5: history + execution + audit + idempotency-copy + badge)
                  PR 7 (Chunk 6: hardening — bulk-ops sweep, docs sync)
                  → apply-progress.md (Stage 3B-1 + 3B-2/3/4 + Stage 3A + Stage 4 + Stage 6 cumulative evidence)
sdd-verify    →  openspec/changes/b12-ui/verify-report.md  (status: passed; AC-1..AC-12 all passed; engine + suite green; 4 non-blocker partials)
sdd-sync      →  openspec/specs/admin/automations/{crud,conditions,actions,history,permissions,ui-conventions}.md  (this phase)
sdd-archive   →  THIS artifact + STATUS.txt + sdds/sdd-archive-b12-ui.md  (now)
```

---

## §2 Final test count

| Metric | Value |
|---|---|
| Total tests | **540 / 540** (`php artisan test`) |
| Total assertions | **1,955** |
| Wall clock | **70.1 s** (Windows-native PHP — within natural variance vs. verify baseline 69.6 s) |
| Engine regression guard (`AutomationEngineTest`) | **10 / 10 / 21 assertions / 1.7 s** |
| Engine + suite drift | **none** — byte-stable envelope vs. `verify-report.md` §3 + §4 |

Re-run during archive phase (no application file touched by this phase, only canonical spec files mirrored):

```
{"tool":"phpunit","result":"passed","tests":540,"passed":540,"assertions":1955,"duration_ms":70123}
{"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":21,"duration_ms":1699}
```

---

## §3 Final AC coverage

| AC | Description | Status |
|---|---|---|
| **AC-1** | Minimum rule authorable via UI | **passed** |
| **AC-2** | Live execution observability (history + steps + response_json + idempotency_key) | **passed** |
| **AC-3** | Simulate preview returns would-be payload | **passed** |
| **AC-4** | Papelera + restore (soft-delete, papelera tab, restore button) | **passed** |
| **AC-5** | DataScope honored on assign_owner user picker | **passed** |
| **AC-6** | B14 stub banners (webhook + send_whatsapp_template) | **passed** (one-character trailing-period cosmetic drift — see §4) |
| **AC-7** | Test-mode purple badge with exact tooltip | **passed** |
| **AC-8** | Idempotency key visible monospace + copy button | **passed** |
| **AC-9** | Audit contextual gating (`@can('automations.audit')`) | **passed** |
| **AC-10** | Retry override hidden (no `retry_policy_json` form input) | **passed** |
| **AC-11** | Drag-to-reorder persistence | **passed** |
| **AC-12** | No bulk-ops buttons rendered | **passed** |

**AC coverage**: **12 / 12 passed**.

---

## §4 Final spec REQ-id coverage

**58 REQ-ids across 6 specs** — **53 fully covered + 5 partial (non-blocker)**.

### §4.1 Fully covered (53 REQ-ids)

Per `verify-report.md` §2 — every remaining REQ-id is backed by at least one passing test + one production file. Spec → REQ-id count:

| Spec file | REQ-ids covered | Count |
|---|---|---:|
| `admin-automations-crud.md` | REQ-CRUD-01, -02, -03, -04, -05, -06, -07, -08 | 8 |
| `admin-automations-conditions.md` | REQ-COND-01, -02, -03, -05, -06, -07 | 6 |
| `admin-automations-actions.md` | REQ-ACT-01, -02, -03, -04, -05, -06, -07, -08 | 8 |
| `admin-automations-history.md` | REQ-HIST-01, -02, -03, -04, -05, -06, -08, -09, -10 | 9 |
| `admin-automations-permissions.md` | REQ-PERM-01, -02, -03, -04, -05, -06, -07, -08, -09 | 9 |
| `admin-automations-ui-conventions.md` | REQ-UI-01, -02, -03, -04, -05, -06, -07, -08, -09, -10, -11, -12, -14 | 13 |
| **Σ fully covered** | | **53** |

### §4.2 Partial coverage — 5 non-blocker REQ-ids

| # | REQ-id | Spec file | Rationale | Severity |
|---|---|---|---|---|
| 1 | **REQ-COND-04** | `admin-automations-conditions.md` | Drag-to-reorder UI on conditions deferred — position column is persisted via `RuleWriterService::reorder(kind='conditions')` (persistence half covered by `AdminAutomationRuleFormTest::test_reorder_persists_new_rule_sequence` for `kind=rules`). The `wire:sort` directive is the JS-over-Livewire polish item slated for a future sdd-apply. No AC depends on the drag UI; **AC-11** (reorder persistence) is fully covered. | minor / non-blocker |
| 2 | **REQ-ACT-09** | `admin-automations-actions.md` | Drag-to-reorder UI on actions — same caveat as REQ-COND-04. Persistence half is covered; visual drag overlay is the polish item. No AC depends on the drag UI; **AC-11** is fully covered. | minor / non-blocker |
| 3 | **REQ-COND-08** | `admin-automations-conditions.md` | "Trigger removed from catalog" — `StoreRuleRequest` and `UpdateRuleRequest` accept `trigger_event` as `'required', 'string', 'max:191'`; no `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` guard on the FormRequests, and no dedicated test asserting the "Trigger no disponible en el catálogo actual" 422 + warning `<x-alert>`. The dropdown in `RuleForm.php:182` (`getTriggersProperty()`) renders the 19 FQCNs from the catalog source of truth, so a removed trigger cannot be selected via the UI under normal conditions. This is a defensive edge-case guard for the "refactor removes a trigger between save and re-edit" path. | minor / non-blocker |
| 4 | **REQ-HIST-07** | `admin-automations-history.md` | Cycle-break `<details>` block in execution detail — rendering is plumbed in `execution.blade.php` (per design §7.3); no dedicated Feature test asserts the cycle-break rows are rendered. Cycle-breaks are rare (30 s window — `CycleDetector::DEFAULT_WINDOW_SECONDS`); the integration test for the cycle window is exercised in the engine tests. No AC depends on this REQ. | minor / non-blocker |
| 5 | **REQ-UI-13** | `admin-automations-ui-conventions.md` | A11y + semantics baseline (modals with `aria-labelledby`, keyboard accessibility, semantic tables) — ARIA attributes + semantic markup are present in views (`aria-label`, `aria-current`, `<th scope="col">` via `<x-table>`); no automated Playwright/Dusk smoke test shipped. `apply-progress.md` §Stage 6 explicitly notes "AutomationVisualSmokeTest was not shipped (the brief marks it optional and explicitly says 'if skipped, defer to manual a11y review')". No AC depends on automated a11y testing. | minor / non-blocker |

**REQ coverage**: **53 / 58 fully + 5 partial (non-blocker) = 58 / 58 total**.

### §4.3 Cosmetic (AC-level, not REQ-level)

`verify-report.md` §5.1 records a one-character trailing-period drift on the AC-6 B14 banner copy (`Pendiente (B14) — esta acción fallará con NotImplementedException hasta que se entregue B14.` vs. task-brief literal without period). Semantic content is identical; this is a Spanish closing-punctuation choice. AC-7's byte-for-byte `Modo test: simuló, no ejecutó acciones reales` matches exactly. Severity: cosmetic / non-blocker.

---

## §5 Known deferred / follow-up items

The following items are acknowledged as **non-blocker** follow-ups for a future change ticket (recommended carrier: `b12.5-ui-polish` or a dedicated follow-up proposal):

1. **REQ-COND-04 + REQ-ACT-09 — drag-to-reorder polish** (the user's "drag-reorder polish" item). Add `wire:sort` JS hook + visual drag handles on conditions + actions; persistence layer is already in place via `RuleWriterService::reorder(kind=...)`. Reuse the pattern from `kind=rules` already exercised in `AdminAutomationRuleFormTest::test_reorder_persists_new_rule_sequence`.
2. **REQ-COND-08 — trigger catalog guard**. Add `Rule::in(AutomationServiceProvider::TRIGGER_EVENTS)` to `UpdateRuleRequest` (store path is reachable from the dropdown so a guard would be redundant there). Add a `ConditionRemovedTriggerTest` that seeds a rule with a bogus trigger and asserts the 422 + warning `<x-alert>` in `show.blade.php`.
3. **REQ-HIST-07 — cycle-break rendering test**. Add a Feature test that creates an `AutomationCycleBreak` row for an execution, asserts the `<details>` block in `execution.blade.php` renders the row with the cycle-break badge + `AutomationExecutionStatus::label()` copy.
4. **REQ-UI-13 — a11y smoke (Playwright/Dusk)**. Add the `AutomationVisualSmokeTest` per `apply-progress.md` §Stage 6 plan; covers index → create → edit → simulate → restore → audit visibility.
5. **AC-6 cosmetic — strip trailing period** from the B14 banner in `resources/views/livewire/admin/automations/widgets/webhook-widget.blade.php:4` and `send-whatsapp-template-widget.blade.php:4`. The `apply-progress.md` records the text without the period in its evidence table; the rendered file carries it. Optional — not required for archive.
6. **B14 (webhook + WhatsApp) stub unwiring**. The "Pendiente (B14)" banners in `webhook-widget.blade.php:4` and `send-whatsapp-template-widget.blade.php:4` (the user's "B14 stubs when B14 lands" item) remain the truth visible to the admin until B14 ships and the banners can be removed. The widgets' inputs stay inert because `WebhookAction` and `SendWhatsAppTemplateAction` throw `NotImplementedException` until B14.
7. **B13 (email template catalog)** can introduce new action types. The widget matrix follows `ActionRegistry::registered()` so new types render automatically without touching the editor component.

---

## §6 Rollback note

This change added **no migrations** and **no engine contract changes**. The scope is restricted to:

- `app/` — controllers, FormRequests, Livewire components, services (rules engine untouched)
- `resources/views/` — admin/automations/*+ livewire/admin/automations/* + components/*
- `routes/web.php` — extending the existing B12 route group
- `tests/Feature/Admin/Automations/**` + `tests/Unit/Admin/Automations/**` — new test files
- `docs/` — AVANCE.md + v2/01-roadmap.md append entries
- `sdds/` — SDD phase envelopes
- `openspec/changes/b12-ui/` — change artifacts (preserved as audit trail)
- `openspec/specs/admin/automations/` — canonical spec files (6 mirrored modules)

The 7 engine migrations `database/migrations/2026_08_18_0100{00..60}_*.php` were **not touched**. The `AutomationServiceProvider` (5 Spatie permissions + ACTION_TYPES + TRIGGER_EVENTS) is registered at boot and the 4 non-`view` permissions were *registered-but-unenforced* before this change; B12-UI only added UI/server enforcement of permissions that were already registered.

**No git in this workspace** — confirmed by `config.yaml` `repository: none` and the B10 audit decision. **Rollback is `git revert` only after the user initializes git + commits** — this is **out of scope for this archive**. When the user initializes git post-archive, a single `git revert` of the b12-ui commit (or revert of the chained PR 1..7 stack) returns to the pre-B12-UI placeholder state. No DDL, no schema rollback needed.

---

## §7 sdd-sync evidence — 6 verbatim mirror copies

Mirror source → destination with **SHA256 byte-for-byte match** (no edits, no wrappers, no canonical header added):

| # | Source (change artifact) | Destination (canonical) | Source SHA256 | Mirrored SHA256 | Match | Lines | Bytes | Status |
|---|---|---|---|---|---|---:|---:|---|
| 1 | `openspec/changes/b12-ui/specs/admin-automations-crud.md` | `openspec/specs/admin/automations/crud.md` | `c9a07acc381d65005ce5a262a7b66c36e61a4950a6b06b44fc02d5fd8a7f66fc` | `c9a07acc381d65005ce5a262a7b66c36e61a4950a6b06b44fc02d5fd8a7f66fc` | ✓ | 120 | 8,638 | mirrored |
| 2 | `openspec/changes/b12-ui/specs/admin-automations-conditions.md` | `openspec/specs/admin/automations/conditions.md` | `6034bef43b61ee35cb03d9d314d9a75b581b8621392475aa4ef9cfe8aee0e7ff` | `6034bef43b61ee35cb03d9d314d9a75b581b8621392475aa4ef9cfe8aee0e7ff` | ✓ | 116 | 8,069 | mirrored |
| 3 | `openspec/changes/b12-ui/specs/admin-automations-actions.md` | `openspec/specs/admin/automations/actions.md` | `9c6efb7423374cc19ea47c33af6cb497427952b697912cba3f3f6227a2e5f09b` | `9c6efb7423374cc19ea47c33af6cb497427952b697912cba3f3f6227a2e5f09b` | ✓ | 135 | 11,599 | mirrored |
| 4 | `openspec/changes/b12-ui/specs/admin-automations-history.md` | `openspec/specs/admin/automations/history.md` | `6672a95e766bc5aadedac1efe1ae959cce25e3ff4a4bb32fd0bd6a07bac46697` | `6672a95e766bc5aadedac1efe1ae959cce25e3ff4a4bb32fd0bd6a07bac46697` | ✓ | 125 | 8,809 | mirrored |
| 5 | `openspec/changes/b12-ui/specs/admin-automations-permissions.md` | `openspec/specs/admin/automations/permissions.md` | `4008f849c191457442072b128592a8ca582b865fa0640a4a594c5b63a09c0ca1` | `4008f849c191457442072b128592a8ca582b865fa0640a4a594c5b63a09c0ca1` | ✓ | 122 | 9,407 | mirrored |
| 6 | `openspec/changes/b12-ui/specs/admin-automations-ui-conventions.md` | `openspec/specs/admin/automations/ui-conventions.md` | `eae54d7c4c46b340a7417979ee03a59b360ab725e0a44bc4da557857d1699131` | `eae54d7c4c46b340a7417979ee03a59b360ab725e0a44bc4da557857d1699131` | ✓ | 124 | 9,037 | mirrored |
| **Σ** | | | | | **6/6** | **742** | **55,559** | **PASS** |

Append-only policy honored: 6/6 copies created on a previously empty `openspec/specs/` tree; zero skipped, zero clobbers.

`proposal.md`, `design.md`, `tasks.md`, `apply-progress.md`, `verify-report.md`, `explore.md` are **not** promoted to canonical — they remain as change artifacts under `openspec/changes/b12-ui/`.

---

## §8 Structured status / actionContext findings

| Field | Value | Source |
|---|---|---|
| `changeName` | `b12-ui` | status engine JSON |
| `artifactStore` | `openspec` | status engine JSON + `config.yaml` |
| `execution_mode` | `interactive` | `config.yaml` |
| `applyState` | `passed` (overridden from incoming `blocked` by verify + archive) | `verify-report.md` §0 + this artifact |
| `dependencies.verify` | `passed` | `verify-report.md` §0 |
| `dependencies.sync` | `passed` | this artifact §7 |
| `dependencies.archive` | `passed` | this artifact §0 |
| `actionContext.mode` | `repo-local` | status engine JSON |
| `actionContext.workspaceRoot` | `C:\laragon\www\crm-maia-consultores` | status engine JSON |
| `actionContext.allowedEditRoots` | `[C:\laragon\www\crm-maia-consultores]` | status engine JSON |
| `actionContext.warnings` | (none) | this phase |
| `nextRecommended` | none — change archived; follow-up change ticket optional for the 7 items in §5 | this artifact |
| `isNonAuthoritative` | `false` | this phase (authoritative — written by the archive executor) |
| `rules.archive` from `openspec/config.yaml` | n/a — config.yaml has no `rules` section | `config.yaml` |

---

## §9 Archived path

- Change directory left in place as audit trail: `C:\laragon\www\crm-maia-consultores\openspec\changes\b12-ui\` (not moved to `openspec/changes/archive/` per the user's task instructions and the supervisor's directive: "leave the directory in place (it's the change's audit trail)").
- Canonical spec files: `C:\laragon\www\crm-maia-consultores\openspec\specs\admin\automations\` (6 files, 55,559 bytes total, SHA256-verified byte-identical to change sources).
- Status marker: `C:\laragon\www\crm-maia-consultores\openspec\changes\b12-ui\STATUS.txt` (single-line `closed — b12-ui archived`).
- This archive report: `C:\laragon\www\crm-maia-consultores\openspec\changes\b12-ui\archive-report.md`.
- Parent envelope: `C:\laragon\www\crm-maia-consultores\sdds\sdd-archive-b12-ui.md`.

---

**End of archive-report.**
