<?php

namespace App\Models\InternalProjects;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordered stage of an internal project (e.g. Análisis, Diseño, Desarrollo,
 * Pruebas, Lanzamiento) with optional planned dates (S2).
 *
 * @use HasFactory<\Database\Factories\InternalProjects\InternalProjectPhaseFactory>
 */
class InternalProjectPhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_project_id',
        'name',
        'sort',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InternalProject::class, 'internal_project_id');
    }

    /**
     * Tasks in planned order.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(InternalProjectTask::class)->orderBy('sort')->orderBy('starts_on')->orderBy('id');
    }
}
