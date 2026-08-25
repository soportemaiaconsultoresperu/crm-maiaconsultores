<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\InvoiceStatus;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\CalendarEventService;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    private CalendarEventService $calendarEvents;

    private User $actor;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);

        $this->service = app(ActivityService::class);
        $this->calendarEvents = app(CalendarEventService::class);

        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole('admin');
        $this->actor->givePermissionTo(['calendar.view', 'customers.view.any', 'customer-payments.view']);
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

    public function test_type_filter_is_applied_in_the_activity_database_query(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $selectedType = ActivityType::query()->where('slug', 'reunion')->firstOrFail();
        $this->make($lead, now()->addHour());
        $matching = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $selectedType->id,
            'title' => 'Reunión filtrada',
            'scheduled_at' => now()->addHour(),
            'owner_id' => $this->actor->id,
        ], $this->actor);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"activities"')) {
                $queries[] = $query;
            }
        });

        $events = $this->service->calendarEvents(
            $this->actor,
            DateRange::daysForCalendarView('month', now()),
            ['type_id' => $selectedType->id],
        );

        $this->assertCount(1, $events);
        $this->assertSame($matching->id, $events->first()->id);
        $this->assertNotEmpty($queries);
        $this->assertContains($selectedType->id, $queries[0]->bindings);
    }

    public function test_calendar_event_service_preserves_activities_and_adds_invoice_event_items(): void
    {
        $lead = Lead::factory()->forOwner($this->actor)->create();
        $customer = Customer::factory()->forOwner($this->actor)->create(['trade_name' => 'Cliente Calendar']);
        $activity = $this->make($lead, CarbonImmutable::parse('2026-09-15 10:00:00'));
        $invoice = $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-CAL-001');

        $events = $this->calendarEvents->events(
            $this->actor,
            DateRange::daysForCalendarView('day', CarbonImmutable::parse('2026-09-15')),
        );

        $this->assertCount(2, $events);
        $this->assertTrue($events->contains(fn ($event) => $event->kind === 'activity' && $event->id === $activity->id));
        $this->assertTrue($events->contains(fn ($event) => $event->kind === 'invoice_due' && $event->title === 'Factura FAC-CAL-001 — Cliente Calendar'));
        $this->assertTrue($events->contains(fn ($event) => $event->typeLabel === 'Factura' && str_contains($event->url, route('customers.show', $customer))));
        $this->assertSame(InvoiceStatus::SLUG_IN_PROCESS, $invoice->refresh()->status->slug);
    }

    public function test_calendar_event_filters_apply_to_invoices_by_design(): void
    {
        $customer = Customer::factory()->forOwner($this->actor)->create();
        $otherCustomer = Customer::factory()->forOwner($this->otherOwner)->create();
        $type = ActivityType::query()->where('slug', 'llamada')->firstOrFail();
        $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-OWNER-001');
        $this->invoice($otherCustomer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-OWNER-002');

        $range = DateRange::daysForCalendarView('day', CarbonImmutable::parse('2026-09-15'));

        $typeFiltered = $this->calendarEvents->events($this->actor, $range, ['type_id' => $type->id]);
        $this->assertFalse($typeFiltered->contains(fn ($event) => $event->kind === 'invoice_due'));

        $ownerFiltered = $this->calendarEvents->events($this->actor, $range, ['owner_id' => $this->actor->id]);
        $this->assertTrue($ownerFiltered->contains(fn ($event) => str_contains($event->title, 'FAC-OWNER-001')));
        $this->assertFalse($ownerFiltered->contains(fn ($event) => str_contains($event->title, 'FAC-OWNER-002')));

        $customerSubject = $this->calendarEvents->events($this->actor, $range, ['subject_type' => 'customer']);
        $this->assertTrue($customerSubject->contains(fn ($event) => $event->kind === 'invoice_due'));

        $leadSubject = $this->calendarEvents->events($this->actor, $range, ['subject_type' => 'lead']);
        $this->assertFalse($leadSubject->contains(fn ($event) => $event->kind === 'invoice_due'));
    }

    public function test_calendar_user_without_financial_read_keeps_activity_events_but_not_invoice_events(): void
    {
        $calendarOnly = User::factory()->create(['is_active' => true]);
        $calendarOnly->givePermissionTo(['calendar.view', 'customers.view.any']);
        $customer = Customer::factory()->forOwner($calendarOnly)->create();
        $lead = Lead::factory()->forOwner($calendarOnly)->create();

        $this->make($lead, CarbonImmutable::parse('2026-09-15 09:00:00'), $calendarOnly);
        $this->invoice($customer, InvoiceStatus::SLUG_IN_PROCESS, '2026-09-15', 'FAC-HIDDEN-001');

        $events = $this->calendarEvents->events(
            $calendarOnly,
            DateRange::daysForCalendarView('day', CarbonImmutable::parse('2026-09-15')),
        );

        $this->assertTrue($events->contains(fn ($event) => $event->kind === 'activity'));
        $this->assertFalse($events->contains(fn ($event) => $event->kind === 'invoice_due'));
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

    private function invoice(Customer $customer, string $statusSlug, string $dueDate, string $number): CustomerInvoice
    {
        return CustomerInvoice::factory()
            ->forCustomer($customer)
            ->forStatus(InvoiceStatus::query()->where('slug', $statusSlug)->firstOrFail())
            ->create([
                'invoice_number' => $number,
                'due_date' => $dueDate,
                'total_amount' => '500.00',
            ]);
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