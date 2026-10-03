<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContentGridFilterRequest;
use App\Http\Requests\Content\RescheduleContentItemRequest;
use App\Http\Requests\Content\StoreContentItemRequest;
use App\Http\Requests\Content\UpdateContentItemRequest;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\Setting;
use App\Services\Content\ContentAttachmentService;
use App\Services\Content\ContentItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Content scheduling module — a project's content grid (T6c).
 *
 * Reachability is not decided here: the `contentProject` route binding already
 * answers 404 for a project the user may not reach, before validation or the
 * policy run. On top of that, reads ask the policy for `view` and mutations ask
 * for `update`, so a reachable member without the mutation ability is refused
 * with 403 while still able to read.
 *
 * The listing is always the addressed project's own contents. A child id that
 * belongs to another project is resolved inside the addressed project and
 * therefore answers 404, so no cross-project row can be listed or read by URL.
 */
class ContentItemController extends Controller
{
    public function __construct(
        private readonly ContentItemService $items,
        private readonly ContentAttachmentService $attachments,
    ) {
    }

    public function index(ContentGridFilterRequest $request, ContentProject $contentProject): View
    {
        Gate::authorize('view', $contentProject);

        $filters = $request->filters();

        $contents = $this->items
            ->query($contentProject, $filters)
            ->paginate($this->pageSize())
            ->withQueryString();

        return view('content.items.index', [
            'project' => $contentProject,
            'contents' => $contents,
            'filters' => $filters,
            'catalogOptions' => $this->items->catalogOptions($contentProject),
        ]);
    }

    public function create(ContentProject $contentProject): View
    {
        Gate::authorize('update', $contentProject);

        return view('content.items.create', [
            'project' => $contentProject,
            'item' => null,
            'form' => $this->items->formData($contentProject, null),
            'catalogOptions' => $this->items->catalogOptions($contentProject),
        ]);
    }

    public function store(StoreContentItemRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->items->create($contentProject, $request->validated());

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $item])
            ->with('status', 'Contenido "'.$item->title.'" creado correctamente.');
    }

    public function show(ContentProject $contentProject, string $contentItem): View
    {
        Gate::authorize('view', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem)
            ->load(['pillar', 'topic', 'objectives', 'formats', 'channels', 'attachments']);

        return view('content.items.show', [
            'project' => $contentProject,
            'item' => $item,
            'form' => $this->items->formData($contentProject, $item),
            'attachments' => $item->attachments,
            'attachmentLimits' => $this->attachments->limits(),
        ]);
    }

    public function edit(ContentProject $contentProject, string $contentItem): View
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem)
            ->load(['objectives', 'formats', 'channels']);

        return view('content.items.edit', [
            'project' => $contentProject,
            'item' => $item,
            'form' => $this->items->formData($contentProject, $item),
            'catalogOptions' => $this->items->catalogOptions($contentProject),
        ]);
    }

    public function update(UpdateContentItemRequest $request, ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $item = $this->items->update($item, $request->validated());

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $item])
            ->with('status', 'Contenido "'.$item->title.'" actualizado correctamente.');
    }

    /**
     * Duplicate a content into a new row: same text, selections, custom values
     * and attachments, but no planned instant, the Incompleto status and its own
     * copy of every uploaded file. The source is left untouched (T8a).
     */
    public function duplicate(ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $source = $this->resolveItem($contentProject, $contentItem);
        $copy = $this->items->duplicate($source);

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $copy])
            ->with('status', 'Contenido "'.$source->title.'" duplicado. La copia quedó sin fecha y en estado Incompleto, con sus propios adjuntos.');
    }

    /**
     * Move a content to another Peru date and time, storing the matching UTC
     * instant so it appears under the correct month in the grid (T8a). The
     * submitted revision must still be current; a stale one is refused.
     */
    public function reschedule(RescheduleContentItemRequest $request, ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $item = $this->items->reschedule($item, $request->validated());

        return redirect()
            ->route('content.projects.contents.show', [$contentProject, $item])
            ->with('status', 'Contenido reprogramado para el '.\App\Support\Content\PeruTime::date($item->planned_at).' a las '.\App\Support\Content\PeruTime::toPeru($item->planned_at)->format('H:i').' (hora de Perú).');
    }

    /**
     * Resolve a child content inside the addressed project. A foreign or
     * unknown id is a 404, never a disclosure of another project's row.
     */
    private function resolveItem(ContentProject $project, string $contentItem): ContentItem
    {
        return ContentItem::query()
            ->where('content_project_id', $project->getKey())
            ->findOrFail((int) $contentItem);
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
