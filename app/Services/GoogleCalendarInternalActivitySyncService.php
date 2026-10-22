<?php

namespace App\Services;

use App\Integrations\Dto\CalendarEventDto;
use App\Integrations\Google\GoogleCalendarProvider;
use App\Jobs\SyncGoogleCalendarInternalActivity;
use App\Models\ActivityCalendarLink;
use App\Models\IntegrationAccount;
use App\Models\InternalActivity;
use App\Models\InternalActivityCalendarLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pushes internal activities to the Google Calendar of the creator and of every
 * participant with a connected account (odd/tasks/internal-activities.md, T4: S7).
 * Account lookup and error handling follow {@see GoogleCalendarActivitySyncService}.
 */
class GoogleCalendarInternalActivitySyncService
{
    private const PROVIDER = 'google';
    private const DEFAULT_CALENDAR_ID = 'primary';
    private const DEFAULT_DURATION_MINUTES = 60;
    private const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** @var array<string, string> */
    private const RRULE_FREQUENCIES = [
        'daily' => 'DAILY',
        'weekly' => 'WEEKLY',
        'monthly' => 'MONTHLY',
    ];

    public function __construct(
        private readonly GoogleCalendarProvider $provider,
    ) {}

    public function queueInternalActivity(InternalActivity $activity): void
    {
        $hasConnectedTarget = collect($this->targetUserIds($activity))
            ->contains(fn (int $userId): bool => $this->calendarAccountForOwner($userId) !== null);

        if (! $hasConnectedTarget && ! $activity->calendarLinks()->exists()) {
            return;
        }

        SyncGoogleCalendarInternalActivity::dispatch((int) $activity->getKey())->afterCommit();
    }

    /**
     * @return Collection<int, InternalActivityCalendarLink> links still pending attention
     */
    public function syncInternalActivity(int $internalActivityId): Collection
    {
        /** @var InternalActivity|null $activity */
        $activity = InternalActivity::withTrashed()->find($internalActivityId);
        if ($activity === null) {
            return collect();
        }

        $targetIds = $this->targetUserIds($activity);
        $links = collect();

        foreach ($activity->calendarLinks()->get() as $staleLink) {
            if (! in_array((int) $staleLink->user_id, $targetIds, true)) {
                $remaining = $this->removeLink($staleLink);
                if ($remaining !== null) {
                    $links->push($remaining);
                }
            }
        }

        foreach ($targetIds as $userId) {
            $account = $this->calendarAccountForOwner($userId);
            if ($account !== null) {
                $links->push($this->syncLink($activity, $userId, $account));
            }
        }

        return $links;
    }

    /**
     * Creator and participants; nobody once the activity is deleted.
     *
     * @return list<int>
     */
    private function targetUserIds(InternalActivity $activity): array
    {
        if ($activity->trashed()) {
            return [];
        }

        $ids = $activity->participants()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $ids[] = (int) $activity->created_by;

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    private function syncLink(InternalActivity $activity, int $userId, IntegrationAccount $account): InternalActivityCalendarLink
    {
        $link = $this->linkFor($activity, $userId, $account, $this->calendarIdFor($account));
        $syncHash = $this->syncHashFor($activity);

        if ($link->sync_status === ActivityCalendarLink::STATUS_SYNCED
            && $link->sync_hash === $syncHash
            && $link->external_event_id !== null) {
            return $link;
        }

        $this->markSyncing($link);

        try {
            $this->provider->bindAccount($account);
            $dto = $this->dtoFor($activity);
            $ref = $link->external_event_id === null
                ? $this->provider->createEvent($dto)
                : $this->provider->updateEvent($link->external_event_id, $dto);

            $link->forceFill([
                'external_event_id' => $ref->externalEventId,
                'sync_hash' => $syncHash,
                'sync_status' => ActivityCalendarLink::STATUS_SYNCED,
                'last_synced_at' => now(),
                'error_class' => null,
                'error_message' => null,
            ])->save();
        } catch (RequestException $exception) {
            $this->markHttpFailure($link, $exception);
        } catch (ConnectionException $exception) {
            $this->markTemporaryFailure($link, $exception);
        } catch (Throwable $exception) {
            $this->markPermanentFailure($link, $exception);
        }

        return $link->refresh();
    }

    /**
     * Delete the remote event and the link. Returns the link when it has to
     * stay (unreachable account or failed delete), null when it is gone.
     */
    private function removeLink(InternalActivityCalendarLink $link): ?InternalActivityCalendarLink
    {
        if ($link->external_event_id === null) {
            $link->delete();

            return null;
        }

        $account = $link->integrationAccount;
        if ($account === null || ! $account->is_active) {
            $link->forceFill([
                'sync_status' => ActivityCalendarLink::STATUS_NOT_SYNCABLE,
                'last_attempt_at' => now(),
            ])->save();

            return $link;
        }

        $this->markSyncing($link);

        try {
            $this->provider->bindAccount($account)->deleteEvent($link->external_event_id);
            $link->delete();

            return null;
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [404, 410], true)) {
                $link->delete();

                return null;
            }
            $this->markHttpFailure($link, $exception);
        } catch (ConnectionException $exception) {
            $this->markTemporaryFailure($link, $exception);
        } catch (Throwable $exception) {
            $this->markPermanentFailure($link, $exception);
        }

