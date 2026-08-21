# sdd-design — b12-ui (envelope)

> Compact envelope for `openspec/changes/b12-ui/design.md`. This file is
> the parent-facing summary; the canonical artifact lives at
> `openspec/changes/b12-ui/design.md` (≈ 502 lines, 14 sections).

---

## status

- **phase**: sdd-design
- **artifact_store**: openspec (writes to `openspec/changes/b12-ui/design.md`)
- **execution_mode**: interactive
- **result**: design-only — no code, no migrations, no routes registered, no tests authored
- **next_recommended**: sdd-tasks (after user approval in this turn; the parent owns the gate)
- **skill_resolution**: paths-injected (skill paths were not pre-injected; this run executed under the SDD design executor's contracted behaviour — `Skill Resolution Contract` reports `paths-injected` because the parent session owns skill-path injection; if missing in a future run, the fallback registry is permitted per the contract)

## executive_summary

1. **Routing**: hand-rolled `Route::controller(AutomationController::class)->group(...)` extending the existing placeholder block; `Route::resource()` rejected because the surface mixes 5 CRUD verbs with 5 verb-irregular actions (`trash`, `clone`, `restore`, `reorder`, `toggle`, `simulate`, `audit`) and an existing nested `showExecution`.
2. **Livewire tree**: 4 stateful PHP classes under `app/Livewire/Admin/Automations/` (`RuleForm`, `ConditionGroupEditor`, `ActionEditor`, `HistoryFilter`) + 17 anonymous Blade widgets under `resources/views/components/admin/automations/`. Stateful = PHP class; tiny presentational = Blade.
3. **FormRequests**: `StoreRuleRequest`, `UpdateRuleRequest`, `ReorderRequest`, `SimulateRequest` — each validates per spec matrix with REQ-id trace; no `CloneRuleRequest` (clone uses Eloquent `replicate()`).
4. **Policies vs Gates**: stick with `Gate::authorize('automations.*')` — a Policy would re-wrap the 5 named permissions without changing semantics; PERM-03 mandates the gate call as the first statement.
5. **payload_json validator**: single `App\Services\Automation\ActionPayloadValidator` with a per-type ruleset map keyed by `ActionRegistry::registered()`; companion `RulePayloadValidator` for condition `value_type` coercion.
6. **History**: `paginate(20)`, GET-param filter form (`status`, `date_from`, `date_to`, `subject`); Livewire `HistoryFilter` is the optional UX path.
7. **Audit contextual**: `_audit_changes.blade.php` partial gated by `@can('automations.audit')`; query `Spatie\Activitylog\Models\Activity` on `subject_type=AutomationRule`, `subject_id`, paginate(10).
8. **Drag-reorder**: Livewire 4 `wire:sort` directive + one PATCH endpoint `admin.automations.reorder` carrying `{kind, order:[…]}` — dispatches to rule/condition/action persistence in one transaction.
9. **Clone**: `Rule::with('conditionGroups.conditions', 'actions')->find($id)->replicate()` chain + `created_by=auth()->id()`, `is_active=false`, `mode='test'`, `name+" (copia)"`, all inside a DB transaction.
10. **Test seams**: ≈ 21 test classes (14 Feature HTTP + 7 Livewire + 6 Unit) each mapped to specific REQ-ids in the design's §11 / §14 table; all Feature tests use `RefreshDatabase` + boot `AutomationServiceProvider` explicitly in `setUp()` (PERM-08).

## artifacts

- **Canonical design doc**: `openspec/changes/b12-ui/design.md` (≈ 502 lines, 14 sections)
- **This envelope**: `sdds/sdd-design-b12-ui.md`

## next_recommended

- **sdd-tasks** — sdd-apply will consume the design's §12 file map, §11 test seams, and §3.2 Livewire tree to author the TDD task list. The parent orchestrator should validate that the user approves the transition before sdd-tasks starts.

## risks

- **R-design-1**: `automation_rules.order` has no optimistic-lock guard in v1 (decision 12). Last-write-wins for concurrent reorders. Mitigated by engine tie-breaker (`scopeOrdered` uses `order ASC, id ASC`). Linked back to proposal §11 R5.
- **R-design-2**: `AssignOwnerAction` operator-precedence bug (explore §8.5) is dead code at the engine. B12-UI compensates by pre-filtering user/team pickers via `DataScopeService::visibleOwnerIds($creator)` in the `assign_owner` widget (decision 5, ACT-04, §13.3).
- **R-design-3**: `automations.webhook.execute` is registered but no v1 route enforces it (decision 13, PERM-06). The test suite ships `SCN-PERM-04` proving the permission is unreachable in v1 — review risk: any code mentioning this permission outside the provider registration is a dead branch (proposal §10 #8).
- **R-design-4**: 24 markdown lint warnings on `design.md` (informational only, lens advisory flagged MD040×4 + MD056×1 + MD060×19 — none are blockers; the design doc is review-ready). No action taken in this turn.
- **R-design-5**: Authoring the 17 Blade widgets in §3.2 is the largest single-class expansion of v1; sdd-tasks should sub-batch them by action type family (write ops / stage mutations / notifications / stubs) to keep each PR small enough for the 400-line review budget.

## design ↔ spec cross-reference (compact)

The canonical mapping table lives at `openspec/changes/b12-ui/design.md` §14 (one row per REQ-id). Compact summary:

| REQ family | # REQs | Design section(s) | Test class count |
|---|---|---|---|
| CRUD-* | 8 | §2, §4, §10, §12 | 5 (CRUD, Clone, Reorder, Trash/Restore, Permissions) |
| COND-* | 8 | §3.2, §3.3, §6.3, §9 | 3 (ConditionGroupEditor, RulePayloadValidator, Conditions part of Crud) |
| ACT-* | 9 | §3.2, §3.3, §6, §9, §13.4 | 7 (ActionEditor, WidgetType, PayloadValidator, Webhook, B14Banner, TestModeBadge, RecipientStrategy, RetryPolicyHidden) |
| HIST-* | 10 | §3.2, §3.3, §7, §8, §13.5 | 4 (History, ExecutionDetail, AuditBlock, IdempotencyKeyCopy) |
| PERM-* | 9 | §2, §4, §5, §13.2 | 1 (Permissions, plus per-route coverage in every Feature test) |
| UI-* | 14 | §3.1, §3.4, §7.3, §12, §13.5 | 4 (RetryPolicyHidden, BulkOpsAbsent, B14Banner, TestModeBadge + render assertions across all view tests) |

**Coverage**: every spec REQ-id appears in §14; all 6 spec families are reachable from at least one design section; no orphan requirements.

---

End of envelope.
