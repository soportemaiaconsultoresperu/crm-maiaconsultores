<?php

namespace App\Models\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Support\Content\PeruTime;
use App\Traits\HasAuditColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single editorial row of a content project: either a planned content or a
 * bare idea, discriminated by `kind`.
 *
 * `planned_at` is stored as a UTC instant and always interpreted through
 * App\Support\Content\PeruTime for the Peru wall-clock date, weekday and time.
 * `isOverdue()` never flags a published row, and a dateless idea is never
 * overdue.
 *
 * `revision` is the optimistic-concurrency counter (T8a): it starts at 1 and
 * every successful save increments it. It is deliberately not mass-assignable,
 * so only the service's transactional update path can move it.
 *
 * @use HasFactory<\Database\Factories\Content\ContentItemFactory>
 */
class ContentItem extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    /**
     * New rows are planned contents in the first editorial state unless the
     * caller overrides them; an idea must be requested explicitly.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'kind' => 'content',
        'status' => 'incomplete',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_project_id',
        'kind',
        'planned_at',
        'content_pillar_id',
        'content_topic_id',
        'status',
        'title',
        'body',
        'hashtags',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContentItemKind::class,
            'planned_at' => 'datetime',
            'status' => ContentItemStatus::class,
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Ordered default scope: dated contents first (earliest planned date),
        // undated ideas last. Ties fall back to the primary key for stability.
        static::addGlobalScope('ordered', function (Builder $query): void {
            $query->ordered();
        });
    }

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }

    /** @return BelongsTo<ContentPillar, $this> */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(ContentPillar::class, 'content_pillar_id');
    }

    /** @return BelongsTo<ContentTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ContentTopic::class, 'content_topic_id');
    }

    /** @return BelongsToMany<ContentObjective, $this> */
    public function objectives(): BelongsToMany
    {
        return $this->belongsToMany(
            ContentObjective::class,
            'content_item_objective',
            'content_item_id',
            'content_objective_id',
        );
    }

    /** @return BelongsToMany<ContentFormat, $this> */
    public function formats(): BelongsToMany
    {
        return $this->belongsToMany(
            ContentFormat::class,
            'content_item_format',
            'content_item_id',
            'content_format_id',
        );
    }

    /** @return BelongsToMany<ContentChannel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            ContentChannel::class,
            'content_item_channel',
            'content_item_id',
            'content_channel_id',
        );
    }

    /**
     * Typed custom-field values stored against this item.
     *
     * @return HasMany<ContentFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(ContentFieldValue::class, 'content_item_id');
    }

    /**
     * Media attachments (uploads and external links) attached to this item.
     *
     * @return HasMany<ContentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ContentAttachment::class, 'content_item_id');
    }

    /**
     * Default listing order: dated rows ascending by planned instant, then
     * undated ideas. `planned_at IS NULL` is portable to SQLite and MySQL.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('planned_at IS NULL')
            ->orderBy('planned_at')
            ->orderBy('id');
    }

    /**
     * Overdue boundary (T6d), kept in one place.
     *
     * A planned row is overdue once the instant it was planned for has passed,
     * EXCEPT a date-only row (stored at Peru midnight), which only becomes
     * overdue after its Peruvian day has ended. A dateless idea and a published
     * row are never overdue.
     *
     * Both boundaries come from the Peru time helper, never from a hardcoded
     * offset: `$now` is the current UTC instant and `$startOfToday` is the start
     * of today's Peruvian calendar day, also expressed as a UTC instant. The
     * rule reduces to `planned_at <= now AND planned_at != startOfToday`:
     *
     * - a row before today's Peru midnight belongs to an earlier Peru day, so
     *   both its date-only and its timed form are overdue;
     * - a row strictly between today's Peru midnight and now is an explicit time
     *   already reached, so it is overdue;
     * - today's exact Peru midnight is the date-only row for today, which is not
     *   overdue until that day ends.
     *
     * A timed row therefore behaves exactly as before; only the date-only row
     * gains the Peru-midnight grace period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function overdueBoundaries(): array
    {
        $now = CarbonImmutable::now();
        $startOfToday = PeruTime::toUtc(PeruTime::date($now).' 00:00:00');

        return [$now, $startOfToday];
    }

    /**
     * Overdue criterion: a planned instant already reached, excluding a
     * date-only row still inside its Peruvian day and every published row.
     * Dateless ideas are excluded by the `planned_at` filter.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        [$now, $startOfToday] = self::overdueBoundaries();

        return $query
            ->whereNotNull('planned_at')
            ->where('planned_at', '<=', $now)
            ->where('planned_at', '!=', $startOfToday)
            ->where('status', '!=', ContentItemStatus::Published->value);
    }

    public function scopeIdeas(Builder $query): Builder
    {
        return $query->where('kind', ContentItemKind::Idea->value);
    }

    public function scopeContents(Builder $query): Builder
    {
        return $query->where('kind', ContentItemKind::Content->value);
    }

    public function isIdea(): bool
    {
        return $this->kind === ContentItemKind::Idea;
    }

    public function isContent(): bool
    {
        return $this->kind === ContentItemKind::Content;
    }

    public function isPublished(): bool
    {
        return $this->status === ContentItemStatus::Published;
    }

    /**
     * Same rule as scopeOverdue(), evaluated on the loaded row so the grid
     * marker and the query never disagree.
     */
    public function isOverdue(): bool
    {
        if ($this->planned_at === null || $this->isPublished()) {
            return false;
        }

        [$now, $startOfToday] = self::overdueBoundaries();

        return $this->planned_at->lessThanOrEqualTo($now)
            && ! $this->planned_at->equalTo($startOfToday);
    }

    /**
     * ISO-8601 weekday (1 = Monday .. 7 = Sunday) of the Peru calendar day, or
     * null for an idea with no planned instant.
     */
    public function plannedWeekday(): ?int
    {
        return $this->planned_at === null ? null : PeruTime::weekday($this->planned_at);
    }

    /**
     * Peru calendar date (Y-m-d) of the planned instant, or null.
     */
    public function plannedDate(): ?string
    {
        return $this->planned_at === null ? null : PeruTime::date($this->planned_at);
    }
}
