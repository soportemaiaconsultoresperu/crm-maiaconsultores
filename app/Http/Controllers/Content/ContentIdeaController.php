<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ConvertContentIdeaRequest;
use App\Http\Requests\Content\StoreContentIdeaRequest;
use App\Http\Requests\Content\UpdateContentIdeaRequest;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\Setting;
use App\Services\Content\ContentItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Content scheduling module — a project's ideas section (T6d).
 *
 * Ideas are a visible section of their own, not a grid: they carry no planned
 * instant and stay out of the content listing until they are converted. The
 * authorization mirrors the contents controller exactly.
 *
 * Reachability is not decided here: the `contentProject` route binding already
 * answers 404 for a project the user may not reach, before validation or the
 * policy run. On top of that, reads ask the policy for `view` and mutations ask
 * for `update`, so a reachable member without the mutation ability is refused
 * with 403 while still able to read.
 *
 * Child ids are resolved inside the addressed project AND as ideas, so a foreign
 * id and an already-converted row both answer 404: an idea that no longer exists
 * can never be converted twice.
 */
class ContentIdeaController extends Controller
{
    public function __construct(private readonly ContentItemService $items)
    {
    }

    public function index(Request $request, ContentProject $contentProject): View
    {
        Gate::authorize('view', $contentProject);

        $ideas = $this->items
            ->ideasQuery($contentProject)
            ->paginate($this->pageSize())
            ->withQueryString();

        return view('content.ideas.index', [
            'project' => $contentProject,
            'ideas' => $ideas,
        ]);
    }

    public function create(ContentProject $contentProject): View
    {
        Gate::authorize('update', $contentProject);

        return view('content.ideas.create', [
            'project' => $contentProject,
            'idea' => null,
            'form' => $this->items->formData($contentProject, null),
            'catalogOptions' => $this->items->catalogOptions($contentProject),
        ]);
    }

    public function store(StoreContentIdeaRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $idea = $this->items->createIdea($contentProject, $request->validated());

        return redirect()
            ->route('content.projects.ideas.show', [$contentProject, $idea])
            ->with('status', 'Idea "'.$idea->title.'" guardada correctamente. Aún no tiene fecha planificada.');
    }

    public function show(ContentProject $contentProject, string $contentIdea): View
    {
        Gate::authorize('view', $contentProject);

        $idea = $this->resolveIdea($contentProject, $contentIdea)
            ->load(['pillar', 'topic', 'objectives', 'formats', 'channels']);

        return view('content.ideas.show', [
            'project' => $contentProject,
            'idea' => $idea,
            'form' => $this->items->formData($contentProject, $idea),
        ]);
    }

    public function edit(ContentProject $contentProject, string $contentIdea): View
    {
        Gate::authorize('update', $contentProject);

        $idea = $this->resolveIdea($contentProject, $contentIdea)
            ->load(['objectives', 'formats', 'channels']);

        return view('content.ideas.edit', [
            'project' => $contentProject,
            'idea' => $idea,
            'form' => $this->items->formData($contentProject, $idea),
            'catalogOptions' => $this->items->catalogOptions($contentProject, $idea),
        ]);
    }

    public function update(UpdateContentIdeaRequest $request, ContentProject $contentProject, string $contentIdea): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $idea = $this->items->updateIdea(
            $this->resolveIdea($contentProject, $contentIdea),
            $request->validated(),
        );

        return redirect()
            ->route('content.projects.ideas.show', [$contentProject, $idea])
            ->with('status', 'Idea "'.$idea->title.'" actualizada correctamente.');
    }

    /**
     * Turn the idea into a planned content, in place and in one transaction. The
     * idea disappears from this section and appears on the grid under the same
     * row id; nothing is duplicated.
     */
    public function convert(ConvertContentIdeaRequest $request, ContentProject $contentProject, string $contentIdea): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $idea = $this->resolveIdea($contentProject, $contentIdea);
        $content = $this->items->convertIdea($idea, $request->validated());

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $content])
            ->with('status', 'Idea "'.$content->title.'" convertida en contenido del cronograma.');
    }

    /**
     * Resolve a child idea inside the addressed project. A foreign id, an
     * unknown id and an already-converted row are all a 404, never a second
     * content and never a disclosure of another project's row.
     */
    private function resolveIdea(ContentProject $project, string $contentIdea): ContentItem
    {
        return ContentItem::query()
            ->where('content_project_id', $project->getKey())
            ->ideas()
            ->findOrFail((int) $contentIdea);
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
