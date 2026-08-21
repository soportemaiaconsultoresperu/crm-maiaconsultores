<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Value object describing an inclusive [start, end] date range.
 *
 * Used by ActivityService::calendarEvents() (RF-CAL-001) and any future
 * report/list query that needs to project onto calendar views without
 * recomputing bounds on every call. The end is inclusive end-of-day
 * (23:59:59.999999) so the day-view is symmetrical with the week and
 * month views and "end of day" stays inside the range.
 *
 * Bounds are exposed as immutable Carbon so callers cannot mutate the
 * shared range.
 */
class DateRange
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {
        if ($this->end->lessThan($this->start)) {
            throw new \InvalidArgumentException(
                'DateRange end must be greater than or equal to start.'
            );
        }
    }

    /**
     * Calendar projection for a given view anchored on $anchor.
     *
     * - "day":   [00:00, 23:59:59] of the anchor's date.
     * - "week":  Monday 00:00 .. Sunday 23:59:59 of the anchor's ISO week.
     * - "month": Day 1 00:00 .. last day 23:59:59 of the anchor's month.
     *
     * The anchor's hour/minute/second are intentionally ignored: a calendar
     * page is a date, not a moment.
     */
    public static function daysForCalendarView(string $view, CarbonInterface $anchor): self
    {
        $view = strtolower($view);

        $date = $anchor->toImmutable();

        return match ($view) {
            'day' => new self(
                $date->startOfDay(),
                $date->endOfDay(),
            ),
            'week' => new self(
                $date->startOfWeek(CarbonInterface::MONDAY),
                $date->endOfWeek(CarbonInterface::SUNDAY),
            ),
            'month' => new self(
                $date->startOfMonth()->startOfDay(),
                $date->endOfMonth()->endOfDay(),
            ),
            default => throw new \InvalidArgumentException(
                "Unsupported calendar view \"{$view}\". Use day|week|month."
            ),
        };
    }

    /**
     * Inclusive start as an immutable Carbon for callers that need to chain
     * further startOf/endOf calls without mutating the stored bound.
     */
    public function start(): CarbonImmutable
    {
        return $this->start->toImmutable();
    }

    /**
     * Inclusive end-of-day as an immutable Carbon.
     */
    public function end(): CarbonImmutable
    {
        return $this->end->toImmutable();
    }

    /**
     * True when $moment is within [start, end] (inclusive on both bounds).
     */
    public function contains(CarbonInterface $moment): bool
    {
        return ! $moment->lt($this->start) && ! $moment->gt($this->end);
    }
}