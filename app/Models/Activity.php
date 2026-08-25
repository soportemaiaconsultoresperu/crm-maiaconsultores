<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Activity: the single source of truth for next actions (ADR-012).
 * Completed activities are always preserved.
 */
class Activity extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityFactory> */
    use HasAuditColumns, HasFactory, SoftDeletes;

    /**
     * Logical subject keys for the polymorphic association (Lead / Customer
     * / Opportunity). The strings here are the public API for callers
     * (controllers, FormRequests, factories); the FQCN is resolved through
     * morphClass() so we never sprinkle model class strings around the code
     * base (B05 / RF-ACT-006).
     *
     * @var list<string>
     */
    public const SUBJECT_TYPES = ['lead', 'customer', 'opportunity', 'support_ticket'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type_id',
        'subject_type',
        'subject_id',
        'owner_id',
        'title',
        'description',
        'scheduled_at',
        'executed_at',
        'result',
        'status',
        'priority',
        'reminder_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
            'reminder_at' => 'datetime',
        ];
    }

    /**
     * Polymorphic subject: Lead, Customer or Opportunity.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Map a logical subject key to the Eloquent morph class string. Avoids
     * hard-coding FQCNs in controllers/FormRequests/factories.
     *
     * @throws \InvalidArgumentException When the key is not a known subject.
     */
    public static function morphClass(string $type): string
    {
        return match ($type) {
            'lead' => Lead::class,
            'customer' => Customer::class,
            'opportunity' => Opportunity::class,
            'support_ticket' => SupportTicket::class,
            default => throw new \InvalidArgumentException(
                "Activity subject type \"{$type}\" is not supported."
            ),
        };
    }

    /**
     * Inverse of morphClass(): turn a stored morph class back into its
     * public key. Falls back to the morph alias when the value is already
     * one of SUBJECT_TYPES (some morph sources use the alias directly).
     */
    public static function subjectKey(string $morphClass): string
    {
        if (in_array($morphClass, self::SUBJECT_TYPES, true)) {
            return $morphClass;
        }

        return match ($morphClass) {
            Lead::class => 'lead',
            Customer::class => 'customer',
            Opportunity::class => 'opportunity',
            SupportTicket::class => 'support_ticket',
            default => throw new \InvalidArgumentException(
                "Unknown morph class \"{$morphClass}\" for activity subject."
            ),
        };
    }

    /**
     * Human-readable label for an activity subject. UI and notifications use
     * this single formatter so users see the person/company/title before the
     * internal CRM code.
     */
    public static function subjectDisplayLabel(?Model $subject = null): string
    {
        if ($subject === null) {
            return '—';
        }

        $label = match (true) {
            $subject instanceof Lead => self::leadDisplayName($subject),
            $subject instanceof Customer => self::customerDisplayName($subject),
            $subject instanceof Opportunity => trim((string) $subject->title),
            $subject instanceof SupportTicket => trim((string) ($subject->title ?? $subject->subject ?? '')),
            default => '',
        };

        $code = trim((string) ($subject->code ?? ''));
        if ($code !== '') {
            return ($label !== '' ? $label : '#'.$subject->getKey()).' ('.$code.')';
        }

        return $label !== '' ? $label : '#'.$subject->getKey();
    }

    private static function leadDisplayName(Lead $lead): string
    {
        $person = trim(implode(' ', array_filter([
            trim((string) $lead->first_name),
            trim((string) $lead->last_name),
        ], fn (string $part): bool => $part !== '')));

        $company = trim((string) ($lead->company_name ?: $lead->trade_name ?: $lead->legal_name));

        return trim(implode(' — ', array_filter([$person, $company], fn (string $part): bool => $part !== '')));
    }

    private static function customerDisplayName(Customer $customer): string
    {
        $business = trim((string) ($customer->trade_name ?: $customer->legal_name));
        $person = trim(implode(' ', array_filter([
            trim((string) $customer->first_name),
            trim((string) $customer->last_name),
        ], fn (string $part): bool => $part !== '')));

        return $business !== '' ? $business : $person;
    }

    /**
     * @return BelongsTo<ActivityType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<ActivityCalendarLink, $this>
     */
    public function calendarLinks(): HasMany
    {
        return $this->hasMany(ActivityCalendarLink::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }

    /**
     * Scope to activities still pending as of now (feeds next-action
     * queries; the daily scheduler persists "overdue", queries re-derive).
     *
     * @param  Builder<Activity>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_process', 'overdue']);
    }

    /**
     * Scope to activities whose scheduled_at is in the past (used by the
     * scheduler and by the overdue reports).
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeDueBefore(Builder $query, mixed $moment): Builder
    {
        return $query->where('scheduled_at', '<', $moment);
    }

    /**
     * Scope to activities scheduled between two moments (inclusive on both
     * bounds). Calendar projections reuse this so day/week/month pages stay
     * consistent.
     *
     * @param  Builder<Activity>  $query
     */
    public function scopeScheduledBetween(Builder $query, mixed $start, mixed $end): Builder
    {
        return $query->where('scheduled_at', '>=', $start)
            ->where('scheduled_at', '<=', $end);
    }
}
