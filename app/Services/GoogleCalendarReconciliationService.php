<?php

namespace App\Services;

use App\Integrations\Google\GoogleCalendarProvider;
use App\Models\ActivityCalendarLink;
use App\Models\GoogleCalendarChannel;
use Illuminate\Http\Client\RequestException;

class GoogleCalendarReconciliationService
{
    public function __construct(
        private readonly GoogleCalendarProvider $provider,
        private readonly GoogleCalendarActivitySyncService $activitySync,
    ) {}

    /**
     * @return array{checked: int, ignored: int, overwritten: int, missing: int}
     */
    public function reconcile(GoogleCalendarChannel $channel): array
    {
        $counts = ['checked' => 0, 'ignored' => 0, 'overwritten' => 0, 'missing' => 0];
        $account = $channel->integrationAccount()->firstOrFail();
        $this->provider->bindAccount($account);

        ActivityCalendarLink::query()
            ->with('activity')
            ->where('integration_account_id', $account->getKey())
            ->where('provider', GoogleCalendarChannel::PROVIDER)
            ->where('external_calendar_id', $channel->external_calendar_id)
            ->whereNotNull('external_event_id')
            ->orderBy('id')
            ->each(function (ActivityCalendarLink $link) use (&$counts, $channel): void {
                $counts['checked']++;

                try {
                    $event = $this->provider->getEvent($channel->external_calendar_id, (string) $link->external_event_id);
                } catch (RequestException $exception) {
                    if ($exception->response->status() === 404) {
                        $this->markExternalEventMissing($link);
                        $counts['missing']++;

                        return;
                    }

                    throw $exception;
                }

                if (! $this->eventBelongsToLink($event, $link)) {
                    $counts['ignored']++;

                    return;
                }

                if ($link->activity === null) {
                    $counts['ignored']++;

                    return;
                }

                $this->activitySync->syncActivity((int) $link->activity_id, true);
                $counts['overwritten']++;
            });

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventBelongsToLink(array $event, ActivityCalendarLink $link): bool
    {
        $extendedProperties = (array) ($event['extendedProperties'] ?? []);
        $private = (array) ($extendedProperties['private'] ?? []);

        return (string) ($private['crm_instance_id'] ?? '') === (string) config('app.url')
            && (string) ($private['crm_activity_id'] ?? '') === (string) $link->activity_id;
    }

    private function markExternalEventMissing(ActivityCalendarLink $link): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_EXTERNAL_EVENT_MISSING,
            'error_class' => 'GoogleCalendarEventNotFound',
            'error_message' => 'The linked Google Calendar event no longer exists.',
            'last_attempt_at' => now(),
        ])->save();
    }
}
