<?php

namespace App\Support;

use App\Models\Activity;
use Illuminate\Support\Collection;

/**
 * Shared "next action" query (ADR-012): the next action of a lead,
 * customer or opportunity is its most proximate future PENDING activity.
 * Activities are the single source of truth; no entity carries next-action
 * columns.
 *
 * Mirrors the original LeadService::nextAction semantics:
 * status = pending AND scheduled_at >= now, ordered by scheduled_at ASC.
 */
class NextActionQuery
{
    /**
     * Next action for a single subject, or null when there is none.
     */
    public static function forSubject(string $subjectType, int $subjectId): ?Activity
    {
        return self::forSubjects($subjectType, [$subjectId])->get($subjectId);
    }

    /**
     * One-query map of subject_id => next Activity for a list page.
     * Subjects without a future pending activity are simply absent from
     * the resulting collection.
     *
     * @param  array<int, int>  $subjectIds
     * @return Collection<int, Activity> keyed by subject_id
     */
    public static function forSubjects(string $subjectType, array $subjectIds): Collection
    {
        $subjectIds = array_values(array_map('intval', $subjectIds));

        if ($subjectIds === []) {
            return new Collection();
        }

        return Activity::query()
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $subjectIds)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $group): Activity => $group->first());
    }
}
