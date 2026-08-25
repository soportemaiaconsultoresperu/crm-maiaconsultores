<?php

namespace App\Services;

use App\Integrations\Google\GoogleCalendarProvider;
use App\Models\GoogleCalendarChannel;
use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarWatchService
{
    private const PROVIDER = 'google';
    private const DEFAULT_CALENDAR_ID = 'primary';
    private const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function __construct(
        private readonly GoogleCalendarProvider $provider,
    ) {}

    public function startForUser(User $user): ?GoogleCalendarChannel
    {
        $account = $this->calendarAccountForOwner((int) $user->getKey());

        return $account instanceof IntegrationAccount ? $this->startForAccount($account) : null;
    }

    public function startForAccount(IntegrationAccount $account): GoogleCalendarChannel
    {
        $calendarId = $this->calendarIdFor($account);
        $existing = $this->activeChannelForAccount($account, $calendarId);
        if ($existing instanceof GoogleCalendarChannel && $existing->expires_at?->isFuture()) {
            return $existing;
        }

        $channelId = (string) Str::uuid();
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        /** @var GoogleCalendarChannel $channel */
        $channel = GoogleCalendarChannel::query()->create([
            'integration_account_id' => (int) $account->getKey(),
            'provider' => GoogleCalendarChannel::PROVIDER,
            'external_calendar_id' => $calendarId,
            'channel_id' => $channelId,
            'channel_token_hash' => GoogleCalendarChannel::tokenHash($token),
            'status' => GoogleCalendarChannel::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ]);

        try {
            $response = $this->provider
                ->bindAccount($account)
                ->watchEvents($calendarId, $this->webhookAddress(), $token, $channelId, $expiresAt);

            $actualExpiresAt = $this->expirationFromGoogle($response['expiration']) ?? $expiresAt;
            $channel->forceFill([
                'channel_id' => $response['id'] !== '' ? $response['id'] : $channelId,
                'resource_id' => $response['resourceId'],
                'resource_uri' => $response['resourceUri'],
                'status' => GoogleCalendarChannel::STATUS_ACTIVE,
                'expires_at' => $actualExpiresAt,
                'last_renewed_at' => now(),
                'error_class' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $channel->forceFill([
                'status' => GoogleCalendarChannel::STATUS_FAILED,
                'error_class' => class_basename($exception),
                'error_message' => $this->safeMessage($exception),
            ])->save();
        }

        return $channel->refresh();
    }

    public function renew(GoogleCalendarChannel $channel): GoogleCalendarChannel
    {
        $channel->forceFill(['status' => GoogleCalendarChannel::STATUS_RENEWING])->save();
        $this->stop($channel);

        return $this->startForAccount($channel->integrationAccount()->firstOrFail());
    }

    public function stopActiveForUser(User $user): int
    {
        $account = $this->calendarAccountForOwner((int) $user->getKey());

        return $account instanceof IntegrationAccount ? $this->stopActiveForAccount($account) : 0;
    }

    public function stopActiveForAccount(IntegrationAccount $account): int
    {
        $calendarId = $this->calendarIdFor($account);
        $channels = GoogleCalendarChannel::query()
            ->where('integration_account_id', $account->getKey())
            ->where('external_calendar_id', $calendarId)
            ->open()
            ->get();

        foreach ($channels as $channel) {
            $this->stop($channel);
        }

        return $channels->count();
    }

    public function stop(GoogleCalendarChannel $channel): GoogleCalendarChannel
    {
        if ($channel->resource_id === null || $channel->resource_id === '') {
            $channel->forceFill(['status' => GoogleCalendarChannel::STATUS_STOPPED])->save();

            return $channel->refresh();
        }

        try {
            $this->provider
                ->bindAccount($channel->integrationAccount()->firstOrFail())
                ->stopChannel($channel->channel_id, $channel->resource_id);

            $channel->forceFill([
                'status' => GoogleCalendarChannel::STATUS_STOPPED,
                'error_class' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $channel->forceFill([
                'status' => GoogleCalendarChannel::STATUS_FAILED,
                'error_class' => class_basename($exception),
                'error_message' => $this->safeMessage($exception),
            ])->save();
        }

        return $channel->refresh();
    }

    public function statusForUser(User $user): ?GoogleCalendarChannel
    {
        $account = $this->calendarAccountForOwner((int) $user->getKey());
        if (! $account instanceof IntegrationAccount) {
            return null;
        }

        return GoogleCalendarChannel::query()
            ->where('integration_account_id', $account->getKey())
            ->where('external_calendar_id', $this->calendarIdFor($account))
            ->latest('id')
            ->first();
    }

    private function activeChannelForAccount(IntegrationAccount $account, string $calendarId): ?GoogleCalendarChannel
    {
        return GoogleCalendarChannel::query()
            ->where('integration_account_id', $account->getKey())
            ->where('external_calendar_id', $calendarId)
            ->where('status', GoogleCalendarChannel::STATUS_ACTIVE)
            ->latest('id')
            ->first();
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

    private function webhookAddress(): string
    {
        $configured = (string) config('integrations.google_calendar_watch.webhook_url');

        return $configured !== '' ? $configured : URL::route('webhooks.google-calendar');
    }

    private function ttlMinutes(): int
    {
        return max(10, (int) config('integrations.google_calendar_watch.ttl_minutes', 8640));
    }

    private function expirationFromGoogle(?string $expiration): ?Carbon
    {
        if ($expiration === null || $expiration === '') {
            return null;
        }

        $milliseconds = (int) $expiration;
        if ($milliseconds <= 0) {
            return null;
        }

        return Carbon::createFromTimestampMs($milliseconds);
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return mb_substr($message !== '' ? $message : class_basename($exception), 0, 500);
    }
}
