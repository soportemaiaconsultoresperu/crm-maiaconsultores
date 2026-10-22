<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role;

/**
 * Organization-wide internal activity shown as the "Interno" calendar layer.
 * Visibility follows the audience (all | roles | users); the creator and the
 * participants always see it, and admins see everything.
 */
class InternalActivity extends Model
{
    use SoftDeletes;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_ROLES = 'roles';

    public const AUDIENCE_USERS = 'users';

    /** @var array<string, string> */
    public const AUDIENCE_LABELS = [
        self::AUDIENCE_ALL => 'Toda la organización',
        self::AUDIENCE_ROLES => 'Roles',
        self::AUDIENCE_USERS => 'Personas específicas',
    ];

    /** @var array<string, string> */
    public const FREQUENCY_LABELS = [
        'daily' => 'Diaria',
        'weekly' => 'Semanal',
        'monthly' => 'Mensual',
    ];

    /** Safety cap on occurrences expanded for one calendar range. */
    private const MAX_OCCURRENCES = 400;

    protected $fillable = [
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'audience_type',
        'recurrence_frequency',
        'recurrence_until',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'recurrence_until' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audienceRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'internal_activity_roles', 'internal_activity_id', 'role_id');
    }

    public function audienceUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_activity_audience_users', 'internal_activity_id', 'user_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_activity_participants', 'internal_activity_id', 'user_id')
            ->withPivot('is_responsible');
    }

    /**
     * Google Calendar events of this activity, one per user calendar (T4).
     */
    public function calendarLinks(): HasMany
    {
        return $this->hasMany(InternalActivityCalendarLink::class);
    }

    public function responsible(): ?User
    {
        return $this->participants->first(fn (User $user) => (bool) $user->pivot->is_responsible);
    }

    /**
     * Restrict the query to the activities the user may see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $roleIds = $user->roles()->pluck('id')->all();

        return $query->where(function (Builder $visible) use ($user, $roleIds): void {
            $visible->where('created_by', $user->id)
                ->orWhere('audience_type', self::AUDIENCE_ALL)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($user->id))
                ->orWhere(fn (Builder $q) => $q->where('audience_type', self::AUDIENCE_USERS)
                    ->whereHas('audienceUsers', fn (Builder $u) => $u->whereKey($user->id)))
                ->orWhere(fn (Builder $q) => $q->where('audience_type', self::AUDIENCE_ROLES)
                    ->whereHas('audienceRoles', fn (Builder $r) => $r->whereIn('roles.id', $roleIds)));
        });
    }

    /**
     * Activities that may have an occurrence between the two instants: single
     * ones starting inside the range, recurring ones whose series overlaps it.
     */
    public function scopeOccurringBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('starts_at', '<=', $end)
            ->where(function (Builder $q) use ($start): void {
                $q->where(fn (Builder $single) => $single->whereNull('recurrence_frequency')
                    ->where('starts_at', '>=', $start))
                    ->orWhere(fn (Builder $series) => $series->whereNotNull('recurrence_frequency')
                        ->whereDate('recurrence_until', '>=', $start->toDateString()));
            });
    }

    /**
     * Start instants of this activity's occurrences inside [start, end].
     *
     * @return list<CarbonInterface>
     */
    public function occurrencesBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $first = $this->starts_at->toImmutable();

        if ($this->recurrence_frequency === null || $this->recurrence_until === null) {
            return $first->betweenIncluded($start, $end) ? [$first] : [];
        }

        $last = $this->recurrence_until->toImmutable()->endOfDay();
        $occurrences = [];

        for ($i = 0; $i < self::MAX_OCCURRENCES * 4; $i++) {
            $current = match ($this->recurrence_frequency) {
                'daily' => $first->addDays($i),
                'weekly' => $first->addWeeks($i),
                default => $first->addMonthsNoOverflow($i),
            };

            if ($current->gt($end) || $current->gt($last)) {
                break;
            }
            if ($current->gte($start)) {
                $occurrences[] = $current;
                if (count($occurrences) >= self::MAX_OCCURRENCES) {
                    break;
                }
            }
        }

        return $occurrences;
    }

    public function recurrenceLabel(): ?string
    {
        if ($this->recurrence_frequency === null || $this->recurrence_until === null) {
            return null;
        }

        return (self::FREQUENCY_LABELS[$this->recurrence_frequency] ?? $this->recurrence_frequency)
            .' hasta el '.$this->recurrence_until->format('d/m/Y');
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }
}
