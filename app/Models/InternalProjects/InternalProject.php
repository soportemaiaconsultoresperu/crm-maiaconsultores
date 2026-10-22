<?php

namespace App\Models\InternalProjects;

use App\Enums\InternalProjects\ProjectStatus;
use App\Models\InternalActivity;
use App\Models\User;
use App\Support\Content\ProjectColor;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role;

/**
 * Internal initiative (e.g. a software development) planned as ordered phases
 * holding tasks (S2).
 *
 * Visibility follows the internal activities audience (all | roles | users);
 * the creator, the responsible and the members always see the project, and
 * admins see everything. The team (members + responsible) works on phases
 * and tasks; only the responsible (or an admin) manages the project itself.
 *
 * @use HasFactory<\Database\Factories\InternalProjects\InternalProjectFactory>
 */
class InternalProject extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    public const AUDIENCE_ALL = InternalActivity::AUDIENCE_ALL;

    public const AUDIENCE_ROLES = InternalActivity::AUDIENCE_ROLES;

    public const AUDIENCE_USERS = InternalActivity::AUDIENCE_USERS;

    /** @var array<string, string> */
    public const AUDIENCE_LABELS = InternalActivity::AUDIENCE_LABELS;

    protected $fillable = [
        'name',
        'code',
        'description',
        'color',
        'starts_on',
        'ends_on',
        'status',
        'responsible_user_id',
        'audience_type',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'status' => 'planificado',
        'audience_type' => self::AUDIENCE_ALL,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * Stored as lowercase `#rrggbb`; anything else is stored as null so every
     * surface falls back to ProjectColor::DEFAULT.
     */
    protected function color(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => ProjectColor::normalize($value));
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The team: users who create and update phases, tasks and statuses.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_project_members', 'internal_project_id', 'user_id');
    }

    public function audienceRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'internal_project_audience_roles', 'internal_project_id', 'role_id');
    }

    public function audienceUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_project_audience_users', 'internal_project_id', 'user_id');
    }

    /**
     * Phases in planned order.
     */
    public function phases(): HasMany
    {
        return $this->hasMany(InternalProjectPhase::class)->orderBy('sort')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(InternalProjectTask::class);
    }

    /**
     * Colour to paint with: the project's own, or the neutral default.
     */
    public function displayColor(): string
    {
        return ProjectColor::resolve($this->color);
    }

    /**
     * Restrict the query to the projects the user may see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $roleIds = $user->roles()->pluck('id')->all();

        return $query->where(function (Builder $visible) use ($user, $roleIds): void {
            $visible->where('created_by', $user->id)
                ->orWhere('responsible_user_id', $user->id)
                ->orWhere('audience_type', self::AUDIENCE_ALL)
                ->orWhereHas('members', fn (Builder $q) => $q->whereKey($user->id))
                ->orWhere(fn (Builder $q) => $q->where('audience_type', self::AUDIENCE_USERS)
                    ->whereHas('audienceUsers', fn (Builder $u) => $u->whereKey($user->id)))
                ->orWhere(fn (Builder $q) => $q->where('audience_type', self::AUDIENCE_ROLES)
                    ->whereHas('audienceRoles', fn (Builder $r) => $r->whereIn('roles.id', $roleIds)));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->hasRole('admin') || $this->isResponsible($user) || $this->created_by === $user->id) {
            return true;
        }

        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    public function isResponsible(User $user): bool
    {
        return $this->responsible_user_id !== null && $this->responsible_user_id === $user->id;
    }

    /**
     * Uses the loaded `members` relation when present, one query otherwise.
     */
    public function isMember(User $user): bool
    {
        if ($this->relationLoaded('members')) {
            return $this->members->contains('id', $user->id);
        }

        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Team work (phases, tasks, statuses): members, the responsible and admins.
     */
    public function isEditableBy(User $user): bool
    {
        return $this->isManageableBy($user) || $this->isMember($user);
    }

    /**
     * Project settings, members, audience and deletion: responsible and admins.
     */
    public function isManageableBy(User $user): bool
    {
        return $user->hasRole('admin') || $this->isResponsible($user);
    }
}
