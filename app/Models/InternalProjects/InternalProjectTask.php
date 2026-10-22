<?php

namespace App\Models\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\User;
use App\Support\Content\PeruTime;
use App\Traits\HasAuditColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Task of an internal project phase (S2). Its weight counts towards progress
 * once it is "Terminada" (S3), and it is "Atrasada" when its due date has
 * passed in Lima while it is not done.
 *
 * Writes go through InternalProjectTaskService, which keeps the phase inside
 * the task's project, the dates ordered, `completed_at` in sync and the
 * status history recorded.
 *
 * @use HasFactory<\Database\Factories\InternalProjects\InternalProjectTaskFactory>
 */
class InternalProjectTask extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'internal_project_id',
        'internal_project_phase_id',
        'title',
        'description',
        'responsible_user_id',
        'starts_on',
        'due_on',
        'status',
        'weight',
        'is_milestone',
        'completed_at',
        'sort',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'pendiente',
        'weight' => 1,
        'is_milestone' => false,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on' => 'date',
            'status' => TaskStatus::class,
            'weight' => 'integer',
            'is_milestone' => 'boolean',
            'completed_at' => 'datetime',
            'sort' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InternalProject::class, 'internal_project_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(InternalProjectPhase::class, 'internal_project_phase_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(InternalProjectTaskStatusChange::class);
    }

    public function isDone(): bool
    {
        return $this->status === TaskStatus::Done;
    }

    /**
     * Due date already passed on the Lima calendar and the task is not done.
     */
    public function isOverdue(?CarbonInterface $now = null): bool
    {
        if ($this->isDone() || $this->due_on === null) {
            return false;
        }

        return $this->due_on->toDateString() < self::limaToday($now);
    }

    /**
     * Tasks overdue on the Lima calendar (same rule as isOverdue()).
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->where('status', '!=', TaskStatus::Done->value)
            ->whereDate('due_on', '<', self::limaToday($now));
    }

    public function scopeMilestones(Builder $query): Builder
    {
        return $query->where('is_milestone', true);
    }

    /**
     * Today's date (Y-m-d) in Lima.
     */
    public static function limaToday(?CarbonInterface $now = null): string
    {
        return PeruTime::date($now ?? now());
    }
}
