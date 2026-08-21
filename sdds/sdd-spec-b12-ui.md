# SDD Spec — b12-ui (sdd-spec) envelope

> Phase: **sdd-spec**. Phase goal: pin the technical functional contract for
> the approved `b12-ui` change as 6 module-level specs that sdd-apply can
> implement under strict TDD.
> Artifact store: `openspec` (config.yaml `artifact_store: openspec`).
> Skill resolution: `paths-injected` (the executor skills for `sdd-spec` were
> referenced by the parent session and resolved via the active executor).
> Upstream: `openspec/changes/b12-ui/explore.md` (engine & placeholder map)
> and `openspec/changes/b12-ui/proposal.md` (PRD, §4 permisos, §7 scope v1,
> §8 no-goals, §10 decisiones locked, §12 AC-1..AC-12).

---

## status

`ready-for-sdd-apply`. All six module specs are written and cross-referenced to
proposal §4 / §7 / §9 / §10 / §12 and explore §1–§8. No new product decisions
introduced.

---

## executive_summary

B12-UI is delivered as 6 module specs under
`openspec/changes/b12-ui/specs/`, grouping the rule CRUD contract
(`admin-automations-crud.md`), condition AND/OR builder
(`admin-automations-conditions.md`), per-type action editor for the 11
`ActionContract` types with B14 stub banners and simulate-now wiring
(`admin-automations-actions.md`), read-only execution history with
`idempotency_key` copy and test-mode purple badge
(`admin-automations-history.md`), 5-permission enforcement contract
(`admin-automations-permissions.md`), and AdminLTE/Bootstrap 5 + Livewire 4
visual conventions (`admin-automations-ui-conventions.md`). Every spec lists
affected routes, server-side gates, the test seams it expects, and the
proposal ACs it satisfies. No code, migrations, controllers, routes, views,
Livewire components, or tests are written here — that belongs to `sdd-apply`
under `strict_tdd: true`. The proposal's 12 acceptance criteria map onto
specific REQ-ids (e.g. AC-1 → REQ-CRUD-02 + REQ-COND-* + REQ-ACT-01). Risks
are limited to engine-side compensations (DataScope pre-filter for the
operator-precedence bug, webhook allow-list absence, retry override hidden,
trigger drift on catalog refactor) and are documented in the per-spec
"Cross-references" trails and the consolidated risks block below.

---

## artifacts

Module specs (file → reported bytes on write, UTF-8):

| Path | Bytes |
|---|---|
| `openspec/changes/b12-ui/specs/admin-automations-crud.md` | 8 557 |
| `openspec/changes/b12-ui/specs/admin-automations-conditions.md` | 7 986 |
| `openspec/changes/b12-ui/specs/admin-automations-actions.md` | 11 479 |
| `openspec/changes/b12-ui/specs/admin-automations-history.md` | 8 706 |
| `openspec/changes/b12-ui/specs/admin-automations-permissions.md` | 9 327 |
| `openspec/changes/b12-ui/specs/admin-automations-ui-conventions.md` | 8 935 |
| **Total** | **54 990** |

All files are written to disk and reference `openspec/changes/b12-ui/explore.md`

+ `openspec/changes/b12-ui/proposal.md` + `openspec/config.yaml` at least once
each. The proposal carries no `Capabilities` section, so per spec convention
the modules are written as full new-domain specs (no delta against a
canonical `openspec/specs/`). Archive will copy them under the new domain
names.

---

## next_recommended

`sdd-apply` with strict TDD (per `openspec/config.yaml` `delivery.strict_tdd`).
Order suggestion for sdd-apply (no enforcement here — this is the only file
that knows the dependency graph):

1. `AdminAutomationPermissionsTest` — boot the provider and lock down gate
   matrix (`REQ-PERM-02..09`) **before** any controller method exists (RED).
2. `AdminAutomationCrudTest` (HTTP) + a Livewire `RuleForm` test
   (`admin-automations-crud.md` + `admin-automations-ui-conventions.md`).
3. Livewire `ConditionGroupEditor` test driven by
   `admin-automations-conditions.md`.
4. Livewire `ActionEditor` test driven by `admin-automations-actions.md`,
   covering all 11 type widgets + simulate-now + stub banners.
