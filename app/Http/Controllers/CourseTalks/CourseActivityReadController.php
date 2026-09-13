<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Http\Controllers\Controller;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class CourseActivityReadController extends Controller
{
    /**
     * The unified list's activity-type filter. It travels as a query parameter so a
     * filtered view is linkable and survives a reload; its name is the one the
     * module's alert screen already uses for the same concept, and its vocabulary is
     * `CourseActivityType`'s own backing values — never a parallel copy.
     */
    private const TYPE_FILTER = 'activity_type';

    /**
     * Why the list chose the featured edition it offers, in the words the screen
     * shows. The reason is a sentence about the CHOICE, so it is not the state's
     * own label: an edition can be the featured one because it is in progress, or
     * because it is the next one, or simply because it is the most recent one.
     */
    private const FEATURED_REASON_IN_PROGRESS = 'Edición en curso';

    private const FEATURED_REASON_SCHEDULED = 'Próxima edición';

    private const FEATURED_REASON_LATEST = 'Última edición';

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CourseActivity::class);

        $typeFilter = $this->typeFilter($request);

        $activities = CourseActivity::query()
            ->withCount('editions')
            // ONE query for the editions of every listed activity. The featured
            // edition is then resolved per activity in PHP (see featuredEdition()),
            // so this screen costs the same whether it holds one activity or two
            // hundred: nothing below loads a relation per row.
            ->with('editions')
            ->orderBy('type')
            ->orderBy('name');

        if ($typeFilter['value'] !== null) {
            // Filtering by activity type is a QUERY concern, and the value that
            // reaches the query is the enum's OWN backing value (`course` / `talk`)
            // — never the entry the user typed. That is what keeps the outcome the
            // same on every engine: the MySQL connection's `utf8mb4_unicode_ci`
            // collation matches case and accent variants (it treats `'Course'` and
            // `'cóurse'` as `'course'`) while SQLite compares exactly, so an entry
            // compared RAW would filter one list on MySQL and a different one on
            // SQLite.
            $activities->where('type', $typeFilter['value']);
        } elseif ($typeFilter['warning'] === 'unknown') {
            // An entry outside the vocabulary is not compared in SQL at all: the
            // list is narrowed to nothing with an explicit, engine-independent
            // predicate, so no collation can reinterpret `'Course'` or `'cóurse'`
            // into a match. A value outside the vocabulary therefore matches
            // nothing rather than silently showing every activity as if the filter
            // had been applied, and it does so identically on MySQL and on SQLite.
            // The view reports the entry it rejected.
            $activities->whereRaw('1 = 0');
        }

        $activities = $activities->get();

        return view('course-talks.activities.index', [
            'activities' => $activities,
            // Keyed by activity id so the view never has to match them up, and
            // already resolved: the view only reads a reason label and a route
            // target, and contains no selection logic of its own.
            'featuredEditions' => $activities
                ->mapWithKeys(fn (CourseActivity $activity): array => [$activity->id => $this->featuredEdition($activity)])
                ->all(),
            'typeOptions' => $this->typeOptions(),
            'activeType' => $typeFilter['activeType'],
            'typeFilterWarning' => $typeFilter['warning'],
        ]);
    }

    public function show(CourseActivity $activity): View
    {
        Gate::authorize('view', $activity);

        return view('course-talks.activities.show', [
            'activity' => $activity->load(['editions' => fn ($query) => $query->orderByDesc('starts_on')]),
        ]);
    }

    public function showEdition(CourseEdition $edition): View
    {
        Gate::authorize('view', $edition);

        return view('course-talks.editions.show', [
            'edition' => $edition->load(['activity']),
        ]);
    }

    /**
     * The filter's option list, derived from `CourseActivityType` itself so the
     * query-string vocabulary and the Spanish labels can never drift from the enum.
     *
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        $options = [];

        foreach (CourseActivityType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * The requested filter, normalized, plus how the screen must report it.
     *
     *  - absent or blank: no filter and nothing to report — the list is exactly the
     *    one this screen showed before the filter existed;
     *  - a scalar that resolves to a `CourseActivityType`: applied through the
     *    enum's OWN backing value, whatever case or surrounding whitespace the entry
     *    carried, and nothing to report — the filter really did apply;
     *  - a scalar that resolves to nothing: `unknown`. It is echoed back to the
     *    screen and narrows the list to nothing, and it is NEVER handed to the query
     *    (see `index()`), because the engines disagree on that comparison and the
     *    outcome must not depend on the connection's collation;
     *  - a non-scalar: not a filter at all, so it is dropped before it can reach a
     *    query (an array in a `where` is the 500 this locks out) and flagged
     *    `discarded`, with the list left complete and the value reported.
     *
     * `activeType` is what the screen shows as the active/rejected entry; `value` is
     * what the query is allowed to compare, and it is non-null only when the entry
     * resolved to an enum case.
     *
     * @return array{activeType: string|null, value: string|null, warning: string|null}
     */
    private function typeFilter(Request $request): array
    {
        $raw = $request->query(self::TYPE_FILTER);

        if (is_array($raw)) {
            return ['activeType' => null, 'value' => null, 'warning' => 'discarded'];
        }

        if (! is_scalar($raw)) {
            return ['activeType' => null, 'value' => null, 'warning' => null];
        }

        $entry = trim((string) $raw);

        if ($entry === '') {
            return ['activeType' => null, 'value' => null, 'warning' => null];
        }

        // The vocabulary is the enum's and only the enum's. The lower-casing is a
        // normalization, not a second vocabulary: `course` and `Course` are the same
        // type in a different case, and the MySQL connection's `utf8mb4_unicode_ci`
        // collation already treats them as equal. Resolving the case HERE, before
        // the query, is what makes SQLite agree with MySQL instead of contradicting
        // it — the raw entry never reaches the `where`.
        $type = CourseActivityType::tryFrom(strtolower($entry));

        if ($type === null) {
            // The rejected entry is reported verbatim, but `value` stays null so the
            // query can never compare it under a collation of its own choosing.
            return ['activeType' => $entry, 'value' => null, 'warning' => 'unknown'];
        }

        return ['activeType' => $type->value, 'value' => $type->value, 'warning' => null];
    }

    /**
     * The featured edition of one activity: the single edition this list offers a
     * way into, plus the reason it was chosen.
     *
     * The rule, implemented as a total order over the activity's already-loaded
     * editions:
     *
     *  1. an `in_progress` edition; among several, the one with the latest
     *     `starts_on` — the delivery that is happening now is the one somebody has
     *     come to mark attendance in;
     *  2. otherwise the `scheduled` edition with the nearest future `starts_on`,
     *     inclusive of today: an edition starting today is exactly the one the
     *     operator is about to run, and `in_progress` (rule 1) is the state of one
     *     that already started. A `draft` is never eligible: it is not published,
     *     so it is not an edition anybody is about to work in;
     *  3. otherwise the most recent edition by `starts_on`, whatever its state, so
     *     the last delivery stays reachable instead of the shortcut disappearing;
     *  4. no editions at all: no shortcut, and only the existing "Ver detalle"
     *     link remains.
     *
     * WHY THIS IS PHP AND NOT SQL. "The in-progress row, else the next scheduled
     * row, else the latest row" has no portable SQL spelling: MySQL answers it with
     * `FIELD()` and SQLite does not have it, and the obvious rewrite (a CASE ladder
     * or a window function) still compares state values under the connection's
     * collation, which is `utf8mb4_unicode_ci` on MySQL and exact on SQLite. The
     * two engines already disagree about comparison on this project, so the choice
     * is made on a plain PHP collection instead: one rule, one implementation, the
     * same outcome on both engines, and no query per row.
     *
     * @return array{edition: CourseEdition, reason: string}|null
     */
    private function featuredEdition(CourseActivity $activity): ?array
    {
        $editions = $activity->editions;

        if ($editions->isEmpty()) {
            return null;
        }

        $inProgress = $this->latestFirst($editions->filter(
            fn (CourseEdition $edition): bool => $edition->state === CourseEditionState::InProgress
        ))->first();

        if ($inProgress instanceof CourseEdition) {
            return ['edition' => $inProgress, 'reason' => self::FEATURED_REASON_IN_PROGRESS];
        }

        // A plain `Y-m-d` string comparison: from today onwards, inclusive. The
        // comparison is chronological because the format is zero-padded, and it
        // cannot be reinterpreted by a collation the way a datetime cast can.
        $today = now()->toDateString();

        $scheduled = $this->soonestFirst($editions->filter(
            fn (CourseEdition $edition): bool => $edition->state === CourseEditionState::Scheduled
                && $edition->starts_on !== null
                && $edition->starts_on->toDateString() >= $today
        ))->first();

        if ($scheduled instanceof CourseEdition) {
            return ['edition' => $scheduled, 'reason' => self::FEATURED_REASON_SCHEDULED];
        }

        // Last resort: the most recent edition that is still a real destination.
        // A CANCELLED edition is deliberately excluded — pointing "go and mark
        // attendance" at the most recent thing that never happened is worse than
        // offering nothing — so an activity whose editions were all cancelled shows no
        // shortcut at all, exactly like one with no editions.
        $latest = $this->latestFirst($editions->reject(
            fn (CourseEdition $edition): bool => $edition->state === CourseEditionState::Cancelled
        ))->first();

        if (! $latest instanceof CourseEdition) {
            return null;
        }

        return ['edition' => $latest, 'reason' => self::FEATURED_REASON_LATEST];
    }

    /**
     * The latest `starts_on` first. An edition without dates sorts last — a missing
     * date is not "the most recent" anything — and `id` breaks every tie, including
     * the all-null case, so the choice never depends on the order the rows came back
     * in.
     *
     * The array key is a plain two-scalar comparison performed in PHP, so no SQL
     * function, collation, or engine behaviour is involved.
     *
     * @param  Collection<int, CourseEdition>  $editions
     * @return Collection<int, CourseEdition>
     */
    private function latestFirst(Collection $editions): Collection
    {
        return $editions->sortByDesc(fn (CourseEdition $edition): array => [
            $edition->starts_on?->getTimestamp() ?? PHP_INT_MIN,
            (int) $edition->id,
        ])->values();
    }

    /**
     * The earliest `starts_on` first — the same comparison as `latestFirst()`,
     * reversed. Only called with editions that already carry a date (the caller
     * filters for that); the sentinel is defensive.
     *
     * @param  Collection<int, CourseEdition>  $editions
     * @return Collection<int, CourseEdition>
     */
    private function soonestFirst(Collection $editions): Collection
    {
        return $editions->sortBy(fn (CourseEdition $edition): array => [
            $edition->starts_on?->getTimestamp() ?? PHP_INT_MAX,
            (int) $edition->id,
        ])->values();
    }
}
