<?php

namespace App\Models\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audited status transition of a task (S3): who changed it and when.
 * `from_status` is null for the status a task was created with.
 */
class InternalProjectTaskStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'internal_project_task_id',
        'from_status',
        'to_status',
        'user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => TaskStatus::class,
            'to_status' => TaskStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(InternalProjectTask::class, 'internal_project_task_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
