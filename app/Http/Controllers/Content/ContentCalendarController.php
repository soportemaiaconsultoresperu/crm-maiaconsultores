<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentCalendarAnnotationRequest;
use App\Http\Requests\Content\UpdateContentCalendarAnnotationRequest;
use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentCalendarService;
use App\Services\Content\ContentProjectScopeService;
use App\Support\Content\PeruTime;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Content scheduling module — important-dates calendar (T9).
 *
 * Two surfaces, one authorization model:
 *
 * - A project's own calendar. Reachability is not decided here: the
 *   `contentProject` route binding already answers 404 for a project the user
 *   may not reach, so a foreign calendar is never disclosed even by direct URL.
 *   On top of that, reads ask the policy for `view` and annotation mutations ask
 *   for `update`, so a reader without the mutation ability is refused with 403.
 *   Every annotation is resolved inside the addressed project, so a foreign
 *   annotation id is a 404.
 *
 * - The consolidated admin calendar. It is the module's cross-project read and
 *   is guarded by `content_projects.view_all`, answering 403 for a user who
 *   lacks it. The project filter is passed straight to
 *   ContentCalendarService::consolidatedAnnotations(), which intersects it with
 *   the projects the user may see: the filter can only narrow, never widen.
 */
class ContentCalendarController extends Controller
{
    public function __construct(
        private readonly ContentCalendarService $calendar,
        private readonly ContentProjectScopeService $scope,
    ) {
    }

    /**
     * A project's important-dates calendar for one month.
     */
    public function index(Request $request, ContentProject $contentProject): View
    {
        Gate::authorize('view', $contentProject);

        $month = $this->calendar->month((string) $request->query('month', ''));
        $grid = $this->calendar->grid($month);
        $start = $grid['start']->toDateString();
        $end = $grid['end']->toDateString();

        $editing = $this->editingAnnotation($request, $contentProject);

        return view('content.calendar.index', [
            'project' => $contentProject,
            'month' => $month,
            'grid' => $grid,
            'annotations' => $this->calendar->annotationsByDay($contentProject, $start, $end),
            'contents' => $this->calendar->contentsByDay($contentProject, $start, $end),
            'editing' => $editing,
            'formDate' => $this->formDate($request, $grid, $editing),
            'canManage' => Gate::allows('update', $contentProject),
            'canViewConsolidated' => $this->scope->hasCrossProjectView($request->user()),
        ]);
    }

    /**
     * The consolidated calendar across every project the administrator may see,
     * optionally narrowed to one, several or all projects.
     */
    public function consolidated(Request $request): View
    {
        $user = $request->user();

        // The cross-project reading permission the module defines. A 403 here is
        // correct: this surface has no record to hide, it is a permission gate.
        abort_unless($this->scope->hasCrossProjectView($user), 403);

        $month = $this->calendar->month((string) $request->query('month', ''));
        $grid = $this->calendar->grid($month);
        $start = $grid['start']->toDateString();
        $end = $grid['end']->toDateString();
        $selected = $this->selectedProjectIds($request);

        $annotations = $this->calendar->consolidatedAnnotations($user, $selected, $start, $end);

        return view('content.calendar.consolidated', [
            'month' => $month,
            'grid' => $grid,
            'projects' => $this->scope->visibleProjects($user)->orderBy('name')->orderBy('id')->get(),
            'selected' => $selected,
            'annotations' => $this->calendar->groupAnnotationsByDay($annotations),
            'total' => $annotations->count(),
        ]);
    }

    public function store(StoreContentCalendarAnnotationRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $annotation = $this->calendar->createAnnotation($contentProject, $request->validated());

        return redirect()
            ->route('content.projects.calendar', ['contentProject' => $contentProject, 'month' => $this->calendar->monthOf($annotation)])
            ->with('status', 'Fecha importante "'.$annotation->name.'" guardada correctamente.');
    }

    public function update(UpdateContentCalendarAnnotationRequest $request, ContentProject $contentProject, string $annotation): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $record = $this->resolveAnnotation($contentProject, $annotation);
        $this->calendar->updateAnnotation($record, $request->validated());

        return redirect()
            ->route('content.projects.calendar', ['contentProject' => $contentProject, 'month' => $this->calendar->monthOf($record)])
            ->with('status', 'Fecha importante "'.$record->name.'" actualizada correctamente.');
    }

    public function destroy(Request $request, ContentProject $contentProject, string $annotation): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $record = $this->resolveAnnotation($contentProject, $annotation);
        $name = $record->name;
        $month = $this->calendar->monthOf($record);
        $this->calendar->removeAnnotation($record);

        return redirect()
            ->route('content.projects.calendar', ['contentProject' => $contentProject, 'month' => $month])
            ->with('status', 'Fecha importante "'.$name.'" eliminada correctamente.');
    }

    /**
     * Resolve a child annotation inside the addressed project. A foreign or
     * unknown id is a 404, never a disclosure of another project's annotation.
     */
    private function resolveAnnotation(ContentProject $project, string $annotation): ContentCalendarAnnotation
    {
        return ContentCalendarAnnotation::query()
            ->where('content_project_id', $project->getKey())
            ->findOrFail((int) $annotation);
    }

    /**
     * The annotation being edited when the calendar is opened with `?edit=`.
     * It is resolved inside the addressed project, so a foreign id is a 404.
     */
    private function editingAnnotation(Request $request, ContentProject $project): ?ContentCalendarAnnotation
    {
        $id = $request->query('edit');

        if ($id === null || ! is_numeric($id)) {
            return null;
        }

        return $this->resolveAnnotation($project, (string) $id);
    }

    /**
     * The day the form starts on: the annotation being edited, the clicked day,
     * or the first day of the shown month.
     *
     * @param  array{month: \Carbon\CarbonImmutable, start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable, weeks: list<list<\Carbon\CarbonImmutable>>}  $grid
     */
    private function formDate(Request $request, array $grid, ?ContentCalendarAnnotation $editing): string
    {
        if ($editing !== null) {
            return PeruTime::date($editing->annotated_at);
        }

        $date = (string) $request->query('date', '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return $grid['month']->toDateString();
    }

    /**
     * The project ids the consolidated selector asked for. An empty selection
     * means "all visible projects"; the ids are intersected with the visible
     * set by the service, so a crafted id can never widen the view.
     *
     * @return list<int>
     */
    private function selectedProjectIds(Request $request): array
    {
        $raw = $request->query('projects', []);

        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
