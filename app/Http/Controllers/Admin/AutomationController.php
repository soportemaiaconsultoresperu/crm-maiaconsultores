<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Automations\ReorderRequest;
use App\Http\Requests\Admin\Automations\SimulateRequest;
use App\Http\Requests\Admin\Automations\StoreRuleRequest;
use App\Http\Requests\Admin\Automations\UpdateRuleRequest;
use App\Models\AutomationAction;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\Exceptions\NotImplementedException;
use App\Services\Automation\Exceptions\WebhookNotAuthorizedException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * B12 — Admin index / show for automation rules.
 *
 * The UI is a placeholder: B12 delivers engine + persistence + jobs +
 * tests; B12-UI brings the full Livewire CRUD. This controller is the
 * minimum needed so the route name, the permission gate and the views
 * (sidebar link) are wired.
 *
 * PR 1 (B12-UI) extends the surface to 13 actions. Every write method
 * starts with `Gate::authorize('automations.view')` followed by the
 * specific permission (`automations.manage`, `automations.test`,
 * `automations.audit`) per PERM-03 / PERM-04 / design §5. The 403
 * fallback is implicit in `Gate::authorize` (AuthorizationException →
 * 403).
 *
 * PR 5 (Chunk 5) fills in `show`, `showExecution`, `audit` and
 * `simulate` bodies — the read + audit contextual layer (HIST-01..10,
 * SCN-HIST-01..08, SCN-AUDIT-01-A, SCN-SIMULATE-01-A..C).
 */
class AutomationController extends Controller
{
    // ------------------------------------------------------------------
    // Read surface — `automations.view`
    // ------------------------------------------------------------------

