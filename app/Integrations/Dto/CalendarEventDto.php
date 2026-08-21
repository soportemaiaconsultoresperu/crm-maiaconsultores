<?php

declare(strict_types=1);

namespace App\Integrations\Dto;

use DateTimeInterface;

/**
 * Input DTO for {@see \App\Integrations\Contracts\CalendarProvider::createEvent()}
 * and {@see \App\Integrations\Contracts\CalendarProvider::updateEvent()}.
 *
 * Times are passed as DateTimeInterface so callers can attach any timezone
 * they like; the adapter is responsible for converting to UTC for the
 * provider API (Google/Outlook always accept RFC3339 with offset).
 */
final readonly class CalendarEventDto
{
    /**
     * @param  list<string>  $attendees
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $summary,
        public ?string $description,
        public DateTimeInterface $startsAt,
        public DateTimeInterface $endsAt,
        public ?string $timezone,
        public array $attendees = [],
        public ?string $location = null,
        public array $metadata = [],
    ) {
    }
}