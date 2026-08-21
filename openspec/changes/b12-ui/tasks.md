# B12-UI — Implementation Tasks (sdd-tasks)

> **Phase**: sdd-tasks (no code, no tests authored; this artifact only plans them).
> **Upstream artifacts (authoritative)**:
>
> - `openspec/changes/b12-ui/explore.md` — engine + placeholder surface map.
> - `openspec/changes/b12-ui/proposal.md` — PRD + 12 locked decisions (§10) + AC-1..AC-12 (§12).
> - `openspec/changes/b12-ui/specs/admin-automations-{crud,conditions,actions,history,permissions,ui-conventions}.md` — 6 module specs, 58 REQ-ids.
> - `openspec/changes/b12-ui/design.md` — 13 architectural decisions; §3 Livewire tree; §11 test seams; §12 file map; §14 spec↔test cross-ref.
> - `openspec/config.yaml` — Laravel 13.25 / PHP 8.3.16 / Livewire 4 / Spatie Permission + Activitylog / AdminLTE/Bootstrap 5, `strict_tdd: true`, artifact store `openspec`, execution mode `interactive`.
>
> **V1 success bar**: 12 acceptance criteria AC-1..AC-12 (`proposal.md` §12). **Locked decisions** (4 explicit + 8 firmadas) live in `proposal.md` §10 and are NOT re-opened here.
> **No-goals**: bulk ops, replay, manual event emission, retry-policy editing, schedule preview, inbound webhook trigger, idempotency-key editing, migrations, `audit.view` reuse, i18n stack, JSON-raw editor — all restated in `proposal.md` §8.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines (additions + modifications) | ≈ **5,500** (production ~3,200 + tests ~2,100 + docs ~150) |
| Review budget (parent preflight — config.yaml carries none) | **600** lines per PR |
| 600-line budget risk | **High** — every production chunk + tests exceeds 600 |
| Chained PRs recommended | **Yes** |
| Suggested split | 7 stacked PRs in order 1 → 2 → 3 → 4a → 4b → 5 → 6 |
| Delivery strategy | **ask-on-risk** (parent decides single-PR exception vs chain) |
| Chain strategy | **stacked-to-main** (each PR independently reviewable; no long-lived branches) |
| Largest PR | PR 5 (Chunk 5) ≈ **1,250 LOC** — exceeds 600 by ~108% |
| Decision needed before apply | **Yes** — confirm stacked-to-main vs size-exception for PRs 4a/5 |

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
600-line budget risk: High
```

### Per-PR LOC table (chained_prs_recommended verdict)

| PR | Chunk | Production LOC | Test LOC | Total | Over 600? |
|---|---|---:|---:|---:|---|
| PR 1 | Chunk 1 — Permissions & routing skeleton | ~560 | ~180 | **~740** | yes (+23%) |
| PR 2 | Chunk 2 — Index + papelera + toggle | ~360 | ~300 | **~660** | yes (+10%) |
| PR 3 | Chunk 3 — Condition builder | ~610 | ~280 | **~890** | yes (+48%) |
| PR 4 | Chunk 4a — ActionEditor + 9 write/stage widgets | ~810 | ~610 | **~1,420** | yes (+137%) |
| PR 5 | Chunk 4b — Notifications + stubs + simulate + recipient sync | ~270 | ~400 | **~670** | yes (+12%) |
| PR 6 | Chunk 5 — History + execution + audit + idempotency copy | ~530 | ~560 | **~1,090** | yes (+82%) |
| PR 7 | Chunk 6 — Hardening (smoke + lint + docs) | ~50 | ~100 | **~150** | no |
| **Σ** | | **~3,190** | **~2,430** | **~5,620** | |

> **Note for parent**: PRs 4 and 6 exceed the 600 budget substantially. The parent may (a) accept a size exception for those two PRs, (b) split PR 4 into PR-4a-widgets / PR-4b-ActionEditor+validator+tests, or (c) split PR 6 into PR-6a-history+exec / PR-6b-audit+idempotency+badge. **Decision needed before sdd-apply.**

### Stack-order summary (for `stacked-to-main`)

```
PR 1 (foundation)        ──► gates, controller skeleton, 4 FormRequests, routes, permissions test
PR 2 (read + toggle)     ──► index view, trash tab, toggle/clone/restore buttons
PR 3 (conditions)        ──► RuleForm + ConditionGroupEditor + RulePayloadValidator
PR 4 (actions half-A)    ──► ActionEditor + 9 widgets (write ops + stage mutations)
PR 5 (actions half-B)    ──► 6 remaining widgets + simulate + recipient sync + B14 banners + retry-hidden test
PR 6 (history + audit)   ──► show/exec views + audit partial + idempotency-key copy + test-mode badge
PR 7 (hardening)         ──► BulkOpsAbsentTest + visual smoke + autofix + docs/AVANCE.md entry
```

Each PR is independently buildable; `php artisan test --filter=AdminAutomation` passes at every PR boundary. The existing `AutomationEngineTest` (10 tests, 21 assertions) stays green through all 7 PRs.

---

## A. Implementation chunks

### Chunk 1 — Permissions & routing skeleton  ·  kind: foundation  ·  PR 1

| Item | Value |
|---|---|
| LOC estimate (production) | ~560 |
| LOC estimate (tests) | ~180 |
| LOC total | **~740** |
| Cross-batch dependency | none (root of the stack) |
| Spec REQ-ids covered | CRUD-01..08 (route surface), PERM-01..09 (gate matrix, provider boot), UI-01 (layout yields, partial) |
| AC trace | AC-9 (audit gate wiring), AC-12 (no bulk-ops buttons — guard rail established here) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `routes/web.php` | extend the `Route::controller(AutomationController::class)->group(...)` block at lines 375–380 with the 12 new verbs (see design §2) | modified |
| `app/Http/Controllers/Admin/AutomationController.php` | extend the existing 3-action controller to 13 actions; method shells with `Gate::authorize(...)` + 403 fallback; **do not rewrite existing methods** | modified (+~180) |
| `app/Http/Requests/Admin/Automations/StoreRuleRequest.php` | per design §4.1 field rules + `authorize() === automations.manage` | new |
| `app/Http/Requests/Admin/Automations/UpdateRuleRequest.php` | per design §4.2; `sometimes` + id-aware child rows + trigger-catalog guard | new |
| `app/Http/Requests/Admin/Automations/ReorderRequest.php` | per design §4.3; `kind ∈ {rules, conditions, actions}` dispatch | new |
| `app/Http/Requests/Admin/Automations/SimulateRequest.php` | per design §4.4; `authorize() === automations.test` runs before any registry call | new |
| `tests/Feature/Admin/Automations/AdminAutomationPermissionsTest.php` | SCN-PERM-01..06 verbatim; explicit `app()->register(AutomationServiceProvider::class, force: true)` in `setUp()` (PERM-08) | new |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/AdminAutomationPermissionsTest.php
        (asserts 403 for every write route without automations.manage,
         403 for simulate without automations.test, 403 for every route
         with only automations.webhook.execute — SCN-PERM-01..04,06)

GREEN:  app/Http/Requests/Admin/Automations/{Store,Update,Reorder,Simulate}RuleRequest.php
        app/Http/Controllers/Admin/AutomationController.php
        routes/web.php

REFACTOR: tighten authorize() order, document the "first-statement" rule in PERM-03.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter=AdminAutomationPermissionsTest`.

