<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Handles reassignment of a campaign participant's `assigned_to` (the person
 * responsible for the campaign tasks targeting this contact). When the operator
 * chooses to cascade, every still-pending item is also reassigned via
 * `activities.owner_id` (since the activity remains the source of truth for
 * the executor). Completed items are never modified.
 */
class CampaignParticipantReassignService
{
    public function reassign(
        CampaignParticipant $participant,
        User $newAssignee,
        bool $cascadeToPendingItems,
        User $actor,
    ): CampaignParticipant {
        return DB::transaction(function () use ($participant, $newAssignee, $cascadeToPendingItems, $actor) {
            $oldAssignee = $participant->assigned_to;
            $participant->update([
                'assigned_to' => $newAssignee->id,
            ]);

            if ($cascadeToPendingItems) {
                // Update owner_id on the activities that back the pending
                // items of this participant. Completed items are untouched.
                $itemIds = $participant->items()
                    ->whereIn('status', [
                        CampaignActionItem::STATUS_PENDING,
                        CampaignActionItem::STATUS_IN_PROCESS,
                        CampaignActionItem::STATUS_OVERDUE,
                    ])
                    ->pluck('activity_id');

                if ($itemIds->isNotEmpty()) {
                    \App\Models\Activity::query()
                        ->whereIn('id', $itemIds)
                        ->update(['owner_id' => $newAssignee->id]);
                }
            }

            // Audit trail: ActivityLog fires automatically via the model boot
            // for both CampaignParticipant (reassignment) and Activity (when
            // cascaded). The actor is recorded automatically by Spatie.

            return $participant->fresh();
        });
    }
}
