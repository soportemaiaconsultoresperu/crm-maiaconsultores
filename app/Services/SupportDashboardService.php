<?php

namespace App\Services;

use App\Models\SupportCategory;
use App\Models\SupportObservation;
use App\Models\SupportPriority;
use App\Models\SupportResolutionCycle;
use App\Models\SupportStatus;
use App\Models\SupportStatusPeriod;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Support KPIs (docs/soporte-tecnico-diseno.md §11–§12), always computed from
 * persisted timestamps, cycles and status periods inside the actor's scope.
 */
class SupportDashboardService
{
    public function __construct(private readonly SupportTicketScopeService $scope) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function metrics(array $filters = [], ?User $user = null): array
    {
        $ticketIds = fn () => $this->query($filters, $user)->select('support_tickets.id');
        $timestamps = $this->query($filters, $user)->get(['id', 'created_at', 'first_responded_at', 'resolved_at', 'closed_at']);
        $resolvedStatusId = SupportStatus::query()->where('slug', SupportStatus::SLUG_RESOLVED)->value('id');
        $cancelledStatusId = SupportStatus::query()->where('slug', SupportStatus::SLUG_CANCELLED)->value('id');
        $pausedPeriods = $this->pausedPeriods($ticketIds());

        $cycleOutcomes = Schema::hasColumn('support_resolution_cycles', 'resolved_at');
        $everResolved = $this->query($filters, $user)->where(function (Builder $q) use ($resolvedStatusId, $cycleOutcomes): void {
            $q->whereNotNull('resolved_at')->orWhereHas('statusPeriods', fn (Builder $periods) => $periods->where('status_id', $resolvedStatusId));
            if ($cycleOutcomes) {
                $q->orWhereHas('cycles', fn (Builder $cycles) => $cycles->whereNotNull('resolved_at'));
            }
        });
        $everResolvedCount = (clone $everResolved)->count();
        $reopenedCount = (clone $everResolved)->has('cycles', '>', 1)->count();

        // Resolved cycles: per-cycle resolved_at when stored, otherwise ended and not closed by a cancellation.
        $cycles = SupportResolutionCycle::query()->whereIn('ticket_id', $ticketIds())
            ->where(function (Builder $q) use ($cancelledStatusId, $cycleOutcomes): void {
                $q->where(fn (Builder $ended) => $ended->whereNotNull('ended_at')
                    ->whereDoesntHave('periods', fn (Builder $periods) => $periods->where('status_id', $cancelledStatusId)));
                if ($cycleOutcomes) {
                    $q->orWhereNotNull('resolved_at');
                }
            })
            ->get(array_merge(['id', 'ticket_id', 'started_at', 'ended_at'], $cycleOutcomes ? ['resolved_at'] : []));
        $cycleEnd = fn ($c) => $cycleOutcomes && $c->resolved_at !== null ? Carbon::parse($c->resolved_at) : $c->ended_at;
        $cycleSeconds = $cycles->map(fn ($c) => $this->seconds($c->started_at, $cycleEnd($c)));
        $cycleEffective = $cycles->map(fn ($c) => max(0, $this->seconds($c->started_at, $cycleEnd($c))
            - $this->pausedWithin($pausedPeriods->get($c->ticket_id, collect()), $c->started_at, $cycleEnd($c))));

        $resolved = $timestamps->filter(fn ($t) => $t->resolved_at !== null);
        $effective = $resolved->map(fn ($t) => max(0, $this->seconds($t->created_at, $t->resolved_at)
            - $this->pausedWithin($pausedPeriods->get($t->id, collect()), $t->created_at, $t->resolved_at)));
        $observations = SupportObservation::query()->whereIn('ticket_id', $ticketIds());

        return [
            'total' => $timestamps->count(),
            'by_state' => $this->countBy($filters, $user, 'status_id', SupportStatus::query()->pluck('name', 'id')),
            'by_priority' => $this->countBy($filters, $user, 'priority_id', SupportPriority::query()->pluck('name', 'id')),
            'by_type' => $this->countBy($filters, $user, 'type_id', SupportTicketType::query()->pluck('name', 'id')),
            'by_category' => $this->countBy($filters, $user, 'category_id', SupportCategory::query()->pluck('name', 'id')),
            'by_responsible' => $this->countBy($filters, $user, 'responsible_id', User::query()->whereIn('id', $this->query($filters, $user)->select('responsible_id'))->pluck('name', 'id')),
            'observations' => [
                'pending' => (clone $observations)->whereIn('state', ['pending', 'in_process', 'rejected', 'reopened'])->count(),
                'lifted' => (clone $observations)->where('state', 'lifted')->count(),
                'validated' => (clone $observations)->where('state', 'validated')->count(),
            ],
            'counts' => [
                'first_response' => $timestamps->whereNotNull('first_responded_at')->count(),
                'resolved' => $resolved->count(),
                'closed' => $timestamps->whereNotNull('closed_at')->count(),
                'ever_resolved' => $everResolvedCount,
                'reopened' => $reopenedCount,
                'cycles' => $cycles->count(),
            ],
            'average_first_response_seconds' => $this->averageSince($timestamps, 'first_responded_at'),
            'average_resolution_seconds' => $this->averageSince($timestamps, 'resolved_at'),
            'average_closure_seconds' => $this->averageSince($timestamps, 'closed_at'),
            'average_effective_resolution_seconds' => $this->average($effective),
            'average_cycle_seconds' => $this->average($cycleSeconds),
            'average_cycle_effective_seconds' => $this->average($cycleEffective),
            'reopened_percent' => $everResolvedCount === 0 ? 0.0 : round(min($reopenedCount, $everResolvedCount) * 100 / $everResolvedCount, 2),
            // Target times are not configurable yet (S11): no denominator to report.
            'resolved_within_target_percent' => null,
        ];
    }

