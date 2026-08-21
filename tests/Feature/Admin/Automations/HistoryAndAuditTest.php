<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationStepStatus;
use App\Events\V2\LeadCreated;
use App\Models\AutomationAction;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use App\Models\AutomationRule;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B12-UI — PR 5 (Chunk 5) — history + execution detail + audit contextual
 * block + idempotency-key copy + purple test-mode badge + simulate() real
 * wiring.
 *
 * One Feature test class. Covers the spec scenarios:
 *
 *   SCN-HIST-01-A / SCN-HIST-01-B / SCN-HIST-01-C / SCN-HIST-01-D
 *   SCN-HIST-02-A / SCN-HIST-02-B / SCN-HIST-02-C / SCN-HIST-02-D
 *   SCN-HIST-03-A / SCN-HIST-03-B
 *   SCN-SIMULATE-01-A / SCN-SIMULATE-01-B / SCN-SIMULATE-01-C
 *   SCN-AUDIT-01-A
 *
 * Trace:
 *   - Spec  : openspec/changes/b12-ui/specs/admin-automations-history.md
 *             (HIST-01..10 + SCN-HIST-01..08)
 *   - Design: openspec/changes/b12-ui/design.md §3.2 (widgets),
 *             §7 (history), §8 (audit), §13.5 (idempotency + badge)
 *   - Tasks : openspec/changes/b12-ui/tasks.md §A.Chunk 5 (PR 6 in the
 *             chain, executed here as "PR 5")
 *
 * Conventions borrowed from AdminAutomationToggleTest +
 * AdminAutomationRuleFormTest:
 *   - `RefreshDatabase`
 *   - `app()->register(AutomationServiceProvider::class, force: true)` +
 *     `seed(RolesAndPermissionsSeeder::class)` in setUp (PERM-08)
 *   - `actingAs($user)` + `givePermissionTo(...)` for the gate matrix
 */
class HistoryAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PERM-08 — provider boot + seeder permissions before any test
        // calls `givePermissionTo(...)`.
        app()->register(AutomationServiceProvider::class, force: true);
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(AutomationServiceProvider::class, force: true);
    }

    // =====================================================================
    // SCN-HIST-01 — admin.automations.show list surface
    // =====================================================================

    public function test_show_renders_paginated_executions_with_status_badges(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-01-A');
        $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS);
        $this->makeExecution($rule, AutomationExecutionStatus::FAILED);
        $this->makeExecution($rule, AutomationExecutionStatus::QUEUED);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.show', $rule));

        $response->assertOk();
        $response->assertViewIs('admin.automations.show');

        // Each status pill rendered with the canonical Spanish label
        // (HIST-01).
        $response->assertSeeText('Exitoso');
        $response->assertSeeText('Fallido');
        $response->assertSeeText('En cola');
    }

    public function test_show_filters_by_status_query(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-01-B');
        $success = $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS);
        $failed = $this->makeExecution($rule, AutomationExecutionStatus::FAILED);
        $queued = $this->makeExecution($rule, AutomationExecutionStatus::QUEUED);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.show', $rule) . '?status=failed');

        $response->assertOk();

        // The filter shows ONLY the failed row. We count `execution-row`
        // occurrences to assert the structural filter behavior — the form
        // select also renders Spanish labels for every status, so a
        // `assertDontSee('Exitoso')` over the full body would falsely fail.
        $rowsRendered = substr_count(
            (string) $response->getContent(),
            'data-testid="execution-row"'
        );
        $this->assertSame(
            1,
            $rowsRendered,
            "SCN-HIST-01-B: ?status=failed must render exactly 1 execution-row; got {$rowsRendered}."
        );

        $response->assertSee('Fallido', escape: false);
    }

    public function test_show_filters_by_subject_type_query(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-01-C');
        $lead = $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS, subjectType: 'Lead');
        $customer = $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS, subjectType: 'Customer');

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.show', $rule) . '?subject_type=Lead');

        $response->assertOk();

        // ?subject_type=Lead renders only the Lead row.
        $rowsRendered = substr_count(
            (string) $response->getContent(),
            'data-testid="execution-row"'
        );
        $this->assertSame(
            1,
            $rowsRendered,
            "SCN-HIST-01-C: ?subject_type=Lead must render exactly 1 execution-row; got {$rowsRendered}."
        );

        // The Lead row's subject_type is rendered as <code>Lead</code>.
        $response->assertSee('<code>Lead</code>', escape: false);
    }

    public function test_view_only_user_does_not_see_audit_block_in_show(): void
    {
        $user = $this->userWith(['automations.view']);
        $rule = $this->makeRule('R-HIST-01-D');
        // Seed an activity row anyway — the gate must hide it.
        Activity::create([
            'log_name' => 'default',
            'description' => 'creó regla',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.automations.show', $rule));

        $response->assertOk();
        // HIST-08 + SCN-PERM-03: the audit block is wrapped in
        // `@can('automations.audit')` — when the user lacks the
        // permission, the wrapper simply is not rendered.
        $response->assertDontSee('Cambios', escape: false);
    }

    // =====================================================================
    // SCN-HIST-02 — admin.automations.executions.show detail surface
    // =====================================================================

    public function test_show_execution_renders_steps_and_idempotency_key(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-02-A');
        $action = $this->makeAction($rule, 'add_tag', ['tag_slug' => 'vip']);
        $execution = $this->makeExecution(
            $rule,
            AutomationExecutionStatus::SUCCESS,
            idempotencyKey: 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd',
        );
        $this->makeStep($execution, $action, AutomationStepStatus::SUCCESS);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.executions.show', [$rule, $execution]));

        $response->assertOk();
        $response->assertViewIs('admin.automations.execution');
        $response->assertSee('abc123def456abc123def456abc123def456abc123def456abc123def456abcd');
        $response->assertSeeText($action->id);
        $response->assertSeeText('Exitoso');
    }

    public function test_show_execution_test_mode_renders_purple_badge_with_exact_tooltip(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-02-B', mode: 'test');
        $execution = $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.executions.show', [$rule, $execution]));

        $response->assertOk();

        // HIST-05 + SCN-HIST-04 + AC-7: the badge MUST read "Modo test" with
        // the EXACT tooltip text. The brief allows Bootstrap `bg-purple` or
        // an inline `style="background:#6f42c1;color:#fff"` — the inline
        // style is what we ship.
        $response->assertSee('Modo test', escape: false);
        $response->assertSee('Modo test: simuló, no ejecutó acciones reales', escape: false);
        $response->assertSee('#6f42c1', escape: false);
    }

    public function test_show_execution_failed_step_renders_error_class_and_message_in_red(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-02-C', mode: 'live');
        $action = $this->makeAction($rule, 'send_whatsapp_template', []);
        $execution = $this->makeExecution($rule, AutomationExecutionStatus::FAILED);
        $this->makeStep(
            $execution,
            $action,
            AutomationStepStatus::FAILED,
            errorClass: 'NotImplementedException',
            errorMessage: 'WhatsApp provider is not yet implemented; expected in B14.',
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.executions.show', [$rule, $execution]));

        $response->assertOk();

        $response->assertSeeText('NotImplementedException');
        $response->assertSeeText('WhatsApp provider is not yet implemented');
        // HIST-09: error_class + error_message rendered in red — verify the
        // alert-danger class is present.
        $response->assertSee('alert-danger', escape: false);
    }

    public function test_show_execution_step_response_json_is_in_pre_code_monospace(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.manage']);
        $rule = $this->makeRule('R-HIST-02-D', mode: 'test');
        $action = $this->makeAction($rule, 'add_tag', []);
        $execution = $this->makeExecution($rule, AutomationExecutionStatus::SUCCESS);
        $this->makeStep(
            $execution,
            $action,
            AutomationStepStatus::SIMULATED,
            responseJson: ['would_dispatch' => true, 'tag_slug' => 'vip'],
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.executions.show', [$rule, $execution]));

        $response->assertOk();

        // HIST-04 (REQ-HIST-04): response_json rendered inside a
        // monospace <pre><code>...</code></pre> block. We assert the
        // opening + closing tags of the <pre><code> pair both wrap the
        // rendered JSON content. The exact JSON content is encoded by
        // Blade's e() so we check for an escaped key.
        $preOpen = '<pre';
        $codeTag = '<code';
        $closePre = '</pre>';
        $closeCode = '</code>';

        $body = $response->getContent();
        $this->assertStringContainsString($preOpen, $body, '<pre> tag missing in execution detail.');
        $this->assertStringContainsString($codeTag, $body, '<code> tag missing in execution detail.');
        $this->assertStringContainsString($closePre, $body, '</pre> closing tag missing in execution detail.');
        $this->assertStringContainsString($closeCode, $body, '</code> closing tag missing in execution detail.');

        // And the JSON payload should appear escaped inside the pre/code
        // pair — checked against Blade's {{ ... }} escaping of &quot;.
        $this->assertStringContainsString('would_dispatch', $body);
        $this->assertStringContainsString('vip', $body);
        $this->assertStringContainsString('font-monospace', $body, 'monospace class missing — response_json must be in monospace.');
    }

    // =====================================================================
    // SCN-HIST-03 — audit contextual block + gating (HIST-08, SCN-PERM-03)
    // =====================================================================

    public function test_show_audit_block_lists_spatie_activitylog_entries_for_rule_only(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.audit']);
        $rule = $this->makeRule('R-HIST-03-A');
        $otherRule = $this->makeRule('Other');

        Activity::create([
            'log_name' => 'default',
            'description' => 'creó regla',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
            'created_at' => now()->subDay(),
        ]);
        Activity::create([
            'log_name' => 'default',
            'description' => 'editó nombre',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
        ]);
        Activity::create([
            'log_name' => 'default',
            'description' => 'esta entrada no debería verse (otra regla)',
            'subject_type' => AutomationRule::class,
            'subject_id' => $otherRule->id,
        ]);
        Activity::create([
            'log_name' => 'default',
            'description' => 'esta entrada no debería verse (otro subject_type)',
            'subject_type' => \App\Models\Lead::class,
            'subject_id' => 999,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.show', $rule));

        $response->assertOk();
        // The two entries for $rule render, the other two do not.
        $response->assertSeeText('creó regla');
        $response->assertSeeText('editó nombre');
        $response->assertDontSee('esta entrada no debería verse (otra regla)');
        $response->assertDontSee('esta entrada no debería verse (otro subject_type)');
        // HIST-08 anchor: the audit section title text.
        $response->assertSee('Cambios', escape: false);
    }

    public function test_show_audit_block_visible_with_audit_perm_and_hidden_without(): void
    {
        $rule = $this->makeRule('R-HIST-03-B');

        // With audit permission
        $withAudit = $this->userWith(['automations.view', 'automations.audit']);
        Activity::create([
            'log_name' => 'default',
            'description' => 'entrada visible',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
        ]);

        $responseWithAudit = $this->actingAs($withAudit)
            ->get(route('admin.automations.show', $rule));
        $responseWithAudit->assertOk();
        $responseWithAudit->assertSee('Cambios', escape: false);
        $responseWithAudit->assertSeeText('entrada visible');

        // Without audit permission
        $manageOnly = $this->userWith(['automations.view', 'automations.manage']);
        $responseWithoutAudit = $this->actingAs($manageOnly)
            ->get(route('admin.automations.show', $rule));
        $responseWithoutAudit->assertOk();
        $responseWithoutAudit->assertDontSee('Cambios', escape: false);
        $responseWithoutAudit->assertDontSeeText('entrada visible');
    }

    // =====================================================================
    // SCN-SIMULATE-01 — POST admin.automations.actions.simulate real wiring
    // =====================================================================

    public function test_simulate_returns_response_json_from_action_contract(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.test']);
        $rule = $this->makeRule('R-SIM-01-A');
        $action = $this->makeAction($rule, 'add_tag', ['tag_slug' => 'preview-tag']);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.automations.actions.simulate', [$rule, $action]));

        $response->assertOk();
        $response->assertJsonStructure(['ok', 'response_json']);
        // success envelope (SCN-SIMULATE-01-A): ok=true + response_json
        // echoes the action's simulate() output.
        $response->assertJson(['ok' => true]);
        $payload = $response->json('response_json');
        $this->assertIsArray($payload);
    }

    public function test_simulate_webhook_unauthorized_url_returns_webhook_exception_envelope(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.test']);
        $rule = $this->makeRule('R-SIM-01-B');
        $action = $this->makeAction($rule, 'webhook', [
            'url' => 'https://evil.example.invalid/hook',
            'method' => 'POST',
        ]);

        // Empty allow-list (default in CI) → deny by default. We do NOT
        // depend on the env value; we set the config explicitly so the
        // test is hermetic.
        config()->set('integrations.webhooks.allowed_destinations', [
            'https://allowed.example.test/hook',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.automations.actions.simulate', [$rule, $action]));

        // SCN-SIMULATE-01-B: response is the caught-throwable envelope (200
        // because the UI consumes the JSON body, NOT the status code).
        $response->assertOk();
        $response->assertJson([
            'ok' => false,
            'error_class' => \App\Services\Automation\Exceptions\WebhookNotAuthorizedException::class,
        ]);
        $this->assertStringContainsString(
            'WebhookAction: destination',
            (string) $response->json('error_message'),
            'error_message must mention WebhookAction + URL rejection.'
        );
    }

    public function test_simulate_whatsapp_template_returns_not_implemented_envelope(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.test']);
        $rule = $this->makeRule('R-SIM-01-C');
        $action = $this->makeAction($rule, 'send_whatsapp_template', [
            'template_name' => 'bienvenida',
            'phone_number' => '+51999999999',
            'language' => 'es_PE',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.automations.actions.simulate', [$rule, $action]));

        // SCN-SIMULATE-01-C: simulate() on the B14 stub returns the
        // NotImplementedException error envelope (status 200, ok=false).
        $response->assertOk();
        $response->assertJson([
            'ok' => false,
            'error_class' => \App\Services\Automation\Exceptions\NotImplementedException::class,
        ]);
        $this->assertStringContainsString(
            'B14',
            (string) $response->json('error_message'),
            'error_message must mention B14 to signal the stub is pending.'
        );
    }

    // =====================================================================
    // SCN-AUDIT-01 — GET admin.automations.audit dedicated feed
    // =====================================================================

    public function test_audit_route_returns_blade_view_for_rule(): void
    {
        $admin = $this->userWith(['automations.view', 'automations.audit']);
        $rule = $this->makeRule('R-AUDIT-01-A');
        Activity::create([
            'log_name' => 'default',
            'description' => 'creó regla',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
        ]);
        Activity::create([
            'log_name' => 'default',
            'description' => 'activó regla',
            'subject_type' => AutomationRule::class,
            'subject_id' => $rule->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.audit', $rule));

        $response->assertOk();
        // SCN-AUDIT-01-A: dedicated Blade view (not JSON) with the
        // activity rows for this rule only.
        $response->assertViewIs('admin.automations.audit');
        $response->assertViewHas('rule');
        $response->assertViewHas('entries');
        $response->assertSeeText('creó regla');
        $response->assertSeeText('activó regla');
    }

    public function test_audit_route_is_forbidden_without_automations_audit_permission(): void
    {
        $user = $this->userWith(['automations.view']);
        $rule = $this->makeRule('R-AUDIT-01-A-403');

        $response = $this->actingAs($user)
            ->get(route('admin.automations.audit', $rule));

        $response->assertForbidden();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function makeRule(string $name, string $mode = 'live', bool $isActive = true): AutomationRule
    {
        return AutomationRule::create([
            'name' => $name,
            'description' => null,
            'trigger_event' => LeadCreated::class,
            'is_active' => $isActive,
            'order' => 1,
            'mode' => $mode,
        ]);
    }

    private function makeAction(
        AutomationRule $rule,
        string $type = 'add_tag',
        array $payload = [],
    ): AutomationAction {
        return AutomationAction::create([
            'rule_id' => $rule->id,
            'type' => $type,
            'position' => 1,
            'channel' => null,
            'recipient_strategy' => null,
            'payload_json' => $payload,
            'retry_policy_json' => null,
            'is_active' => true,
        ]);
    }

        /**
         * Monotonic seed for the idempotency_key helper — guarantees
         * UNIQUE(idempotency_key) across calls within a single test.
         */
        private int $executionSeed = 0;

        private function makeExecution(
            AutomationRule $rule,
            string $status = AutomationExecutionStatus::SUCCESS,
            ?string $idempotencyKey = null,
            string $subjectType = 'Lead',
            int $subjectId = 1,
        ): AutomationExecution {
            $this->executionSeed++;
            if ($idempotencyKey === null) {
                // char(64) idempotency_key — SHA-1 hex (40 chars) padded
                // with a deterministic numeric suffix per call.
                $idempotencyKey = sprintf(
                    '%-40s_%010d_%010d',
                    sha1($rule->id.'|'.$subjectType.'|'.$subjectId.'|'.$this->executionSeed),
                    (int) $rule->id,
                    $this->executionSeed,
                );
            }

            return AutomationExecution::create([
            'rule_id' => $rule->id,
            'trigger_event' => LeadCreated::class,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'attempt' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    private function makeStep(
        AutomationExecution $execution,
        AutomationAction $action,
        string $status = AutomationStepStatus::SUCCESS,
        ?array $responseJson = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): AutomationExecutionStep {
        return AutomationExecutionStep::create([
            'execution_id' => $execution->id,
            'action_id' => $action->id,
            'status' => $status,
            'attempt' => 1,
            'response_json' => $responseJson,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);
    }
}
