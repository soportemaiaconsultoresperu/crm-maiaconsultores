<?php

namespace App\Jobs;

use App\Models\ActivityCalendarLink;
use App\Models\InternalActivityCalendarLink;
use App\Services\GoogleCalendarInternalActivitySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SyncGoogleCalendarInternalActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $internalActivityId)
    {
    }

    public function handle(GoogleCalendarInternalActivitySyncService $sync): void
    {
        $failed = $sync->syncInternalActivity($this->internalActivityId)
            ->first(fn (InternalActivityCalendarLink $link): bool => $link->sync_status === ActivityCalendarLink::STATUS_TEMPORARY_ERROR);

        if ($failed !== null && $this->attempts() < $this->tries) {
            throw new RuntimeException($failed->error_message ?: 'Google Calendar sync failed temporarily.');
        }
    }
}
