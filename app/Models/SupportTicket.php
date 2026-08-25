<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupportTicket extends Model
{
    use HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'code',
        'title',
        'customer_id',
        'requester_contact_id',
        'type_id',
        'category_id',
        'channel_id',
        'priority_id',
        'status_id',
        'responsible_id',
        'team_id',
        'description',
        'impact',
        'general_observations',
        'cancel_reason',
        'reopen_reason',
        'assigned_at',
'first_responded_at',
            'work_started_at', 'resolved_at', 'validated_at', 'closed_at',
            'solution_summary', 'close_reason', 'reopen_reason',
        ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'first_responded_at' => 'datetime',
                'work_started_at' => 'datetime', 'resolved_at' => 'datetime',
                'validated_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Support ticket {$this->code} was {$eventName}");
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'requester_contact_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(SupportTicketType::class, 'type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SupportChannel::class, 'channel_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(SupportPriority::class, 'priority_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(SupportStatus::class, 'status_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(\App\Models\SupportAssignment::class, 'ticket_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(\App\Models\SupportTicketUpdate::class, 'ticket_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'subject_id')->where('subject_type', self::class);
    }

    public function sessionDetails(): HasMany { return $this->hasMany(SupportSessionDetail::class, 'ticket_id'); }

    public function incidentDetail(): HasOne { return $this->hasOne(SupportIncidentDetail::class, 'ticket_id'); }

    public function observations(): HasMany { return $this->hasMany(SupportObservation::class, 'ticket_id'); }

    public function cycles(): HasMany { return $this->hasMany(SupportResolutionCycle::class, 'ticket_id'); }

    public function statusPeriods(): HasMany { return $this->hasMany(SupportStatusPeriod::class, 'ticket_id'); }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $status) => $status->where('is_terminal', false));
    }
}
