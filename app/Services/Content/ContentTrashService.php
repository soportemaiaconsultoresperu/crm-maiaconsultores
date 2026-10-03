<?php

namespace App\Services\Content;

use App\Models\Content\ContentAttachment;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The content trash (T8b): soft retirement, restoration and the explicit,
 * operator-driven purge.
 *
 * This is where the module's permanence rule lives:
 *
 *   - trashing a content soft-deletes ONLY its own row. Every typed custom
 *     value, every catalog pivot and every attachment row — and every file on
 *     the private disk — is left untouched, so a deletion by mistake loses
 *     nothing and can be undone;
 *   - restoring brings the row back with the values, selections and
 *     attachments it already had, because they were never removed;
 *   - purging is the ONLY path in the module that removes a file from the
 *     private disk. It is deliberately reachable only from the explicit purge
 *     action for ONE content that is already in the trash: no scheduled task,
 *     console command, model event or automatic trigger calls it, and it
 *     refuses a row that is not trashed so it can never be turned into a
 *     destructive shortcut.
 */
class ContentTrashService
{
    public function __construct(
        private readonly ContentAttachmentService $attachments = new ContentAttachmentService,
    ) {
    }

    /**
     * The addressed project's trashed contents, newest deletion first.
     *
     * The global `ordered` scope is dropped here because ordering by the
     * planned instant makes no sense on a deletion list; `deleted_at` is the
     * meaningful order. The project is pinned so a foreign row can never leak.
     *
     * @return Builder<ContentItem>
     */
    public function deletedQuery(ContentProject $project): Builder
    {
        return ContentItem::query()
            ->withoutGlobalScope('ordered')
            ->onlyTrashed()
            ->where('content_project_id', $project->getKey())
            ->orderByDesc('deleted_at')
            ->orderByDesc('id');
    }

    /**
     * Move a live content to the trash. Only the row's `deleted_at` changes:
     * its values, selections, attachments and files survive until an explicit
     * purge. A row that is already trashed is refused.
     *
     * @throws ValidationException
     */
    public function trash(ContentItem $item): void
    {
        if ($item->trashed()) {
            throw ValidationException::withMessages([
                'trash' => 'El contenido ya está en la papelera.',
            ]);
        }

        $item->delete();
    }

    /**
     * Restore a trashed content. Its typed values, its catalog selections and
     * its attachments are already intact, so only the row's `deleted_at` is
     * cleared. A row that is not trashed is refused, so this can never be used
     * as a silent no-op shortcut.
     *
     * @throws ValidationException
     */
    public function restore(ContentItem $item): ContentItem
    {
        if (! $item->trashed()) {
            throw ValidationException::withMessages([
                'restore' => 'Solo se puede restaurar un contenido que está en la papelera.',
            ]);
        }

        $item->restore();

        return $item;
    }

    /**
     * Permanently remove a trashed content: every attachment file (the files of
     * retired attachments included), every attachment row, every typed value
     * and its option pivot, the catalog pivots and finally the row itself.
     *
     * This is the module's only user-file removal, and it requires the row to
     * be trashed first; a live content is refused. Everything runs inside one
     * transaction, so a failure leaves the trashed content recoverable rather
     * than half-purged.
     *
     * @throws ValidationException
     */
    public function purge(ContentItem $item): void
    {
        if (! $item->trashed()) {
            throw ValidationException::withMessages([
                'purge' => 'Solo se puede eliminar definitivamente un contenido que está en la papelera.',
            ]);
        }

        DB::transaction(function () use ($item): void {
            $locked = ContentItem::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            $this->purgeAttachments($locked);

            foreach ($locked->fieldValues()->get() as $value) {
                $value->selectedOptions()->detach();
                $value->delete();
            }

            $locked->objectives()->detach();
            $locked->formats()->detach();
            $locked->channels()->detach();

            $locked->forceDelete();
        });
    }

    /**
     * Remove every attachment row and file of a content being purged. Retired
     * rows are included so their files are not orphaned on the private disk.
     */
    private function purgeAttachments(ContentItem $item): void
    {
        $attachments = ContentAttachment::query()
            ->withTrashed()
            ->where('content_item_id', $item->getKey())
            ->get();

        foreach ($attachments as $attachment) {
            $this->attachments->discardStoredFile($attachment->path);
            $attachment->forceDelete();
        }
    }
}
