<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Activities follow the owner scope (ADR-012). Visibility of the activity
 * also implies visibility of its subject; the subject policy governs
 * module entry.
 */
class ActivityPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'activities';
    }

    /**
     * @param  Activity  $record
     */
    protected function ownerId(Model $record): ?int
    {
        return $record->owner_id;
    }
}