5. `AdminAutomationHistoryTest` + `AdminAutomationAuditTest` driven by
   `admin-automations-history.md`.
6. Visual cross-cut verification per `admin-automations-ui-conventions.md`
   (grep for bulk-ops strings, `retry_policy`, badge monospace, `America/Lima`
   timestamps).
7. `php artisan test --filter=AutomationEngine` to confirm B12 engine tests
   still pass (explore §1.5).

---

## risks

+ **R1 — Trigger catalog drift (carried from proposal R1)**: if
  `AutomationServiceProvider::TRIGGER_EVENTS` mutates between save and
  re-edit, REQ-CRUD-03 + SCN-COND-08 mandate a non-blocking warning +
  blocked save. Engine does not surface new triggers via event listeners, so
  this is the only safe fallback.
+ **R2 — Stubs run silently when active** (proposal R2). REQ-ACT-06 mandates
  a banner AND a small pill on the index row; no further guardrail prevents
  admins from saving `live` rules containing a `webhook` /
  `send_whatsapp_template` action. v1 chose to warn, not block.
+ **R3 — `DataScope` is UI-only compensation** (proposal R3, explore §8.5):
  REQ-ACT-04 pre-filters user/team pickers via
  `DataScopeService::visibleOwnerIds($creator)`. If a future payload is
  constructed outside the UI, the engine's own check is dead code (operator
  precedence); the execution fails with `error_class=InvalidArgumentException`
  and REQ-HIST-09 surfaces it.
+ **R4 — `retry_policy_json` exported-but-hidden** (proposal R4). REQ-ACT-08
  and REQ-UI-11 keep the column absent from the UI. Future API exports may
  include it; that is out of scope.
+ **R5 — Concurrent reorder overwrites** (proposal R5). REQ-CRUD-06 ships
  last-write-wins. Engine ties-break by `id asc` (`scopeOrdered`, explore
  §5.2) so no rule is orphaned from the list, but two operators can step on
  each other; documented as accepted in v1.
+ **R6 — Proposal has no `Capabilities` section**. Per spec convention,
  this phase treats the 6 modules as new functional domains and reports it
  here as a risk so reviewers see the assumption: archive will lay the spec
  bodies under `openspec/specs/<domain>/spec.md` for each module.
+ **R7 — Spec files emit no `RENAMED Requirements`** because
  `lib/openspec-deltas.ts` rename semantics are not implemented in gentle-pi
  yet. Renames are expressed as ADDED + REMOVED pairs. None of the v1 scope
  needs renames; flagged so future changes don't model a rename and lose it.
+ **R8 — `automations.webhook.execute` is registered-but-unused** in v1
  (proposal §4, decision 8). REQ-PERM-06 keeps the permission registered but
  enforces nothing on it; dead-branch references must be flagged in code
  review.

---

## skill_resolution

`paths-injected` — the parent session injected the SDD phase executor skill
path for sdd-spec and did not require the child to fall back to the registry.

---

## Cross-reference summary

| Proposal ref | Spec REQ-id(s) |
|---|---|
| AC-1 (mínimum rule) | REQ-CRUD-02 + REQ-COND-* + REQ-ACT-01 |
| AC-2 (live observability) | REQ-HIST-01 + REQ-HIST-10 |
| AC-3 (simulate preview) | REQ-ACT-07 + REQ-PERM-04 |
| AC-4 (papelera restore) | REQ-CRUD-07 + REQ-CRUD-08 |
| AC-5 (DataScope) | REQ-ACT-04 |
| AC-6 (B14 stub) | REQ-ACT-06 + REQ-UI-09 |
| AC-7 (purple badge) | REQ-HIST-05 + REQ-UI-08 |
| AC-8 (idempotency_key) | REQ-HIST-06 + REQ-UI-07 |
| AC-9 (audit gating) | REQ-HIST-08 + REQ-PERM-05 |
| AC-10 (retry hidden) | REQ-ACT-08 + REQ-UI-11 |
| AC-11 (drag reorder) | REQ-CRUD-06 + REQ-UI-05 |
| AC-12 (no bulk ops) | REQ-UI-10 |

No implementation, no code, no migrations, no routes, no controllers, no
views, no Livewire components, no tests authored in this phase.
