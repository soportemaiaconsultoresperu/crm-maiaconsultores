<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Documents inherit the data scope of the record they are attached to
 * (docable). If the subject has no owner concept, only users with
 * documents.view.any may see it.
 */
class DocumentPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'documents';
    }

    /**
     * Upload capability gates off `documents.upload` instead of the default
     * `{module}.create`. The "create" verb in CRUD does not fit the
     * file-upload lifecycle; the rest of the policy stack still applies.
     */
    public function create(User $user): bool
    {
        return $user->can('documents.upload');
    }

    /**
     * Delete: must hold `documents.delete`. Admin scope is checked
     * independently through DocumentService::canDelete when the uploader
     * is not the actor.
     */
    public function delete(User $user, Model $record): bool
    {
        return $user->can('documents.delete')
            && $this->withinScope($user, $record);
    }

    /**
     * @param  Document  $record
     */
    protected function ownerId(Model $record): ?int
    {
        $subject = $record->docable;

        if ($subject === null) {
            return null;
        }

        return $subject->owner_id
            ?? $subject->customer?->owner_id;
    }

    /**
     * Download requires the download permission on top of visibility
     * (private disk, ADR-011).
     */
    public function download(User $user, Model $record): bool
    {
        return $user->can('documents.download')
            && $this->withinScope($user, $record);
    }
}
