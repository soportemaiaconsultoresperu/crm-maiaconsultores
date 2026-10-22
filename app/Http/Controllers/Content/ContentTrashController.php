<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\Setting;
use App\Services\Content\ContentTrashService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Content scheduling module — a project's trash (T8b).
 *
 * Reachability is not decided here: the `contentProject` route binding already
 * answers 404 for a project the user may not reach, before validation or the
 * policy run. On top of that, reading the trash asks the policy for `view`
 * while trashing, restoring, purging and emptying the trash ask for `trash` —
 * granted to the project's members and refused on an archived project.
 *
 * Ideas live in the same table, so these routes serve both kinds; redirects
 * send an idea back to the ideas section and a content to the grid.
 *
 * A child id is always resolved inside the addressed project. Trashing only
 * accepts a live row; restoring and purging resolve the row with its trashed
 * rows included and delegate the "must be trashed" refusal to
 * ContentTrashService, so neither action can be turned into a shortcut around
 * the trash.
 */
class ContentTrashController extends Controller
{
    public function __construct(private readonly ContentTrashService $trash)
    {
    }

    public function index(ContentProject $contentProject): View
    {
        Gate::authorize('view', $contentProject);

        $contents = $this->trash
            ->deletedQuery($contentProject)
            ->paginate($this->pageSize())
            ->withQueryString();

        return view('content.trash.index', [
            'project' => $contentProject,
            'contents' => $contents,
        ]);
    }

    /**
     * Move a live content to the trash. Nothing reaches the private disk: the
     * row is only marked and its files stay available for a later restore.
     */
    public function destroy(ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('trash', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $this->trash->trash($item);

        if ($item->isIdea()) {
            return redirect()
                ->route('content.projects.ideas.index', $contentProject)
                ->with('status', 'Idea "'.$item->title.'" movida a la papelera. Podrá restaurarla mientras la papelera no se vacíe.');
        }

        return redirect()
            ->route('content.projects.contents.index', $contentProject)
            ->with('status', 'Contenido "'.$item->title.'" movido a la papelera. Podrá restaurarlo mientras la papelera no se vacíe.');
    }

    public function restore(ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('trash', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem, withTrashed: true);
        $this->trash->restore($item);

        if ($item->isIdea()) {
            return redirect()
                ->route('content.projects.ideas.show', [$contentProject, $item])
                ->with('status', 'Idea "'.$item->title.'" restaurada con sus valores.');
        }

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $item])
            ->with('status', 'Contenido "'.$item->title.'" restaurado con sus valores y sus adjuntos.');
    }

    /**
     * "Vaciar papelera": permanently remove every trashed row of THIS project
     * and their files. Other projects' trash and live rows are never touched.
     */
    public function empty(ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('trash', $contentProject);

        $count = $this->trash->emptyTrash($contentProject);

        return redirect()
            ->route('content.projects.contents.trash', $contentProject)
            ->with('status', $count === 0
                ? 'La papelera ya estaba vacía.'
                : 'Papelera vaciada: '.$count.' elemento(s) eliminados definitivamente. Sus archivos ya no se pueden recuperar.');
    }

    public function purge(ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('trash', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem, withTrashed: true);
        $title = (string) $item->title;
        $this->trash->purge($item);

        return redirect()
            ->route('content.projects.contents.trash', $contentProject)
            ->with('status', 'Contenido "'.$title.'" eliminado definitivamente. Sus archivos ya no se pueden recuperar.');
    }

    /**
     * Resolve a child content inside the addressed project. A foreign or
     * unknown id is a 404, never a disclosure of another project's row. With
     * `$withTrashed` the trashed rows are included so restore and purge can
     * find them; a live row is still resolved and then refused by the service.
     */
    private function resolveItem(ContentProject $project, string $contentItem, bool $withTrashed = false): ContentItem
    {
        $query = ContentItem::query()->where('content_project_id', $project->getKey());

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->findOrFail((int) $contentItem);
    }

    /**
     * Page size from the shared setting, with the repository default.
     */
    private function pageSize(): int
    {
        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');

        return max(1, $pageSize ?: 25);
    }
}
