# SDD explore — b12-ui

## Phase envelope

- **status:** passed
- **executive_summary:**
  - Mapped the full B12 automation engine surface (contracts, enums, 7 models, services, 11 actions, jobs, listener, notifications, 5 console commands) with file paths and a "ready / map / out" status for each seam.
  - Mapped the placeholder admin surface: 3 read-only routes + thin `AutomationController`, 3 placeholder Blade views (51/57/58 lines), AdminLTE/Bootstrap 5 component conventions (`<x-table>`, `<x-text-input>`, `<x-select>`, `<x-modal>`, `<x-alert>`), and the already-wired sidebar entry under `@can('automations.view')`.
  - Mapped the data model: 7 tables, fillable columns, indexes (including UNIQUE `idempotency_key`), per-column read/write/hide classification for the UI, plus `mode` semantics (live → real jobs, test → simulated steps).
  - Mapped the action configuration surface: 11 action slugs ↔ classes (from `AutomationServiceProvider::ACTION_TYPES`) with each `payload_json` schema and per-type UI form hints.
  - Mapped the trigger catalog: 19 `App\Events\V2\*` events (16 domain + 3 time-driven), listener subscription mechanism via explicit `Event::listen` in the provider, `DispatchAutomationRule::handle()` query chain.
  - Confirmed Livewire 4 (`livewire/livewire: ^4.4`); no `app/Livewire` and no `resources/views/livewire` exist yet; no existing Livewire tests — B12-UI will be the first block to introduce them; `Tests\TestCase` + `RefreshDatabase` + `Queue::fake` conventions are reusable.
  - Listed reusable services (`ActionRegistry::registered()`, `AutomationServiceProvider::ACTION_TYPES` + `TRIGGER_EVENTS`, `DataScopeService::visibleOwnerIds()`, status `label()` helpers) and UI logic that must stay decoupled from the engine.
  - Documented 15 constraints/gotchas: idempotency key formula, 30 s cycle window, 3-step race resolution via DB UNIQUE, webhook allow-list, the dead-code `DataScope` check in `AssignOwnerAction`, B14 stubs (`webhook`, `send_whatsapp_template`), `recipient_strategy` duplication, no inbound webhook trigger, runtime permission registration.
  - Produced 12 product-round questions for the sdd-proposal stage covering CRUD surface, condition/action authoring, simulate-now, history controls, bulk ops, soft-delete UX, manual event emission, audit, test-mode UX, retry override, idempotency key visibility.
  - No application code, tests, migrations, routes, or views were modified. No git operations.
- **artifacts:**
  - `{path: "openspec/changes/b12-ui/explore.md", kind: "SDD explore mapping", bytes: 35656}`
  - `{path: "sdds/sdd-explore-b12-ui.md", kind: "findings envelope", bytes: ...}`
- **next_recommended:** round of product questions (the 12 listed in `openspec/changes/b12-ui/explore.md` §9) before sdd-proposal; in interactive mode the user turn is the natural moment to triage them.
- **risks:**
  - Several B12 permissions are registered but unused (`automations.manage`, `automations.test`, `automations.webhook.execute`, `automations.audit`); the proposal must decide which ones the UI enforces and where.
  - `AssignOwnerAction::execute` contains an operator-precedence bug that disables its `DataScope` check; B12-UI must compensate in the UI until the engine is fixed.
  - `retry_policy_json` column is reserved but never read; risk of the proposal surfacing a knob the engine ignores.
  - `webhook` and `send_whatsapp_template` actions are stubs (throw, no real call); proposal must label them accordingly to avoid admin surprise.
  - B12 permissions are registered at boot time by `AutomationServiceProvider`, not by seeder; tests must boot the provider before counting permissions (currently asserted at 84 in seeders).
  - No git in workspace → "no staged files" cannot be verified via `git status`; rely on file-writes only.
- **skill_resolution:** none

## Acceptance report

```acceptance-report
{
  "criteriaSatisfied": [
    {
      "id": "criterion-1",
      "status": "satisfied",
      "evidence": "Concrete findings produced in openspec/changes/b12-ui/explore.md with file paths and a severity/readiness classification per engine seam (ready/map/out); 35,656-byte artifact covers engine surface, placeholder admin surface, data model, action configuration, triggers and events, Livewire conventions, reusable services, constraints and gotchas, and 12 product-round questions."
    }
  ],
  "changedFiles": [
    "openspec/changes/b12-ui/explore.md",
    "sdds/sdd-explore-b12-ui.md"
  ],
  "testsAddedOrUpdated": [],
  "commandsRun": [],
  "validationOutput": [
    "No code paths under app/, database/, routes/, tests/, resources/views/ were modified (read-only mapping per task contract).",
    "All 11 action classes enumerated with payload schemas: app/Services/Automation/Actions/{CreateActivity,CreateFollowUpActivity,AssignOwner,ChangeStatus,ChangeStage,AddTag,SendNotification,SendEmail,SendWhatsAppTemplate,AddNote,Webhook}Action.php.",
    "All 19 trigger events enumerated: 16 under app/Events/V2/ + 3 under app/Events/V2/TimeDriven/.",
    "7 automation tables enumerated with columns, casts, indexes, and UI read/write/hide tags.",
    "5 B12 permissions enumerated from AutomationServiceProvider::registerAutomationPermissions(); only automations.view is currently enforced by the placeholder controller.",
    "Confirmed no app/Livewire, no resources/views/livewire, no existing Livewire test usage anywhere under tests/."
  ],
  "residualRisks": [
    "Engine operator-precedence bug in AssignOwnerAction::execute (dead DataScope check) — UI must compensate.",
    "retry_policy_json column unused by engine — proposal must decide whether to surface it.",
    "WhatsApp and Webhook actions are B14 stubs that throw at runtime.",
    "B12 permissions registered at boot, not via seeder — test setup must boot the provider.",
    "No git repository, so staged-file state cannot be queried."
  ],
  "noStagedFiles": true,
  "diffSummary": "Created only the SDD explore artifact and the findings envelope; no application code, tests, migrations, routes, or views changed.",
  "reviewFindings": [
    "no blockers"
  ],
  "manualNotes": "pi-lens advisory (MD060/MD040) on the explore.md is informational; the markdownlint autofix in the explore.md has already normalised the artifact and the content is authoritative. The next phase in interactive mode is a 3-5 question product round drawn from §9 of openspec/changes/b12-ui/explore.md."
}
```