        return $link->refresh();
    }

    private function linkFor(InternalActivity $activity, int $userId, IntegrationAccount $account, string $calendarId): InternalActivityCalendarLink
    {
        return DB::transaction(function () use ($activity, $userId, $account, $calendarId): InternalActivityCalendarLink {
            /** @var InternalActivityCalendarLink $link */
            $link = InternalActivityCalendarLink::query()->firstOrCreate([
                'internal_activity_id' => (int) $activity->getKey(),
                'integration_account_id' => (int) $account->getKey(),
                'external_calendar_id' => $calendarId,
            ], [
                'user_id' => $userId,
                'provider' => self::PROVIDER,
                'sync_status' => ActivityCalendarLink::STATUS_PENDING,
            ]);

            return $link->refresh();
        });
    }

    private function markSyncing(InternalActivityCalendarLink $link): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_SYNCING,
            'last_attempt_at' => now(),
            'error_class' => null,
            'error_message' => null,
        ])->save();
    }

    private function markHttpFailure(InternalActivityCalendarLink $link, RequestException $exception): void
    {
        $status = $exception->response->status();

        if ($status === 404 && $link->external_event_id !== null) {
            $link->forceFill([
                'sync_status' => ActivityCalendarLink::STATUS_EXTERNAL_EVENT_MISSING,
                'error_class' => 'GoogleCalendarEventNotFound',
                'error_message' => 'The linked Google Calendar event no longer exists.',
            ])->save();

            return;
        }

        if ($status === 429 || $status >= 500) {
            $this->markTemporaryFailure($link, $exception);

            return;
        }

        $this->markPermanentFailure($link, $exception);
    }

    private function markTemporaryFailure(InternalActivityCalendarLink $link, Throwable $exception): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_TEMPORARY_ERROR,
            'error_class' => class_basename($exception),
            'error_message' => $this->safeMessage($exception),
        ])->save();
    }

    private function markPermanentFailure(InternalActivityCalendarLink $link, Throwable $exception): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_FAILED,
            'error_class' => class_basename($exception),
            'error_message' => $this->safeMessage($exception),
        ])->save();
    }

    private function calendarAccountForOwner(int $ownerId): ?IntegrationAccount
    {
        if ($ownerId <= 0) {
            return null;
        }

        return IntegrationAccount::query()
            ->active()
            ->where('provider', self::PROVIDER)
            ->where('owner_id', $ownerId)
            ->get()
            ->first(function (IntegrationAccount $account): bool {
                $config = (array) ($account->config_json ?? []);
                $services = (array) ($config['services'] ?? []);
                $scopes = array_values(array_filter((array) ($account->scopes ?? []), 'is_string'));

                return (bool) ($services['calendar'] ?? false)
                    && in_array(self::CALENDAR_SCOPE, $scopes, true);
            });
    }

    private function calendarIdFor(IntegrationAccount $account): string
    {
        $config = (array) ($account->config_json ?? []);
        $calendar = (array) ($config['calendar'] ?? []);
        $calendarId = (string) ($calendar['default_calendar_id'] ?? self::DEFAULT_CALENDAR_ID);

        return $calendarId !== '' ? $calendarId : self::DEFAULT_CALENDAR_ID;
    }

    private function dtoFor(InternalActivity $activity): CalendarEventDto
    {
        $timezone = $this->timezoneFor($activity);
        $startsAt = $this->wallClock($activity->starts_at, $timezone);
        $endsAt = $activity->ends_at !== null
            ? $this->wallClock($activity->ends_at, $timezone)
            : $startsAt->copy()->addMinutes(self::DEFAULT_DURATION_MINUTES);
        $description = trim(implode("\n\n", array_filter([
            trim((string) $activity->description),
            'Actividad interna CRM: '.$this->urlFor($activity),
        ])));

        return new CalendarEventDto(
            summary: $activity->title,
            description: $description,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            location: $activity->location,
            metadata: [
                'crm_instance_id' => (string) config('app.url'),
                'crm_internal_activity_id' => (string) $activity->getKey(),
                'crm_internal_activity_url' => $this->urlFor($activity),
                'google_event_status' => 'confirmed',
            ],
            recurrence: $this->recurrenceFor($activity),
        );
    }

    /**
     * One RRULE for the whole series, ending at the end of the last day (UTC).
     *
     * @return list<string>|null
     */
    private function recurrenceFor(InternalActivity $activity): ?array
    {
        $frequency = self::RRULE_FREQUENCIES[$activity->recurrence_frequency ?? ''] ?? null;
        if ($frequency === null || $activity->recurrence_until === null) {
            return null;
        }

        return ['RRULE:FREQ='.$frequency.';UNTIL='.$activity->recurrence_until->format('Ymd').'T235959Z'];
    }

    private function wallClock(\DateTimeInterface $value, string $timezone): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $timezone);
    }

    private function urlFor(InternalActivity $activity): string
    {
        return rtrim((string) config('app.url'), '/').'/internal-activities/'.$activity->getKey();
    }

    private function timezoneFor(InternalActivity $activity): string
    {
        $creatorTimezone = $activity->creator?->getAttribute('timezone');

        foreach ([$creatorTimezone, config('company.timezone'), config('app.timezone'), 'UTC'] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private function syncHashFor(InternalActivity $activity): string
    {
        return hash('sha256', json_encode([
            'id' => $activity->getKey(),
            'title' => $activity->title,
            'description' => $activity->description,
            'location' => $activity->location,
            'starts_at' => optional($activity->starts_at)->toIso8601String(),
            'ends_at' => optional($activity->ends_at)->toIso8601String(),
            'recurrence' => $this->recurrenceFor($activity),
            'timezone' => $this->timezoneFor($activity),
            'url' => $this->urlFor($activity),
        ], JSON_THROW_ON_ERROR));
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return mb_substr($message !== '' ? $message : class_basename($exception), 0, 500);
    }
}
