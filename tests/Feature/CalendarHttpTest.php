<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ActivityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B05 calendar HTTP layer (RF-CAL-001..002). Covers the four view modes
 * (month/week/day/list) and the cross-scope owner filter. Scope
 * enforcement is already covered by CalendarQueryTest at the service
 * level; this file only verifies the HTTP routing + filters.
 */
class CalendarHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salespersonOne;

    private User $salespersonTwo;

    private ActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->salespersonOne = User::factory()->create(['is_active' => true]);
        $this->salespersonOne->assignRole('vendedor');

        $this->salespersonTwo = User::factory()->create(['is_active' => true]);
        $this->salespersonTwo->assignRole('vendedor');

        $this->service = app(ActivityService::class);
    }

    private function typeId(string $slug): int
    {
        return ActivityType::query()->where('slug', $slug)->value('id');
    }

    private function makeActivity(string $subjectType, $subject, $when, User $owner): Activity
    {
        $key = match (true) {
            $subject instanceof Lead => 'lead',
            $subject instanceof Customer => 'customer',
            $subject instanceof Opportunity => 'opportunity',
        };

        return $this->service->create([
            'subject_type' => $key,
            'subject_id' => $subject->id,
            'type_id' => $this->typeId('llamada'),
            'title' => "Actividad {$owner->name}",
            'scheduled_at' => $when,
            'owner_id' => $owner->id,
        ], $owner);
    }

    public function test_default_view_is_month_and_returns_200(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();
        $this->makeActivity('lead', $lead, now()->addDay(), $this->salespersonOne);

        $this->actingAs($this->salespersonOne)
            ->get('/calendar')
            ->assertOk()
            ->assertSee('calendar-month', false);
    }

    public function test_view_week_returns_200(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=week')
            ->assertOk()
            ->assertSee('calendar-week', false);
    }

    public function test_view_day_returns_200(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=day')
            ->assertOk()
            ->assertSee('calendar-day', false);
    }

    public function test_view_list_returns_200(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=list')
            ->assertOk()
            ->assertSee('calendar-list', false);
    }

    public function test_invalid_view_falls_back_to_month(): void
    {
        $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=invalid')
            ->assertOk()
            ->assertSee('calendar-month', false);
    }

    public function test_owner_filter_is_honoured(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();
        $mine = $this->makeActivity('lead', $lead, now()->addDay(), $this->salespersonOne);
        $other = $this->makeActivity(
            'lead',
            Lead::factory()->forOwner($this->salespersonTwo)->create(),
            now()->addDay(),
            $this->salespersonTwo,
        );

        $this->actingAs($this->admin)
            ->get('/calendar?view=month&owner_id='.$this->salespersonOne->id)
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($other->title);
    }

    public function test_type_filter_is_honoured(): void
    {
        $lead = Lead::factory()->forOwner($this->salespersonOne)->create();

        $llamada = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $this->typeId('llamada'),
            'title' => 'Solo llamada',
            'scheduled_at' => now()->addDay(),
        ], $this->salespersonOne);

        $reunion = $this->service->create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'type_id' => $this->typeId('reunion'),
            'title' => 'Solo reunión',
            'scheduled_at' => now()->addDay(),
        ], $this->salespersonOne);

        $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=list&type_id='.$this->typeId('reunion'))
            ->assertOk()
            ->assertSee('Solo reunión')
            ->assertDontSee('Solo llamada');
    }

    public function test_calendar_requires_authentication(): void
    {
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_navigation_displays_the_viewed_month_and_year(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=month&anchor=2099-06-15');

        $response->assertOk();
        $response->assertSee('btn-prev', false);
        $response->assertSee('btn-today', false);
        $response->assertSee('btn-next', false);
        $response->assertSee('calendar-period', false);
        $response->assertSee('junio 2099');
    }

    public function test_date_picker_preserves_the_active_view_and_selected_date_without_javascript(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=week&anchor=2099-06-15');

        $response->assertOk();
        $response->assertSee('data-testid="calendar-date-picker"', false);
        $response->assertSee('type="date"', false);
        $response->assertSee('name="anchor"', false);
        $response->assertSee('value="2099-06-15"', false);
        $response->assertSee('aria-label="Elegir fecha"', false);
        $response->assertSee('name="view" value="week"', false);
    }

    public function test_list_navigation_moves_to_the_adjacent_month(): void
    {
        $response = $this->actingAs($this->salespersonOne)
            ->get('/calendar?view=list&anchor=2099-06-15');

        $response->assertOk();
        $response->assertSee('view=list&amp;anchor=2099-05-01', false);
        $response->assertSee('view=list&amp;anchor=2099-07-01', false);
    }
}
