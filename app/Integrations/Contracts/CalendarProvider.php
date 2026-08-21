<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\Dto\CalendarEventDto;
use App\Integrations\Dto\CalendarEventRef;

/**
 * Contract every external calendar provider must implement.
 *
 * B11 ships the contract only. Adapters (Google, Outlook) live in
 * {@see App\Integrations\Adapters\*CalendarAdapter} (B16).
 */
interface CalendarProvider
{
    public function createEvent(CalendarEventDto $dto): CalendarEventRef;

    public function updateEvent(string $externalId, CalendarEventDto $dto): CalendarEventRef;

    public function deleteEvent(string $externalId): void;

    /**
     * List the calendars the connected account can write to.
     *
     * @return list<array{id: string, name: string, is_primary?: bool}>
     */
    public function fetchCalendars(): array;

    /**
     * Verify the signature of an inbound push notification. Google uses
     * `X-Goog-Signature` (HMAC over the channel-id + resource-id +
     * message-number); Outlook uses a shared `clientState` and no header.
     */
    public function verifyWebhook(string $signature, string $body, ?int $timestamp): bool;
}