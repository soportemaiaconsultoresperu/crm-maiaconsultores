# SDD proposal — b12-ui

## Phase envelope

- **status:** passed
- **executive_summary:**
  - PRD-style proposal written at `openspec/changes/b12-ui/proposal.md` covering the 12 mandated sections in the requested order: Resumen ejecutivo, Problema de negocio, Usuarios objetivo y situaciones, Reglas de negocio y permisos, Resultado esperado, Brecha actual, Scope del primer slice, No-goals, Edge cases, Decisiones de producto tomadas, Riesgos y rollback, Criterios de éxito.
  - Cites `openspec/changes/b12-ui/explore.md` as the authoritative engine/placeholder map and `openspec/config.yaml` as the project context throughout.
  - Restates the 12 locked product decisions (4 explicit + 8 firmadas) in §10 without re-opening any of them.
  - Maps all 5 B12 permissions (`automations.view`, `automations.manage`, `automations.test`, `automations.audit`, `automations.webhook.execute`) to specific UI actions; `automations.webhook.execute` is flagged as registered-but-unused for v1 (no manual trigger per decision 8).
  - Encodes engine compensations the UI must implement: `DataScopeService::visibleOwnerIds()` pre-filter on `assign_owner` pickers (compensating for the `AssignOwnerAction` operator-precedence bug), webhook allow-list surfaced from `config('integrations.php')`, B14 stubs labelled "Pendiente (B14)", `recipient_strategy` synced between column and payload key, `idempotency_key` visible monospace + copy-to-clipboard.
  - Defines 12 acceptance criteria (AC-1..AC-12) for the V1 bar; no tests, commands, or PR shape recommended (those belong to sdd-tasks).
  - Length: ~325 lines of markdown, within the 250–450 target; no code, migrations, routes, or tests authored.
- **artifacts:**
  - `{path: "openspec/changes/b12-ui/proposal.md", kind: "SDD proposal (PRD)", bytes: 32666}`
  - `{path: "sdds/sdd-proposal-b12-ui.md", kind: "findings envelope", bytes: ...}`
- **next_recommended:** sdd-spec (technical spec for the 8 new routes, controllers, Livewire components, FormRequests, gates) — pending explicit user turn approval per `interactive phase gate` rule.
- **risks:**
  - The proposal assumes `automations.manage` will be granted to a single admin-style role and `automations.test` to admin + supervisor; sdd-spec must confirm against the role catalogue (`RolesAndPermissionsSeeder`) and may need to add a new role or repurpose an existing one.
  - `automations.webhook.execute` is registered but unused in v1 — risk of leaving a dead permission in the catalogue. sdd-verify should assert it's still seeded only by the provider boot and not enforced anywhere, so a future B-block can adopt it without surprise.
  - `retry_policy_json` column is hidden in v1 per decision 11; if a future engine change starts reading it, the UI must be updated atomically — flag for the B13/B14 owners.
  - Drag-to-reorder has no optimistic-lock guard on `automation_rules.order` (risk R5 in §11 of the proposal). Acceptable for v1 but worth a follow-up ticket.
  - Livewire 4 is the first Livewire introduction in the codebase (`explore.md` §6); sdd-spec must confirm the team's familiarity with `#[Layout]` / `#[Computed]` / `#[On]` attributes.
  - No git repository exists in the workspace; `git status` is not available to verify staged-file state.
- **skill_resolution:** none

## Acceptance report

