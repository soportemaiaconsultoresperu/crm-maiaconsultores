<?php

namespace App\Models\Content;

use App\Support\Content\PeruTime;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A free note on one day of a content project's important-dates calendar (T9):
 * a festivity, an anniversary or an activity the editorial team wants to pin to
 * a date. Several annotations may share a day, so the date is never unique.
 *
 * `annotated_at` is a UTC instant for the Peruvian calendar day the editor
 * picked, exactly like `ContentItem::$planned_at`. Every read of the day an
 * annotation belongs to goes through App\Support\Content\PeruTime, never through
 * the raw UTC date, so the CRM's UTC storage and the Peru wall clock never drift
 * apart in the calendar.
 *
 * Ordering and retirement follow the module convention: a global `ordered`
 * scope (by day, then id) keeps every calendar read stable, and retirement is
 * the soft delete, so removing an annotation never physically destroys it.
 *
 * @use HasFactory<\Database\Factories\Content\ContentCalendarAnnotationFactory>
 */
class ContentCalendarAnnotation extends Model
{
    use HasAuditColumns, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_project_id',
        'annotated_at',
        'name',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Stable default order: earliest day first, primary key as the
        // tie-breaker so two annotations on the same day never swap places.
        static::addGlobalScope('ordered', function (Builder $query): void {
            $query->ordered();
        });
    }

    /** @return BelongsTo<ContentProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ContentProject::class, 'content_project_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('annotated_at')->orderBy('id');
    }

    /**
     * The Peruvian calendar date (Y-m-d) the annotation belongs to. The date
     * shown, grouped and navigated by is always this one, never the UTC date.
     */
    public function annotatedDate(): string
    {
        return PeruTime::date($this->annotated_at);
    }
}
