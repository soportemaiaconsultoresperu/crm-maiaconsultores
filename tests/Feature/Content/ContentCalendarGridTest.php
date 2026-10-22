<?php

namespace Tests\Feature\Content;

use App\Models\Content\ContentCalendarAnnotation;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentCalendarService;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * T9 — the month grid and the Peru-date grouping of the content calendar.
 *
 * The grid must be correct for ANY month and year, not only the 2026 blanks of
 * the specification, and every bucket must be the PERUVIAN calendar day:
 * `annotated_at` and `planned_at` are UTC instants, so grouping by the raw UTC
 * date would file a late Peruvian evening under the next day.
 */
class ContentCalendarGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
    }

    private function service(): ContentCalendarService
    {
        return app(ContentCalendarService::class);
    }

    /**
     * Every expected grid, including February of two leap years and a January
     * that starts in the previous year.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function months(): array
    {
        return [
            ['2026-02', '2026-01-26', '2026-03-01', 5],
            ['2026-03', '2026-02-23', '2026-04-05', 6],
            ['2026-08', '2026-07-27', '2026-09-06', 6],
            ['2027-01', '2026-12-28', '2027-01-31', 5],
            ['2024-02', '2024-01-29', '2024-03-03', 5],
            ['2028-02', '2028-01-31', '2028-03-05', 5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('months')]
    public function test_grid_is_monday_first_and_covers_the_whole_month(
        string $month,
        string $expectedStart,
        string $expectedEnd,
        int $expectedWeeks,
    ): void {
        $service = $this->service();
        $anchor = $service->month($month);
        $grid = $service->grid($anchor);

        // Starts on a Monday and ends on a Sunday, including the leading and
        // trailing days of the neighbouring months.
        $this->assertSame(1, $grid['start']->dayOfWeekIso, $month.' should start on Monday');
        $this->assertSame(7, $grid['end']->dayOfWeekIso, $month.' should end on Sunday');
        $this->assertSame($expectedStart, $grid['start']->toDateString());
        $this->assertSame($expectedEnd, $grid['end']->toDateString());
        // The grid never clips the month: it always starts on or before the 1st
        // and ends on or after the last day (a month may end exactly on Sunday).
        $this->assertTrue($grid['start']->lessThanOrEqualTo($anchor->startOfMonth()));
        $this->assertTrue($grid['end']->greaterThanOrEqualTo($anchor->endOfMonth()));

        $this->assertCount($expectedWeeks, $grid['weeks'], $month.' should span '.$expectedWeeks.' weeks');
        $this->assertCount(7, $grid['weeks'][0]);

        // Every row is a complete, consecutive Monday-to-Sunday week.
        foreach ($grid['weeks'] as $index => $week) {
            $this->assertCount(7, $week);
            $this->assertSame(1, $week[0]->dayOfWeekIso);
            $this->assertSame(7, $week[6]->dayOfWeekIso);

            for ($day = 1; $day < 7; $day++) {
                $this->assertTrue(
                    $week[$day - 1]->addDay()->equalTo($week[$day]),
                    'Week '.$index.' of '.$month.' is not consecutive.',
                );
            }
        }
    }

    public function test_the_grid_includes_the_leading_and_trailing_neighbour_days(): void
    {
        $service = $this->service();
        $grid = $service->grid($service->month('2026-02'));
        $days = $this->daysOf($grid);

        // February 2026 starts on a Sunday, so the grid opens with the last days
        // of January and closes with the first day of March.
        $this->assertTrue($days->contains('2026-01-26'));
        $this->assertTrue($days->contains('2026-01-31'));
        $this->assertTrue($days->contains('2026-03-01'));
        $this->assertFalse($days->contains('2026-01-25'));
        $this->assertFalse($days->contains('2026-03-02'));
    }

    public function test_leap_year_february_has_one_more_day_than_a_common_february(): void
    {
        $service = $this->service();

        $leap = $service->grid($service->month('2024-02'));
        $common = $service->grid($service->month('2026-02'));

        $this->assertSame(29, $service->month('2024-02')->daysInMonth);
        $this->assertSame(28, $service->month('2026-02')->daysInMonth);

        $this->assertTrue($this->daysOf($leap)->contains('2024-02-29'));
        $this->assertFalse($this->daysOf($common)->contains('2024-02-29'));
        $this->assertTrue($this->daysOf($common)->contains('2026-02-28'));
        $this->assertFalse($this->daysOf($common)->contains('2026-02-29'));
    }

    public function test_an_annotation_is_grouped_under_its_peruvian_date_not_its_utc_date(): void
    {
        $project = ContentProject::factory()->create();

        // 2026-03-09 02:00 UTC is 2026-03-08 21:00 in Peru, so the annotation
        // belongs to the 8th even though its UTC date is the 9th.
        $annotation = ContentCalendarAnnotation::factory()
            ->for($project, 'project')
            ->atUtc('2026-03-09 02:00:00')
            ->create(['name' => 'Aniversario nocturno']);

        $this->assertSame('2026-03-08', $annotation->annotatedDate());
        $this->assertSame('2026-03-09', CarbonImmutable::parse($annotation->annotated_at)->utc()->toDateString());

        $grouped = $this->service()->annotationsByDay($project, '2026-03-01', '2026-03-31');

        $this->assertArrayHasKey('2026-03-08', $grouped);
        $this->assertArrayNotHasKey('2026-03-09', $grouped);
        $this->assertSame($annotation->id, $grouped['2026-03-08']->first()->id);
    }

    public function test_an_annotation_written_on_a_peruvian_day_lands_on_that_day(): void
    {
        $project = ContentProject::factory()->create();

        $annotation = ContentCalendarAnnotation::factory()
            ->for($project, 'project')
            ->onDate('2026-07-15')
            ->create(['name' => 'Fiestas patrias']);

        $grouped = $this->service()->annotationsByDay($project, '2026-07-01', '2026-07-31');

        $this->assertSame('2026-07-15', $annotation->annotatedDate());
        $this->assertArrayHasKey('2026-07-15', $grouped);
        $this->assertSame('Fiestas patrias', $grouped['2026-07-15']->first()->name);
    }

    public function test_planned_contents_are_grouped_by_their_peruvian_date_too(): void
    {
        $project = ContentProject::factory()->create();

        // 2026-03-08 23:30 in Peru is stored as 2026-03-09 04:30 UTC.
        $content = ContentItem::factory()->for($project, 'project')->create([
            'planned_at' => PeruTime::toUtc('2026-03-08 23:30:00'),
            'title' => 'Publicación de la noche',
        ]);

        // An idea carries no date and is therefore never on the calendar.
        ContentItem::factory()->for($project, 'project')->idea()->create(['title' => 'Idea sin fecha']);

        $grouped = $this->service()->contentsByDay($project, '2026-03-01', '2026-03-31');

        $this->assertSame('2026-03-09', CarbonImmutable::parse($content->planned_at)->utc()->toDateString());
        $this->assertArrayHasKey('2026-03-08', $grouped);
        $this->assertArrayNotHasKey('2026-03-09', $grouped);
        $this->assertSame('Publicación de la noche', $grouped['2026-03-08']->first()->title);
        $this->assertSame(1, $grouped['2026-03-08']->count());
    }

    /**
     * The consolidated filter is a view convenience, never a permission: the
     * candidate set is the projects the user may already see, so asking for a
     * project they cannot reach adds nothing.
     */
    public function test_the_consolidated_filter_can_only_narrow_and_never_widen(): void
    {
        $mine = ContentProject::factory()->create(['name' => 'Proyecto propio']);
        $foreign = ContentProject::factory()->create(['name' => 'Proyecto ajeno']);

        ContentCalendarAnnotation::factory()->for($mine, 'project')->onDate('2026-03-10')->create(['name' => 'Mía']);
        ContentCalendarAnnotation::factory()->for($foreign, 'project')->onDate('2026-03-10')->create(['name' => 'Ajena']);

        $member = User::factory()->create(['is_active' => true]);
        $member->givePermissionTo('content_projects.view');
        $mine->members()->attach($member);

        $service = $this->service();

        // No filter: only the project the member belongs to.
        $all = $service->consolidatedAnnotations($member, [], '2026-03-01', '2026-03-31');
        $this->assertSame(['Mía'], $all->pluck('name')->all());

        // Filtering to the foreign project cannot reveal it: intersection is empty.
        $widened = $service->consolidatedAnnotations($member, [$foreign->id], '2026-03-01', '2026-03-31');
        $this->assertCount(0, $widened);

        // Filtering to the visible project still answers with it.
        $narrowed = $service->consolidatedAnnotations($member, [$mine->id], '2026-03-01', '2026-03-31');
        $this->assertSame(['Mía'], $narrowed->pluck('name')->all());

        // An authorized cross-project reader does see both when asking for both.
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo(['content_projects.view', 'content_projects.view_all']);

        $both = $service->consolidatedAnnotations($reader, [$mine->id, $foreign->id], '2026-03-01', '2026-03-31');
        $this->assertEqualsCanonicalizing(['Mía', 'Ajena'], $both->pluck('name')->all());
    }

    /**
     * @param  array{weeks: list<list<CarbonImmutable>>}  $grid
     * @return Collection<int, string>
     */
    private function daysOf(array $grid): Collection
    {
        return collect($grid['weeks'])
            ->flatten()
            ->map(fn (CarbonImmutable $day): string => $day->toDateString())
            ->values();
    }
}
