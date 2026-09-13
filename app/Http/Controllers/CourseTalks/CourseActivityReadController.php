<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\CourseActivityType;
use App\Http\Controllers\Controller;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
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

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CourseActivity::class);

        $typeFilter = $this->typeFilter($request);

        $activities = CourseActivity::query()
            ->withCount('editions')
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

        return view('course-talks.activities.index', [
            'activities' => $activities->get(),
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
}
