<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Allows a supervisor (with `campaigns.override_completion`) to reopen an item
 * marked as completed (e.g. registered by mistake). The full old/new values
 * are recorded in Spatie ActivityLog automatically by the model boot hooks.
 */
class CampaignOverrideService
{
    public function reopenCompleted(CampaignActionItem $item, string $reason, User $actor): CampaignActionItem
    {
        if ($item->status !== CampaignActionItem::STATUS_COMPLETED) {
            throw new \InvalidArgumentException(
                "Solo se pueden reabrir items en estado completed (actual: {$item->status})."
            );
        }

        return DB::transaction(function () use ($item, $reason, $actor) {
            $item->update([
                'status' => CampaignActionItem::STATUS_PENDING,
                'executed_at' => null,
                'completed_by' => null,
                // result, contact_response and observations are preserved as
                // historical context — Spatie ActivityLog captures the change
                // with old/new values for auditability.
            ]);
            // The reason is recorded through ActivityLog automatically.
            activity()
                ->performedOn($item)
                ->causedBy($actor)
                ->withProperties([
                    'reason' => $reason,
                    'previous_status' => 'completed',
                ])
                ->event('campaign-item-reopened')
                ->log("Item de campaña reabierto por {$actor->name}");
            return $item->fresh();
        });
    }
}
