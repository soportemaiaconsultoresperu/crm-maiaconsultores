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
use App\Models\SupportSessionParticipant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketLifecycleService
{
    /** Ticket state machine, design §7. */
    public const TRANSITIONS = [
        SupportStatus::SLUG_NEW => [SupportStatus::SLUG_ASSIGNED, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_ASSIGNED => [SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_WAITING_INTERNAL, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_SCHEDULED => [SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_WAITING_INTERNAL, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_IN_PROGRESS => [SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_WAITING_INTERNAL, SupportStatus::SLUG_RESOLVED, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_WAITING_CUSTOMER => [SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_WAITING_INTERNAL => [SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_CANCELLED],
        SupportStatus::SLUG_RESOLVED => [SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_REOPENED],
        SupportStatus::SLUG_CLOSED => [SupportStatus::SLUG_REOPENED],
        SupportStatus::SLUG_REOPENED => [SupportStatus::SLUG_ASSIGNED, SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_IN_PROGRESS, SupportStatus::SLUG_CANCELLED],
    ];

    /** Session modalities and their Spanish labels (schedule form, session card, CSV). */
    public const MODALITIES = ['virtual' => 'Virtual', 'presential' => 'Presencial', 'phone' => 'Teléfono', 'not_applicable' => 'No aplica'];

    /** Waiting kinds accepted by putOnHold(). */
    public const HOLD_STATES = ['cliente' => SupportStatus::SLUG_WAITING_CUSTOMER, 'interna' => SupportStatus::SLUG_WAITING_INTERNAL];

    /** @return list<string> */
    public static function allowedTransitions(?string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /** @param array<string,mixed> $activityData @param array<string,mixed> $sessionData */
    public function schedule(SupportTicket $ticket, array $activityData, array $sessionData, User $actor): Activity
    {
        $modality = $sessionData['modality'] ?? null;
        if (! array_key_exists((string) $modality, self::MODALITIES) || empty($activityData['scheduled_at']) || empty($activityData['type_id'])) {
            throw ValidationException::withMessages(['schedule' => 'Fecha, tipo de actividad y modalidad válida son obligatorios.']);
        }
            return DB::transaction(function () use ($ticket, $activityData, $sessionData, $actor): Activity {
                $ticket = $this->lockTicket($ticket);
                $currentStatus = $ticket->status?->slug;
                $moves = in_array($currentStatus, [SupportStatus::SLUG_ASSIGNED, SupportStatus::SLUG_WAITING_CUSTOMER, SupportStatus::SLUG_WAITING_INTERNAL, SupportStatus::SLUG_REOPENED], true);
                if (! $moves && ! in_array($currentStatus, [SupportStatus::SLUG_SCHEDULED, SupportStatus::SLUG_IN_PROGRESS], true)) {
                    throw new InvalidOperationException('Transición de soporte no permitida.');
                }

                $activity = app(ActivityService::class)->create(array_merge($activityData, ['subject_type' => 'support_ticket', 'subject_id' => $ticket->id, 'owner_id' => $activityData['owner_id'] ?? $ticket->responsible_id ?? $actor->id]), $actor);
                SupportSessionDetail::query()->create(array_merge($sessionData, ['ticket_id' => $ticket->id, 'activity_id' => $activity->id]));
                if ($moves) $this->applyTransition($ticket, SupportStatus::SLUG_SCHEDULED, $actor, '');

                return $activity;
            });
    }

    public function reschedule(Activity $activity, Carbon|string $newScheduledAt, string $reason, User $actor): Activity
    {
        $reason = trim($reason); if ($reason === '' || $activity->subject_type !== SupportTicket::class || in_array($activity->status, ['completed', 'cancelled'], true)) throw ValidationException::withMessages(['reason' => 'La reprogramación requiere motivo y actividad no terminal.']);
        return DB::transaction(function () use ($activity, $newScheduledAt, $reason, $actor): Activity {
            $this->assertNotFrozen($this->lockTicket(SupportTicket::query()->findOrFail($activity->subject_id)), 'No se puede reprogramar la atención de un ticket cerrado o cancelado.');
            SupportReschedule::query()->create(['ticket_id' => $activity->subject_id, 'activity_id' => $activity->id, 'old_scheduled_at' => $activity->scheduled_at, 'new_scheduled_at' => $newScheduledAt, 'reason' => $reason, 'rescheduled_by' => $actor->id, 'responsible_id' => $activity->owner_id]);
            $activity->scheduled_at = $newScheduledAt; $activity->updated_by = $actor->id; $activity->save();
            return $activity->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function saveIncident(SupportTicket $ticket, array $data): SupportIncidentDetail
    {
        return DB::transaction(function () use ($ticket, $data): SupportIncidentDetail {
            $this->assertNotFrozen($this->lockTicket($ticket), 'No se puede modificar el incidente de un ticket cerrado o cancelado.');
            return SupportIncidentDetail::query()->updateOrCreate(['ticket_id' => $ticket->id], $data);
        });
    }

    /** @param array<string,mixed> $data */
    public function addParticipant(SupportSessionDetail $session, array $data): SupportSessionParticipant
    {
        return DB::transaction(function () use ($session, $data): SupportSessionParticipant {
            $this->assertNotFrozen($this->lockTicket($session->ticket), 'No se pueden registrar participantes en un ticket cerrado o cancelado.');
            return $session->participants()->create($data);
        });
    }

    /** @param array<string,mixed> $data */
    public function createObservation(SupportTicket $ticket, array $data, User $actor): SupportObservation
    {
        if (trim((string) ($data['title'] ?? '')) === '') throw ValidationException::withMessages(['title' => 'El título es obligatorio.']);
        return DB::transaction(function () use ($ticket, $data, $actor): SupportObservation {
            $this->assertNotFrozen($this->lockTicket($ticket), 'No se pueden crear observaciones en un ticket cerrado o cancelado.');
            $observation = SupportObservation::query()->create(array_merge($data, ['ticket_id' => $ticket->id, 'state' => 'pending', 'created_by' => $actor->id, 'raised_at' => $data['raised_at'] ?? now()]));
            $this->observationHistory($observation, null, 'pending', null, $actor); return $observation;
        });
    }

    /** Moves an observation along SupportObservation::TRANSITIONS; the actor needs the target state's permission. */
    public function transitionObservation(SupportObservation $observation, string $state, User $actor, ?string $reason = null): SupportObservation
    {
        if (! in_array($state, SupportObservation::STATES, true)) throw ValidationException::withMessages(['state' => 'Estado de observación inválido.']);
        $permission = SupportObservation::TARGET_PERMISSIONS[$state] ?? null;
        if ($permission === null) throw new InvalidOperationException('Transición de observación no permitida.');
        if (! $actor->can($permission)) throw new AuthorizationException('No tiene permiso para marcar la observación como «'.SupportObservation::LABELS[$state].'».');
        $reason = trim((string) $reason);
        if (in_array($state, ['rejected', 'reopened', 'not_applicable'], true) && $reason === '') throw ValidationException::withMessages(['reason' => 'Este cambio exige motivo.']);
        return DB::transaction(function () use ($observation, $state, $actor, $reason): SupportObservation {
            $observation = SupportObservation::query()->whereKey($observation->id)->lockForUpdate()->firstOrFail();
            $from = $observation->state;
            if ($from === $state) return $observation;
            if (! in_array($state, $observation->allowedTransitions(), true)) {
                throw new InvalidOperationException('Transición de observación no permitida: de «'.(SupportObservation::LABELS[$from] ?? $from).'» a «'.SupportObservation::LABELS[$state].'».');
            }
            $observation->state = $state; $observation->reason = $reason ?: $observation->reason;
            if ($state === 'lifted') {$observation->lifted_at = now(); $observation->lifted_by = $actor->id;}
            if ($state === 'validated') {$observation->validated_at = now(); $observation->validated_by = $actor->id;}
            if ($state === 'reopened') {$observation->validated_at = null; $observation->validated_by = null;}
            $observation->save(); $this->observationHistory($observation, $from, $state, $reason ?: null, $actor); return $observation->refresh();
        });
    }

    /**
     * Runs a state change under a row lock. Asking for the state the ticket is
     * already in is a no-op, so a double submit never duplicates periods/cycles.
     */
    public function transition(SupportTicket $ticket, string $to, User $actor, ?string $reason = null, bool $closePendingException = false, bool $customerValidated = false): SupportTicket
    {
        $reason = trim((string) $reason);
        if (in_array($to, [SupportStatus::SLUG_CANCELLED, SupportStatus::SLUG_REOPENED], true) && $reason === '') throw ValidationException::withMessages(['reason' => 'El motivo es obligatorio.']);
        if ($to === SupportStatus::SLUG_CLOSED && $reason === '') throw ValidationException::withMessages(['reason' => 'Cerrar requiere validación o motivo.']);

        return DB::transaction(fn (): SupportTicket => $this->applyTransition($this->lockTicket($ticket), $to, $actor, $reason, $closePendingException, $customerValidated));
    }

    /** Saves the solution summary and resolves in one transaction; nothing persists if the move is refused. */
    public function resolve(SupportTicket $ticket, string $solutionSummary, User $actor): SupportTicket
    {
        $solutionSummary = trim($solutionSummary);
        if ($solutionSummary === '') throw ValidationException::withMessages(['solution_summary' => 'Resolver requiere resumen de solución.']);

        return DB::transaction(function () use ($ticket, $solutionSummary, $actor): SupportTicket {
            $ticket = $this->lockTicket($ticket);
            if ($ticket->status?->slug === SupportStatus::SLUG_RESOLVED) return $ticket;
            $ticket->solution_summary = $solutionSummary;
            return $this->applyTransition($ticket, SupportStatus::SLUG_RESOLVED, $actor, '');
        });
    }

    /** @param string $kind cliente|interna — only "cliente" pauses the effective clock (design §11). */
    public function putOnHold(SupportTicket $ticket, string $kind, User $actor, ?string $reason = null): SupportTicket
    {
        $to = self::HOLD_STATES[$kind] ?? null;
        if ($to === null) throw ValidationException::withMessages(['kind' => 'Tipo de espera inválido: use cliente o interna.']);
        $this->authorize($actor, 'support.attention.start', 'No tiene permiso para poner el ticket en espera.');

        return $this->transition($ticket, $to, $actor, $reason);
    }

    public function resumeFromHold(SupportTicket $ticket, User $actor): SupportTicket
    {
        $this->authorize($actor, 'support.attention.start', 'No tiene permiso para reanudar la atención.');

        return DB::transaction(function () use ($ticket, $actor): SupportTicket {
            $ticket = $this->lockTicket($ticket);
            $from = $ticket->status?->slug;
            if ($from === SupportStatus::SLUG_IN_PROGRESS) return $ticket;
            if (! in_array($from, self::HOLD_STATES, true)) throw new InvalidOperationException('El ticket no está en espera.');
            return $this->applyTransition($ticket, SupportStatus::SLUG_IN_PROGRESS, $actor, '');
        });
    }

    public function lockTicket(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }


    /** Must run inside a transaction on a ticket obtained through lockTicket(). */
    private function applyTransition(SupportTicket $ticket, string $to, User $actor, string $reason, bool $closePendingException = false, bool $customerValidated = false): SupportTicket
    {
        $from = $ticket->status?->slug;
        if ($from === $to) return $ticket->refresh();
        if (! in_array($to, self::allowedTransitions($from), true)) throw new InvalidOperationException('Transición de soporte no permitida.');
        if ($to === SupportStatus::SLUG_RESOLVED && trim((string) $ticket->solution_summary) === '') throw ValidationException::withMessages(['solution_summary' => 'Resolver requiere resumen de solución.']);
        if ($to === SupportStatus::SLUG_RESOLVED && $ticket->observations()->whereIn('state', SupportObservation::UNATTENDED_STATES)->exists()) {
            throw new InvalidOperationException('No se puede resolver con observaciones pendientes: levántelas o márquelas como no aplicables.');
        }
        if ($to === SupportStatus::SLUG_CLOSED) {
            if ($closePendingException && ! $actor->can('support.close.with-pending-observations')) throw new InvalidOperationException('No tiene permiso para cerrar con observaciones pendientes.');
            if (! $closePendingException && $ticket->observations()->whereNotIn('state', SupportObservation::FINISHED_STATES)->exists()) throw new InvalidOperationException('No se puede cerrar con observaciones pendientes.');
        }

        $now = now();
        $cycle = $this->cycleFor($ticket, $to);
        SupportStatusPeriod::query()->where('ticket_id', $ticket->id)->whereNull('ended_at')->update(['ended_at' => $now]);
        $ticket->status_id = SupportStatus::query()->where('slug', $to)->value('id'); $ticket->updated_by = $actor->id;
        if ($to === SupportStatus::SLUG_IN_PROGRESS && $ticket->work_started_at === null) $ticket->work_started_at = $now;
        if ($to === SupportStatus::SLUG_RESOLVED) {
            $ticket->resolved_at = $now;
            $cycle->fill(['ended_at' => $now, 'resolved_at' => $now, 'resolved_by' => $actor->id, 'solution_summary' => $ticket->solution_summary]);
        }
        if ($to === SupportStatus::SLUG_CLOSED) {
            $ticket->closed_at = $now; $ticket->close_reason = $reason; $cycle->closed_at = $now; $cycle->ended_at ??= $now;
            if ($customerValidated) {$ticket->validated_at = $now; $cycle->validated_at = $now;}
        }
        // The ticket-level outcome describes the current cycle only; finished cycles keep their own times.
        if ($to === SupportStatus::SLUG_REOPENED) {$ticket->reopen_reason = $reason; $ticket->resolved_at = null; $ticket->closed_at = null; $ticket->validated_at = null;}
        if ($to === SupportStatus::SLUG_CANCELLED) {$ticket->cancel_reason = $reason; $cycle->ended_at ??= $now;}
        $cycle->save();
        $ticket->save();
        SupportStatusPeriod::query()->create(['ticket_id'=>$ticket->id,'cycle_id'=>$cycle->id,'status_id'=>$ticket->status_id,'period_type'=>$to,'pauses_clock'=>$to === SupportStatus::SLUG_WAITING_CUSTOMER,'started_at'=>$now]);

        activity()->performedOn($ticket)->causedBy($actor)->event('support-ticket-status-changed')
            ->withProperties(['ticket_id' => $ticket->id, 'code' => $ticket->code, 'old_status_slug' => $from, 'new_status_slug' => $to, 'reason' => $reason !== '' ? $reason : null, 'close_pending_exception' => $to === SupportStatus::SLUG_CLOSED && $closePendingException])
            ->log('Estado del ticket de soporte actualizado');

        return $ticket->refresh();
    }

    /** Reopen starts a new cycle; close attaches to the cycle that was resolved; anything else uses the open cycle. */
    private function cycleFor(SupportTicket $ticket, string $to): SupportResolutionCycle
    {
        $cycle = match (true) {
            $to === SupportStatus::SLUG_REOPENED => null,
            $to === SupportStatus::SLUG_CLOSED => $ticket->cycles()->latest('sequence')->first(),
            default => $ticket->cycles()->whereNull('ended_at')->latest('sequence')->first(),
        };
        return $cycle ?? SupportResolutionCycle::query()->create(['ticket_id'=>$ticket->id,'sequence'=>(int) $ticket->cycles()->max('sequence') + 1,'started_at'=>now()]);
    }

    private function authorize(User $actor, string $permission, string $message): void
    {
        if (! $actor->can($permission)) throw new AuthorizationException($message);
    }

    /** Closed and cancelled tickets are read-only for scheduling, incidents, observations and attendance. */
    private function assertNotFrozen(SupportTicket $ticket, string $message): void
    {
        if (in_array($ticket->status?->slug, [SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_CANCELLED], true)) throw new InvalidOperationException($message);
    }

    private function observationHistory(SupportObservation $o, ?string $from, string $to, ?string $reason, User $actor): void { SupportObservationHistory::query()->create(['observation_id'=>$o->id,'from_state'=>$from,'to_state'=>$to,'reason'=>$reason,'actor_id'=>$actor->id]); }
}
