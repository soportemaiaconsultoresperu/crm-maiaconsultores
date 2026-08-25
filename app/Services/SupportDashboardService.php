<?php

namespace App\Services;

use App\Models\SupportObservation;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SupportDashboardService
{
    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function metrics(array $filters = []): array
    {
        $tickets = $this->query($filters)->withCount('cycles')->with(['status:id,slug','priority:id,slug','type:id,slug','category:id,slug','responsible:id,name','customer:id,code'])->get();
        $countBy = fn (string $relation) => $tickets->groupBy(fn ($ticket) => $ticket->{$relation}?->slug ?? $ticket->{$relation}?->name ?? 'unassigned')->map->count()->all();
        $average = fn (string $column) => $this->averageSeconds($tickets, $column);
        $terminal = $tickets->filter(fn ($ticket) => in_array($ticket->status?->slug, ['resuelto','cerrado'], true));
        $observationQuery = SupportObservation::query()->whereIn('ticket_id', $tickets->pluck('id'));
        return ['total'=>$tickets->count(), 'by_state'=>$countBy('status'), 'by_priority'=>$countBy('priority'), 'by_type'=>$countBy('type'), 'by_category'=>$countBy('category'), 'by_responsible'=>$tickets->groupBy(fn($t)=>$t->responsible?->name ?? 'unassigned')->map->count()->all(), 'by_client'=>$tickets->groupBy(fn($t)=>$t->customer?->code ?? 'unknown')->map->count()->all(), 'observations'=>['pending'=>(clone $observationQuery)->whereIn('state',['pending','in_process','lifted','reopened'])->count(),'lifted'=>(clone $observationQuery)->where('state','lifted')->count(),'validated'=>(clone $observationQuery)->where('state','validated')->count()], 'average_first_response_seconds'=>$average('first_responded_at'), 'average_resolution_seconds'=>$average('resolved_at'), 'average_closure_seconds'=>$average('closed_at'), 'reopened_percent'=>$terminal->isEmpty() ? 0.0 : round($tickets->filter(fn($t)=>$t->cycles_count > 1)->count() * 100 / $terminal->count(),2), 'resolved_within_target_percent'=>null];
    }

    /** @param array<string,mixed> $filters @return Collection<int,SupportTicket> */
    public function tickets(array $filters = []): Collection { return $this->query($filters)->with(['status:id,name,slug','customer:id,code','responsible:id,name','type:id,name','category:id,name','priority:id,name','sessionDetails:id,ticket_id,modality'])->get(); }
    /** @param array<string,mixed> $filters @return Builder<SupportTicket> */
    private function query(array $filters): Builder
    {
        $query=SupportTicket::query();
        foreach (['customer_id','responsible_id','team_id','type_id','category_id','priority_id','status_id'] as $field) if (!empty($filters[$field])) $query->where($field,(int)$filters[$field]);
        if (!empty($filters['from'])) $query->whereDate('created_at','>=',$filters['from']);
        if (!empty($filters['to'])) $query->whereDate('created_at','<=',$filters['to']);
        if (!empty($filters['modality'])) $query->whereHas('sessionDetails',fn(Builder $sessions)=>$sessions->where('modality',$filters['modality']));
        return $query;
    }
    private function averageSeconds(Collection $tickets, string $column): float { $values=$tickets->filter(fn($t)=>$t->{$column} !== null)->map(fn($t)=>$t->{$column}->diffInSeconds($t->created_at)); return $values->isEmpty()?0.0:round((float)$values->avg(),2); }
}
