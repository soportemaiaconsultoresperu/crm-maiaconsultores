# Assign custom roles to users

## Objective and problem
Allow administrators to assign newly created roles to existing or new users. The role selector lists every `web` role, but both user request validators accept only the three seeded names.

## Scope and constraints
- Validate selected role names against existing `web` roles; preserve nullable role behavior and service enforcement.
- Cover both create and update paths and rejection of invalid role names/guards.
- Touch only role-assignment request validation, relevant comments, and focused admin HTTP tests. Preserve unrelated in-progress course changes.
- No schema/data migrations or permission-policy redesign.
- Existing working branch contains unrelated uncommitted edits. No commit without explicit user authorization; task closure/commit evidence remains pending if not authorized.

## Execution settings
- Route: delegated writer (two request classes and feature test); exploration was delegated because the flow spans 4+ files.
- TDD mode: not explicitly enabled; docs/PRUEBAS.md recommends strict TDD when justified, but does not configure it. Apply ordinary regression checks; runner: `php artisan test --filter AdminHttpTest` (documented `php artisan test` runner with filter example in README/docs/PRUEBAS.md).
- Delivery strategy: ask-on-risk. Forecast: ~90 authored diff lines, one work unit; no chained PR anticipated.

## Tasks
- [ ] T1 (in progress; partial verification) — Correct create/update role validation and regressions. Add HTTP coverage for new web roles and invalid role/guard; align comments and messages; run focused test. Route: delegated writer (multi-file). Acceptance: custom web roles are assigned on create and update; nonexistent and non-web roles are rejected with validation errors; seeded roles still work. Commit: pending explicit user authorization; do not stage unrelated edits.
- [ ] T2 (blocked by scope decision) — Resolve or isolate the pre-existing settings-audit test failure in the broader AdminHttpTest suite before claiming full suite green. Do not change unrelated settings logic without user authorization.

## Progress and evidence
- Read-only mapping found hardcoded `Rule::in(['admin', 'supervisor', 'vendedor'])` in both request classes; `UserController::availableRoles()` lists all web roles and `UserService::syncRole()` checks guard `web`.
- Writer changed two FormRequests, UserController comment, and AdminHttpTest regression cases; unrelated dirty course files preserved.
- `php artisan test --filter AdminHttpTest`: initial invocation unavailable (`php` absent from PATH); equivalent absolute PHP executable invocation ran with 13/14 passing, 85 assertions, failing unrelated `test_settings_update_round_trip_through_admin_form` due to missing `setting-updated` activity record. First absolute invocation timed out; second completed.
- Filtered five role-related HTTP tests: 5 passed, 31 assertions. `git diff --check` on four changed files passed. Human UI acceptance not run.
- Native read-only assess returned unassessable because ambient untracked files require explicit declaration; independent verifier ran the tests. No native review or commit, because the current branch has unrelated dirty work and commit lacks explicit authorization.

## Next step
Report focused role fix as provisionally validated, the unrelated full-suite failure and blocked commit/review. Seek a scope decision only before repairing settings audit or staging a work-unit commit.