---

### Chunk 2 — Index + papelera + toggle active  ·  kind: feature  ·  PR 2

| Item | Value |
|---|---|
| LOC estimate (production) | ~360 |
| LOC estimate (tests) | ~300 |
| LOC total | **~660** |
| Cross-batch dependency | `blocked_by: chunk_1` (needs controller shells + permissions) |
| Spec REQ-ids covered | CRUD-01 (index), CRUD-05 (toggle), CRUD-07 (soft-delete), CRUD-08 (restore); partial UI-02..04 |
| AC trace | AC-4 (papelera + restore), AC-12 (no bulk-ops buttons in index), AC-7 (test-mode badge in index) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `resources/views/admin/automations/index.blade.php` | replace 51-line placeholder with full paginated index; pagination + per-row toggle/clone/edit/trash + `@can(...)` gates + `@include('layouts.partials.empty-state')` (UI-03) | modified (51 → ~200, net +150) |
| `resources/views/admin/automations/trash.blade.php` | papelera tab; same controller index filtered by `deleted_at IS NOT NULL` | new |
| `resources/views/components/admin/automations/test-mode-badge.blade.php` | purple `<span class="badge text-bg-purple" title="Modo test: simuló, no ejecutó acciones reales" data-bs-toggle="tooltip">Modo test</span>` | new |
| `resources/views/components/admin/automations/delete-confirm.blade.php` | `<x-modal>` + form posting to `admin.automations.destroy` | new |
| `resources/views/components/admin/automations/restore-button.blade.php` | inline form posting to `admin.automations.restore` | new |
| `app/Http/Controllers/Admin/AutomationController.php` | fill in `trash()`, `toggle()`, `clone()`, `destroy()`, `restore()` bodies — DB transactions + Eloquent `replicate()` for clone (design §10) | modified (PR-1 shells → PR-2 bodies) |
| `tests/Feature/Admin/Automations/AdminAutomationCrudTest.php` | SCN-CRUD-01..03, 06, 07; gate matrix on writes | new |
| `tests/Feature/Admin/Automations/AdminAutomationTrashRestoreTest.php` | SCN-CRUD-05 (restore), SCN-CRUD-06 (trash tab), FK break error rendering (proposal §9.6) | new |
| `tests/Feature/Admin/Automations/AdminAutomationCloneTest.php` | SCN-CRUD-04 verbatim; checks `created_by`, `is_active`, `mode`, name suffix, child row counts | new |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/AdminAutomationCrudTest.php
        tests/Feature/Admin/Automations/AdminAutomationTrashRestoreTest.php
        tests/Feature/Admin/Automations/AdminAutomationCloneTest.php

GREEN:  app/Http/Controllers/Admin/AutomationController.php  (fill in 5 method bodies)
        resources/views/admin/automations/index.blade.php
        resources/views/admin/automations/trash.blade.php
        resources/views/components/admin/automations/{test-mode-badge,delete-confirm,restore-button}.blade.php

