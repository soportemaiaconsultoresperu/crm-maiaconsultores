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
            // Filtering by activity type is a QUERY concern: the requested value is
            // compared against the column the enum casts. A value outside the
            // vocabulary therefore matches nothing, which is the documented outcome
            // — the list is narrowed to nothing rather than silently showing every
            // activity as if the filter had been applied. The view reports it.
            $activities->where('type', $typeFilter['value']);
        }

        return view('course-talks.activities.index', [
            'activities' => $activities->get(),
            'typeOptions' => $this->typeOptions(),
            'activeType' => $typeFilter['value'],
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
     *  - a scalar: applied as it came in, and flagged `unknown` when it is outside
     *    the vocabulary, so an unknown value narrows the list to nothing AND says so;
     *  - a non-scalar: not a filter at all, so it is dropped before it can reach a
     *    query (an array in a `where` is the 500 this locks out) and flagged
     *    `discarded`, with the list left complete and the value reported.
     *
     * @return array{value: string|null, warning: string|null}
     */
    private function typeFilter(Request $request): array
    {
        $raw = $request->query(self::TYPE_FILTER);

        if (is_array($raw)) {
            return ['value' => null, 'warning' => 'discarded'];
        }

        if (! is_scalar($raw)) {
            return ['value' => null, 'warning' => null];
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return ['value' => null, 'warning' => null];
        }

        return [
            'value' => $value,
            'warning' => CourseActivityType::tryFrom($value) === null ? 'unknown' : null,
        ];
    }
}
