<?php

namespace App\Services;

use App\Events\V2\ActivityCompleted;
use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\ActivityAssigned;
use App\Support\DateRange;
use App\Traits\NotifiesOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Activities business logic (B05 / RF-ACT-001..008, RF-CAL-001..003,
 * ADR-006, ADR-012).
 *
 * - Activities are the single source of truth for next actions; they are
 *   never physically deleted (RNF-DAT-001, ADR-012).
 * - Status transitions are guarded: completed/cancelled are terminal,
 *   update is rejected on those states (InvalidOperationException).
 * - "Complete with next" creates a follow-up activity in the same
 *   transaction (RF-ACT-005, ADR-012).
 * - Data scope for the list / calendar queries is applied via
 *   DataScopeService on `activity.owner_id`. The subject's owner is
 *   ignored: an activity's responsibility is whoever owns the row, not
 *   whoever owns the subject — this matches every other module in the
 *   system and is consistent with ActivityPolicy.
 * - Notifications go through the database channel only (RF-NOT-001);
 *   `NotifiesOwner` suppresses self-noise.
 */
class ActivityService
{
    use NotifiesOwner;

    public function __construct(
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Create an activity in a single transaction with audit + (optional)
     * ActivityAssigned notification.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Activity
    {
        $this->assertSubjectExists($data);
        $this->assertActiveType($data['type_id'] ?? null);

        return DB::transaction(function () use ($data, $actor): Activity {
            $ownerId = (int) ($data['owner_id'] ?? $actor->id);

            $payload = [
                'type_id' => (int) $data['type_id'],
                'subject_type' => Activity::morphClass($this->subjectKey($data)),
                'subject_id' => (int) $data['subject_id'],
                'owner_id' => $ownerId,
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? now(),
                'executed_at' => null,
                'result' => null,
                'status' => $data['status'] ?? 'pending',
                'priority' => $data['priority'] ?? 'media',
                'reminder_at' => $data['reminder_at'] ?? null,
            ];

            $activity = new Activity($payload);
            $activity->created_by = $actor->id;
            $activity->updated_by = $actor->id;
            $activity->save();

            activity()
                ->performedOn($activity)
                ->causedBy($actor)
                ->event('activity-created')
                ->withProperties([
                    'subject_type' => $payload['subject_type'],
                    'subject_id' => $payload['subject_id'],
                    'type_id' => $payload['type_id'],
                ])
                ->log("Actividad \"{$activity->title}\" creada");

            $owner = $activity->owner()->first();
            if ($owner !== null) {
                $subjectLabel = $this->subjectLabel($activity);
                self::notifyOwnerUnlessActor(
                    $owner,
                    $actor,
                    new ActivityAssigned(
                        $activity->title,
                        $subjectLabel,
                        $actor->name,
                        ActivityAssigned::dueInHuman($activity->scheduled_at),
                    ),
                );
            }

            return $activity->refresh();
        });
    }

    /**
     * Update an activity. Rejected when the activity is in a terminal state
     * (completed / cancelled). Reminder noise is only emitted when the
     * owner actually changes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidOperationException
     */
    public function update(Activity $activity, array $data, User $actor): Activity
    {
        if (in_array($activity->status, ['completed', 'cancelled'], true)) {
            throw new InvalidOperationException(
                "La actividad \"{$activity->title}\" está en estado {$activity->status} y no admite cambios."
            );
        }

        if (array_key_exists('type_id', $data) && $data['type_id'] !== null) {
            $this->assertActiveType($data['type_id']);
        }

        $ownerChanged = array_key_exists('owner_id', $data)
            && (int) $data['owner_id'] !== (int) $activity->owner_id;

        DB::transaction(function () use ($activity, $data, $actor): void {
            unset($data['code'], $data['created_by'], $data['updated_by']);

            $activity->fill($data);
            $activity->updated_by = $actor->id;
            $activity->save();
        });

        $activity->refresh();

        if ($ownerChanged) {
            $owner = $activity->owner()->first();
            if ($owner !== null) {
                $subjectLabel = $this->subjectLabel($activity);
                self::notifyOwnerUnlessActor(
                    $owner,
                    $actor,
                    new ActivityAssigned(
                        $activity->title,
                        $subjectLabel,
                        $actor->name,
                        ActivityAssigned::dueInHuman($activity->scheduled_at),
                    ),
                );
            }
        }

        return $activity;
    }

    /**
     * Transition pending -> in_process. executed_at is NOT set here; the
     * execution timestamp is captured at completion.
     *
     * @throws InvalidOperationException
     */
    public function start(Activity $activity, User $actor): Activity
    {
        if ($activity->status !== 'pending') {
            throw new InvalidOperationException(
                "Solo se puede iniciar una actividad en estado pendiente (actual: {$activity->status})."
            );
        }

        DB::transaction(function () use ($activity, $actor): void {
            $activity->status = 'in_process';
            $activity->updated_by = $actor->id;
            $activity->save();
        });

        return $activity->refresh();
    }

    /**
     * Mark the activity as completed. Optionally creates a follow-up
     * activity in the same transaction when the caller supplies `next_*`
     * fields (RF-ACT-005, ADR-012).
     *
     * @param  array{
     *     result?: string|null,
     *     title?: string|null,
     *     description?: string|null,
     *     executed_at?: \DateTimeInterface|string|null,
     *     next_scheduled_at?: \DateTimeInterface|string|null,
     *     next_type_id?: int|null,
     *     next_title?: string|null,
     *     next_owner_id?: int|null,
     * }  $data
     *
     * @throws InvalidOperationException
     */
    public function complete(Activity $activity, array $data, User $actor): Activity
    {
        $hasNext = ! empty($data['next_scheduled_at'])
            && ! empty($data['next_type_id'])
            && ! empty($data['next_title']);

        if ($hasNext) {
            $this->assertActiveType($data['next_type_id']);
        }

        return DB::transaction(function () use ($activity, $data, $actor, $hasNext): Activity {
            $activity->status = 'completed';
            $activity->result = $data['result'] ?? ($activity->result ?? 'Completada');
            if (array_key_exists('title', $data) && $data['title'] !== null) {
                $activity->title = (string) $data['title'];
            }
            if (array_key_exists('description', $data)) {
                $activity->description = $data['description'];
            }
            $activity->executed_at = $data['executed_at'] ?? now();
            $activity->updated_by = $actor->id;
            $activity->save();

activity()
                ->performedOn($activity)
                ->causedBy($actor)
                ->event('activity-completed')
                ->withProperties([
                    'result' => $activity->result,
                    'executed_at' => optional($activity->executed_at)->toIso8601String(),
                ])
                ->log("Actividad \"{$activity->title}\" completada");

            $followUp = null;

            if ($hasNext) {
                $followUp = new Activity([
                    'type_id' => (int) $data['next_type_id'],
                    'subject_type' => $activity->subject_type,
                    'subject_id' => $activity->subject_id,
                    'owner_id' => (int) ($data['next_owner_id'] ?? $activity->owner_id),
                    'title' => (string) $data['next_title'],
                    'description' => null,
                    'scheduled_at' => $data['next_scheduled_at'],
                    'executed_at' => null,
                    'result' => null,
                    'status' => 'pending',
                    'priority' => $activity->priority,
                    'reminder_at' => null,
                ]);
                $followUp->created_by = $actor->id;
                $followUp->updated_by = $actor->id;
                $followUp->save();

                activity()
                    ->performedOn($activity)
                    ->causedBy($actor)
                    ->event('activity-completed-with-follow-up')
                    ->withProperties([
                        'follow_up_id' => $followUp->id,
                        'follow_up_scheduled_at' => $followUp->scheduled_at->toIso8601String(),
                    ])
->log("Actividad \"{$activity->title}\" completada y se creó seguimiento");
            }

            return $activity->refresh();
        });

        $activity->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new ActivityCompleted($activity, $actor));

        return $activity;
    }

