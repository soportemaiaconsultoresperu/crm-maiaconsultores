<?php

namespace App\Services\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read and write surface for the important-dates calendar (T9).
 *
 * The month grid is built here, in Peru wall-clock time, so the calendar is
 * correct for any month, any year and any leap year instead of assuming a fixed
 * four-week month: `grid()` always returns complete Monday-to-Sunday weeks and
 * includes the leading and trailing days of the neighbouring months.
 *
 * Both the annotations an editor writes and the planned contents of the
 * project are grouped by their PERUVIAN calendar date. `annotated_at` and
 * `planned_at` are UTC instants, so the day bucket is always
 * `PeruTime::date(...)`; grouping by the raw UTC date would put a late Peruvian
 * evening on the following day.
 *
 * The consolidated view is the only cross-project read, and it never widens
 * what a user may see: its query starts from
 * `ContentProjectScopeService::visibleProjects()` and the project filter only
 * NARROWS that set through an intersection. Selecting a project the user cannot
 * reach therefore returns nothing instead of revealing it.
 */
class ContentCalendarService
{
    public function __construct(
        private readonly ContentProjectScopeService $scope,
    ) {
    }

    /**
     * The Peru calendar month addressed by a `YYYY-MM` string, falling back to
     * the current Peruvian month when the input is missing or malformed.
     */
    public function month(string $month): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return CarbonImmutable::parse($month.'-01', PeruTime::TIMEZONE)->startOfMonth();
        }

        return CarbonImmutable::now(PeruTime::TIMEZONE)->startOfMonth();
    }

    /**
     * A Monday-first month grid: complete weeks covering the whole month, plus
     * the leading days of the previous month and the trailing days of the next
     * one. Leap years need no special case because Carbon owns the month
     * arithmetic.
     *
     * @return array{month: CarbonImmutable, start: CarbonImmutable, end: CarbonImmutable, weeks: list<list<CarbonImmutable>>}
     */
    public function grid(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->startOfWeek(CarbonInterface::MONDAY);
        $end = $month->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY);

        $weeks = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $week = [];

            for ($day = 0; $day < 7; $day++) {
                $week[] = $cursor;
                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'weeks' => $weeks,
        ];
    }

    /**
     * The addressed project's annotations, grouped by Peruvian calendar date.
     *
     * @return array<string, Collection<int, ContentCalendarAnnotation>>
     */
    public function annotationsByDay(ContentProject $project, string $startPeruDate, string $endPeruDate): array
    {
        [$from, $to] = $this->utcBounds($startPeruDate, $endPeruDate);

        return $this->groupAnnotationsByDay(
            ContentCalendarAnnotation::query()
                ->where('content_project_id', $project->getKey())
                ->where('annotated_at', '>=', $from)
                ->where('annotated_at', '<', $to)
                ->with('project')
                ->get(),
        );
    }

    /**
     * The addressed project's planned contents, grouped by Peruvian calendar
     * date. Ideas carry no date and are therefore excluded by the `planned_at`
     * filter.
     *
     * @return array<string, Collection<int, ContentItem>>
     */
    public function contentsByDay(ContentProject $project, string $startPeruDate, string $endPeruDate): array
    {
        [$from, $to] = $this->utcBounds($startPeruDate, $endPeruDate);

        return $this->groupByPeruDate(
            ContentItem::query()
                ->contents()
                ->where('content_project_id', $project->getKey())
                ->whereNotNull('planned_at')
                ->where('planned_at', '>=', $from)
                ->where('planned_at', '<', $to)
                ->with(['pillar', 'topic'])
                ->get(),
            fn (ContentItem $item): string => PeruTime::date($item->planned_at),
        );
    }

    /**
     * The annotations the consolidated view may show: every visible project, or
     * the visible subset the user filtered to.
     *
     * The filter is a view convenience and never a permission. The candidate set
     * is `visibleProjects()` FIRST and the requested ids are intersected with it,
     * so an id the user cannot reach can never add a row: filtering can only
     * narrow, never widen. An empty selection means "all visible projects".
     *
     * @param  list<int>  $projectIds
     * @return Collection<int, ContentCalendarAnnotation>
     */
    public function consolidatedAnnotations(
        User $user,
        array $projectIds,
        string $startPeruDate,
        string $endPeruDate,
    ): Collection {
        $visibleIds = $this->consolidatedProjectIds($user, $projectIds);

        if ($visibleIds === []) {
            return collect();
        }

        [$from, $to] = $this->utcBounds($startPeruDate, $endPeruDate);

        return ContentCalendarAnnotation::query()
            ->whereIn('content_project_id', $visibleIds)
            ->where('annotated_at', '>=', $from)
            ->where('annotated_at', '<', $to)
            ->with('project')
            ->get();
    }

    /**
     * The planned contents the consolidated view may show, with the same
     * project scope as consolidatedAnnotations(): visible projects first,
     * narrowed by the filter. Ideas (no date) and trashed contents are left out.
     *
     * @param  list<int>  $projectIds
     * @return array<string, Collection<int, ContentItem>>
     */
    public function consolidatedContentsByDay(
        User $user,
        array $projectIds,
        string $startPeruDate,
        string $endPeruDate,
    ): array {
        $visibleIds = $this->consolidatedProjectIds($user, $projectIds);

        if ($visibleIds === []) {
            return [];
        }

        [$from, $to] = $this->utcBounds($startPeruDate, $endPeruDate);

        return $this->groupByPeruDate(
            ContentItem::query()
                ->contents()
                ->whereIn('content_project_id', $visibleIds)
                ->whereNotNull('planned_at')
                ->where('planned_at', '>=', $from)
                ->where('planned_at', '<', $to)
                ->with('project')
                ->orderBy('planned_at')
                ->get(),
            fn (ContentItem $item): string => PeruTime::date($item->planned_at),
        );
    }

    /**
     * Visible project ids intersected with the requested filter (empty = all).
     * The filter can only narrow, never widen.
     *
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    private function consolidatedProjectIds(User $user, array $projectIds): array
    {
        $visibleIds = $this->scope->visibleProjects($user)->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($projectIds !== []) {
            $visibleIds = array_values(array_intersect($visibleIds, $projectIds));
        }

        return $visibleIds;
    }

    /**
     * Group a set of annotations by the Peruvian day they belong to.
     *
     * @param  Collection<int, ContentCalendarAnnotation>  $annotations
     * @return array<string, Collection<int, ContentCalendarAnnotation>>
     */
    public function groupAnnotationsByDay(Collection $annotations): array
    {
        return $this->groupByPeruDate(
            $annotations,
            fn (ContentCalendarAnnotation $annotation): string => PeruTime::date($annotation->annotated_at),
        );
    }

    /**
     * Write a new annotation on the project's calendar for the submitted Peru
     * date.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAnnotation(ContentProject $project, array $data): ContentCalendarAnnotation
    {
        return ContentCalendarAnnotation::query()->create([
            'content_project_id' => $project->getKey(),
            'annotated_at' => $this->peruInstant((string) $data['date']),
            'name' => $data['name'],
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);
    }

    /**
     * Edit an annotation's name, notes and/or day. Several annotations may share
     * a day, so nothing here rejects a day already used.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAnnotation(ContentCalendarAnnotation $annotation, array $data): ContentCalendarAnnotation
    {
        $annotation->update([
            'annotated_at' => $this->peruInstant((string) $data['date']),
            'name' => $data['name'],
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        return $annotation;
    }

    /**
     * Retire an annotation. This is the module's soft-delete behaviour, so the
     * row is marked, not physically destroyed.
     */
    public function removeAnnotation(ContentCalendarAnnotation $annotation): void
    {
        $annotation->delete();
    }

    /**
     * The `YYYY-MM` Peru month an annotation falls in, for the redirect that
     * keeps the editor on the month they were looking at.
     */
    public function monthOf(ContentCalendarAnnotation $annotation): string
    {
        return PeruTime::toPeru($annotation->annotated_at)->format('Y-m');
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, Collection<int, mixed>>
     */
    private function groupByPeruDate(Collection $rows, callable $peruDate): array
    {
        return $rows->groupBy($peruDate)->all();
    }

    /**
     * Convert an inclusive Peru calendar-day range into the half-open UTC
     * instant range the stored instants are compared against.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function utcBounds(string $startPeruDate, string $endPeruDate): array
    {
        return [
            $this->peruInstant($startPeruDate),
            $this->peruInstant($endPeruDate)->addDay(),
        ];
    }

    /**
     * The UTC instant that matches midnight on a Peruvian calendar day.
     */
    private function peruInstant(string $peruDate): CarbonImmutable
    {
        return PeruTime::toUtc($peruDate.' 00:00:00');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
