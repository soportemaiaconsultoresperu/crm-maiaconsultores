<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ReorderContentAttachmentRequest;
use App\Http\Requests\Content\StoreContentAttachmentLinkRequest;
use App\Http\Requests\Content\StoreContentAttachmentRequest;
use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Content scheduling module — a content's media attachments (T7).
 *
 * Reachability is not decided here: the `contentProject` route binding already
 * answers 404 for a project the user may not reach, before validation or the
 * policy run. On top of that, the download asks the policy for `view`, every
 * mutation asks for `update` and restoring a retired attachment asks for
 * `trash` (like restoring a content), so a reachable member without the
 * mutation ability is refused with 403 while still able to read.
 *
 * Child ids are resolved inside the addressed project AND content, so an
 * attachment of another project (or of another content) is a 404 and can never
 * be fetched, reordered or retired through a foreign URL.
 *
 * Nothing is served from a public path: an upload is streamed only by
 * `download()` through the private disk. Retiring only soft-deletes the row and
 * leaves the file on disk, so `restore()` brings the same attachment back.
 */
class ContentAttachmentController extends Controller
{
    public function __construct(private readonly ContentAttachmentService $attachments)
    {
    }

    public function store(StoreContentAttachmentRequest $request, ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $attachment = $this->attachments->upload($item, $request->file('file'));

        return $this->back($contentProject, $item, 'Archivo "'.$attachment->original_name.'" adjuntado correctamente.');
    }

    public function storeLink(StoreContentAttachmentLinkRequest $request, ContentProject $contentProject, string $contentItem): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $this->attachments->attachLink($item, (string) $request->validated('url'));

        return $this->back($contentProject, $item, 'Enlace adjuntado correctamente.');
    }

    public function reorder(ReorderContentAttachmentRequest $request, ContentProject $contentProject, string $contentItem, string $attachment): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $row = $this->resolveAttachment($item, $attachment);
        $this->attachments->reorder($row, (int) $request->validated('sort'));

        return $this->back($contentProject, $item, 'Orden del adjunto actualizado correctamente.');
    }

    public function retire(Request $request, ContentProject $contentProject, string $contentItem, string $attachment): RedirectResponse
    {
        Gate::authorize('update', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $this->resolveAttachment($item, $attachment)->retire();

        return $this->back($contentProject, $item, 'Adjunto retirado. El archivo se conserva y puede recuperarlo con "Restaurar adjunto" en la lista de adjuntos retirados.');
    }

    public function restore(ContentProject $contentProject, string $contentItem, string $attachment): RedirectResponse
    {
        Gate::authorize('trash', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $row = ContentAttachment::query()
            ->onlyTrashed()
            ->where('content_item_id', $item->getKey())
            ->findOrFail((int) $attachment);
        $this->attachments->restore($item, $row);

        return $this->back($contentProject, $item, 'Adjunto restaurado correctamente.');
    }

    public function download(ContentProject $contentProject, string $contentItem, string $attachment): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $contentProject);

        $item = $this->resolveItem($contentProject, $contentItem);
        $row = $this->resolveAttachment($item, $attachment, withRetired: true);

        return $this->attachments->download($row);
    }

    /**
     * Resolve a child content inside the addressed project. A foreign or
     * unknown id is a 404, never a disclosure of another project's row, and so
     * is an idea id: attachments belong to planned contents.
     */
    private function resolveItem(ContentProject $project, string $contentItem): ContentItem
    {
        return ContentItem::query()
            ->contents()
            ->where('content_project_id', $project->getKey())
            ->findOrFail((int) $contentItem);
    }

    /**
     * Resolve an attachment inside the addressed content. A foreign id, an
     * unknown id and (for mutations) a retired row are all a 404.
     */
    private function resolveAttachment(ContentItem $item, string $attachment, bool $withRetired = false): ContentAttachment
    {
        $query = ContentAttachment::query()->where('content_item_id', $item->getKey());

        if ($withRetired) {
            $query->withTrashed();
        }

        return $query->findOrFail((int) $attachment);
    }

    private function back(ContentProject $project, ContentItem $item, string $message): RedirectResponse
    {
        return redirect()
            ->route('content.projects.contents.show', [$project, $item])
            ->with('status', $message);
    }
}
