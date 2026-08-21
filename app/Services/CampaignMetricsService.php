<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\CampaignRun;

class CampaignMetricsService
{
    /**
     * Compute KPIs from the current data (not from cached progress_cache).
     * Safe to call repeatedly; the only "expensive" part is the COUNT queries
     * which are indexed by `campaign_action_items(run_id, status)`.
     *
     * @return array<string, int>
     */
    public function compute(CampaignRun $run): array
    {
        $items = CampaignActionItem::query()
            ->where('run_id', $run->id)
            ->whereNull('deleted_at');

        $total = (clone $items)->count();
        $pending = (clone $items)->where('status', CampaignActionItem::STATUS_PENDING)->count();
        $inProcess = (clone $items)->where('status', CampaignActionItem::STATUS_IN_PROCESS)->count();
        $completed = (clone $items)->where('status', CampaignActionItem::STATUS_COMPLETED)->count();
        $overdue = (clone $items)->where('status', CampaignActionItem::STATUS_OVERDUE)->count();
        $cancelled = (clone $items)->where('status', CampaignActionItem::STATUS_CANCELLED)->count();
        $notApplicable = (clone $items)->where('status', CampaignActionItem::STATUS_NOT_APPLICABLE)->count();

        $denominator = $total - $cancelled - $notApplicable;
        $progress = $denominator > 0
            ? (int) round($completed / $denominator * 100)
            : 0;

        return [
            'total' => $total,
            'pending' => $pending,
            'in_process' => $inProcess,
            'completed' => $completed,
            'overdue' => $overdue,
            'cancelled' => $cancelled,
            'not_applicable' => $notApplicable,
            'progress' => $progress,
        ];
    }

    /**
     * Recompute and persist into campaign_runs.progress_cache.
     *
     * @return array<string, int>
     */
    public function recomputeCache(CampaignRun $run): array
    {
        $metrics = $this->compute($run);
        $run->update(['progress_cache' => $metrics]);
        return $metrics;
    }
}
