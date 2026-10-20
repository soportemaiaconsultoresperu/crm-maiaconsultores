<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    protected $fillable = [
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'audience_type',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }
}
