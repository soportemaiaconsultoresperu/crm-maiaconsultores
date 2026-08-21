<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\DataScopeService;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * B05 Calendar UI (RF-CAL-001..003). The controller is intentionally thin:
 * - DateRange::daysForCalendarView() owns the day/week/month projection.
 * - ActivityService::calendarEvents() owns the scope + filters + eager
 *   loading. The controller only maps query parameters to the projection
 *   inputs and resolves the navigation anchors (prev/next/today).
 *
 * Calendar permission (calendar.view) covers the index; it is granted to
 * vendedor and supervisor in RolesAndPermissionsSeeder. The actual
 * activity list is still owner-scoped by ActivityService::scopeQuery().
 */
class CalendarController extends Controller
{
    public function __construct(
        private readonly ActivityService $activities,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Calendar projection (RF-CAL-001). Default view: month anchored on today.
     * Filters: view (month|week|day|list), anchor (YYYY-MM-DD), owner_id,
     * type_id, subject_type, status.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('calendar.view'), 403);

        $view = $this->resolveView((string) $request->query('view', 'month'));
        $anchor = $this->resolveAnchor((string) $request->query('anchor', ''));

        $filters = array_filter([
            'subject_type' => $request->query('subject_type'),
            'owner_id' => $request->query('owner_id'),
            'status' => $request->query('status'),
        ], fn ($value) => $value !== null && $value !== '');

        // type_id is a controller-level filter: the DateRange query in
        // ActivityService is scope-driven, but the activity-type filter is
        // a UI concern (a calendar page does not need a type filter unless
        // the user explicitly picked one).
        $typeId = $request->query('type_id');
        if ($typeId !== null && $typeId !== '') {
            $filters['type_id'] = (int) $typeId;
        }

        $range = DateRange::daysForCalendarView($view === 'list' ? 'month' : $view, $anchor);
        $events = $this->activities->calendarEvents($request->user(), $range, $filters);

        // Narrow the eager-loaded events to the type filter when it was
        // supplied. ActivityService::calendarEvents does not accept type_id
        // because the type catalog is a UI concern; the controller applies
        // it post-fetch (the list is small per view).
        if (isset($filters['type_id'])) {
            $events = $events->where('type_id', (int) $filters['type_id'])->values();
        }

        $user = $request->user();

        return view('calendar.'.$view, [
            'view' => $view,
            'anchor' => $anchor,
            'range' => $range,
            'events' => $events,
            'prevAnchor' => $this->navigate($view, $anchor, -1),
            'nextAnchor' => $this->navigate($view, $anchor, 1),
            'owners' => $this->ownerOptions($user),
            'types' => ActivityType::query()->where('is_active', true)->orderBy('sort')->get(),
            'filters' => $request->only(['view', 'owner_id', 'type_id', 'subject_type', 'status']),
        ]);
    }

    /**
     * Normalize the requested view name. Unknown values fall back to month
     * because the list view needs a dedicated template and the month grid is
     * the safer default.
     */
    private function resolveView(string $view): string
    {
        $view = strtolower(trim($view));

        if (! in_array($view, ['month', 'week', 'day', 'list'], true)) {
            return 'month';
        }

        return $view;
    }

    /**
     * Parse the anchor as YYYY-MM-DD; invalid/empty inputs default to today.
     * The DateRange projection ignores the hour/minute/second component
     * (the anchor is a date, not a moment).
     */
    private function resolveAnchor(string $anchor): CarbonImmutable
    {
        $anchor = trim($anchor);

        if ($anchor === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $anchor)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * Compute the previous / next anchor for the calendar navigation links.
     * - month: ±1 month, anchored on day 1.
     * - week: ±1 week, anchored on Monday.
     * - day: ±1 day.
     * - list: keep the same anchor (the list view has no temporal nav of
     *   its own; the prev/next links are disabled in the template).
     */
    private function navigate(string $view, CarbonInterface $anchor, int $direction): CarbonImmutable
    {
        return match ($view) {
            'month' => $anchor->startOfMonth()->addMonths($direction),
            'week' => $anchor->startOfWeek(CarbonInterface::MONDAY)->addWeeks($direction),
            'day' => $anchor->addDays($direction),
            'list' => $anchor,
            default => $anchor,
        };
    }

    /**
     * Selectable owners: within the user's visibility scope only.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function ownerOptions(User $user): \Illuminate\Support\Collection
    {
        $visible = $this->scope->visibleOwnerIds($user);

        return User::query()
            ->where('is_active', true)
            ->when($visible !== null, fn ($q) => $q->whereIntegerInRaw('id', $visible))
            ->orderBy('name')
            ->get();
    }
}