REFACTOR: factor the per-row action cluster into a `<x-rule-actions>` partial if it grows past 40 LOC.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='AdminAutomation(Crud|TrashRestore|Clone)Test'`.

---

### Chunk 3 — Condition builder + condition Group persistence  ·  kind: feature  ·  PR 3

| Item | Value |
|---|---|
| LOC estimate (production) | ~610 |
| LOC estimate (tests) | ~280 |
| LOC total | **~890** |
| Cross-batch dependency | `blocked_by: chunk_1` (FormRequests) |
| Spec REQ-ids covered | COND-01..08 (full builder), UI-05 (Livewire 4 attributes), partial UI-02 |
| AC trace | AC-1 (minimum authorable rule), AC-5 (DataScope pre-filter — first-class wired here for `assign_owner` widget) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Livewire/Admin/Automations/RuleForm.php` | dual-purpose `create` + `edit`; `#[Layout('layouts.app')]`; `#[Computed]` for `triggers`, `operators`, `actionTypes`, `visibleUsers`, `visibleTeams`; `mount()` + `save()` | new |
| `app/Livewire/Admin/Automations/ConditionGroupEditor.php` | one instance per group; AND/OR switch (first-group fixed); `#[On('group-updated')]`; `wire:sort` per-group | new |
| `app/Services/Automation/RulePayloadValidator.php` | value_type coercion (COND-06); `is_null` strips value; `in/not_in/between` require array; `bool` canonicalize; date/datetime `Carbon::parse` | new |
| `resources/views/livewire/admin/automations/rule-form.blade.php` | full-page Livewire view; extends `layouts.app` via attribute; renders `<livewire:…condition-group-editor>` per group + placeholder for `<livewire:…action-editor>` | new |
| `resources/views/livewire/admin/automations/condition-group-editor.blade.php` | group header + condition rows + AND/OR switch + drag handles | new |
| `resources/views/admin/automations/partials/_rule_form.blade.php` | thin Blade stub host that delegates to `<livewire:admin.automations.rule-form>` | new |
| `tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php` | SCN-COND-01..07; datalist on field; `is_null` strips value; empty-group auto-remove | new |
| `tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php` | create + edit dual purpose; `Livewire::test` + `set/call/assertSet/assertHasErrors` | new |
| `tests/Unit/Admin/Automations/RulePayloadValidatorTest.php` | every row in the coercion map; COND-06, COND-07 | new |
| `tests/Unit/Admin/Automations/ConditionOperatorValuesTest.php` | exhaustiveness vs. 16 declared constants (regression guard) | new |
| `tests/Unit/Admin/Automations/TriggerCatalogTest.php` | `AutomationServiceProvider::TRIGGER_EVENTS` size = 19, no duplicates | new |

**Tests-first ordering**

```
RED:    tests/Unit/Admin/Automations/{RulePayloadValidatorTest,ConditionOperatorValuesTest,TriggerCatalogTest}.php
        tests/Feature/Admin/Automations/Livewire/ConditionGroupEditorLivewireTest.php
        tests/Feature/Admin/Automations/Livewire/RuleFormLivewireTest.php

GREEN:  app/Services/Automation/RulePayloadValidator.php
        app/Livewire/Admin/Automations/ConditionGroupEditor.php
        app/Livewire/Admin/Automations/RuleForm.php
        resources/views/livewire/admin/automations/{rule-form,condition-group-editor}.blade.php
        resources/views/admin/automations/partials/_rule_form.blade.php

REFACTOR: extract repeated datalist payload into a #[Computed] helper on RuleForm.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='ConditionGroupEditorLivewireTest|RuleFormLivewireTest|RulePayloadValidatorTest'`.

---

### Chunk 4a — ActionEditor + 9 widgets (write ops + stage mutations)  ·  kind: widget-batch  ·  PR 4

| Item | Value |
|---|---|
| LOC estimate (production) | ~810 |
| LOC estimate (tests) | ~610 |
| LOC total | **~1,420** (exceeds 600 by ~137% — parent decision needed: split into PR-4a-widgets + PR-4b-editor? See note below) |
| Cross-batch dependency | `blocked_by: chunk_3` (RuleForm host) |
| Spec REQ-ids covered | ACT-01 (action list editor), ACT-02 (matrix rows for 9 of 11 types), ACT-04 (DataScope pre-filter), ACT-05 (webhook allow-list), partial ACT-09 (drag-reorder plumbing) |
| AC trace | AC-1 (assign_owner widget — DataScope visible), AC-5 (DataScope pre-filter), AC-6 (webhook stub banner) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Livewire/Admin/Automations/ActionEditor.php` | one instance per action row; type selector swap; `WIDGET_MAP` slug→component; drag-reorder; toggle `is_active`; "Eliminar" gate | new |
| `app/Services/Automation/ActionPayloadValidator.php` | per-type ruleset map keyed by `ActionRegistry::registered()` (design §6); `validateMany()` for batches | new |
| `resources/views/livewire/admin/automations/action-editor.blade.php` | Livewire partial for a single action row | new |
| `resources/views/components/admin/automations/action-widget.blade.php` | dispatcher: `<x-admin-automations.action-widget :action="$action" />` resolves to `*-widget` | new |
| `resources/views/components/admin/automations/assign-owner-widget.blade.php` | `recipient_strategy` segmented control + DataScope-filtered user/team picker (ACT-03, ACT-04) | new |
| `resources/views/components/admin/automations/change-status-widget.blade.php` | subject-aware column + value selector (`LeadStatus` / `Customer.status` enum / `PipelineStage`) | new |
| `resources/views/components/admin/automations/change-stage-widget.blade.php` | `PipelineStage` selector + note textarea | new |
| `resources/views/components/admin/automations/add-tag-widget.blade.php` | tag picker + "crear si no existe" checkbox (proposal §9.8) | new |
| `resources/views/components/admin/automations/create-activity-widget.blade.php` | `ActivityType` selector + title + description + datetime + priority + owner | new |
| `resources/views/components/admin/automations/create-follow-up-activity-widget.blade.php` | same + **required** `next_scheduled_at` (SCN-ACT-08) | new |
| `resources/views/components/admin/automations/add-note-widget.blade.php` | body + priority + owner + info note "auto-creates `nota` ActivityType" | new |
| `resources/views/components/admin/automations/webhook-widget.blade.php` | B14 stub banner + URL `<x-select>` from `config('integrations.webhooks.allowed_destinations')` + method + body + headers (repeatable k/v) | new |
| `tests/Feature/Admin/Automations/Livewire/ActionEditorLivewireTest.php` | type swap; per-widget rendering; ACT-03 unified control; ACT-09 drag reorder | new |
| `tests/Feature/Admin/Automations/Livewire/ActionWidgetTypeTest.php` | parameterised over the 11 action types — ensures each renders the right payload keys (ACT-02) | new |
| `tests/Unit/Admin/Automations/ActionPayloadValidatorTest.php` | every row in the RULES map; missing type → no-op; rejects with 422 + bag (ACT-02, ACT-08) | new |
| `tests/Feature/Admin/Automations/WebhookAllowListSurfaceTest.php` | SCN-ACT-05 — empty config disables save; URL outside list renders red alert | new |
| `tests/Unit/Admin/Automations/WebhookAllowListConfigTest.php` | reads `config('integrations.webhooks.allowed_destinations')`; empty = deny | new |
| `tests/Feature/Admin/Automations/RecipientStrategyUnifiedControlTest.php` | SCN-ACT-01 — column + payload_json stay in lockstep after save (ACT-03) | new |

**Tests-first ordering**

```
RED:    tests/Unit/Admin/Automations/ActionPayloadValidatorTest.php
        tests/Unit/Admin/Automations/WebhookAllowListConfigTest.php
        tests/Feature/Admin/Automations/Livewire/ActionEditorLivewireTest.php
        tests/Feature/Admin/Automations/Livewire/ActionWidgetTypeTest.php
        tests/Feature/Admin/Automations/WebhookAllowListSurfaceTest.php
        tests/Feature/Admin/Automations/RecipientStrategyUnifiedControlTest.php

