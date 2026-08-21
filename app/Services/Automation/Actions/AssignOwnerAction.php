<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\Team;
use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reassign the owner of the event subject.
 *
 * Recipient strategies:
 *  - user: explicit user_id in payload
 *  - team:  pick the supervisor of the team in payload.team_id (or the
 *           first active team if not specified)
 *  - current: keep the current owner (no-op)
 *  - round_robin: distribute deterministically among the team's members.
 *
 * DataScope: when the rule creator has DataScope restrictions, the new
 * owner MUST be inside the visible owner set; otherwise the action fails
 * with a permission error.
 */
class AssignOwnerAction implements ActionContract
{
    public function __construct(private readonly DataScopeService $dataScope)
    {
    }

    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $subject = $this->resolveSubject($execution->subject_type, (int) $execution->subject_id);

        if ($subject === null) {
            return;
        }

        $strategy = (string) ($payload['recipient_strategy'] ?? $step->action()->first()?->recipient_strategy ?? 'current');

        $newOwner = match ($strategy) {
            'user' => $this->resolveUserOwner($payload),
            'team' => $this->resolveTeamOwner($payload, (int) $subject->owner_id),
            'round_robin' => $this->resolveRoundRobin($payload, (int) $execution->subject_id),
            'current', '' => null,
            default => throw new InvalidArgumentException("Unknown recipient_strategy {$strategy}"),
        };

        if ($newOwner === null) {
            $step->response_json = array_merge((array) ($step->response_json ?? []), [
                'recipient_strategy' => $strategy,
                'result' => 'no-change',
            ]);
            $step->save();

            return;
        }

        $rule = $execution->rule()->first();
        $creator = $rule?->created_by !== null
            ? User::query()->find($rule->created_by)
            : null;

        if ($creator !== null && ! $this->dataScope->visibleOwnerIds($creator) === false) {
            $visible = $this->dataScope->visibleOwnerIds($creator);

            if (is_array($visible) && ! in_array((int) $newOwner->id, $visible, true)) {
                throw new InvalidArgumentException(
                    "AssignOwnerAction: target user {$newOwner->id} is outside the actor's data scope."
                );
            }
        }

        DB::transaction(function () use ($subject, $newOwner, $strategy): void {
            $subject->owner_id = $newOwner->id;
            $subject->updated_by = $newOwner->id;
            $subject->save();
        });

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'recipient_strategy' => $strategy,
            'new_owner_id' => $newOwner->id,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_assign_owner' => true,
            'recipient_strategy' => $payload['recipient_strategy'] ?? null,
            'payload' => $payload,
        ];
    }

    private function resolveSubject(string $subjectType, int $subjectId): ?object
    {
        return match ($subjectType) {
            \App\Models\Lead::class => \App\Models\Lead::query()->find($subjectId),
            \App\Models\Opportunity::class => \App\Models\Opportunity::query()->find($subjectId),
            \App\Models\Customer::class => \App\Models\Customer::query()->find($subjectId),
            \App\Models\Contact::class => \App\Models\Contact::query()->find($subjectId),
            default => null,
        };
    }

    private function resolveUserOwner(array $payload): ?User
    {
        $id = (int) ($payload['user_id'] ?? 0);

        return $id > 0 ? User::query()->find($id) : null;
    }

    private function resolveTeamOwner(array $payload, int $currentOwnerId): ?User
    {
        $teamId = (int) ($payload['team_id'] ?? 0);

        if ($teamId > 0) {
            return Team::query()->whereKey($teamId)->where('is_active', true)->value('supervisor_id')
                ? User::query()->find(Team::query()->whereKey($teamId)->where('is_active', true)->value('supervisor_id'))
                : null;
        }

        // No team specified — keep the current owner.
        return User::query()->find($currentOwnerId);
    }

    private function resolveRoundRobin(array $payload, int $subjectId): ?User
    {
        $teamId = (int) ($payload['team_id'] ?? 0);

        if ($teamId <= 0) {
            return null;
        }

        $memberIds = Team::query()->whereKey($teamId)->where('is_active', true)
            ->with('members:id')
            ->get()
            ->flatMap(fn (Team $team) => $team->members->pluck('id'))
            ->unique()
            ->values()
            ->all();

        if (empty($memberIds)) {
            return null;
        }

        $index = abs($subjectId) % count($memberIds);

        return User::query()->find($memberIds[$index]);
    }
}