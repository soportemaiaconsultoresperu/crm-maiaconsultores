<?php

namespace App\Jobs;

use App\Models\GoogleCalendarChannel;
use App\Services\GoogleCalendarWatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenewGoogleCalendarChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $channelId)
    {
    }

    public function handle(GoogleCalendarWatchService $watch): void
    {
        $channel = GoogleCalendarChannel::query()->find($this->channelId);
        if (! $channel instanceof GoogleCalendarChannel) {
            return;
        }

        $watch->renew($channel);
    }
}