    /**
     * Cancel an activity (pending or in_process only).
     *
     * @throws InvalidOperationException
     */
    public function cancel(Activity $activity, User $actor, string $reason): Activity
    {
        if (! in_array($activity->status, ['pending', 'in_process'], true)) {
            throw new InvalidOperationException(
                "Solo se pueden cancelar actividades pendientes o en proceso (actual: {$activity->status})."
            );
        }

        DB::transaction(function () use ($activity, $actor, $reason): void {
            $activity->status = 'cancelled';
            $activity->updated_by = $actor->id;
            $activity->save();

            activity()
                ->performedOn($activity)
                ->causedBy($actor)
                ->event('activity-cancelled')
                ->withProperties(['reason' => $reason])
                ->log("Actividad \"{$activity->title}\" cancelada: {$reason}");
        });

        return $activity->refresh();
    }

    /**
     * Bookkeeping hook used by the scheduler: when an overdue activity is
     * manually completed from the inbox, the row is already past its
     * scheduled_at. No state mutation here — the caller's `complete()`
     * owns the transition.
     */
    public function markCompletedByScheduler(Activity $activity): Activity
    {
        return $activity;
    }

    /**
     * Owner-scoped query for list pages and calendar views
     * (RF-ACT-008 / RF-CAL-001 / ADR-006).
     *
     * @return Builder<Activity>
     */
    public function scopeQuery(User $user): Builder
    {
        return $this->dataScope->appliesTo(Activity::query(), $user, 'owner_id');
    }

