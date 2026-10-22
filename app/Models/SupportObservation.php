<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportObservation extends Model
{
    use SoftDeletes;

    /** Fixed internal workflow slugs, deliberately strings rather than database ENUM. */
    public const STATES = ['pending', 'in_process', 'lifted', 'validated', 'rejected', 'reopened', 'not_applicable'];

    public const LABELS = ['pending' => 'Pendiente', 'in_process' => 'En proceso', 'lifted' => 'Levantada', 'validated' => 'Validada', 'rejected' => 'Rechazada', 'reopened' => 'Reabierta', 'not_applicable' => 'No aplica'];

    /**
     * Allowed moves (design §8/§10: levantar, validar, rechazar o reabrir sin borrar historial).
     * Rejected means the lifting was returned as not solved, so it goes back to work.
     */
    public const TRANSITIONS = [
        'pending' => ['in_process', 'lifted', 'not_applicable'],
        'in_process' => ['lifted', 'not_applicable'],
        'lifted' => ['validated', 'rejected'],
        'rejected' => ['in_process', 'lifted', 'reopened'],
        'reopened' => ['in_process', 'lifted', 'not_applicable'],
        'validated' => ['reopened'],
        'not_applicable' => ['reopened'],
    ];

    /** Permission required to move an observation into each target state. */
    public const TARGET_PERMISSIONS = [
        'in_process' => 'support.observations.lift',
        'lifted' => 'support.observations.lift',
        'validated' => 'support.observations.validate',
        'not_applicable' => 'support.observations.validate',
        'rejected' => 'support.observations.reject',
        'reopened' => 'support.observations.reject',
    ];

    /** Finished states: anything else blocks closing the ticket. */
    public const FINISHED_STATES = ['validated', 'not_applicable'];

    /** States that still need work: they block resolving the ticket. */
    public const UNATTENDED_STATES = ['pending', 'in_process', 'rejected', 'reopened'];

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->state] ?? [];
    }

    public function stateLabel(): string
    {
        return self::LABELS[$this->state] ?? $this->state;
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'due_at' => 'datetime', 'lifted_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(\App\Models\SupportObservationHistory::class, 'observation_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }
}
