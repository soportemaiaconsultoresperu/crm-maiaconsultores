<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\DataScopeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataScopeTest extends TestCase
{
    use RefreshDatabase;

    private DataScopeService $service;

    private User $admin;

    private User $supervisor;

    private User $vendedorOne;

    private User $vendedorTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(DataScopeService::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('supervisor');

        $this->vendedorOne = User::factory()->create();
        $this->vendedorOne->assignRole('vendedor');

        $this->vendedorTwo = User::factory()->create();
        $this->vendedorTwo->assignRole('vendedor');

        $team = Team::create([
            'name' => 'Equipo Maia',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => true,
        ]);
        $team->members()->attach($this->vendedorOne->id);
    }

    public function test_admin_visibility_is_unrestricted(): void
    {
        $this->assertNull($this->service->visibleOwnerIds($this->admin));
    }

    public function test_vendedor_sees_only_own_records(): void
    {
        $this->assertSame(
            [$this->vendedorOne->id],
            $this->service->visibleOwnerIds($this->vendedorOne)
        );
    }

    public function test_vendedor_outside_team_does_not_see_team_records(): void
    {
        $visible = $this->service->visibleOwnerIds($this->vendedorTwo);

        $this->assertNotNull($visible);
        $this->assertNotContains($this->vendedorOne->id, $visible);
        $this->assertSame([$this->vendedorTwo->id], $visible);
    }

    public function test_supervisor_sees_team_members_and_self(): void
    {
        $visible = $this->service->visibleOwnerIds($this->supervisor);

        $this->assertNotNull($visible);
        $this->assertContains($this->vendedorOne->id, $visible);
        $this->assertContains($this->supervisor->id, $visible);
        $this->assertNotContains($this->vendedorTwo->id, $visible);
    }

    public function test_inactive_team_is_excluded_from_supervisor_scope(): void
    {
        $inactiveTeam = Team::create([
            'name' => 'Equipo Inactivo',
            'supervisor_id' => $this->supervisor->id,
            'is_active' => false,
        ]);
        $inactiveTeam->members()->attach($this->vendedorTwo->id);

        $visible = $this->service->visibleOwnerIds($this->supervisor);

        $this->assertNotContains($this->vendedorTwo->id, $visible);
    }
}
