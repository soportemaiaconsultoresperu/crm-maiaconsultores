<?php

namespace App\Integrations\Google;

use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\Dto\CalendarEventDto;
use App\Integrations\Dto\CalendarEventRef;
use App\Models\IntegrationAccount;
use App\Services\Google\GoogleOAuthService;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarProvider implements CalendarProvider
{
    private ?IntegrationAccount $account = null;

    public function __construct(
        private readonly GoogleOAuthService $oauth,
    ) {}

    public function bindAccount(IntegrationAccount $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function createEvent(CalendarEventDto $dto): CalendarEventRef
    {
        $calendarId = $this->calendarId();
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->eventCollectionUrl($calendarId), $this->payloadFor($dto));

        return $this->eventRefFromResponse($response, created: true);
    }

    public function updateEvent(string $externalId, CalendarEventDto $dto): CalendarEventRef
    {
        $calendarId = $this->calendarId();
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->patch($this->eventUrl($calendarId, $externalId), $this->payloadFor($dto));

        return $this->eventRefFromResponse($response, created: false, fallbackEventId: $externalId);
    }

    public function deleteEvent(string $externalId): void
    {
        $calendarId = $this->calendarId();
        Http::withToken($this->accessToken())
            ->acceptJson()
            ->delete($this->eventUrl($calendarId, $externalId))
            ->throw();
    }

    public function fetchCalendars(): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList');

        $response->throw();

        return collect((array) ($response->json('items') ?? []))
            ->map(fn (array $calendar): array => [
                'id' => (string) ($calendar['id'] ?? ''),
                'name' => (string) ($calendar['summary'] ?? ($calendar['id'] ?? '')),
                'is_primary' => (bool) ($calendar['primary'] ?? false),
            ])
            ->filter(fn (array $calendar): bool => $calendar['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, resourceId: string|null, resourceUri: string|null, expiration: string|null, raw: array<string, mixed>}
     */
    public function watchEvents(string $calendarId, string $address, string $token, string $channelId, ?DateTimeInterface $expiresAt = null): array
    {
        $payload = [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $address,
            'token' => $token,
        ];

        if ($expiresAt !== null) {
            $payload['expiration'] = (string) ($expiresAt->getTimestamp() * 1000);
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->eventCollectionUrl($calendarId).'/watch', $payload);

        $response->throw();

        return [
            'id' => (string) ($response->json('id') ?? $channelId),
            'resourceId' => is_string($response->json('resourceId')) ? $response->json('resourceId') : null,
            'resourceUri' => is_string($response->json('resourceUri')) ? $response->json('resourceUri') : null,
            'expiration' => is_string($response->json('expiration')) ? $response->json('expiration') : null,
            'raw' => $response->json() ?? [],
        ];
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post('https://www.googleapis.com/calendar/v3/channels/stop', [
                'id' => $channelId,
                'resourceId' => $resourceId,
            ])
            ->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEvent(string $calendarId, string $eventId): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->eventUrl($calendarId, $eventId));

        $response->throw();

        return $response->json() ?? [];
    }

    public function verifyWebhook(string $signature, string $body, ?int $timestamp): bool
    {
        return false;
    }

    private function accessToken(): string
    {
        $account = $this->requireAccount();
        $scopes = array_values(array_filter((array) ($account->scopes ?? []), 'is_string'));

        if (! in_array('https://www.googleapis.com/auth/calendar.events', $scopes, true)) {
            throw new RuntimeException('Google Calendar scope is not available for this account.');
        }

        return $this->oauth->accessTokenFor($account);
    }

    private function calendarId(): string
    {
        $config = (array) ($this->requireAccount()->config_json ?? []);
        $calendar = (array) ($config['calendar'] ?? []);
        $calendarId = (string) ($calendar['default_calendar_id'] ?? 'primary');

        return $calendarId !== '' ? $calendarId : 'primary';
    }

    private function requireAccount(): IntegrationAccount
    {
        if (! $this->account instanceof IntegrationAccount) {
            throw new RuntimeException('Google Calendar provider requires an integration account.');
        }

        return $this->account;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(CalendarEventDto $dto): array
    {
        $metadata = [];
        foreach ($dto->metadata as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $metadata[$key] = (string) $value;
            }
        }

        $payload = [
            'summary' => $dto->summary,
            'description' => $dto->description,
            'start' => [
                'dateTime' => $dto->startsAt->format(DATE_RFC3339),
                'timeZone' => $dto->timezone,
            ],
            'end' => [
                'dateTime' => $dto->endsAt->format(DATE_RFC3339),
                'timeZone' => $dto->timezone,
            ],
            'extendedProperties' => [
                'private' => $metadata,
            ],
        ];

        if ($dto->location !== null && $dto->location !== '') {
            $payload['location'] = $dto->location;
        }

        if ($dto->attendees !== []) {
            $payload['attendees'] = array_map(
                fn (string $email): array => ['email' => $email],
                $dto->attendees,
            );
        }

        if (($metadata['google_event_status'] ?? null) === 'cancelled') {
            $payload['status'] = 'cancelled';
        }

        return $payload;
    }

    private function eventRefFromResponse(Response $response, bool $created, ?string $fallbackEventId = null): CalendarEventRef
    {
        $response->throw();

        $eventId = (string) ($response->json('id') ?? $fallbackEventId ?? '');
        $etag = $response->json('etag');

        if ($eventId === '') {
            throw new RuntimeException('Google Calendar response did not include an event id.');
        }

        return $created
            ? CalendarEventRef::created($eventId, is_string($etag) ? $etag : null, $response->status(), $response->json() ?? [])
            : CalendarEventRef::updated($eventId, is_string($etag) ? $etag : null, $response->status(), $response->json() ?? []);
    }

    private function eventCollectionUrl(string $calendarId): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->eventCollectionUrl($calendarId).'/'.rawurlencode($eventId);
    }
}
