<?php

namespace App\Console\Commands;

use App\Models\CampaignRun;
use App\Services\CampaignMetricsService;
use Illuminate\Console\Command;

/**
 * Recomputes progress_cache on every active campaign run. Runs nightly.
 */
class RecomputeCampaignKpis extends Command
{
    protected $signature = 'campaign:recompute-kpis';
    protected $description = 'Recalcula progress_cache en todas las ejecuciones activas de campañas.';

    public function handle(CampaignMetricsService $metrics): int
    {
        $runs = CampaignRun::query()
            ->whereIn('status', [
                CampaignRun::STATUS_DRAFT,
                CampaignRun::STATUS_SCHEDULED,
                CampaignRun::STATUS_RUNNING,
                CampaignRun::STATUS_PAUSED,
            ])
            ->get();

        foreach ($runs as $run) {
            $metrics->recomputeCache($run);
        }

        $this->info("KPIs recalculados para {$runs->count()} ejecuciones.");

        return self::SUCCESS;
    }
}
