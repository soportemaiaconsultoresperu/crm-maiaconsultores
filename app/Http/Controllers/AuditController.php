<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin audit viewer UI (B08 / RF-USR-007, ADR-008).
 *
 * Read-only surface over spatie/laravel-activitylog. The service owns
 * the filtering and pagination; the controller simply translates HTTP
 * filters into the service call and passes the result to Blade. The
 * detail view shows the full old/new JSON for one row.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Paginated, filterable list of activity_log entries (RF-USR-007).
     * Filters: subject_type, subject_id, user_id (causer), event, dates.
     */
    public function index(Request $request): View
    {
        if (! $request->user()->can('audit.view')) {
            abort(403);
        }

        $filters = [
            'subject_type' => $request->query('subject_type'),
            'subject_id' => $request->query('subject_id'),
            'user_id' => $request->query('user_id'),
            'event' => $request->query('event'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $entries = $this->audit->query($filters, $request->user());

        return view('admin.audit.index', [
            'entries' => $entries,
            'filters' => $filters,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'subjectTypes' => $this->distinctSubjectTypes(),
            'events' => $this->distinctEvents(),
        ]);
    }

    /**
     * Single audit entry with full properties + JSON pretty-printed
     * old/new view.
     */
    public function show(Request $request, Activity $activity): View
    {
        if (! $request->user()->can('audit.view')) {
            abort(403);
        }

        $entry = $this->audit->show($activity);

        return view('admin.audit.show', [
            'entry' => $entry,
            'properties' => $entry->properties ?? collect(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function distinctSubjectTypes(): array
    {
        return Activity::query()
            ->whereNotNull('subject_type')
            ->select('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function distinctEvents(): array
    {
        return Activity::query()
            ->whereNotNull('event')
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->all();
    }
}