GREEN:  app/Services/Automation/ActionPayloadValidator.php
        app/Livewire/Admin/Automations/ActionEditor.php
        resources/views/livewire/admin/automations/action-editor.blade.php
        resources/views/components/admin/automations/{action-widget,assign-owner-widget,change-status-widget,change-stage-widget,add-tag-widget,create-activity-widget,create-follow-up-activity-widget,add-note-widget,webhook-widget}.blade.php

REFACTOR: collapse the per-widget switch in ActionEditor::WIDGET_MAP into a single associative constant.
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='ActionEditorLivewireTest|ActionWidgetTypeTest|ActionPayloadValidatorTest|WebhookAllowListSurfaceTest|RecipientStrategyUnifiedControlTest'`.

> **Note for parent**: PR 4 exceeds 600 LOC by 137%. Two sub-splits are possible: (a) PR-4a-widgets — only the 9 widget blade files + the `ActionWidgetTypeTest` (≈ 600 LOC); (b) PR-4b-ActionEditor+validator+tests — `ActionEditor.php` + `ActionPayloadValidator.php` + `ActionEditorLivewireTest` + `ActionPayloadValidatorTest` + Webhook/RecipientStrategy tests (≈ 600 LOC). **Decision needed before apply.**

---

### Chunk 4b — Notifications + stubs + simulate + recipient sync + retry hidden  ·  kind: widget-batch  ·  PR 5

| Item | Value |
|---|---|
| LOC estimate (production) | ~270 |
| LOC estimate (tests) | ~400 |
| LOC total | **~670** (slightly over 600) |
| Cross-batch dependency | `blocked_by: chunk_4a` (ActionEditor host) |
| Spec REQ-ids covered | ACT-02 (remaining 2 widget rows: send_notification, send_email), ACT-03 (recipient sync — finalize), ACT-06 (B14 stub banners), ACT-07 (simulate-now), ACT-08 (retry hidden), HIST-05 (test-mode badge), HIST-06 (idempotency key copy), UI-08 (badge styling), UI-09 (B14 pill) |
| AC trace | AC-3 (simulate-now), AC-6 (stub banners + index pill), AC-7 (badge morado), AC-8 (idempotency_key copy), AC-10 (retry hidden) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `resources/views/components/admin/automations/send-notification-widget.blade.php` | user picker + title + body + `level ∈ info|warning|error` | new |
| `resources/views/components/admin/automations/send-email-widget.blade.php` | recipient + subject + body (textarea/Markdown) + `queue` toggle | new |
| `resources/views/components/admin/automations/send-whatsapp-template-widget.blade.php` | B14 stub banner + `account_id` + `variables` repeatable k/v + `phone_number` + `language` | new |
| `resources/views/components/admin/automations/b14-stub-banner.blade.php` | literal banner fragment shared by `webhook-widget` + `send-whatsapp-template-widget` | new |
| `resources/views/components/admin/automations/simulate-button.blade.php` | "Simular ahora" + payload textarea + `<x-modal>` showing `response_json` monospace + `<x-alert type="error">` for caught exceptions (ACT-07) | new |
| `resources/views/components/admin/automations/idempotency-key-copy.blade.php` | `<code class="user-select-all font-monospace">` + "Copiar" + 2s `<x-badge-status variant="success">Copiado</x-badge-status>` toast (HIST-06, UI-07) | new |
| `app/Http/Controllers/Admin/AutomationController.php` | fill in `simulateAction(AutomationRule, AutomationAction, SimulateRequest)` body — calls `ActionRegistry::resolveForAction()->simulate($payload)`, catches `Throwable`, returns the array or error envelope | modified |
| `tests/Feature/Admin/Automations/B14StubBannerTest.php` | SCN-ACT-04 — banner + index pill present (ACT-06, UI-09) | new |
| `tests/Feature/Admin/Automations/TestModeBadgeComponentTest.php` | SCN-HIST-04 — renders exact copy `Modo test: simuló, no ejecutó acciones reales` + `bg-purple` + tooltip (HIST-05, UI-08) | new |
| `tests/Feature/Admin/Automations/RetryPolicyHiddenTest.php` | SCN-UI-06 — grep-equivalent assertion over rendered views (ACT-08, UI-11, AC-10) | new |
| `tests/Unit/Admin/Automations/RecipientStrategyDualWriteTest.php` | invariant: setting `recipient_strategy` on column keeps `payload_json['recipient_strategy']` in sync (ACT-03) | new |
| `tests/Feature/Admin/Automations/Livewire/SimulateNowLivewireTest.php` | SCN-ACT-02, SCN-ACT-03 — calls `ActionRegistry::resolveForAction()->simulate()`; modal renders `response_json`; missing perm → 403 before registry touch (PERM-04) | new |
| `tests/Feature/Admin/Automations/Livewire/IdempotencyKeyCopyComponentTest.php` | clipboard write (jsdom), 2s toast; literal value rendered (HIST-06, UI-07) | new |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/{B14StubBanner,TestModeBadge,RetryPolicyHidden}Test.php
        tests/Unit/Admin/Automations/RecipientStrategyDualWriteTest.php
        tests/Feature/Admin/Automations/Livewire/SimulateNowLivewireTest.php
        tests/Feature/Admin/Automations/Livewire/IdempotencyKeyCopyComponentTest.php

GREEN:  resources/views/components/admin/automations/{send-notification-widget,send-email-widget,send-whatsapp-template-widget,b14-stub-banner,simulate-button,idempotency-key-copy}.blade.php
        app/Http/Controllers/Admin/AutomationController.php  (simulateAction body)

REFACTOR: extract the clipboard + toast helper into a tiny `@clipboard` Blade directive (out of v1 scope; leave TODO).
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='B14StubBanner|TestModeBadge|RetryPolicyHidden|SimulateNowLivewire|IdempotencyKeyCopy|RecipientStrategyDualWrite'`.

