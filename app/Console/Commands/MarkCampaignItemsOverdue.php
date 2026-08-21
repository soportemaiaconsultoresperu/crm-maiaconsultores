<?php

namespace App\Console\Commands;

use App\Models\CampaignActionItem;
use Illuminate\Console\Command;

/**
 * Marks campaign action items as `overdue` when their `scheduled_at` is in the
 * past and they are still pending/in-process. Runs every 15 minutes.
 *
 * Important: this command only touches campaign_action_items (and by extension
 * the campaign module). The general Activity module manages its own
 * "overdue" lifecycle and must not be affected.
 */
class MarkCampaignItemsOverdue extends Command
{
    protected $signature = 'campaign:mark-overdue';
    protected $description = 'Marca como overdue los items de campaña cuya scheduled_at ya pasó.';

    public function handle(): int
    {
        $cutoff = now();

        $count = CampaignActionItem::query()
            ->whereIn('status', [
                CampaignActionItem::STATUS_PENDING,
                CampaignActionItem::STATUS_IN_PROCESS,
            ])
            ->where('scheduled_at', '<', $cutoff)
            ->update(['status' => CampaignActionItem::STATUS_OVERDUE]);

        $this->info("Items marcados como overdue: {$count}");

        return self::SUCCESS;
    }
}
