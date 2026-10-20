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
     * Optional standard fields a project may hide (S2), in form order, with
     * their Spanish labels. Hiding `pillar` also hides `topic`: a topic only
     * exists under a pillar, so it cannot be chosen without one.
     *
     * @var array<string, string>
     */
    public const HIDEABLE_STANDARD_FIELDS = [
        'pillar' => 'Pilar de contenido',
        'topic' => 'Tema',
        'objectives' => 'Objetivos',
        'formats' => 'Formato',
        'channels' => 'Red social o canal',
        'hashtags' => 'Hashtags',
        'notes' => 'Notas',
    ];

    /**
     * Standard fields every content needs; they can never be hidden.
     *
     * @var array<string, string>
     */
    public const MANDATORY_STANDARD_FIELDS = [
        'planned_date' => 'Fecha',
        'planned_time' => 'Horario',
        'status' => 'Estado',
        'title' => 'Gancho o título',
        'body' => 'Descripción o copy',
    ];

    /**
     * The request input (and column or relation) behind each hideable field.
     *
     * @var array<string, string>
     */
    public const STANDARD_FIELD_INPUTS = [
        'pillar' => 'content_pillar_id',
        'topic' => 'content_topic_id',
        'objectives' => 'objectives',
        'formats' => 'formats',
        'channels' => 'channels',
        'hashtags' => 'hashtags',
        'notes' => 'notes',
    ];

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
        'hidden_standard_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'hidden_standard_fields' => 'array',
        ];
    }

    /**
     * The hideable fields this project hides, normalised: unknown and
     * mandatory keys are dropped, and `topic` follows a hidden `pillar`.
     *
     * @return list<string>
     */
    public function hiddenStandardFields(): array
    {
        $stored = array_map('strval', (array) ($this->hidden_standard_fields ?? []));

        if (in_array('pillar', $stored, true)) {
            $stored[] = 'topic';
        }

        return array_values(array_filter(
            array_keys(self::HIDEABLE_STANDARD_FIELDS),
            fn (string $key): bool => in_array($key, $stored, true),
        ));
    }

    public function isStandardFieldHidden(string $key): bool
    {
        return in_array($key, $this->hiddenStandardFields(), true);
    }

    /**
     * The hideable fields this project shows, in form order. Exports and other
     * listings use it to decide which optional columns to render; the mandatory
     * fields are always shown and are not part of this list.
     *
     * @return list<string>
     */
    public function visibleStandardFields(): array
    {
        return array_values(array_diff(array_keys(self::HIDEABLE_STANDARD_FIELDS), $this->hiddenStandardFields()));
    }

    /**
     * The request inputs of the hidden fields, so validation and writes can
     * ignore them.
     *
     * @return list<string>
     */
    public function hiddenStandardInputs(): array
    {
        return array_map(fn (string $key): string => self::STANDARD_FIELD_INPUTS[$key], $this->hiddenStandardFields());
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
