# Admin Automations — Permissions

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (§2.2 controller-only `automations.view`, §8.11 permissions registered at provider boot, §8 gotchas + permission table) and `openspec/changes/b12-ui/proposal.md` (§4 permisos table, §7.15 gates, §11 rollback on permissions).
> Pair with: every other spec — this file is the cross-cutting authorization contract.

---

## Purpose

Pin the server-side enforcement of the 5 `automations.*` Spatie permissions registered by `AutomationServiceProvider::registerAutomationPermissions()` at boot (explore §8.11): where each one is checked, what happens when missing, and the contract for `automations.webhook.execute` that is registered-but-unused in v1.

---

## Requirements

### REQ-PERM-01 — Five permissions, registered at provider boot

The system SHALL rely on exactly 5 permissions registered by `AutomationServiceProvider::registerAutomationPermissions()` during `boot()`: `automations.view`, `automations.manage`, `automations.test`, `automations.audit`, `automations.webhook.execute`. The registration MUST be idempotent across re-boots (i.e. re-calling `boot()` re-registers without throwing). The system SHALL NOT add a 6th permission in v1 and SHALL NOT introduce a `permissions:cache` artisan migration.

### REQ-PERM-02 — View surface (read-only)

The system SHALL enforce `automations.view` via `Gate::authorize('automations.view')` on every GET route listed in `admin-automations-crud.md` (index, trash, show, edit's GET form… only the page that lists rules, executions, and detail), on `admin.automations.executions.show`, and on any read-only filter endpoint. The current `AutomationController` already enforces this (explore §2.2); B12-UI MUST keep the same line.

### REQ-PERM-03 — Manage surface (writes)

The system SHALL enforce `automations.manage` (in addition to `automations.view`) on every write route: `store`, `update`, `toggle`, `clone`, `reorder`, `destroy`, `restore`. The check SHALL be the first statement of each method and SHALL throw `AuthorizationException` (translated by Laravel to a 403) on failure. Buttons SHALL additionally be wrapped in `@can('automations.manage')` so the UI never surfaces an action the actor can't perform — but the server-side check is the source of truth.

### REQ-PERM-04 — Test surface (simulate-now)

The system SHALL enforce `automations.test` on the simulate-only endpoint `admin.automations.actions.simulate`. The check MUST run before any `ActionRegistry::resolveForAction($action)` call so calling the route never exposes the registered action class structure (defense in depth). The "Simular ahora" button SHALL be wrapped in `@can('automations.test')` and SHALL NOT be rendered without the permission (proposal §9.5).

### REQ-PERM-05 — Audit surface (contextual audit block)

The system SHALL enforce `automations.audit` on the audit-only controller method that feeds the "Cambios" section of `admin.automations.show`. The block SHALL additionally be wrapped in `@can('automations.audit')` in the view so the markup is absent without the permission (proposal §4 + AC-9). The system SHALL NOT touch the global `audit.view` page or any `B10 audit` surface.

### REQ-PERM-06 — `automations.webhook.execute` registered-but-unused

The system SHALL keep `automations.webhook.execute` registered by the provider (no removal) and SHALL NOT enforce it on any v1 route. The check is contractually reserved for a future replay/scheduled trigger surface (proposal §4 + §8). Any code that references this permission in v1 MUST be flagged as a dead branch.

### REQ-PERM-07 — Server-side fallback when UI hides a button

The system SHALL always re-check the relevant gate on every server-side handler. Concretely: a user who hand-crafts a `POST admin.automations` via curl without `automations.manage` SHALL receive 403; a user who hand-crafts a simulate POST without `automations.test` SHALL receive 403; a user who hand-crafts a `PATCH automation.toggle` without `automations.manage` SHALL receive 403. The `@can(...)` UI wrapping is for UX, never for security.

### REQ-PERM-08 — Test base boots provider before asserting

The system SHALL establish a test base (`tests/Feature/AdminAutomationPermissionsTest` and any other automation test) that explicitly initializes `AutomationServiceProvider::registerAutomationPermissions()` inside `setUp()` BEFORE `actingAs($user)` to guarantee the 5 permissions exist on the Spatie `Permission` table — `RefreshDatabase` runs migrations but `registerAutomationPermissions()` only fires when the provider boots (explore §8.11). The pattern: `app()->register(\App\Providers\AutomationServiceProvider::class, force: true)` after migrations.

### REQ-PERM-09 — Role assignment contract

The system SHALL test role assignment by combining seeded roles (`tests/.../RolesAndPermissionsSeeder`, explore §6) with explicit `$user->givePermissionTo('automations.X')` and `actingAs($user)`. The system SHALL NOT auto-assign any of the 5 permissions to any pre-existing role — that decision belongs to a separate audit/seeding task outside B12-UI scope.

---

## Scenarios

#### SCN-PERM-01 — Missing `automations.manage` returns 403 from store

- GIVEN a user with `automations.view` only
- WHEN they POST `admin.automations.store` with a valid payload
- THEN the response is 403 and no rule is created (proposal §9.4).

#### SCN-PERM-02 — Missing `automations.test` blocks simulate

- GIVEN a user with `automations.manage` only (no `automations.test`)
- WHEN they POST `admin.automations.actions.simulate`
- THEN the response is 403 and the action class is never loaded.

#### SCN-PERM-03 — Missing `automations.audit` hides "Cambios"

- GIVEN a user with `automations.view` only (no `automations.audit`)
- WHEN they GET `admin.automations.show`
- THEN the rendered HTML contains no `id="audit-changes-block"` and no occurrences of the Spatie log model strings.

#### SCN-PERM-04 — `automations.webhook.execute` has no enforcement

- GIVEN a user with only `automations.webhook.execute`
- WHEN they attempt every v1 route
- THEN every route returns 403 (because no route enforces that permission); the permission exists but is unreachable in v1 (proposal §4, decision 8).

#### SCN-PERM-05 — Provider boots before tests

- GIVEN a Feature test extending `Tests\TestCase` with `RefreshDatabase`
- WHEN the test asserts a user's `can('automations.manage')`
- THEN the assertion passes iff the test setUp registered the provider explicitly; otherwise the assertion is `false` because the permission is not in the DB.

#### SCN-PERM-06 — UI hides button AND server rejects

- GIVEN a user without `automations.manage` viewing the index
- WHEN they inspect the rendered DOM
- THEN zero buttons exist with `wire:click` / `form action="…/destroy"` / `form action="…/restore"` and a direct browser hit to those URLs returns 403 — i.e. UI and server agree.

---

## Affected routes (cross-cutting)

Every v1 route listed across the 5 sibling specs. The full matrix is reconstructed here for the sdd-apply contract:

| Route name | Gate enforced server-side | Gate enforced in Blade |
|---|---|---|
| `admin.automations.index` | `automations.view` | `@can('automations.view')` (sidebar already) |
| `admin.automations.trash` | `automations.view` | `@can('automations.view')` |
| `admin.automations.create` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.store` | `automations.view` + `automations.manage` | n/a |
| `admin.automations.show` | `automations.view` | `@can('automations.view')` |
| `admin.automations.edit` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.update` | `automations.view` + `automations.manage` | n/a |
| `admin.automations.toggle` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.clone` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.reorder` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.destroy` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.restore` | `automations.view` + `automations.manage` | `@can('automations.manage')` |
| `admin.automations.executions.show` | `automations.view` | `@can('automations.view')` |
| `admin.automations.actions.simulate` | `automations.view` + `automations.test` | `@can('automations.test')` |
| audit-only endpoint feeding `Cambios` block | `automations.view` + `automations.audit` | `@can('automations.audit')` |

---

## Cross-references

- Proposal: §4 (permissions table), §7.15 (gates), §9.4 + §9.5 (edge cases without manage/test), §10 decision 5 (audit contextual), decision 8 (webhook.execute reserved), §11 rollback (provider-restoration), AC-9.
- Explore: §2.2 (controller's `Gate::authorize('automations.view')`), §6 (test conventions), §7 (`DataScopeService`, `ActionRegistry`), §8.5 (DataScope bug — kept in engine), §8.11 (provider boot registers 5 perms), §8.13 (recipient_strategy dual write).
- Config: `openspec/config.yaml` — Spatie Laravel Permission in stack, `strict_tdd: true` (test seams must pre-date controller code), Livewire 4 in stack.
