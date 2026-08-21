<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\ActivityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Activity data scope (RF-ACT-008 / ADR-006). Mirrors DataScopeTest but
 * exercises the activity-level visibility through ActivityService::scopeQuery
 * + the integration with DataScopeService.
 */
class ActivityScopeTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    private User $admin;

    private User $supervisor;

    private User $vendedorOne;

    private User $vendedorTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->service = app(ActivityService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create(['is_active' => true]);
        $this->supervisor->assignRole('supervisor');

        $this->vendedorOne = User::factory()->create(['is_active' => true]);
        $this->vendedorOne->assignRole('vendedor');

        $this->vendedorTwo = User::factory()->create(['is_active' => true]);
        $this->vendedorTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->vendedorOne->id);
    }

    public function test_admin_sees_all_activities(): void
    {
        $this->makeActivity($this->vendedorOne);
        $this->makeActivity($this->vendedorTwo);

        $ids = $this->service->scopeQuery($this->admin)->pluck('owner_id')->all();

        $this->assertContains($this->vendedorOne->id, $ids);
        $this->assertContains($this->vendedorTwo->id, $ids);
    }

    public function test_supervisor_sees_team_activities_and_own(): void
    {
        $this->makeActivity($this->supervisor);
        $this->makeActivity($this->vendedorOne);
        $this->makeActivity($this->vendedorTwo);

        $ownerIds = $this->service->scopeQuery($this->supervisor)->pluck('owner_id')->all();

        $this->assertContains($this->vendedorOne->id, $ownerIds);
        $this->assertContains($this->supervisor->id, $ownerIds);
        $this->assertNotContains($this->vendedorTwo->id, $ownerIds);
    }

    public function test_vendedor_sees_only_own_activities(): void
    {
        $this->makeActivity($this->vendedorOne);
        $this->makeActivity($this->vendedorTwo);

        $ownerIds = $this->service->scopeQuery($this->vendedorOne)->pluck('owner_id')->all();

        $this->assertSame([$this->vendedorOne->id], $ownerIds);
    }

    public function test_scope_uses_activity_owner_not_subject_owner(): void
    {
        // Subject owned by vendedorOne, activity assigned to vendedorTwo:
        // vendedorOne must NOT see the activity.
        $lead = Lead::factory()->forOwner($this->vendedorOne)->create();

        $activity = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => \App\Models\ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada de calificación',
            'scheduled_at' => now()->addDay(),
            'owner_id' => $this->vendedorTwo->id,
        ], $this->vendedorOne);

        $vendedorOneScope = $this->service->scopeQuery($this->vendedorOne)->pluck('id')->all();

        $this->assertNotContains($activity->id, $vendedorOneScope);
    }

    private function makeActivity(User $owner): Activity
    {
        $lead = Lead::factory()->forOwner($owner)->create();

        return $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => \App\Models\ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada',
            'scheduled_at' => now()->addDay(),
            'owner_id' => $owner->id,
        ], $owner);
    }
}