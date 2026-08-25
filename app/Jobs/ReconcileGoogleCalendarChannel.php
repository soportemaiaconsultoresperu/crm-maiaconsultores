<?php

namespace App\Jobs;

use App\Models\GoogleCalendarChannel;
use App\Services\GoogleCalendarReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileGoogleCalendarChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $channelId)
    {
    }

    public function handle(GoogleCalendarReconciliationService $reconciliation): void
    {
        $channel = GoogleCalendarChannel::query()->find($this->channelId);
        if (! $channel instanceof GoogleCalendarChannel) {
            return;
        }

        $reconciliation->reconcile($channel);
    }
}
