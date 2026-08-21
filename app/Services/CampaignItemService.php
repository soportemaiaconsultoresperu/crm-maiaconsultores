<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\User;
use InvalidArgumentException;

class CampaignItemService
{
    public function markInProcess(CampaignActionItem $item, User $actor): CampaignActionItem
    {
        if ($item->status !== CampaignActionItem::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Solo se pueden iniciar items en estado pending (actual: {$item->status})."
            );
        }
        $item->update(['status' => CampaignActionItem::STATUS_IN_PROCESS]);
        return $item->fresh();
    }

    public function markRealized(
        CampaignActionItem $item,
        string $result,
        ?string $contactResponse,
        ?string $observations,
        User $actor,
    ): CampaignActionItem {
        if (! in_array($item->status, [
            CampaignActionItem::STATUS_PENDING,
            CampaignActionItem::STATUS_IN_PROCESS,
            CampaignActionItem::STATUS_OVERDUE,
        ], true)) {
            throw new InvalidArgumentException(
                "No se puede marcar como realizada un item en estado {$item->status}."
            );
        }
        $item->update([
            'status' => CampaignActionItem::STATUS_COMPLETED,
            'executed_at' => now(),
            'completed_by' => $actor->id,
            'result' => $result,
            'contact_response' => $contactResponse,
            'observations' => $observations,
        ]);
        return $item->fresh();
    }

    public function cancel(CampaignActionItem $item, string $reason, User $actor): CampaignActionItem
    {
        if ($item->status === CampaignActionItem::STATUS_COMPLETED) {
            throw new InvalidArgumentException(
                'Para reabrir un item completado use campaigns.override_completion.'
            );
        }
        $item->update([
            'status' => CampaignActionItem::STATUS_CANCELLED,
            'cancellation_reason' => $reason,
        ]);
        return $item->fresh();
    }

    public function markNotApplicable(CampaignActionItem $item, string $reason, User $actor): CampaignActionItem
    {
        if ($item->status === CampaignActionItem::STATUS_COMPLETED) {
            throw new InvalidArgumentException(
                'Para reabrir un item completado use campaigns.override_completion.'
            );
        }
        $item->update([
            'status' => CampaignActionItem::STATUS_NOT_APPLICABLE,
            'not_applicable_reason' => $reason,
        ]);
        return $item->fresh();
    }

    /**
     * Save the editable metadata fields of an item (contact_response, observations,
     * next_action_at, next_action_notes, result).
     */
    public function updateMetadata(CampaignActionItem $item, array $data): CampaignActionItem
    {
        $allowed = ['contact_response', 'observations', 'next_action_at', 'next_action_notes', 'result'];
        $update = array_intersect_key($data, array_flip($allowed));
        $item->update($update);
        return $item->fresh();
    }
}
