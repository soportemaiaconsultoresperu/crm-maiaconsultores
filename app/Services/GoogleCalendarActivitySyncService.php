<?php

namespace App\Services;

use App\Integrations\Dto\CalendarEventDto;
use App\Integrations\Google\GoogleCalendarProvider;
use App\Jobs\SyncGoogleCalendarActivity;
use App\Models\Activity;
use App\Models\ActivityCalendarLink;
use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class GoogleCalendarActivitySyncService
{
    private const PROVIDER = 'google';
    private const DEFAULT_CALENDAR_ID = 'primary';
    private const DEFAULT_DURATION_MINUTES = 60;
    private const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function __construct(
        private readonly GoogleCalendarProvider $provider,
    ) {}

    public function queueActivity(Activity $activity): void
    {
        if (app(\App\Services\DemoData\DemoDataGuard::class)->isActivityDemo($activity)) {
            return;
        }

        if ($this->calendarAccountForOwner((int) $activity->owner_id) === null
            && ! $activity->calendarLinks()->exists()) {
            return;
        }

        SyncGoogleCalendarActivity::dispatch((int) $activity->getKey())->afterCommit();
    }

    public function syncActivity(int $activityId, bool $force = false): ?ActivityCalendarLink
    {
        /** @var Activity|null $activity */
        $activity = Activity::withTrashed()->find($activityId);
        if ($activity === null) {
            return null;
        }

        if (app(\App\Services\DemoData\DemoDataGuard::class)->isActivityDemo($activity)) {
            return null;
        }

        $account = $this->calendarAccountForOwner((int) $activity->owner_id);
        if ($account === null) {
            $this->markExistingLinksNotSyncable($activity);

            return null;
        }

        $calendarId = $this->calendarIdFor($account);
        $link = $this->linkFor($activity, $account, $calendarId);
        $syncHash = $this->syncHashFor($activity);

        if ($this->isCancellation($activity)) {
            return $this->syncCancellation($activity, $account, $link, $syncHash);
        }

        if (! $this->isSyncable($activity)) {
            $link->forceFill([
                'sync_status' => ActivityCalendarLink::STATUS_NOT_SYNCABLE,
                'sync_hash' => $syncHash,
                'last_attempt_at' => now(),
                'error_class' => null,
                'error_message' => null,
            ])->save();

            return $link->refresh();
        }

        if (! $force
            && $link->sync_status === ActivityCalendarLink::STATUS_SYNCED
            && $link->sync_hash === $syncHash
            && $link->external_event_id !== null) {
            return $link;
        }

        if (! $force && $this->hasRecentInFlightCreate($link)) {
            return $link;
        }

        $this->markSyncing($link);

        try {
            $this->provider->bindAccount($account);
            $dto = $this->dtoFor($activity, cancelled: false);
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

    public function countInitialSyncCandidates(User $user): int
    {
        $account = $this->calendarAccountForOwner((int) $user->getKey());
        if ($account === null) {
            return 0;
        }

        return $this->initialSyncCandidateQuery($user, $account)->count();
    }

    public function queueInitialSyncCandidates(User $user): int
    {
        $account = $this->calendarAccountForOwner((int) $user->getKey());
        if ($account === null) {
            return 0;
        }

        $ids = $this->initialSyncCandidateQuery($user, $account)->pluck('id');
        foreach ($ids as $id) {
            SyncGoogleCalendarActivity::dispatch((int) $id)->afterCommit();
        }

        return $ids->count();
    }

    private function syncCancellation(
        Activity $activity,
        IntegrationAccount $account,
        ActivityCalendarLink $link,
        string $syncHash,
    ): ActivityCalendarLink {
        if ($link->external_event_id === null) {
            $link->forceFill([
                'sync_status' => ActivityCalendarLink::STATUS_NOT_SYNCABLE,
                'sync_hash' => $syncHash,
                'last_attempt_at' => now(),
                'error_class' => null,
                'error_message' => null,
            ])->save();

            return $link->refresh();
        }

        if ($link->sync_status === ActivityCalendarLink::STATUS_CANCELLED && $link->sync_hash === $syncHash) {
            return $link;
        }

        $this->markSyncing($link);

        try {
            $this->provider->bindAccount($account);
            $this->provider->updateEvent($link->external_event_id, $this->dtoFor($activity, cancelled: true));
            $link->forceFill([
                'sync_status' => ActivityCalendarLink::STATUS_CANCELLED,
                'sync_hash' => $syncHash,
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

    private function linkFor(Activity $activity, IntegrationAccount $account, string $calendarId): ActivityCalendarLink
    {
        return DB::transaction(function () use ($activity, $account, $calendarId): ActivityCalendarLink {
            /** @var ActivityCalendarLink $link */
            $link = ActivityCalendarLink::query()->firstOrCreate([
                'activity_id' => (int) $activity->getKey(),
                'integration_account_id' => (int) $account->getKey(),
                'external_calendar_id' => $calendarId,
            ], [
                'provider' => self::PROVIDER,
                'sync_status' => ActivityCalendarLink::STATUS_PENDING,
            ]);

            return $link->refresh();
        });
    }

    private function markSyncing(ActivityCalendarLink $link): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_SYNCING,
            'last_attempt_at' => now(),
            'error_class' => null,
            'error_message' => null,
        ])->save();
    }

    private function markHttpFailure(ActivityCalendarLink $link, RequestException $exception): void
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

    private function markTemporaryFailure(ActivityCalendarLink $link, Throwable $exception): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_TEMPORARY_ERROR,
            'error_class' => class_basename($exception),
            'error_message' => $this->safeMessage($exception),
        ])->save();
    }

    private function markPermanentFailure(ActivityCalendarLink $link, Throwable $exception): void
    {
        $link->forceFill([
            'sync_status' => ActivityCalendarLink::STATUS_FAILED,
            'error_class' => class_basename($exception),
            'error_message' => $this->safeMessage($exception),
        ])->save();
    }

    private function markExistingLinksNotSyncable(Activity $activity): void
    {
        $activity->calendarLinks()->update([
            'sync_status' => ActivityCalendarLink::STATUS_NOT_SYNCABLE,
            'last_attempt_at' => now(),
        ]);
    }

    private function hasRecentInFlightCreate(ActivityCalendarLink $link): bool
    {
        return $link->sync_status === ActivityCalendarLink::STATUS_SYNCING
            && $link->external_event_id === null
            && $link->last_attempt_at instanceof Carbon
            && $link->last_attempt_at->greaterThan(now()->subMinutes(5));
    }

    private function isSyncable(Activity $activity): bool
    {
        return ! $activity->trashed()
            && in_array($activity->status, ['pending', 'in_process', 'overdue'], true)
            && $activity->scheduled_at !== null;
    }

    private function isCancellation(Activity $activity): bool
    {
        return $activity->trashed() || $activity->status === 'cancelled';
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

    private function dtoFor(Activity $activity, bool $cancelled): CalendarEventDto
    {
        $timezone = $this->timezoneFor($activity);
        $startsAt = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $activity->scheduled_at->format('Y-m-d H:i:s'),
            $timezone,
        );
        $description = $this->descriptionFor($activity, $cancelled);

        return new CalendarEventDto(
            summary: $cancelled ? '[Cancelled] '.$activity->title : $activity->title,
            description: $description,
            startsAt: $startsAt,
            endsAt: $startsAt->copy()->addMinutes(self::DEFAULT_DURATION_MINUTES),
            timezone: $timezone,
            metadata: [
                'crm_instance_id' => (string) config('app.url'),
                'crm_activity_id' => (string) $activity->getKey(),
                'crm_activity_url' => rtrim((string) config('app.url'), '/').'/activities/'.$activity->getKey(),
                'crm_activity_status' => (string) $activity->status,
                'google_event_status' => $cancelled ? 'cancelled' : 'confirmed',
            ],
        );
    }

    private function descriptionFor(Activity $activity, bool $cancelled): ?string
    {
        $parts = [];

        if ($cancelled) {
            $parts[] = 'Cancelled in CRM.';
        }

        $description = trim((string) $activity->description);
        if ($description !== '') {
            $parts[] = $description;
        }

        $parts[] = 'CRM activity: '.rtrim((string) config('app.url'), '/').'/activities/'.$activity->getKey();

        return trim(implode("\n\n", $parts)) ?: null;
    }

    private function timezoneFor(Activity $activity): string
    {
        $ownerTimezone = $activity->owner?->getAttribute('timezone');

        foreach ([$ownerTimezone, config('company.timezone'), config('app.timezone'), 'UTC'] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private function syncHashFor(Activity $activity): string
    {
        return hash('sha256', json_encode([
            'id' => $activity->getKey(),
            'title' => $activity->title,
            'description' => $activity->description,
            'scheduled_at' => optional($activity->scheduled_at)->toIso8601String(),
            'timezone' => $this->timezoneFor($activity),
            'crm_activity_url' => rtrim((string) config('app.url'), '/').'/activities/'.$activity->getKey(),
            'status' => $activity->status,
            'deleted_at' => optional($activity->deleted_at)->toIso8601String(),
            'updated_at' => optional($activity->updated_at)->toIso8601String(),
            'duration_minutes' => self::DEFAULT_DURATION_MINUTES,
        ], JSON_THROW_ON_ERROR));
    }

    private function initialSyncCandidateQuery(User $user, IntegrationAccount $account)
    {
        $calendarId = $this->calendarIdFor($account);

        return Activity::query()
            ->where('owner_id', $user->getKey())
            ->whereIn('status', ['pending', 'in_process', 'overdue'])
            ->where('scheduled_at', '>=', now())
            ->whereDoesntHave('calendarLinks', function ($query) use ($account, $calendarId): void {
                $query->where('integration_account_id', $account->getKey())
                    ->where('external_calendar_id', $calendarId)
                    ->whereNotNull('external_event_id');
            });
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return mb_substr($message !== '' ? $message : class_basename($exception), 0, 500);
    }
}
