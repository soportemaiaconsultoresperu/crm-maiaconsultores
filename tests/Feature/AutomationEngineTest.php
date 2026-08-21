<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationStepStatus;
use App\Events\V2\LeadAssigned;
use App\Events\V2\LeadCreated;
use App\Events\V2\QuotationCreated;
use App\Models\AutomationAction;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use App\Models\AutomationRule;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Automation\Actions\AssignOwnerAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

/**
 * B12 engine smoke tests: a matching rule fires, a non-matching rule does
 * not, condition operators behave as advertised, idempotency holds, mode
 * test does NOT mutate the subject, and a cycle is broken within the
 * 30-second window.
 */
class AutomationEngineTest extends BaseTestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_lead_created_event_triggers_matching_rule(): void
    {
        $rule = $this->makeRule(LeadCreated::class);

        $this->makeConditionGroup($rule, [['field' => 'status_id', 'operator' => 'is_not_null']]);

        $lead = Lead::factory()->forOwner($this->admin)->create();

        event(new LeadCreated($lead, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_lead_assigned_event_triggers_matching_rule(): void
    {
        $rule = $this->makeRule(LeadAssigned::class);

        $this->makeConditionGroup($rule, [['field' => 'previous_owner_id', 'operator' => 'is_not_null']]);

        $previous = User::factory()->create();
        $lead = Lead::factory()->forOwner($this->admin)->create();

        event(new LeadAssigned($lead, $previous->id, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_quotation_created_event_triggers_matching_rule(): void
    {
        $rule = $this->makeRule(QuotationCreated::class);

        $this->makeConditionGroup($rule, [['field' => 'status', 'operator' => 'eq', 'value' => 'draft', 'value_type' => 'string']]);

        Tax::query()->firstOrCreate(['slug' => 'igv'], ['name' => 'IGV', 'rate' => 18, 'sort' => 1, 'is_active' => true]);

        $quotation = Quotation::factory()->forOwner($this->admin)->draft()->create();
        QuotationItem::query()->create([
            'quotation_id' => $quotation->id,
            'description' => 'Servicio',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'tax_id' => null,
            'tax_name' => '',
            'tax_rate' => 0,
            'line_subtotal' => 100,
            'line_tax' => 0,
            'line_total' => 100,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        event(new QuotationCreated($quotation, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_lead_assigned_event_does_not_trigger_lead_created_rule(): void
    {
        $rule = $this->makeRule(LeadCreated::class);

        $previous = User::factory()->create();
        $lead = Lead::factory()->forOwner($this->admin)->create();

        event(new LeadAssigned($lead, $previous->id, $this->admin));

        $this->assertSame(0, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_condition_evaluator_handles_and_group(): void
    {
        $rule = $this->makeRule(LeadCreated::class);
        $this->makeConditionGroup($rule, [
            ['field' => 'status_id', 'operator' => 'is_not_null'],
            ['field' => 'interest_level', 'operator' => 'eq', 'value' => 'alto', 'value_type' => 'string'],
        ]);

        $lead = Lead::factory()->forOwner($this->admin)->create([
            'interest_level' => 'alto',
        ]);

        event(new LeadCreated($lead, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_condition_evaluator_handles_or_group(): void
    {
        $rule = $this->makeRule(LeadCreated::class);
        $group = AutomationConditionGroup::create([
            'rule_id' => $rule->id,
            'logical_operator' => 'OR',
            'position' => 0,
        ]);
        AutomationCondition::create([
            'group_id' => $group->id,
            'rule_id' => $rule->id,
            'field' => 'interest_level',
            'operator' => 'eq',
            'value' => 'alto',
            'value_type' => 'string',
            'position' => 0,
        ]);
        AutomationCondition::create([
            'group_id' => $group->id,
            'rule_id' => $rule->id,
            'field' => 'interest_level',
            'operator' => 'eq',
            'value' => 'bajo',
            'value_type' => 'string',
            'position' => 1,
        ]);

        $lead = Lead::factory()->forOwner($this->admin)->create([
            'interest_level' => 'bajo',
        ]);

        event(new LeadCreated($lead, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_operator_coverage(): void
    {
        $cases = [
            ['eq', 'medio', 'medio', true],
            ['neq', 'alto', 'medio', true],
            ['in', 'alto,medio,bajo', 'medio', true],
            ['contains', 'edi', 'medio', true],
            ['is_null', null, null, true],
            ['gt', 'bajo', 'medio', true],
            ['lt', 'medio', 'bajo', true],
        ];

        foreach ($cases as [$op, $expected, $actual, $shouldMatch]) {
            $rule = $this->makeRule(LeadCreated::class);
            $this->makeConditionGroup($rule, [
                [
                    'field' => 'interest_level',
                    'operator' => $op,
                    'value' => $op === 'is_null' ? null : (string) $expected,
                    'value_type' => $op === 'in' ? 'list' : 'string',
                ],
            ]);

            $lead = Lead::factory()->forOwner($this->admin)->create([
                'interest_level' => $actual,
            ]);

            event(new LeadCreated($lead, $this->admin));

            $count = AutomationExecution::query()->where('rule_id', $rule->id)->count();
            $this->assertSame($shouldMatch ? 1 : 0, $count, "operator {$op} misfired");
        }
    }

    public function test_idempotency_dispatching_same_event_twice_produces_only_one_execution(): void
    {
        $rule = $this->makeRule(LeadCreated::class);

        $lead = Lead::factory()->forOwner($this->admin)->create();

        event(new LeadCreated($lead, $this->admin));
        event(new LeadCreated($lead, $this->admin));

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }

    public function test_test_mode_does_not_mutate_the_lead(): void
    {
        $rule = $this->makeRule(LeadCreated::class, mode: 'test');
        AutomationAction::create([
            'rule_id' => $rule->id,
            'position' => 0,
            'type' => 'change_status',
            'payload_json' => ['value' => '9999'],
            'is_active' => true,
        ]);

        $lead = Lead::factory()->forOwner($this->admin)->create();
        $originalStatus = $lead->status_id;

        event(new LeadCreated($lead, $this->admin));

        $lead->refresh();
        $this->assertSame((int) $originalStatus, (int) $lead->status_id);

        $execution = AutomationExecution::query()->where('rule_id', $rule->id)->first();
        $this->assertNotNull($execution);
        $this->assertSame(AutomationExecutionStatus::SUCCESS, $execution->status);
        $this->assertSame(AutomationStepStatus::SIMULATED, $execution->steps()->first()->status);
    }

    public function test_cycle_detection_breaks_loop(): void
    {
        $rule = $this->makeRule(LeadAssigned::class);

        // No actions on the rule — the cycle test only exercises the
        // detector without involving the action pipeline.
        $previous = User::factory()->create();
        $lead = Lead::factory()->forOwner($this->admin)->create();

        event(new LeadAssigned($lead, $previous->id, $this->admin));
        event(new LeadAssigned($lead, $previous->id, $this->admin));

        $executions = AutomationExecution::query()->where('rule_id', $rule->id)->get();
        $this->assertGreaterThanOrEqual(1, $executions->count());
        $this->assertNotEmpty($executions->where('status', AutomationExecutionStatus::CIRCUIT_BROKEN)->all());
    }

    private function makeRule(string $triggerEvent, string $mode = 'live'): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'Test rule for '.$triggerEvent,
            'description' => null,
            'trigger_event' => $triggerEvent,
            'is_active' => true,
            'order' => 0,
            'mode' => $mode,
            'created_by' => $this->admin->id,
            'owner_id' => $this->admin->id,
        ]);
    }

    private function makeConditionGroup(AutomationRule $rule, array $conditions): AutomationConditionGroup
    {
        $group = AutomationConditionGroup::create([
            'rule_id' => $rule->id,
            'logical_operator' => 'AND',
            'position' => 0,
        ]);

        foreach ($conditions as $i => $cond) {
            AutomationCondition::create([
                'group_id' => $group->id,
                'rule_id' => $rule->id,
                'field' => $cond['field'],
                'operator' => $cond['operator'],
                'value' => $cond['value'] ?? null,
                'value_type' => $cond['value_type'] ?? 'string',
                'position' => $i,
            ]);
        }

        return $group;
    }
}