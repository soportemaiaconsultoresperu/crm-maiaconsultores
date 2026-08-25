<?php

namespace App\Jobs;

use App\Models\ActivityCalendarLink;
use App\Services\GoogleCalendarActivitySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncGoogleCalendarActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $activityId)
    {
    }

    public function handle(GoogleCalendarActivitySyncService $sync): void
    {
        $activity = \App\Models\Activity::withTrashed()->find($this->activityId);
        if ($activity !== null && app(\App\Services\DemoData\DemoDataGuard::class)->isActivityDemo($activity)) {
            return;
        }

        $link = $sync->syncActivity($this->activityId);

        if ($link instanceof ActivityCalendarLink
            && $link->sync_status === ActivityCalendarLink::STATUS_TEMPORARY_ERROR
            && $this->attempts() < $this->tries) {
            throw new RuntimeException($link->error_message ?: 'Google Calendar sync failed temporarily.');
        }
    }
}
