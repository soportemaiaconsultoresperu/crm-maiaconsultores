<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ActivityService;
use App\Support\DateRange;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Calendar projection for activities (RF-CAL-001 / RF-ACT-008). Exercises
 * ActivityService::calendarEvents() across day/week/month projections and
 * the optional filters (subject_type, owner_id, status). Eager relations
 * are verified with a query-count assertion that does not depend on
 * `morphTo` polymorphism metadata.
 */
class CalendarQueryTest extends TestCase
{
    use RefreshDatabase;

    private ActivityService $service;

    private User $actor;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);

        $this->service = app(ActivityService::class);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->otherOwner = User::factory()->create(['is_active' => true]);
    }

    public function test_day_view_returns_only_activities_inside_the_day(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $inDay = $this->make($lead, now()->setTime(10, 0));
        $this->make($lead, now()->subDay()); // yesterday
        $this->make($lead, now()->addDay()); // tomorrow

        $range = DateRange::daysForCalendarView('day', now());

        $events = $this->service->calendarEvents($this->actor, $range);

        $this->assertCount(1, $events);
        $this->assertSame($inDay->id, $events->first()->id);
    }

    public function test_week_view_returns_seven_day_window_monday_to_sunday(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $anchor = now()->next(\Carbon\CarbonInterface::WEDNESDAY);

        // Inside the week (anchor's Sunday).
        $inside = $this->make($lead, $anchor->copy()->endOfWeek()->subHours(2));
        // One second before the week start.
        $this->make($lead, $anchor->copy()->startOfWeek()->subSecond());
        // One second after the week end.
        $this->make($lead, $anchor->copy()->endOfWeek()->addSecond());

        $range = DateRange::daysForCalendarView('week', $anchor);

        $events = $this->service->calendarEvents($this->actor, $range);

        $this->assertCount(1, $events);
        $this->assertSame($inside->id, $events->first()->id);
    }

    public function test_month_view_returns_full_calendar_month(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $anchor = now()->startOfMonth()->addDays(4);

        // Inside the month.
        $inside = $this->make($lead, $anchor->copy()->addDays(2));
        // Outside (last day of previous month).
        $this->make($lead, $anchor->copy()->subDays(5));
        // Outside (first day of next month).
        $this->make($lead, $anchor->copy()->endOfMonth()->addDay());

        $range = DateRange::daysForCalendarView('month', $anchor);

        $events = $this->service->calendarEvents($this->actor, $range);

        $this->assertCount(1, $events);
        $this->assertSame($inside->id, $events->first()->id);
    }

    public function test_filter_by_subject_type_is_applied(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $customer = Customer::factory()->forOwner($this->actor)->create();
        $opportunity = Opportunity::factory()->forOwner($this->actor)->create();

        $leadActivity = $this->make($lead, now()->addHour());
        $this->make($customer, now()->addHour());
        $this->make($opportunity, now()->addHour());

        $range = DateRange::daysForCalendarView('month', now());

        $events = $this->service->calendarEvents($this->actor, $range, ['subject_type' => 'lead']);

        $this->assertCount(1, $events);
        $this->assertSame($leadActivity->id, $events->first()->id);
    }

    public function test_filter_by_owner_id_is_applied(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $mine = $this->make($lead, now()->addHour(), $this->actor);
        $this->make($lead, now()->addHour(), $this->otherOwner);

        $range = DateRange::daysForCalendarView('month', now());

        $events = $this->service->calendarEvents($this->actor, $range, ['owner_id' => $this->actor->id]);

        $this->assertCount(1, $events);
        $this->assertSame($mine->id, $events->first()->id);
    }

    public function test_filter_by_status_is_applied(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $pending = $this->make($lead, now()->addHour());
        $completed = $this->make($lead, now()->addHour());
        $completed->update(['status' => 'completed']);

        $range = DateRange::daysForCalendarView('month', now());

        $events = $this->service->calendarEvents($this->actor, $range, ['status' => 'pending']);

        $this->assertCount(1, $events);
        $this->assertSame($pending->id, $events->first()->id);
    }

    public function test_eager_loading_avoids_n_plus_one_on_subject_relations(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();

        $this->make($lead, now()->addHour());
        $this->make($lead, now()->addHours(2));
        $this->make($lead, now()->addHours(3));

        $range = DateRange::daysForCalendarView('month', now());

        // Warm-up: primes the connection so the next call is a fair test.
        $this->service->calendarEvents($this->actor, $range);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $events = $this->service->calendarEvents($this->actor, $range);

        // 1 base query for activities + owner + type + subject = 4 queries.
        // The subject morphTo triggers one extra query per distinct
        // morph class; here we only have Lead subjects so the total is 4.
        $this->assertSame(3, $events->count());
        $this->assertLessThanOrEqual(6, $queryCount, 'Expected ≤6 queries with eager loading (got '.$queryCount.')');
    }

    private function make($subject, $when, ?User $owner = null): Activity
    {
        return $this->service->create([
            'subject_type' => $subject::class === Lead::class ? 'lead' : ($subject::class === Customer::class ? 'customer' : 'opportunity'),
            'subject_id' => $subject->id,
            'type_id' => ActivityType::query()->where('slug', 'llamada')->value('id'),
            'title' => 'Llamada',
            'scheduled_at' => $when,
            'owner_id' => $owner?->id ?? $this->actor->id,
        ], $owner ?? $this->actor);
    }
}