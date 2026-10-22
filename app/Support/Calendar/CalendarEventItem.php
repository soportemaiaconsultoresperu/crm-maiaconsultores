<?php

namespace App\Support\Calendar;

use Carbon\CarbonInterface;

final readonly class CalendarEventItem
{
    public function __construct(
        public string $kind,
        public int|string $id,
        public CarbonInterface $scheduled_at,
        public string $title,
        public string $status,
        public string $typeLabel,
        public string $subjectLabel,
        public ?string $ownerName,
        public string $url,
        public ?string $amount = null,
        public bool $requiresFinancialRead = false,
        // Date-only events (project task due dates) render without an hour.
        public bool $allDay = false,
        // Inline style painting the event (project colour).
        public ?string $style = null,
        public bool $overdue = false,
    ) {}
}
