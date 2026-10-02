<?php

namespace App\Models\Content;

use App\Models\User;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A content project is the top-level container of the content scheduling
 * module. Projects are dynamic (the three Maia brands are seeded data, never
 * hardcoded), so nothing here enumerates their names, sections or fields.
 *
 * Archiving and soft deletion are deliberately two different lifecycles:
 * `deleted_at` sends the project to the trash, while `archived_at` retires it
 * from normal use while keeping it fully readable. Archiving therefore never
 * touches `deleted_at`.
 *
 * @use HasFactory<\Database\Factories\Content\ContentProjectFactory>
 */
class ContentProject extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'is_active',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Users assigned to the project. Only members may reach its content.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'content_project_user');
    }

    /**
     * Projects available for normal work: active and not archived.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * Projects retired through `archived_at` (still not soft-deleted).
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isActive(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Retire the project without deleting it. `deleted_at` is intentionally
     * left untouched so the project and its data remain readable.
     */
    public function archive(): self
    {
        $this->forceFill([
            'is_active' => false,
            'archived_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Bring an archived project back into normal use.
     */
    public function reactivate(): self
    {
        $this->forceFill([
            'is_active' => true,
            'archived_at' => null,
        ])->save();

        return $this;
    }
}
