<?php

declare(strict_types=1);

namespace App\Services\InternalProjects;

use App\Enums\InternalProjects\TaskStatus;
use App\Models\InternalProjects\InternalProject;
use App\Models\InternalProjects\InternalProjectPhase;
use App\Models\InternalProjects\InternalProjectTask;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Day-scale timeline (Gantt) of an internal project (S4.2).
 *
 * The visible range spans every project, phase and task date, padded to whole
 * Monday–Sunday weeks and capped at MAX_MONTHS; a project without dates shows
 * the current Lima month. Rows are each phase (summary bar over its tasks, or
 * its own planned dates, with its % done) followed by its tasks. Positions are
 * whole days from the range start plus percentages of the range, so the view
 * only has to paint them. "Today" and "overdue" follow the Lima calendar.
 *
 * Pure: it reads the loaded `phases.tasks` (and `tasks.responsible`) relations
 * and returns plain arrays.
 */
class InternalProjectTimelineBuilder
{
    public const MAX_MONTHS = 18;

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(private readonly InternalProjectProgressService $progress) {}

    /**
     * @return array{
     *     range: array{start: string, end: string, days: int, truncated: bool},
     *     months: list<array{label: string, offset: int, days: int, left_pct: float, width_pct: float}>,
     *     weeks: list<array{date: string, label: string, offset: int, left_pct: float}>,
     *     today: array{date: string, label: string, in_range: bool, offset: int|null, left_pct: float|null},
     *     rows: list<array<string, mixed>>
     * }
     */
    public function build(InternalProject $project, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $today = CarbonImmutable::parse(PeruTime::date($now));
        $phases = $project->relationLoaded('phases') ? $project->phases : $project->phases()->with('tasks.responsible')->get();

        [$start, $end, $truncated] = $this->range($project, $phases, $today);
        $days = (int) $start->diffInDays($end) + 1;

        $rows = [];
        foreach ($phases as $phase) {
            $rows[] = $this->phaseRow($phase, $start, $end, $days, $now);

            foreach ($phase->tasks as $task) {
                $rows[] = $this->taskRow($task, $start, $end, $days, $now);
            }
        }

        $todayOffset = $today->betweenIncluded($start, $end) ? (int) $start->diffInDays($today) : null;

        return [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'days' => $days,
                'truncated' => $truncated,
            ],
            'months' => $this->months($start, $end, $days),
            'weeks' => $this->weeks($start, $end, $days),
            'today' => [
                'date' => $today->toDateString(),
                'label' => $today->format('d/m/Y'),
                'in_range' => $todayOffset !== null,
                'offset' => $todayOffset,
                // Centre of the day column.
                'left_pct' => $todayOffset === null ? null : $this->pct($todayOffset + 0.5, $days),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  iterable<InternalProjectPhase>  $phases
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}
     */
    private function range(InternalProject $project, iterable $phases, CarbonImmutable $today): array
    {
        $dates = [$project->starts_on, $project->ends_on];
        foreach ($phases as $phase) {
            $dates[] = $phase->starts_on;
            $dates[] = $phase->ends_on;
            foreach ($phase->tasks as $task) {
                $dates[] = $task->starts_on;
                $dates[] = $task->due_on;
            }
        }

        $dates = array_values(array_filter(array_map(
            fn (?CarbonInterface $date): ?string => $date?->toDateString(),
            $dates,
        )));

        if ($dates === []) {
            $first = $today->startOfMonth();
            $last = $today->endOfMonth()->startOfDay();
        } else {
            $first = CarbonImmutable::parse(min($dates));
            $last = CarbonImmutable::parse(max($dates));
        }

        $start = $first->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $end = $last->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();

        $limit = $start->addMonthsNoOverflow(self::MAX_MONTHS)->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $truncated = $end->greaterThan($limit);

        return [$start, $truncated ? $limit : $end, $truncated];
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseRow(InternalProjectPhase $phase, CarbonImmutable $start, CarbonImmutable $end, int $days, CarbonInterface $now): array
    {
        $tasks = $phase->tasks;

        if ($tasks->isNotEmpty()) {
            $from = $tasks->min(fn (InternalProjectTask $task): string => $task->starts_on->toDateString());
            $to = $tasks->max(fn (InternalProjectTask $task): string => $task->due_on->toDateString());
        } else {
            $from = $phase->starts_on?->toDateString() ?? $phase->ends_on?->toDateString();
            $to = $phase->ends_on?->toDateString() ?? $from;
        }

        $progress = $this->progress->phaseProgress($phase, $now);

        return [
            'type' => 'phase',
            'id' => $phase->id,
            'name' => $phase->name,
            'percent' => $progress['percent'],
            'task_count' => $progress['total'],
            'overdue_count' => $progress['atrasadas'],
            ...$this->span($from, $to, $start, $end, $days),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(InternalProjectTask $task, CarbonImmutable $start, CarbonImmutable $end, int $days, CarbonInterface $now): array
    {
        $overdue = $task->isOverdue($now);
        $span = $this->span($task->starts_on->toDateString(), $task->due_on->toDateString(), $start, $end, $days);
        $due = CarbonImmutable::parse($task->due_on->toDateString());
        $markerOffset = $due->betweenIncluded($start, $end) ? (int) $start->diffInDays($due) : null;

        return [
            'type' => 'task',
            'id' => $task->id,
            'phase_id' => $task->internal_project_phase_id,
            'title' => $task->title,
            'responsible' => $task->responsible?->name,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'badge_class' => $task->status->badgeClass(),
            'done' => $task->isDone(),
            'overdue' => $overdue,
            'milestone' => (bool) $task->is_milestone,
            'state' => $this->state($task->status, $overdue),
            'marker_offset' => $markerOffset,
            'marker_left_pct' => $markerOffset === null ? null : $this->pct($markerOffset + 0.5, $days),
            ...$span,
        ];
    }

    /**
     * Bar colour key: done > overdue > blocked > in progress > pending.
     */
    private function state(TaskStatus $status, bool $overdue): string
    {
        return match (true) {
            $status === TaskStatus::Done => 'done',
            $overdue => 'overdue',
            $status === TaskStatus::Blocked => 'blocked',
            $status === TaskStatus::InProgress => 'in-progress',
            default => 'pending',
        };
    }

    /**
     * Bar position for an inclusive date span, clipped to the visible range.
     *
     * @return array{start: string|null, end: string|null, start_label: string|null, end_label: string|null, has_bar: bool, offset: int|null, length: int|null, left_pct: float|null, width_pct: float|null}
     */
    private function span(?string $from, ?string $to, CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $none = ['has_bar' => false, 'offset' => null, 'length' => null, 'left_pct' => null, 'width_pct' => null];

        if ($from === null || $to === null) {
            return ['start' => null, 'end' => null, 'start_label' => null, 'end_label' => null, ...$none];
        }

        $first = CarbonImmutable::parse(min($from, $to));
        $last = CarbonImmutable::parse(max($from, $to));
        $dates = [
            'start' => $first->toDateString(),
            'end' => $last->toDateString(),
            'start_label' => $first->format('d/m/Y'),
            'end_label' => $last->format('d/m/Y'),
        ];

        if ($last->lessThan($start) || $first->greaterThan($end)) {
            return [...$dates, ...$none];
        }

        $offset = (int) $start->diffInDays($first->max($start));
        $length = (int) $first->max($start)->diffInDays($last->min($end)) + 1;

        return [
            ...$dates,
            'has_bar' => true,
            'offset' => $offset,
            'length' => $length,
            'left_pct' => $this->pct($offset, $days),
            'width_pct' => $this->pct($length, $days),
        ];
    }

    /**
     * @return list<array{label: string, offset: int, days: int, left_pct: float, width_pct: float}>
     */
    private function months(CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $months = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay()->min($end);
            $offset = (int) $start->diffInDays($cursor);
            $span = (int) $cursor->diffInDays($monthEnd) + 1;

            $months[] = [
                'label' => self::MONTHS[$cursor->month - 1].' '.$cursor->year,
                'offset' => $offset,
                'days' => $span,
                'left_pct' => $this->pct($offset, $days),
                'width_pct' => $this->pct($span, $days),
            ];

            $cursor = $monthEnd->addDay();
        }

        return $months;
    }

    /**
     * Monday ticks; the range always starts on a Monday.
     *
     * @return list<array{date: string, label: string, offset: int, left_pct: float}>
     */
    private function weeks(CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $weeks = [];

        for ($monday = $start; $monday->lessThanOrEqualTo($end); $monday = $monday->addWeek()) {
            $offset = (int) $start->diffInDays($monday);
            $weeks[] = [
                'date' => $monday->toDateString(),
                'label' => $monday->format('d/m'),
                'offset' => $offset,
                'left_pct' => $this->pct($offset, $days),
            ];
        }

        return $weeks;
    }

    private function pct(float|int $value, int $days): float
    {
        return round($value / $days * 100, 4);
    }
}