---

### Chunk 5 — History + execution detail + audit contextual + idempotency_key copy + badge  ·  kind: integration  ·  PR 6

| Item | Value |
|---|---|
| LOC estimate (production) | ~530 |
| LOC estimate (tests) | ~560 |
| LOC total | **~1,090** (exceeds 600 by ~82% — parent decision needed) |
| Cross-batch dependency | `blocked_by: chunk_2` (controller shells), `blocked_by: chunk_4b` (idempotency copy + badge widgets) |
| Spec REQ-ids covered | HIST-01..10 (full history layer), UI-03 (empty states), UI-06 (TZ directive), UI-10 (no bulk-ops — sweep) |
| AC trace | AC-2 (real execution appears), AC-7 (test-mode badge on history), AC-8 (idempotency key copy), AC-9 (audit contextual gated), AC-12 (no bulk ops — final sweep) |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `app/Livewire/Admin/Automations/HistoryFilter.php` | Livewire form bound to `?status`, `?date_from`, `?date_to`, `?subject`; emits `filter-changed` | new (optional per design §7.2) |
| `app/Http/Controllers/Admin/AutomationController.php` | fill in `auditFeed(AutomationRule)` body — query `Activity::where('subject_type', AutomationRule::class)->where('subject_id', $id)->paginate(10)` (design §8) | modified |
| `resources/views/admin/automations/show.blade.php` | replace 57-line placeholder; rule header + filter form + executions table + audit partial; `@include('layouts.partials.empty-state')` (UI-03) | modified (57 → ~330, net +270) |
| `resources/views/admin/automations/execution.blade.php` | replace 58-line placeholder; summary alert + steps table + `<details>` for `response_json` + cycle breaks `<details>` + error alerts (HIST-04, HIST-07, HIST-09) | modified (58 → ~210, net +150) |
| `resources/views/admin/automations/partials/_audit_changes.blade.php` | `<x-table title="Cambios">` paginated 10/pg; `@can('automations.audit')` wrap; diff `<pre>` (HIST-08, PERM-05) | new |
| `resources/views/admin/automations/partials/_history_filter.blade.php` | plain Blade form posting via GET to keep URL shareable (HIST-02) | new |
| `resources/views/livewire/admin/automations/history-filter.blade.php` | optional Livewire view (only if HistoryFilter is shipped — design §7.2) | new (optional) |
| `tests/Feature/Admin/Automations/AdminAutomationHistoryTest.php` | SCN-HIST-01, 02, 03, 07; filter form URL persistence; empty-state (HIST-01..03) | new |
| `tests/Feature/Admin/Automations/AdminAutomationExecutionDetailTest.php` | SCN-HIST-04..08; idempotency_key copy button DOM; cycle-break badge; error-class surface (HIST-04..07, HIST-09) | new |
| `tests/Feature/Admin/Automations/AdminAutomationAuditBlockTest.php` | SCN-HIST-05, SCN-PERM-03 — `automations.audit` gates the partial; without perm → DOM has no `#audit-changes-block` | new |
| `tests/Feature/Admin/Automations/Livewire/HistoryFilterLivewireTest.php` | URL persistence; clear filter; pagination link retention (HIST-02) | new (optional) |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/AdminAutomationHistoryTest.php
        tests/Feature/Admin/Automations/AdminAutomationExecutionDetailTest.php
        tests/Feature/Admin/Automations/AdminAutomationAuditBlockTest.php
        tests/Feature/Admin/Automations/Livewire/HistoryFilterLivewireTest.php  (optional)

GREEN:  app/Livewire/Admin/Automations/HistoryFilter.php  (optional)
        app/Http/Controllers/Admin/AutomationController.php  (auditFeed body)
        resources/views/admin/automations/show.blade.php
        resources/views/admin/automations/execution.blade.php
        resources/views/admin/automations/partials/{_audit_changes,_history_filter}.blade.php
        resources/views/livewire/admin/automations/history-filter.blade.php  (optional)