    /** @param array<string,mixed> $filters @return Collection<int,SupportTicket> */
    public function tickets(array $filters = [], ?User $user = null): Collection
    {
        return $this->query($filters, $user)->with(['status:id,name,slug', 'customer:id,code', 'responsible:id,name', 'type:id,name', 'category:id,name', 'priority:id,name', 'sessionDetails:id,ticket_id,modality'])->orderBy('id')->get();
    }

    /** @param array<string,mixed> $filters @return Builder<SupportTicket> */
    private function query(array $filters, ?User $user): Builder
    {
        $query = SupportTicket::query();
        if ($user !== null) {
            $this->scope->apply($query, $user);
        }
        foreach (['customer_id', 'responsible_id', 'team_id', 'type_id', 'category_id', 'priority_id', 'status_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['modality'])) {
            $query->whereHas('sessionDetails', fn (Builder $sessions) => $sessions->where('modality', $filters['modality']));
        }

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  Collection<int|string,string>  $names
     * @return array<string,int>
     */
    private function countBy(array $filters, ?User $user, string $column, Collection $names): array
    {
        return $this->query($filters, $user)->toBase()->reorder()->select($column)->selectRaw('count(*) as aggregate')->groupBy($column)->get()
            ->mapWithKeys(fn ($row) => [($row->{$column} === null ? 'Sin asignar' : ($names[$row->{$column}] ?? 'Sin asignar')) => (int) $row->aggregate])
            ->sortDesc()->all();
    }

    /**
     * Periods that stop the effective clock: flagged at transition time, or a
     * customer-waiting status (§11: internal waiting does not pause the clock).
     *
     * @return Collection<int,Collection<int,SupportStatusPeriod>>
     */
    private function pausedPeriods(Builder $ticketIds): Collection
    {
        $waitingCustomerId = SupportStatus::query()->where('slug', SupportStatus::SLUG_WAITING_CUSTOMER)->value('id');

        return SupportStatusPeriod::query()->whereIn('ticket_id', $ticketIds)
            ->where(fn (Builder $q) => $q->where('pauses_clock', true)->orWhere('status_id', $waitingCustomerId))
            ->get(['ticket_id', 'started_at', 'ended_at'])->groupBy('ticket_id');
    }

    /** @param Collection<int,SupportStatusPeriod> $periods */
    private function pausedWithin(Collection $periods, CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $periods->sum(function ($period) use ($from, $to): int {
            $start = max($period->started_at->getTimestamp(), $from->getTimestamp());
            $end = min(($period->ended_at ?? $to)->getTimestamp(), $to->getTimestamp());

            return max(0, $end - $start);
        });
    }

    private function averageSince(Collection $tickets, string $column): float
    {
        return $this->average($tickets->filter(fn ($t) => $t->{$column} !== null && $t->created_at !== null)
            ->map(fn ($t) => $this->seconds($t->created_at, $t->{$column})));
    }

    private function average(Collection $values): float
    {
        return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 2);
    }

    /** Elapsed seconds from $from to $to, never negative (Carbon 3 diffs are signed). */
    private function seconds(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }
}
