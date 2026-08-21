<?php

namespace App\Services;

use App\Models\CampaignActionItem;
use App\Models\CampaignItemReschedule;
use App\Models\CampaignRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CampaignRescheduleService
{
    private const ALLOWED_STATUSES = [
        CampaignActionItem::STATUS_PENDING,
        CampaignActionItem::STATUS_IN_PROCESS,
        CampaignActionItem::STATUS_OVERDUE,
    ];

    public function rescheduleIndividual(
        CampaignActionItem $item,
        string $newScheduledAt,
        string $reason,
        User $actor,
    ): CampaignActionItem {
        if (! in_array($item->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "Solo se pueden reprogramar items en estado pending/in_process/overdue (actual: {$item->status})."
            );
        }

        $new = Carbon::parse($newScheduledAt);
        if ($new->isPast()) {
            throw new InvalidArgumentException('La nueva fecha debe ser futura.');
        }

        return DB::transaction(function () use ($item, $new, $reason, $actor) {
            $old = $item->scheduled_at;

            CampaignItemReschedule::query()->create([
                'item_id' => $item->id,
                'old_scheduled_at' => $old,
                'new_scheduled_at' => $new,
                'reason' => $reason,
                'rescheduled_by' => $actor->id,
                'rescheduled_at' => now(),
                'scope' => CampaignItemReschedule::SCOPE_INDIVIDUAL,
                'preserved_individual' => false,
            ]);

            $item->update([
                'scheduled_at' => $new,
                'reschedule_count' => $item->reschedule_count + 1,
                'last_rescheduled_at' => now(),
                'status' => CampaignActionItem::STATUS_PENDING,
            ]);

            return $item->fresh();
        });
    }

    /**
     * Global reschedule of a run. $strategy is an array of item_id => 'recalc'|'preserve'.
     * 'recalc' (default) applies new_starts_at + day_offset + scheduled_time.
     * 'preserve' keeps the item's current scheduled_at untouched (still logged in history).
     */
    public function rescheduleAll(
        CampaignRun $run,
        string $newStartsAt,
        string $reason,
        User $actor,
        array $strategy = [],
    ): int {
        if (! in_array($run->status, [CampaignRun::STATUS_RUNNING, CampaignRun::STATUS_PAUSED], true)) {
            throw new InvalidArgumentException(
                "Solo se pueden reprogramar ejecuciones en estado running o paused (actual: {$run->status})."
            );
        }

        return DB::transaction(function () use ($run, $newStartsAt, $reason, $actor, $strategy) {
            $newStart = Carbon::parse($newStartsAt);
            if ($newStart->isPast()) {
                throw new InvalidArgumentException('La nueva fecha de inicio debe ser futura.');
            }

            $items = $run->items()->whereIn('status', self::ALLOWED_STATUSES)->get();
            $count = 0;

            foreach ($items as $item) {
                $action = $strategy[$item->id] ?? 'recalc';

                if ($action === 'preserve') {
                    // Still log the decision for the audit trail.
                    CampaignItemReschedule::query()->create([
                        'item_id' => $item->id,
                        'old_scheduled_at' => $item->scheduled_at,
                        'new_scheduled_at' => $item->scheduled_at,
                        'reason' => $reason,
                        'rescheduled_by' => $actor->id,
                        'rescheduled_at' => now(),
                        'scope' => CampaignItemReschedule::SCOPE_GLOBAL,
                        'preserved_individual' => true,
                    ]);
                    $item->update([
                        'reschedule_count' => $item->reschedule_count + 1,
                        'last_rescheduled_at' => now(),
                    ]);
                    $count++;
                    continue;
                }

                // Recalc.
                $step = $item->step;
                $newScheduled = Carbon::parse($newStart)
                    ->addDays($step->day_offset)
                    ->setTimeFromTimeString($step->scheduled_time ?? '09:00:00');

                CampaignItemReschedule::query()->create([
                    'item_id' => $item->id,
                    'old_scheduled_at' => $item->scheduled_at,
                    'new_scheduled_at' => $newScheduled,
                    'reason' => $reason,
                    'rescheduled_by' => $actor->id,
                    'rescheduled_at' => now(),
                    'scope' => CampaignItemReschedule::SCOPE_GLOBAL,
                    'preserved_individual' => false,
                ]);

                $item->update([
                    'scheduled_at' => $newScheduled,
                    'reschedule_count' => $item->reschedule_count + 1,
                    'last_rescheduled_at' => now(),
                    'status' => CampaignActionItem::STATUS_PENDING,
                ]);
                $count++;
            }

            $run->update(['starts_at' => $newStart]);
            return $count;
        });
    }
}