REFACTOR: extract the `<x-table>` filter+rows+pagination trio into a single `<x-history-executions>` Blade component (out of v1 scope; leave TODO).
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='AdminAutomation(History|ExecutionDetail|AuditBlock)Test|HistoryFilterLivewireTest'`.

> **Note for parent**: PR 6 exceeds 600 LOC by ~82%. Two sub-splits are possible: (a) PR-6a-history+exec — show/execution views + history tests (≈ 600 LOC); (b) PR-6b-audit+idempotency+badge — `_audit_changes.blade.php` + `auditFeed` + idempotency-key copy + test-mode badge integration (≈ 500 LOC). **Decision needed before apply.**

---

### Chunk 6 — Hardening  ·  kind: hardening  ·  PR 7

| Item | Value |
|---|---|
| LOC estimate (production) | ~50 |
| LOC estimate (tests) | ~100 |
| LOC total | **~150** |
| Cross-batch dependency | `blocked_by: chunk_5` (final view sweep) |
| Spec REQ-ids covered | UI-04 (sidebar unchanged — regression guard), UI-10 (no bulk-ops — final sweep), UI-13 (a11y baseline), UI-14 (Vite alignment), HIST-10 (TZ directive) |
| AC trace | AC-10 (retry hidden — second sweep), AC-12 (no bulk ops — second sweep), regression on AC-1..AC-9 |

**Changed files**

| Path | Section / role | New / Modified |
|---|---|---|
| `tests/Feature/Admin/Automations/BulkOpsAbsentTest.php` | SCN-UI-05 — regex sweep over rendered index for bulk-ops phrases | new |
| `tests/Feature/Visual/AutomationVisualSmokeTest.php` | Playwright/Dusk-style smoke covering index → create → edit → simulate → restore → audit visibility (optional; if skipped, defer to manual a11y review) | new (optional) |
| `resources/views/layouts/partials/sidebar.blade.php` | NO change — regression guard verified by git diff (UI-04) | **unchanged** (verified) |
| `vite.config.js` | if a new JS hook is needed for clipboard fallback, register here (UI-14) — otherwise unchanged | modified (only if needed) |
| `docs/AVANCE.md` | append B12-UI entry — chunk summary, AC checklist, rollback recipe (proposal §11) | modified |
| `docs/v2/01-roadmap.md` | note B12-UI as completed block (B12 sub-block) | modified |
| Autofix cleanup | per R-design-4 — clear MD040/MD056/MD060 warnings + any lint drift in the new files | modified (net negative LOC likely) |

**Tests-first ordering**

```
RED:    tests/Feature/Admin/Automations/BulkOpsAbsentTest.php
        tests/Feature/Visual/AutomationVisualSmokeTest.php  (optional)

GREEN:  docs/AVANCE.md
        docs/v2/01-roadmap.md
        (autofix cleanup is GREEN by definition; no new prod code unless smoke test adds a JS hook)

REFACTOR: consolidate the regression guards (sidebar diff, retry-policy sweep, bulk-ops sweep) into a single `tests/Feature/Admin/Automations/ConformanceTest.php` (out of v1 scope; leave TODO).
```

TDD mode: RED → GREEN → REFACTOR. Test runner: `php artisan test`.
Test command (focused): `php artisan test --filter='BulkOpsAbsentTest|AutomationVisualSmokeTest'`.
Final regression (every PR boundary): `php artisan test --filter=AutomationEngine` (must stay green) and `php artisan test --filter=AdminAutomation` (must pass end-to-end after PR 7).

---

## B. Cross-chunk dependency graph

```
                                ┌─► PR 4 (Chunk 4a)
PR 1 (Chunk 1) ──► PR 2 (Chunk 2) ──► PR 3 (Chunk 3) ──┤
                                                          └─► PR 5 (Chunk 4b) ──► PR 6 (Chunk 5) ──► PR 7 (Chunk 6)
                                          ▲                                                       ▲
                                          │                                                       │
                              RuleForm host reused                                    History views reuse widgets
