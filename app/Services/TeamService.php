<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Team administration (B08 / RF-USR-004 follow-up, ADR-006).
 *
 * Teams define the data scope. Supervisors are the owner of one or more
 * teams; salespeople are members of those teams. The TeamService exposes
 * the membership changes with explicit audit events so the audit viewer
 * can show "who moved whom where".
 *
 * Invariants:
 * - Teams are deactivated, never deleted (RNF-DAT-001, scope history).
 * - A team with a sole supervisor cannot have that supervisor removed as
 *   a member without first reassigning the supervision — see
 *   removeMember() and setSupervisor().
 * - Membership changes are transactional with their audit entries.
 */
class TeamService
{
    /**
     * Create a team (initial supervisor + optional initial members).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Team
    {
        $memberIds = $data['members'] ?? [];
        unset($data['members']);

        return DB::transaction(function () use ($data, $actor, $memberIds): Team {
            $team = new Team();
            $team->name = (string) ($data['name'] ?? '');
            $team->supervisor_id = $data['supervisor_id'] ?? null;
            $team->is_active = (bool) ($data['is_active'] ?? true);
            $team->save();

            // The supervisor is always a member of the team they
            // supervise (setSupervisor enforces this too; create() just
            // mirrors the rule on initial insert).
            if ($team->supervisor_id !== null) {
                $memberIds[] = $team->supervisor_id;
            }

            $this->syncMembers($team, $memberIds, $actor, 'team-created');

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-created',
                'description' => "Equipo {$team->name} creado",
                'properties' => [
                    'supervisor_id' => $team->supervisor_id,
                    'is_active' => (bool) $team->is_active,
                    'initial_members' => array_values(array_map('intval', (array) $memberIds)),
                ],
            ]);

            return $team->refresh();
        });
    }

    /**
     * Update the team name/supervisor/active flag. Membership changes go
     * through addMember / removeMember / setSupervisor so each one gets a
     * dedicated audit row.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Team $team, array $data, User $actor): Team
    {
        return DB::transaction(function () use ($team, $data, $actor): Team {
            $oldSupervisorId = $team->supervisor_id;

            if (array_key_exists('name', $data)) {
                $team->name = (string) $data['name'];
            }

            $supervisorChanged = false;
            if (array_key_exists('supervisor_id', $data)) {
                $newSupervisorId = $data['supervisor_id'];
                if ((int) $newSupervisorId !== (int) $team->supervisor_id) {
                    $supervisorChanged = true;
                }
                $team->supervisor_id = $newSupervisorId;
            }

            if (array_key_exists('is_active', $data)) {
                $team->is_active = (bool) $data['is_active'];
            }

            $team->save();

            // If the supervisor changed, ensure the new one is attached as a
            // member (mirrors setSupervisor()'s invariant).
            if ($supervisorChanged && $team->supervisor_id !== null) {
                if (! $team->members()->whereKey($team->supervisor_id)->exists()) {
                    $team->members()->attach($team->supervisor_id);
                }
            }

            $properties = [];
            if ($oldSupervisorId !== $team->supervisor_id) {
                $properties['old_supervisor_id'] = $oldSupervisorId;
                $properties['new_supervisor_id'] = $team->supervisor_id;
            }

            if ($properties !== []) {
                Activity::query()->create([
                    'log_name' => 'default',
                    'subject_type' => Team::class,
                    'subject_id' => $team->id,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'event' => 'team-updated',
                    'description' => "Equipo {$team->name} actualizado",
                    'properties' => $properties,
                ]);
            }

            return $team->refresh();
        });
    }

    /**
     * Deactivate a team (never delete). Active salespeople keep being
     * members; the team's scope is removed from supervisor queries.
     */
    public function deactivate(Team $team, User $actor, string $reason): Team
    {
        return DB::transaction(function () use ($team, $actor, $reason): Team {
            $previous = (bool) $team->is_active;
            $team->is_active = false;
            $team->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-deactivated',
                'description' => "Equipo {$team->name} desactivado",
                'properties' => [
                    'old_is_active' => $previous,
                    'new_is_active' => false,
                    'reason' => $reason,
                ],
            ]);

            return $team->refresh();
        });
    }

    /**
     * Add a member to the team. Idempotent: re-adding a member is a
     * no-op (we never log "user already in team").
     */
    public function addMember(Team $team, User $member, User $actor): Team
    {
        return DB::transaction(function () use ($team, $member, $actor): Team {
            $alreadyMember = $team->members()->whereKey($member->id)->exists();

            if (! $alreadyMember) {
                $team->members()->attach($member->id);

                Activity::query()->create([
                    'log_name' => 'default',
                    'subject_type' => Team::class,
                    'subject_id' => $team->id,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'event' => 'team-member-added',
                    'description' => "{$member->name} agregado al equipo {$team->name}",
                    'properties' => [
                        'user_id' => $member->id,
                    ],
                ]);
            }

            return $team->refresh();
        });
    }

    /**
     * Remove a member from the team. Throws when:
     * - The member is not actually in the team.
     * - The member is the team's only supervisor and no replacement
     *   supervisor is in place yet (callers must call setSupervisor()
     *   first or supply a new supervisor_id).
     *
     * @throws InvalidOperationException
     */
    public function removeMember(Team $team, User $member, User $actor): Team
    {
        $isMember = $team->members()->whereKey($member->id)->exists();

        if (! $isMember) {
            throw new InvalidOperationException(
                "{$member->name} no pertenece al equipo {$team->name}."
            );
        }

        if ($team->supervisor_id !== null && (int) $team->supervisor_id === (int) $member->id) {
            throw new InvalidOperationException(
                "No puedes quitar a {$member->name} del equipo {$team->name}: es su único supervisor. ".
                'Asigna primero otro supervisor con setSupervisor().'
            );
        }

        return DB::transaction(function () use ($team, $member, $actor): Team {
            $team->members()->detach($member->id);

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-member-removed',
                'description' => "{$member->name} removido del equipo {$team->name}",
                'properties' => [
                    'user_id' => $member->id,
                ],
            ]);

            return $team->refresh();
        });
    }

    /**
     * Replace the team's supervisor. The new supervisor must be a member
     * of the team (we attach them automatically if not). The new
     * supervisor becomes the team's sole `supervisor_id`; the previous
     * supervisor remains a member unless explicitly removed afterwards.
     */
    public function setSupervisor(Team $team, User $newSupervisor, User $actor): Team
    {
        return DB::transaction(function () use ($team, $newSupervisor, $actor): Team {
            $oldSupervisorId = $team->supervisor_id;

            if (! $team->members()->whereKey($newSupervisor->id)->exists()) {
                $team->members()->attach($newSupervisor->id);
            }

            $team->supervisor_id = $newSupervisor->id;
            $team->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-supervisor-changed',
                'description' => "Supervisor del equipo {$team->name} cambiado",
                'properties' => [
                    'old_supervisor_id' => $oldSupervisorId,
                    'new_supervisor_id' => $newSupervisor->id,
                ],
            ]);

            return $team->refresh();
        });
    }

    /**
     * Replace the entire membership at once (used by team creation's
     * initial seed). Each add/diff is logged individually so the audit
     * trail stays granular.
     *
     * @param  array<int, mixed>  $memberIds
     */
    private function syncMembers(Team $team, array $memberIds, User $actor, string $eventContext): void
    {
        $existing = $team->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $incoming = array_values(array_unique(array_map('intval', $memberIds)));

        $toAttach = array_values(array_diff($incoming, $existing));
        $toDetach = array_values(array_diff($existing, $incoming));

        if ($toAttach !== []) {
            $team->members()->attach($toAttach);
        }

        if ($toDetach !== []) {
            $team->members()->detach($toDetach);
        }

        foreach ($toAttach as $userId) {
            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-member-added',
                'description' => "Usuario #{$userId} agregado al equipo {$team->name}",
                'properties' => [
                    'user_id' => $userId,
                    'context' => $eventContext,
                ],
            ]);
        }

        foreach ($toDetach as $userId) {
            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => Team::class,
                'subject_id' => $team->id,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'team-member-removed',
                'description' => "Usuario #{$userId} removido del equipo {$team->name}",
                'properties' => [
                    'user_id' => $userId,
                    'context' => $eventContext,
                ],
            ]);
        }
    }
}