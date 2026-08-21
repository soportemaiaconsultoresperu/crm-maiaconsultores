<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignParticipant extends Model
{
    /** @use HasFactory<\Database\Factories\CampaignParticipantFactory> */
    use HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'run_id',
        'subject_type',
        'subject_id',
        'assigned_to',
        'status',
        'included_at',
        'excluded_at',
        'exclusion_reason',
        'display_name',
        'company_name',
        'document_number_masked',
        'email',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'included_at' => 'datetime',
            'excluded_at' => 'datetime',
        ];
    }

    public const SUBJECT_TYPES = ['lead', 'customer', 'contact', 'opportunity'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_CANCELLED = 'cancelled';

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignActionItem::class, 'participant_id');
    }
}