```

- **PR 1** is the root; every later PR depends on its gates + FormRequests.
- **PR 2** reuses PR 1's controller shells + permission tests for the index/papelera flows.
- **PR 3** is independent of PR 2 (different feature surface) but shares the FormRequest contract from PR 1.
- **PR 4** requires PR 3's `RuleForm` host to host `ActionEditor`.
- **PR 5** requires PR 4's `ActionEditor` to host the remaining widgets + simulate button.
- **PR 6** requires PR 5's idempotency-key copy + test-mode badge widgets for the history views.
- **PR 7** is the final regression sweep + docs sync.

---

## C. TDD invariants enforced across every chunk

1. **RED before GREEN**: every test file listed in a chunk must exist on disk and FAIL (`phpunit --filter=…`) before the production file(s) it covers are written. The PR is not ready for review until all listed tests pass.
2. **Strict TDD cycle**: `RED → GREEN → TRIANGULATE → REFACTOR` (per `config.yaml` `delivery.tdd_cycle`). TRIANGULATE applies when a widget or validator needs >1 case per row (e.g., 11 action types in `ActionWidgetTypeTest`).
3. **Provider boot in `setUp()`**: every Feature test that touches an `automations.*` permission calls `app()->register(\App\Providers\AutomationServiceProvider::class, force: true)` after `RefreshDatabase` runs migrations (PERM-08).
4. **No engine mutation**: tests assert engine contracts are unchanged — `AutomationEngineTest` (10 tests, 21 assertions, `tests/Feature/AutomationEngineTest.php`) must stay green at every PR boundary.
5. **`@can` + server gate are tested separately**: every Feature test that exercises a gated route asserts both (a) UI hides the button (DOM grep) AND (b) the server returns 403 when the gate is missing (PERM-07, SCN-PERM-06).
6. **Livewire tests use `Livewire::test`** with `set/call/assertSet/assertHasErrors/assertDispatched` per `config.yaml` `testing.conventions`. HTTP route/gate coverage stays around the component host.
7. **`Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Event::fake()`** on every Livewire write-path test to avoid hidden side effects (config.yaml).
8. **No `retry_policy_json` field** in any new view — verified by `RetryPolicyHiddenTest` (AC-10).
9. **No bulk-ops buttons** in any new view — verified by `BulkOpsAbsentTest` (AC-12).
10. **TZ directive**: every new view that renders a timestamp uses `America/Lima` (HIST-10, UI-06). The directive itself is registered in PR 6 if absent from the layout.

---

## D. Tasks ↔ design cross-reference (REQ-ids → chunks)

| REQ-id | Spec | Chunk / PR |
|---|---|---|
| CRUD-01 | admin-automations-crud.md | Chunk 2 / PR 2 |
| CRUD-02 | admin-automations-crud.md | Chunk 1 (FormRequest) + Chunk 3 (RuleForm Livewire) |
| CRUD-03 | admin-automations-crud.md | Chunk 1 (UpdateRuleRequest) + Chunk 3 (RuleForm) |
| CRUD-04 | admin-automations-crud.md | Chunk 2 (clone controller) |
| CRUD-05 | admin-automations-crud.md | Chunk 2 (toggle) |
| CRUD-06 | admin-automations-crud.md | Chunk 3 (ReorderRequest) — actions re-rank lands in Chunk 4a |
| CRUD-07 | admin-automations-crud.md | Chunk 2 (destroy) |
| CRUD-08 | admin-automations-crud.md | Chunk 2 (restore) |
| COND-01 | admin-automations-conditions.md | Chunk 3 |
| COND-02 | admin-automations-conditions.md | Chunk 3 |
| COND-03 | admin-automations-conditions.md | Chunk 3 |
| COND-04 | admin-automations-conditions.md | Chunk 3 |
| COND-05 | admin-automations-conditions.md | Chunk 3 |
| COND-06 | admin-automations-conditions.md | Chunk 3 (RulePayloadValidator) |
| COND-07 | admin-automations-conditions.md | Chunk 3 (RulePayloadValidator + UpdateRuleRequest hook) |
| COND-08 | admin-automations-conditions.md | Chunk 1 (UpdateRuleRequest guard) + Chunk 3 |
| ACT-01 | admin-automations-actions.md | Chunk 4a (ActionEditor) |
| ACT-02 | admin-automations-actions.md | Chunk 4a (write/stage widgets + validator), Chunk 4b (notification/email/whatsapp widgets) |
| ACT-03 | admin-automations-actions.md | Chunk 4a (`assign-owner-widget`) + Chunk 4b (RecipientStrategyDualWriteTest) |
| ACT-04 | admin-automations-actions.md | Chunk 4a (`assign-owner-widget` DataScope pre-filter) |
| ACT-05 | admin-automations-actions.md | Chunk 4a (`webhook-widget` + allow-list surface) |
| ACT-06 | admin-automations-actions.md | Chunk 4b (`b14-stub-banner` + index pill) |
| ACT-07 | admin-automations-actions.md | Chunk 4b (`simulate-button` + SimulateRequest + simulateAction) |
| ACT-08 | admin-automations-actions.md | Chunk 4b (`RetryPolicyHiddenTest` — column never read by engine, never written by UI) |
| ACT-09 | admin-automations-actions.md | Chunk 4a (ActionEditor wire:sort + ReorderRequest dispatch) |
| HIST-01 | admin-automations-history.md | Chunk 5 |
| HIST-02 | admin-automations-history.md | Chunk 5 (`HistoryFilter` + `_history_filter.blade.php`) |
| HIST-03 | admin-automations-history.md | Chunk 5 (empty state partial) |
| HIST-04 | admin-automations-history.md | Chunk 5 (`execution.blade.php` + error-class surface) |
| HIST-05 | admin-automations-history.md | Chunk 4b (`test-mode-badge` component) — index/show/exec badge wiring lands in Chunk 5 |
| HIST-06 | admin-automations-history.md | Chunk 4b (`idempotency-key-copy` component) — used by Chunk 5 |
| HIST-07 | admin-automations-history.md | Chunk 5 (cycle-break `<details>` block) |
| HIST-08 | admin-automations-history.md | Chunk 5 (`_audit_changes.blade.php` + `auditFeed`) |
| HIST-09 | admin-automations-history.md | Chunk 5 (error alert in execution view) |
| HIST-10 | admin-automations-history.md | Chunk 5 (TZ directive + audit + history views) |
| PERM-01 | admin-automations-permissions.md | Chunk 1 (provider boot + test base) |
| PERM-02 | admin-automations-permissions.md | Chunk 1 (index/show/exec gates) + every later PR (route-level coverage) |
| PERM-03 | admin-automations-permissions.md | Chunk 1 (FormRequest `authorize()` + first-statement `Gate::authorize`) |
| PERM-04 | admin-automations-permissions.md | Chunk 1 (SimulateRequest) + Chunk 4b (SimulateNowLivewireTest SCN-PERM-02) |
| PERM-05 | admin-automations-permissions.md | Chunk 5 (`_audit_changes.blade.php` + AdminAutomationAuditBlockTest SCN-PERM-03) |
| PERM-06 | admin-automations-permissions.md | Chunk 1 (SCN-PERM-04 — provider registered but no route enforces) |
| PERM-07 | admin-automations-permissions.md | Chunk 1 + every Feature test (UI hide + server 403) |
| PERM-08 | admin-automations-permissions.md | Chunk 1 (AdminAutomationPermissionsTest `setUp`) + applied by every later test |
| PERM-09 | admin-automations-permissions.md | Chunk 1 + every Feature test (`givePermissionTo` + `actingAs`) |
| UI-01 | admin-automations-ui-conventions.md | Chunk 3 (`RuleForm` `#[Layout]`) + Chunk 5 (show/exec views) |
| UI-02 | admin-automations-ui-conventions.md | All chunks — `<x-table>` / `<x-text-input>` / `<x-select>` / `<x-modal>` / `<x-alert>` / `<x-badge-status>` / `<x-validation-error>` vocabulary |
| UI-03 | admin-automations-ui-conventions.md | Chunk 2 (index empty) + Chunk 5 (history/audit empty) |
| UI-04 | admin-automations-ui-conventions.md | Chunk 6 (regression guard — sidebar git diff) |
| UI-05 | admin-automations-ui-conventions.md | Chunk 3 + Chunk 4a + Chunk 5 (Livewire 4 attributes) |
| UI-06 | admin-automations-ui-conventions.md | Chunk 5 (TZ directive) |
| UI-07 | admin-automations-ui-conventions.md | Chunk 4b (idempotency-key-copy component) |
| UI-08 | admin-automations-ui-conventions.md | Chunk 4b (test-mode-badge component) — used in Chunk 5 |
| UI-09 | admin-automations-ui-conventions.md | Chunk 4b (b14-stub-banner) |
| UI-10 | admin-automations-ui-conventions.md | Chunk 6 (BulkOpsAbsentTest final sweep) |
| UI-11 | admin-automations-ui-conventions.md | Chunk 4b (RetryPolicyHiddenTest) |
| UI-12 | admin-automations-ui-conventions.md | All chunks — hard-coded Spanish copy in widgets |
| UI-13 | admin-automations-ui-conventions.md | Chunk 6 (a11y review / smoke) |
| UI-14 | admin-automations-ui-conventions.md | Chunk 6 (Vite alignment check) |

