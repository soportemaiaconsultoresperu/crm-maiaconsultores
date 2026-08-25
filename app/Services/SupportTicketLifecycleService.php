<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\SupportIncidentDetail;
use App\Models\SupportObservation;
use App\Models\SupportObservationHistory;
use App\Models\SupportResolutionCycle;
use App\Models\SupportReschedule;
use App\Models\SupportStatus;
use App\Models\SupportStatusPeriod;
use App\Models\SupportTicket;
use App\Models\SupportSessionDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketLifecycleService
{
    /** @param array<string,mixed> $activityData @param array<string,mixed> $sessionData */
    public function schedule(SupportTicket $ticket, array $activityData, array $sessionData, User $actor): Activity
    {
        $modality = $sessionData['modality'] ?? null;
        if (! in_array($modality, ['virtual', 'presential', 'phone', 'not_applicable'], true) || empty($activityData['scheduled_at']) || empty($activityData['type_id'])) {
            throw ValidationException::withMessages(['schedule' => 'Fecha, tipo de actividad y modalidad válida son obligatorios.']);
        }
            return DB::transaction(function () use ($ticket, $activityData, $sessionData, $actor): Activity {
                $activity = app(ActivityService::class)->create(array_merge($activityData, ['subject_type' => 'support_ticket', 'subject_id' => $ticket->id, 'owner_id' => $activityData['owner_id'] ?? $ticket->responsible_id ?? $actor->id]), $actor);
                SupportSessionDetail::query()->create(array_merge($sessionData, ['ticket_id' => $ticket->id, 'activity_id' => $activity->id]));

                $currentStatus = $ticket->status?->slug;
                if (in_array($currentStatus, [SupportStatus::SLUG_ASSIGNED, SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_WAITING_INTERNAL, SupportStatus::SLUG_REOPENED], true)) {
                    $this->transition($ticket, SupportStatus::SLUG_SCHEDULED, $actor);
                } elseif (! in_array($currentStatus, [SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_IN_PROGRESS], true)) {
                    throw new InvalidOperationException('Transición de soporte no permitida.');
                }

                return $activity;
            });
    }

    public function reschedule(Activity $activity, Carbon|string $newScheduledAt, string $reason, User $actor): Activity
    {
        $reason = trim($reason); if ($reason === '' || $activity->subject_type !== SupportTicket::class || in_array($activity->status, ['completed', 'cancelled'], true)) throw ValidationException::withMessages(['reason' => 'La reprogramación requiere motivo y actividad no terminal.']);
        return DB::transaction(function () use ($activity, $newScheduledAt, $reason, $actor): Activity {
            SupportReschedule::query()->create(['ticket_id' => $activity->subject_id, 'activity_id' => $activity->id, 'old_scheduled_at' => $activity->scheduled_at, 'new_scheduled_at' => $newScheduledAt, 'reason' => $reason, 'rescheduled_by' => $actor->id, 'responsible_id' => $activity->owner_id]);
            $activity->scheduled_at = $newScheduledAt; $activity->updated_by = $actor->id; $activity->save();
            return $activity->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function saveIncident(SupportTicket $ticket, array $data): SupportIncidentDetail
    {
        return SupportIncidentDetail::query()->updateOrCreate(['ticket_id' => $ticket->id], $data);
    }

    /** @param array<string,mixed> $data */
    public function createObservation(SupportTicket $ticket, array $data, User $actor): SupportObservation
    {
        if (trim((string) ($data['title'] ?? '')) === '') throw ValidationException::withMessages(['title' => 'El título es obligatorio.']);
        return DB::transaction(function () use ($ticket, $data, $actor): SupportObservation {
            $observation = SupportObservation::query()->create(array_merge($data, ['ticket_id' => $ticket->id, 'state' => 'pending', 'created_by' => $actor->id, 'raised_at' => $data['raised_at'] ?? now()]));
            $this->observationHistory($observation, null, 'pending', null, $actor); return $observation;
        });
    }

    public function transitionObservation(SupportObservation $observation, string $state, User $actor, ?string $reason = null): SupportObservation
    {
        if (! in_array($state, SupportObservation::STATES, true)) throw ValidationException::withMessages(['state' => 'Estado de observación inválido.']);
        $reason = trim((string) $reason);
        if (in_array($state, ['rejected', 'reopened', 'not_applicable'], true) && $reason === '') throw ValidationException::withMessages(['reason' => 'Este cambio exige motivo.']);
        return DB::transaction(function () use ($observation, $state, $actor, $reason): SupportObservation {
            $from = $observation->state; $observation->state = $state; $observation->reason = $reason ?: $observation->reason;
            if ($state === 'lifted') {$observation->lifted_at = now(); $observation->lifted_by = $actor->id;}
            if ($state === 'validated') {$observation->validated_at = now(); $observation->validated_by = $actor->id;}
            $observation->save(); $this->observationHistory($observation, $from, $state, $reason ?: null, $actor); return $observation->refresh();
        });
    }

    public function transition(SupportTicket $ticket, string $to, User $actor, ?string $reason = null, bool $closePendingException = false): SupportTicket
    {
        $from = $ticket->status?->slug;
        $allowed = ['nuevo'=>['asignado','cancelado'], 'asignado'=>['programado','en-atencion','en-espera-del-cliente','en-espera-interna','cancelado'], 'programado'=>['en-atencion','en-espera-del-cliente','en-espera-interna','cancelado'], 'en-atencion'=>['en-espera-del-cliente','en-espera-interna','resuelto','cancelado'], 'en-espera-del-cliente'=>['en-atencion','programado','cancelado'], 'en-espera-interna'=>['en-atencion','programado','cancelado'], 'resuelto'=>['cerrado','reabierto'], 'cerrado'=>['reabierto'], 'reabierto'=>['asignado','programado','en-atencion','cancelado']];
        if (! in_array($to, $allowed[$from] ?? [], true)) throw new InvalidOperationException('Transición de soporte no permitida.');
        $reason = trim((string) $reason);
        if (in_array($to, ['cancelado','reabierto'], true) && $reason === '') throw ValidationException::withMessages(['reason' => 'El motivo es obligatorio.']);
        if ($to === 'resuelto' && trim((string) $ticket->solution_summary) === '') throw ValidationException::withMessages(['solution_summary' => 'Resolver requiere resumen de solución.']);
        if ($to === 'cerrado' && $reason === '') throw ValidationException::withMessages(['reason' => 'Cerrar requiere validación o motivo.']);
        if ($to === 'cerrado' && $ticket->observations()->whereNotIn('state', ['validated','rejected','not_applicable'])->exists() && ! $closePendingException) throw new InvalidOperationException('No se puede cerrar con observaciones pendientes.');
        if ($to === 'cerrado' && $closePendingException && ! $actor->can('support.close.with-pending-observations')) throw new InvalidOperationException('No tiene permiso para cerrar con observaciones pendientes.');
        if ($to === 'cerrado' && $closePendingException && $reason === '') throw ValidationException::withMessages(['reason' => 'La excepción de cierre requiere motivo.']);
        return DB::transaction(function () use ($ticket, $to, $actor, $reason): SupportTicket {
            $cycle = $this->currentCycle($ticket, $to === 'reabierto');
            SupportStatusPeriod::query()->where('ticket_id', $ticket->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $ticket->status_id = SupportStatus::query()->where('slug', $to)->value('id'); $ticket->updated_by = $actor->id;
            if ($to === 'en-atencion' && $ticket->work_started_at === null) $ticket->work_started_at = now();
            if ($to === 'resuelto') {$ticket->resolved_at = now(); $cycle->ended_at = now(); $cycle->save();}
            if ($to === 'cerrado') {$ticket->closed_at = now(); $ticket->close_reason = $reason;}
            if ($to === 'reabierto') $ticket->reopen_reason = $reason;
            if ($to === 'cancelado') {$ticket->cancel_reason = $reason; $cycle->ended_at = now(); $cycle->save();}
            $ticket->save();
            SupportStatusPeriod::query()->create(['ticket_id'=>$ticket->id,'cycle_id'=>$cycle->id,'status_id'=>$ticket->status_id,'period_type'=>$to,'pauses_clock'=>$to === SupportStatus::SLUG_WAITING_CUSTOMER,'started_at'=>now()]);
            return $ticket->refresh();
        });
    }

    public function effectiveSeconds(SupportTicket $ticket): int
    {
        $end = $ticket->resolved_at ?? $ticket->closed_at ?? now(); $total = max(0, $end->diffInSeconds($ticket->created_at));
        $paused = $ticket->statusPeriods()->where('pauses_clock', true)->get()->sum(fn ($p) => ($p->ended_at ?? $end)->diffInSeconds($p->started_at));
        return max(0, $total - $paused);
    }

    private function currentCycle(SupportTicket $ticket, bool $new = false): SupportResolutionCycle
    {
        $cycle = $new ? null : $ticket->cycles()->whereNull('ended_at')->latest('sequence')->first();
        return $cycle ?? SupportResolutionCycle::query()->create(['ticket_id'=>$ticket->id,'sequence'=>(int) $ticket->cycles()->max('sequence') + 1,'started_at'=>now()]);
    }
    private function observationHistory(SupportObservation $o, ?string $from, string $to, ?string $reason, User $actor): void { SupportObservationHistory::query()->create(['observation_id'=>$o->id,'from_state'=>$from,'to_state'=>$to,'reason'=>$reason,'actor_id'=>$actor->id]); }
}
