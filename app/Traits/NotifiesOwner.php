<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Avoids self-noise: internal notifications (RF-NOT-001) are only sent
 * when the recipient is not the actor who performed the action.
 */
trait NotifiesOwner
{
    /**
     * Send the notification to the owner unless they are the actor.
     */
    protected static function notifyOwnerUnlessActor(User $owner, User $actor, Notification $notification): void
    {
        if ((int) $owner->id !== (int) $actor->id) {
            $owner->notify($notification);
        }
    }
}