**Coverage**: all 58 REQ-ids across the 6 spec files map to at least one chunk. No orphan requirements. `automation_rules.order` concurrency (design §13.1, proposal R5) is acknowledged in Chunk 3 (ReorderRequest) and Chunk 4a (ActionEditor wire:sort) — both use last-write-wins with engine tie-breaker.

---

## E. AC ↔ chunks cross-reference (AC-1..AC-12 → primary PR)

| AC | Description | Primary PR / chunk | Verification artifact |
|---|---|---|---|
| AC-1 | Minimum rule authorable via UI | PR 3 (Chunk 3) | `RuleFormLivewireTest` |
| AC-2 | Real execution appears in history | PR 6 (Chunk 5) | `AdminAutomationHistoryTest` |
| AC-3 | Simulate-now returns would-be payload | PR 5 (Chunk 4b) | `SimulateNowLivewireTest` |
| AC-4 | Papelera restores soft-deleted | PR 2 (Chunk 2) | `AdminAutomationTrashRestoreTest` |
| AC-5 | DataScope honored on assign_owner | PR 4 (Chunk 4a) | `RecipientStrategyUnifiedControlTest` + `ActionEditorLivewireTest` |
| AC-6 | Stubs labeled (webhook + whatsapp) | PR 5 (Chunk 4b) | `B14StubBannerTest` |
| AC-7 | Test-mode purple badge + exact tooltip | PR 5 (Chunk 4b) component + PR 6 (Chunk 5) integration | `TestModeBadgeComponentTest` + history render assertions |
| AC-8 | Idempotency key visible + copiable | PR 5 (Chunk 4b) component + PR 6 (Chunk 5) integration | `IdempotencyKeyCopyComponentTest` + `AdminAutomationExecutionDetailTest` |
| AC-9 | Audit contextual gated | PR 6 (Chunk 5) | `AdminAutomationAuditBlockTest` |
| AC-10 | Retry override hidden | PR 5 (Chunk 4b) | `RetryPolicyHiddenTest` |
| AC-11 | Drag reorder works | PR 4 (Chunk 4a) actions + PR 3 (Chunk 3) conditions/rules | `AdminAutomationReorderTest` (lives in Chunk 3 per design §11) |
| AC-12 | No bulk ops | PR 2 (Chunk 2) initial guard + PR 7 (Chunk 6) final sweep | `BulkOpsAbsentTest` |

---

## F. Files explicitly NOT touched by B12-UI v1

Per `proposal.md` §11 rollback path + `design.md` §13 + `config.yaml` `boundaries`:

- `database/migrations/2026_08_18_0100{00..60}_*.php` — engine schema authoritative.
- `app/Providers/AutomationServiceProvider.php` — 5 permissions + ACTION_TYPES + TRIGGER_EVENTS already wired.
- `app/Models/{AutomationRule,AutomationConditionGroup,AutomationCondition,AutomationAction,AutomationExecution,AutomationExecutionStep,AutomationCycleBreak}.php` — fillable + casts + relations stay as-is.
- `app/Services/Automation/{ActionRegistry,ConditionEvaluator,CycleDetector}.php` — engine services unchanged.
- `resources/views/layouts/partials/sidebar.blade.php` — UI-04, sidebar already wired at line ~92.
- `tests/Feature/AutomationEngineTest.php` — engine test untouched; stays green through every PR.

---

## G. Blockers / flags for parent

**None**. All engine contracts referenced by the design (idempotency_key formula `sha1(rule_id|event_class|subject_type|subject_id|payload_hash)`; `recipient_strategy` dual-write semantics; 5 Spatie permissions at provider boot; webhook allow-list at `config('integrations.webhooks.allowed_destinations')`; AssignOwnerAction DataScope dead-code bug) match the explore.md and proposal.md surface. The engine bug in `AssignOwnerAction::execute` is compensated by the UI picker pre-filter (ACT-04, design §13.3) and is NOT fixed in v1.

---

**End of tasks.**
