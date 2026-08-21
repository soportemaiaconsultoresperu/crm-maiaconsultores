<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\DataScopeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * RF-LEAD-012 / ADR-006: owner-based visibility through LeadPolicy +
 * DataScopeService.
 */
class LeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private DataScopeService $scope;

    private User $admin;

    private User $supervisor;

    private User $salespersonOne;

    private User $salespersonTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->scope = app(DataScopeService::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');

        $this->salespersonOne = User::factory()->create();
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create();
        $this->salespersonTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->salespersonOne->id);
    }

    public function test_scoped_query_excludes_other_salespersons_leads(): void
    {
        $own = Lead::factory()->forOwner($this->salespersonOne)->create();
        $foreign = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $visible = $this->scope
            ->appliesTo(Lead::query(), $this->salespersonOne)
            ->pluck('id');

        $this->assertContains($own->id, $visible);
        $this->assertNotContains($foreign->id, $visible);
    }

    public function test_scoped_query_for_supervisor_includes_team_members(): void
    {
        $teamLead = Lead::factory()->forOwner($this->salespersonOne)->create();
        $outsiderLead = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $visible = $this->scope
            ->appliesTo(Lead::query(), $this->supervisor)
            ->pluck('id');

        $this->assertContains($teamLead->id, $visible);
        $this->assertNotContains($outsiderLead->id, $visible);
    }

    public function test_scoped_query_for_admin_is_unrestricted(): void
    {
        $own = Lead::factory()->forOwner($this->salespersonOne)->create();
        $foreign = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $visible = $this->scope
            ->appliesTo(Lead::query(), $this->admin)
            ->pluck('id');

        $this->assertContains($own->id, $visible);
        $this->assertContains($foreign->id, $visible);
    }

    public function test_vendedor_cannot_view_another_salespersons_lead_via_policy(): void
    {
        $foreign = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $this->assertFalse(Gate::forUser($this->salespersonOne)->allows('view', $foreign));
        $this->assertTrue(Gate::forUser($this->salespersonTwo)->allows('view', $foreign));
    }

    public function test_supervisor_can_view_team_members_lead_via_policy(): void
    {
        $teamLead = Lead::factory()->forOwner($this->salespersonOne)->create();
        $outsiderLead = Lead::factory()->forOwner($this->salespersonTwo)->create();

        $this->assertTrue(Gate::forUser($this->supervisor)->allows('view', $teamLead));
        $this->assertFalse(Gate::forUser($this->supervisor)->allows('view', $outsiderLead));
    }

    public function test_admin_can_view_any_lead_via_policy(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $lead));
    }
}