```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "PRD-style proposal persisted at openspec/changes/b12-ui/proposal.md (32,666 bytes). All 12 mandated sections present in the requested order (Resumen ejecutivo, Problema de negocio, Usuarios objetivo y situaciones, Reglas de negocio y permisos, Resultado esperado, Brecha actual, Scope del primer slice, No-goals, Edge cases, Decisiones de producto tomadas, Riesgos y rollback, Criterios de éxito). Upstream artifacts referenced: openspec/changes/b12-ui/explore.md (cited with section numbers across the document) and openspec/config.yaml (cited in the header and Quick cross-reference). 12 acceptance criteria (AC-1..AC-12) defined as measurable conditions; no code, migrations, routes, or tests authored. 5 B12 permissions mapped to UI actions. Engine compensations (DataScope pre-filter, webhook allow-list surface, B14 stub banners, recipient_strategy sync, idempotency_key visibility) all encoded into the scope section and acceptance criteria. Length is within the 250-450 line target."
    }
  ],
  "changedFiles": [
    "openspec/changes/b12-ui/proposal.md",
    "sdds/sdd-proposal-b12-ui.md"
  ],
  "testsAddedOrUpdated": [],
  "commandsRun": [],
  "validationOutput": [
    "Proposal section count: 12 mandated sections + header block + Quick cross-reference footer.",
    "Product decisions restated: 4 explicit (CRUD surface, condition builder, action forms per-type, simulate-now) + 8 firmadas (history read-only, no bulk ops, soft-delete UX with Papelera, no manual emit, audit contextual, purple test-mode badge, retry override hidden, idempotency_key visible) — total 12, all in §10.",
    "5 B12 permissions mapped to UI actions in §4: automations.view (existing), automations.manage (CRUD), automations.test (simulate-now), automations.audit (Spatie Activitylog block), automations.webhook.execute (registered but unused in v1).",
    "Engine compensations from explore.md §8 covered in §4 and §7: DataScopeService::visibleOwnerIds() pre-filter for assign_owner pickers (compensates AssignOwnerAction operator-precedence bug); webhook allow-list from config('integrations.webhooks.allowed_destinations') surfaced in webhook form; B14 stubs (webhook, send_whatsapp_template) labelled 'Pendiente (B14)'; recipient_strategy column + payload key synced as single control; idempotency_key visible monospace + copy-to-clipboard.",
    "Acceptance criteria: 12 (AC-1..AC-12) covering minimum-viable rule authoring, live execution observability, simulate preview returning would-be payload, papelera restore cycle, DataScope honor, B14 stub banners, purple test-mode badge with exact tooltip copy, idempotency_key visibility + copy, audit contextual gating, retry override hidden, drag-to-reorder persistence, no bulk-ops buttons.",
    "No code, migrations, routes, tests, or PR shape authored — all left to sdd-spec / sdd-design / sdd-tasks phases."
  ],
  "residualRisks": [
    "Engine operator-precedence bug in AssignOwnerAction::execute (dead DataScope check) — UI pre-filters pickers but the engine fix remains a separate ticket.",
    "retry_policy_json column unused by engine — UI hides it per decision 11 but the column exists; future engine change must be coupled with UI re-surfacing.",
    "WhatsApp and Webhook actions are B14 stubs that throw at runtime — UI banner 'Pendiente (B14)' is the only guardrail in v1.",
    "B12 permissions registered at boot (AutomationServiceProvider), not via seeder — sdd-spec must ensure tests boot the provider before counting permissions.",
    "automations.webhook.execute is registered but unused in v1; risk of being mistaken for an enforced permission by future readers.",
    "Drag-to-reorder has no optimistic-lock on automation_rules.order; last-write-wins is acceptable for v1 but flagged as risk R5.",
    "No git repository exists in this workspace, so staged-file state cannot be queried via git status."
  ],
  "noStagedFiles": true,
  "diffSummary": "Created the OpenSpec proposal artifact (openspec/changes/b12-ui/proposal.md, 32,666 bytes, ~325 lines) and this findings envelope (sdds/sdd-proposal-b12-ui.md). No application code, migrations, routes, controllers, views, Livewire components, FormRequests, gates, or tests were modified. No git operations were performed.",
  "reviewFindings": [
    "no blockers"
  ],
  "manualNotes": "pi-lens advisory (MD060 table-column count and MD056 table-row count) on the proposal is informational and the document has already been autofixed. The next phase in interactive mode is sdd-spec (technical spec for the new routes, controllers, Livewire components, FormRequests, and gates), but per the SDD interactive phase gate it must wait for explicit user approval on this proposal before starting."
}
```