    /**
     * Calendar projection: scope + range + filters, eager-loaded relations
     * (owner, type, subject via morphTo) to avoid N+1 on the calendar
     * rendering.
     *
     * @param  array{
     *     subject_type?: string|null,
     *     owner_id?: int|null,
     *     status?: string|list<string>|null,
     * }  $filters
     *
     * @return Collection<int, Activity>
     */
    public function calendarEvents(User $user, DateRange $range, array $filters = []): Collection
    {
        $query = $this->scopeQuery($user)
            ->scheduledBetween($range->start(), $range->end())
            ->with([
                'owner:id,name',
                'type:id,name,slug',
                'subject',
            ])
            ->orderBy('scheduled_at');

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', Activity::morphClass((string) $filters['subject_type']));
        }

        if (! empty($filters['owner_id'])) {
            $query->where('owner_id', (int) $filters['owner_id']);
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? $filters['status']
                : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        return $query->get();
    }

    /**
     * Validate that the (subject_type, subject_id) pair points to an
     * existing, supported record.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSubjectExists(array $data): void
    {
        $key = $this->subjectKey($data);

        $id = (int) ($data['subject_id'] ?? 0);

        if ($id <= 0) {
            throw new \InvalidArgumentException(
                "El campo subject_id es obligatorio para asociar la actividad."
            );
        }

        $exists = match ($key) {
            'lead' => Lead::query()->whereKey($id)->exists(),
            'customer' => Customer::query()->whereKey($id)->exists(),
            'opportunity' => Opportunity::query()->whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new \InvalidArgumentException(
                "El sujeto de la actividad ({$key} #{$id}) no existe."
            );
        }
    }

    /**
     * Resolve the activity-type catalog row and ensure it is active.
     *
     * @throws \InvalidArgumentException
     */
    private function assertActiveType(?int $typeId): void
    {
        if ($typeId === null || $typeId <= 0) {
            throw new \InvalidArgumentException(
                'El tipo de actividad es obligatorio.'
            );
        }

        $type = ActivityType::query()->whereKey($typeId)->first();

        if ($type === null) {
            throw new \InvalidArgumentException(
                "El tipo de actividad seleccionado no existe (id={$typeId})."
            );
        }

        if (! $type->is_active) {
            throw new \InvalidArgumentException(
                "El tipo de actividad \"{$type->name}\" está desactivado."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function subjectKey(array $data): string
    {
        $key = $data['subject_type'] ?? null;

        if (! is_string($key) || ! in_array($key, Activity::SUBJECT_TYPES, true)) {
            throw new \InvalidArgumentException(
                'subject_type debe ser uno de: '.implode(', ', Activity::SUBJECT_TYPES).'.'
            );
        }

        return $key;
    }

    /**
     * Human-readable label for the activity's subject, used inside the
     * ActivityAssigned notification body. Falls back to the morph class +
     * id when the relation cannot be resolved.
     */
    private function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if ($subject === null) {
            return "{$activity->subject_type} #{$activity->subject_id}";
        }

        return match (true) {
            $subject instanceof Lead => "el prospecto {$subject->code}",
            $subject instanceof Customer => "el cliente {$subject->code}",
            $subject instanceof Opportunity => "la oportunidad {$subject->code}",
            default => $subject->getKey() !== null
                ? "registro #{$subject->getKey()}"
                : 'registro relacionado',
        };
    }
}