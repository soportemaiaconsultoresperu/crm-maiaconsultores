<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SupportTicketScopeService
{
    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->can('support.view.any')) {
            return $query;
        }

        if ($user->can('support.view.team')) {
            $teamIds = $this->visibleTeamIds($user);
            $memberIds = $this->visibleTeamMemberIds($teamIds, $user);

            return $query->where(function (Builder $scoped) use ($teamIds, $memberIds, $user): void {
                if ($teamIds !== []) {
                    $scoped->whereIn('team_id', $teamIds);
                }

                if ($memberIds !== []) {
                    $method = $teamIds === [] ? 'whereIn' : 'orWhereIn';
                    $scoped->{$method}('responsible_id', $memberIds);
                }

                $scoped->orWhere(function (Builder $ownDrafts) use ($user): void {
                    $ownDrafts->whereNull('responsible_id')->where('created_by', $user->id);
                });
            });
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('responsible_id', $user->id)
                ->orWhere(function (Builder $ownDrafts) use ($user): void {
                    $ownDrafts->whereNull('responsible_id')->where('created_by', $user->id);
                });
        });
    }

    public function canView(User $user, SupportTicket $ticket): bool
    {
        if ($user->can('support.view.any')) {
            return true;
        }

        if ($user->can('support.view.team')) {
            $teamIds = $this->visibleTeamIds($user);
            $memberIds = $this->visibleTeamMemberIds($teamIds, $user);

            return ($ticket->team_id !== null && in_array((int) $ticket->team_id, $teamIds, true))
                || ($ticket->responsible_id !== null && in_array((int) $ticket->responsible_id, $memberIds, true))
                || ($ticket->responsible_id === null && (int) $ticket->created_by === (int) $user->id);
        }

        return ((int) $ticket->responsible_id === (int) $user->id)
            || ($ticket->responsible_id === null && (int) $ticket->created_by === (int) $user->id);
    }

    /** @return list<int> */
    private function visibleTeamIds(User $user): array
    {
        return Team::query()
            ->where('supervisor_id', $user->id)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $teamIds
     * @return list<int>
     */
    private function visibleTeamMemberIds(array $teamIds, User $user): array
    {
        if ($teamIds === []) {
            return [(int) $user->id];
        }

        return Team::query()
            ->whereIn('id', $teamIds)
            ->with('members:id')
            ->get()
            ->flatMap(fn (Team $team) => $team->members->pluck('id'))
            ->push($user->id)
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
