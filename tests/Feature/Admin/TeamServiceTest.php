<?php

namespace Tests\Feature\Admin;

use App\Exceptions\InvalidOperationException;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B08 — Team administration service tests.
 *
 * Three rules pin down the contract:
 * - addMember / removeMember correctly mutate the pivot and audit
 *   each change.
 * - removeMember refuses to remove a user who is the team's only
 *   supervisor (defensive: the admin must reassign supervision first).
 */
class TeamServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->service = app(TeamService::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_add_member_and_remove_member_audit_each_change(): void
    {
        $supervisor = User::factory()->create();
        $member = User::factory()->create();

        $team = $this->service->create([
            'name' => 'Equipo Norte',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ], $this->admin);

        $this->service->addMember($team, $member, $this->admin);

        $this->assertTrue($team->members()->whereKey($member->id)->exists());

        $addLog = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-added')
            ->whereJsonContains('properties->user_id', $member->id)
            ->first();

        $this->assertNotNull($addLog, 'Adding the member must emit a team-member-added audit row tagged with their user id.');

        // Re-adding is a no-op (idempotent, no extra log row).
        $beforeReAdd = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-added')
            ->count();

        $this->service->addMember($team, $member, $this->admin);

        $afterReAdd = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-added')
            ->count();

        $this->assertSame($beforeReAdd, $afterReAdd, 'Re-adding the same member must not emit an additional audit row.');

        $this->service->removeMember($team, $member, $this->admin);

        $this->assertFalse($team->fresh()->members()->whereKey($member->id)->exists());

        $removeLog = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-member-removed')
            ->first();

        $this->assertNotNull($removeLog);
        $this->assertSame((int) $removeLog->properties['user_id'], $member->id);
    }

    public function test_remove_member_throws_when_target_is_the_only_supervisor(): void
    {
        $supervisor = User::factory()->create();

        $team = $this->service->create([
            'name' => 'Equipo Sur',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ], $this->admin);

        // The supervisor is a member too (auto-attached on setSupervisor)
        // and they are the ONLY supervisor of this brand-new team.
        $this->assertTrue($team->members()->whereKey($supervisor->id)->exists());

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('único supervisor');

        $this->service->removeMember($team, $supervisor, $this->admin);
    }

    public function test_set_supervisor_moves_supervision_and_keeps_old_supervisor_as_member(): void
    {
        $oldSupervisor = User::factory()->create();
        $newSupervisor = User::factory()->create();

        $team = $this->service->create([
            'name' => 'Equipo Centro',
            'supervisor_id' => $oldSupervisor->id,
            'is_active' => true,
        ], $this->admin);

        $this->service->setSupervisor($team, $newSupervisor, $this->admin);

        $fresh = $team->fresh();
        $this->assertSame($newSupervisor->id, $fresh->supervisor_id);
        $this->assertTrue($fresh->members()->whereKey($oldSupervisor->id)->exists(), 'Previous supervisor stays a member.');
        $this->assertTrue($fresh->members()->whereKey($newSupervisor->id)->exists(), 'New supervisor is attached as a member.');

        $log = Activity::query()
            ->where('subject_type', Team::class)
            ->where('subject_id', $team->id)
            ->where('event', 'team-supervisor-changed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($oldSupervisor->id, (int) $log->properties['old_supervisor_id']);
        $this->assertSame($newSupervisor->id, (int) $log->properties['new_supervisor_id']);
    }
}