<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\V2\QuotationAccepted;
use App\Models\AutomationCondition;
use App\Models\AutomationConditionGroup;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rule bound to the quotation triggers must fire from the SERVICE — the
 * defect was that QuotationService::create()/accept() returned DB::transaction()
 * directly, leaving the post-commit event() calls unreachable, so no automation
 * bound to those triggers ever fired in production.
 *
 * Why this file exists instead of a second test inside AutomationEngineTest:
 * HardeningCrossCutTest::test_engine_test_suite_remains_10_over_10_green pins
 * that file to exactly 10 tests / 21 assertions with `--filter=AutomationEngineTest`,
 * and HardeningCrossCutTest is outside this unit's edit surfaces. The create()
 * proof was therefore replaced IN PLACE there (same footprint, now driving
 * QuotationService); this file carries the acceptance-trigger proof.
 */
class QuotationAutomationTriggerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_accept_through_the_service_fires_a_rule_bound_to_quotation_accepted(): void
    {
        $rule = AutomationRule::create([
            'name' => 'Test rule for '.QuotationAccepted::class,
            'description' => null,
            'trigger_event' => QuotationAccepted::class,
            'is_active' => true,
            'order' => 0,
            'mode' => 'live',
            'created_by' => $this->admin->id,
            'owner_id' => $this->admin->id,
        ]);

        $group = AutomationConditionGroup::create([
            'rule_id' => $rule->id,
            'logical_operator' => 'AND',
            'position' => 0,
        ]);

        AutomationCondition::create([
            'group_id' => $group->id,
            'rule_id' => $rule->id,
            'field' => 'status',
            'operator' => 'eq',
            'value' => 'accepted',
            'value_type' => 'string',
            'position' => 0,
        ]);

        $service = app(QuotationService::class);

        $quotation = $service->create([
            'lead_id' => Lead::factory()->forOwner($this->admin)->create()->id,
            'items' => [
                ['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => null],
            ],
        ], $this->admin);

        // Only the QuotationAccepted rule exists, so the single execution below
        // can only come from the acceptance transition.
        $service->accept($quotation, $this->admin, 'Aceptada por el cliente');

        $this->assertSame(1, AutomationExecution::query()->where('rule_id', $rule->id)->count());
    }
}