    public function index(Request $request): View
    {
        Gate::authorize('automations.view');

        $rules = AutomationRule::query()
            ->withCount('executions')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.automations.index', [
            'rules' => $rules,
        ]);
    }

    /**
     * PR 5 — show(AutomationRule $automation)
     *
     * New surface: rule header (metadata + clone/edit/toggle/trash)
     * + filterable executions list (HIST-01..03) + audit contextual
     * block (HIST-08, gated `@can('automations.audit')`).
     *
     * Filters (HIST-02) read from `?status`, `?date_from`, `?date_to`,
     * `?subject_type`. AND-combined. Pagination links carry the same
     * query string.
     */
    public function show(Request $request, AutomationRule $automation): View
    {
        Gate::authorize('automations.view');

        // HIST-01 + HIST-02 — eager-load executions + steps, then layer
        // the GET-param filters (status, date_from, date_to, subject_type).
        // Default order matches the placeholder (newest first); the
        // scoped query is reused for the audit partial's pagination.
        $executionsQuery = AutomationExecution::query()
            ->where('rule_id', $automation->id)
            ->with('steps');

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $executionsQuery->where('status', $status);
        }

        $dateFrom = trim((string) $request->query('date_from', ''));
        if ($dateFrom !== '') {
            $executionsQuery->whereDate('started_at', '>=', $dateFrom);
        }

        $dateTo = trim((string) $request->query('date_to', ''));
        if ($dateTo !== '') {
            $executionsQuery->whereDate('started_at', '<=', $dateTo);
        }

        $subjectType = trim((string) $request->query('subject_type', ''));
        if ($subjectType !== '') {
            $executionsQuery->where('subject_type', $subjectType);
        }

        /** @var LengthAwarePaginator $executions */
        $executions = $executionsQuery->orderByDesc('id')->paginate(20)->withQueryString();

        // HIST-08 + PERM-05 — Spatie Activitylog query, scoped to this rule.
        // The wrapper view will guard this with `@can('automations.audit')`
        // so without the permission the variable is unused (defense in
        // depth — the query is cheap, 10 rows max per page).
        $auditEntries = Activity::query()
            ->where('subject_type', AutomationRule::class)
            ->where('subject_id', $automation->id)
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return view('admin.automations.show', [
            'rule' => $automation,
            'executions' => $executions,
            'auditEntries' => $auditEntries,
            // Pre-resolved filter state for the form (preserve on submit).
            'filters' => [
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'subject_type' => $subjectType,
            ],
        ]);
    }

    /**
     * PR 5 — showExecution(AutomationRule $automation, AutomationExecution $execution)
     *
     * Detail view with steps expanded (`<pre><code>response_json</code></pre>`),
     * the purple test-mode badge when `$automation->mode === 'test'`
     * (HIST-05 + AC-7), error_class + error_message in red `alert-danger`
     * for failed steps (HIST-04, HIST-09), and the idempotency_key
     * rendered via `<x-idempotency-key-copy>` (HIST-06).
     */
    public function showExecution(
        Request $request,
        AutomationRule $automation,
        AutomationExecution $execution,
    ): View {
        Gate::authorize('automations.view');

        abort_unless((int) $execution->rule_id === (int) $automation->id, 404);

        return view('admin.automations.execution', [
            'rule' => $automation,
            'execution' => $execution->load(['steps.action']),
        ]);
    }

    public function trash(Request $request): View
    {
        // Papelera tab (CRUD-01 secondary tab). Reuses `automations.view`
        // — the same users allowed to browse the index may browse trashed
        // rows; restore itself is gated `automations.manage`.
        Gate::authorize('automations.view');

        $rules = AutomationRule::query()
            ->onlyTrashed()
            ->withCount('executions')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.automations.index', [
            'rules' => $rules,
            'trashView' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Write surface — `automations.manage` (CRUD-02..08)
    // ------------------------------------------------------------------

    public function create(): View
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 3 / Stage 3B-3 — render the create host view that embeds the
        // `<livewire:admin.automations.rule-form>` component.
        return view('admin.automations.create');
    }

    public function store(StoreRuleRequest $request)
    {
        // StoreRuleRequest::authorize() already enforced `automations.manage`
        // (PERM-03). Re-check view for defense in depth.
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 3 / Stage 3A — CRUD-02: persist the rule + groups + conditions +
        // actions in a single DB transaction. Stage 3B wires the Livewire
        // `RuleForm` host around this same endpoint.
        $rule = app(\App\Services\Automation\RuleWriterService::class)
        ->create($request->validated(), $request->user());

        return redirect()
        ->route('admin.automations.show', $rule)
        ->with('success', 'Regla creada.');
    }

    public function edit(AutomationRule $automation): View
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 3 / Stage 3B-3 — render the edit host view that embeds the
        // `<livewire:admin.automations.rule-form>` component bound to
        // the existing rule (with groups + conditions + actions).
        return view('admin.automations.edit', [
        'rule' => $automation->load(['conditionGroups.conditions', 'actions']),
        ]);
    }

    public function update(UpdateRuleRequest $request, AutomationRule $automation)
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 3 / Stage 3A — CRUD-03: replace rule + children in a single
        // transaction. The Livewire form (Stage 3B) submits here.
        app(\App\Services\Automation\RuleWriterService::class)
        ->update($automation, $request->validated());

        return redirect()
        ->route('admin.automations.show', $automation)
        ->with('success', 'Regla actualizada.');
    }

    public function clone(AutomationRule $automation)
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 3 / Stage 3A — CRUD-04: replicate a rule + groups + conditions +
        // actions. The clone is created with `is_active=false` and `mode='test'`
        // so the operator can dry-run it before promoting to live.
        $clone = app(\App\Services\Automation\RuleWriterService::class)
        ->clone($automation, $this->getCurrentUser());

        return redirect()
        ->route('admin.automations.edit', $clone)
        ->with('success', 'Regla duplicada como borrador.');
    }

    private function getCurrentUser(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        return $user;
    }

    public function destroy(AutomationRule $automation)
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 2 / Stage 2B — CRUD-07: soft-delete via Eloquent `SoftDeletes`
        // trait on AutomationRule. The `onlyTrashed()` scope filters
        // soft-deleted rows; the model's `delete()` populates `deleted_at`.
        // PR 2B does NOT hard-delete (CRUD-07 stays recoverable until the
        // papelera expires — out of scope; the controller preserves the
        // soft-delete as the v1 contract).
        $automation->delete();

        return redirect()
        ->route('admin.automations.index')
        ->with('success', 'Regla enviada a la papelera.');
    }

    public function restore(int $id)
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 2 / Stage 2B — CRUD-08: restore from the papelera. We use
        // `onlyTrashed()->findOrFail($id)` because Laravel's default
        // route-model binding skips soft-deleted rows.
        $rule = AutomationRule::query()
        ->onlyTrashed()
        ->findOrFail($id);

        $rule->restore();

        return redirect()
        ->route('admin.automations.trash')
        ->with('success', 'Regla restaurada.');
    }

        public function reorder(ReorderRequest $request)
        {
            Gate::authorize('automations.view');
            Gate::authorize('automations.manage');

            // PR 3 / Stage 3A — CRUD-06: persist a new sequence for rules,
            // conditions, or actions. The form submits `{kind, order: [...]}`.
            // PR 3 rule reorders only; condition/action reorders arrive when
            // the Livewire editors land (PR 3b).
            app(\App\Services\Automation\RuleWriterService::class)
                ->reorder(
                    $request->string('kind')->toString(),
                    $request->input('order', []),
                );

            return back()->with('success', 'Orden actualizado.');
        }

    public function toggle(AutomationRule $automation): JsonResponse
    {
        // Defense in depth (PERM-03 / design §5): the route middleware
        // `can:automations.manage` already gates the request, but the
        // first-statement Gate::authorize pattern is the project
        // convention so every write action is auditable in isolation.
        Gate::authorize('automations.view');
        Gate::authorize('automations.manage');

        // PR 2 / Stage 2A — CRUD-05: inline flip of `is_active`. The
        // route accepts the current value as informational (the controller
        // always flips); the body runs inside a DB transaction so a future
        // activity-log / metrics hook (design §13.4, §8.7) can be added
        // without restructuring.
        DB::transaction(function () use ($automation): void {
        $automation->is_active = ! $automation->is_active;
        $automation->save();
        });

        // JSON envelope is consumed by the Stage 2B fetch() upgrade on
        // the index (it currently submits via a regular form submit +
        // full-page reload — the response is ignored). Keeping the
        // shape stable now means Stage 2B won't need a controller change.
        return response()->json([
        'ok' => true,
        'is_active' => (bool) $automation->is_active,
        'id' => $automation->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Simulate surface — `automations.test` (PERM-04, ACT-07, SCN-SIMULATE-01)
    // ------------------------------------------------------------------

    /**
     * PR 5 — simulate(SimulateRequest, AutomationRule, AutomationAction)
     *
     * Real wiring (ACT-07, design §4.4) — calls
     * `ActionRegistry::resolveForAction()->simulate()` and returns the
     * engine-side envelope. Any Throwable caught returns the JSON error
     * envelope (status 200, the UI consumes the body, not the status).
     *
     * Two pre-flight checks layer on top of the engine simulation to keep
     * the engine files untouched (PR 5 explicit out-of-scope):
     *
     *   1. `webhook` with a URL not in
     *      `config('integrations.webhooks.allowed_destinations')` →
     *      `WebhookNotAuthorizedException` (SCN-SIMULATE-01-B).
     *   2. `send_whatsapp_template` →
     *      `NotImplementedException` mirroring the B14 stub banner
     *      (SCN-SIMULATE-01-C, UI-09).
     */
    public function simulate(
        SimulateRequest $request,
        AutomationRule $automation,
        AutomationAction $action,
    ): JsonResponse {
        // PERM-04 SCN-PERM-02: `automations.test` runs BEFORE
        // ActionRegistry::resolveForAction() is touched — defense in depth.
        Gate::authorize('automations.view');
        Gate::authorize('automations.test');

        abort_unless((int) $action->rule_id === (int) $automation->id, 404);

        try {
            // ------------------------------------------------------------------
            // Pre-flight 1 — webhook allow-list (SCN-SIMULATE-01-B). Mirrors
            // WebhookAction::isAuthorized() so the engine files stay
            // untouched while the simulate endpoint still surfaces the
            // would-be failure envelope to the admin.
            // ------------------------------------------------------------------
            if ($action->type === 'webhook') {
                $payload = (array) $action->payload_json;
                $url = (string) ($payload['url'] ?? '');
                $allowed = (array) config('integrations.webhooks.allowed_destinations', []);

                $okUrl = $url !== ''
                    && ! empty($allowed)
                    && in_array($url, $allowed, true);

                if (! $okUrl) {
                    throw new WebhookNotAuthorizedException(
                        "WebhookAction: destination {$url} is not in the allowed list."
                    );
                }
            }

            // ------------------------------------------------------------------
            // Pre-flight 2 — WhatsApp B14 stub (SCN-SIMULATE-01-C). The
            // engine's simulate() returns a payload; the B14 deliverable
            // replaces that with the live HTTP call. Until then the
            // simulate endpoint mirrors the engine's execute() path by
            // raising NotImplementedException so admins see the same
            // error envelope they would see at runtime.
            // ------------------------------------------------------------------
            if ($action->type === 'send_whatsapp_template') {
                throw new NotImplementedException(
                    'WhatsApp provider is not yet implemented; expected in B14.'
                );
            }

            // ------------------------------------------------------------------
            // Main path — call the engine's simulate() (SCN-SIMULATE-01-A).
            // ------------------------------------------------------------------
            $registry = app(ActionRegistry::class);
            /** @var \App\Contracts\Automation\ActionContract $instance */
            $instance = $registry->resolveForAction($action);

            $result = $instance->simulate((array) ($action->payload_json ?? []));

            return response()->json([
                'ok' => true,
                'response_json' => $result,
            ]);
        } catch (\Throwable $e) {
            // Status 200: the UI consumes the JSON body, not the HTTP code.
            return response()->json([
                'ok' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ], 200);
        }
    }

    // ------------------------------------------------------------------
    // Audit surface — `automations.audit` (PERM-05, HIST-08, AC-9, SCN-AUDIT-01-A)
    // ------------------------------------------------------------------

    /**
     * PR 5 — audit(AutomationRule $automation)
     *
     * Dedicated Blade view (`admin.automations.audit`) showing the
     * paginated Spatie Activitylog feed for the rule (HIST-08,
     * SCN-AUDIT-01-A). Reuses `_audit_changes` partial so the same row
     * rendering shows both on the rule's `show` and on this dedicated
     * page.
     */
    public function audit(AutomationRule $automation): View
    {
        Gate::authorize('automations.view');
        Gate::authorize('automations.audit');

        /** @var LengthAwarePaginator $entries */
        $entries = Activity::query()
            ->where('subject_type', AutomationRule::class)
            ->where('subject_id', $automation->id)
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return view('admin.automations.audit', [
            'rule' => $automation,
            'entries' => $entries,
        ]);
    }
}
