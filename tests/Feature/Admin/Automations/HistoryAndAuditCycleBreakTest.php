<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Enums\AutomationExecutionStatus;
use App\Events\V2\LeadCreated;
use App\Models\AutomationCycleBreak;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B12.5 — UI Polish — Cycle-break rendering Feature test (HIST-07).
 *
 * Pins the contract that `resources/views/admin/automations/execution.blade.php`
 * renders a `<details>` block listing the `AutomationCycleBreak` rows attached
 * to the rule. The rendering half was deferred in B12-UI (verify-report §5.4)
 * because the cycle-break path is rare (30s window — `CycleDetector::DEFAULT_
 * WINDOW_SECONDS`). This test ensures the rendering block is present and
 * shows the count, rule name, and a literal substring of the `reason`.
 *
 * Spec   : openspec/changes/b12.5-ui-polish/specs/admin-automations-history.md
 *          REQ-HIST-07 (B12.5 delta).
 * Design : openspec/changes/b12.5-ui-polish/design.md §2.2.
 * Tasks  : openspec/changes/b12.5-ui-polish/tasks.md Chunk 2.
 *
 * @see \App\Http\Controllers\Admin\AutomationController::showExecution
 */
class HistoryAndAuditCycleBreakTest extends TestCase
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

    // ---------------------------------------------------------------------
    // SCN-HIST-07-B12.5 — execution detail renders cycle-break <details>
    // block with the count, rule name, and a literal substring of the
    // reason.
    // ---------------------------------------------------------------------
    public function test_show_execution_renders_cycle_break_details_block(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo(['automations.view', 'automations.manage']);

        $rule = AutomationRule::create([
            'name' => 'R-CYCLEBREAK-01',
            'description' => null,
            'trigger_event' => LeadCreated::class,
            'is_active' => true,
            'order' => 1,
            'mode' => 'live',
        ]);

        $execution = AutomationExecution::create([
            'rule_id' => $rule->id,
            'trigger_event' => LeadCreated::class,
            'subject_type' => 'Lead',
            'subject_id' => 1,
            'idempotency_key' => str_repeat('a', 64),
            'status' => AutomationExecutionStatus::CIRCUIT_BROKEN,
            'attempt' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        // 2 cycle-break rows — the count to assert in the rendered HTML.
        AutomationCycleBreak::create([
            'rule_id' => $rule->id,
            'subject_type' => 'Lead',
            'subject_id' => 1,
            'reason' => 'Re-entry detected within 30s window',
            'detected_at' => now()->subSeconds(10),
        ]);
        AutomationCycleBreak::create([
            'rule_id' => $rule->id,
            'subject_type' => 'Lead',
            'subject_id' => 2,
            'reason' => 'CycleDetector tripped for second subject',
            'detected_at' => now()->subSeconds(5),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.automations.executions.show', [$rule, $execution]));

        $response->assertOk();
        $response->assertViewIs('admin.automations.execution');

        $body = (string) $response->getContent();

        // 1. The cycle-break count (2) is rendered.
        $this->assertStringContainsString(
            'Cycle breaks (2)',
            $body,
            'SCN-HIST-07-B12.5: the <details> summary must include the cycle-break count (2).'
        );

        // 2. The rule name is rendered (always was, but reaffirms the
        // contract — the cycle-break block lives BELOW the rule header).
        $this->assertStringContainsString(
            'R-CYCLEBREAK-01',
            $body,
            'SCN-HIST-07-B12.5: the rule name must be rendered (already true pre-B12.5; reaffirms the contract).'
        );

        // 3. The <details> block + a literal substring of the reason.
        $this->assertStringContainsString(
            '<details',
            $body,
            'SCN-HIST-07-B12.5: the <details> opening tag must be rendered.'
        );
        $this->assertStringContainsString(
            'Re-entry detected within 30s window',
            $body,
            'SCN-HIST-07-B12.5: a literal substring of the first cycle-break reason must be rendered.'
        );
        $this->assertStringContainsString(
            'CycleDetector tripped for second subject',
            $body,
            'SCN-HIST-07-B12.5: a literal substring of the second cycle-break reason must be rendered.'
        );
    }
}
