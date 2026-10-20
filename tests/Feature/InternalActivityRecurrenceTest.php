<?php

namespace Tests\Feature;

use App\Models\InternalActivity;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Internal activity recurrence (odd/tasks/internal-activities.md, T2: S5).
 * Daily, weekly or monthly repetition until an end date; occurrences are
 * expanded at read time into the calendar.
 */
class InternalActivityRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('vendedor');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function store(array $overrides): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post(route('internal-activities.store'), array_merge([
            'title' => 'Reunión semanal',
            'starts_at' => '2026-10-07 09:00',
            'audience_type' => 'all',
        ], $overrides));
    }

    private function listRows(string $anchor, string $layer = 'internal'): int
    {
        $html = $this->actingAs($this->user)
            ->get(route('calendar.index', ['view' => 'list', 'anchor' => $anchor, 'layer' => $layer]))
            ->assertOk()
            ->getContent();

        return substr_count($html, 'data-testid="calendar-list-row"');
    }

    public function test_weekly_recurrence_expands_until_end_date(): void
    {
        $this->store(['recurrence_frequency' => 'weekly', 'recurrence_until' => '2026-10-21'])->assertRedirect();

        $this->assertSame(3, $this->listRows('2026-10-01'));
        $this->assertSame(0, $this->listRows('2026-11-01'));
    }

    public function test_daily_recurrence_includes_every_day_of_the_range(): void
    {
        $this->store(['recurrence_frequency' => 'daily', 'recurrence_until' => '2026-11-03'])->assertRedirect();

        $this->assertSame(25, $this->listRows('2026-10-01'));
        $this->assertSame(3, $this->listRows('2026-11-01'));
    }

    public function test_monthly_recurrence_appears_in_later_months_and_shows_in_default_layer(): void
    {
        $this->store([
            'title' => 'Cierre mensual',
            'starts_at' => '2026-10-31 15:00',
            'recurrence_frequency' => 'monthly',
            'recurrence_until' => '2027-01-31',
        ])->assertRedirect();

        $this->assertSame(1, $this->listRows('2026-11-01', ''));
        $html = $this->actingAs($this->user)
            ->get(route('calendar.index', ['view' => 'list', 'anchor' => '2026-11-01']))
            ->getContent();
        $this->assertStringContainsString('30/11/2026 15:00', $html);
        $this->assertSame(1, $this->listRows('2027-01-01'));
    }

    public function test_activity_without_recurrence_shows_once(): void
    {
        $this->store([])->assertRedirect();

        $this->assertSame(1, $this->listRows('2026-10-01'));
        $this->assertSame(0, $this->listRows('2026-11-01'));
    }

    public function test_recurrence_validation(): void
    {
        $this->store(['recurrence_frequency' => 'weekly'])->assertSessionHasErrors('recurrence_until');
        $this->store(['recurrence_frequency' => 'yearly', 'recurrence_until' => '2026-12-01'])->assertSessionHasErrors('recurrence_frequency');
        $this->store(['recurrence_frequency' => 'daily', 'recurrence_until' => '2026-10-01'])->assertSessionHasErrors('recurrence_until');
        $this->store(['recurrence_frequency' => 'daily', 'recurrence_until' => '2027-10-08'])->assertSessionHasErrors('recurrence_until');

        $this->assertSame(0, InternalActivity::query()->count());
    }

    public function test_show_page_describes_recurrence(): void
    {
        $this->store(['recurrence_frequency' => 'weekly', 'recurrence_until' => '2026-12-30'])->assertRedirect();
        $activity = InternalActivity::query()->firstOrFail();

        $this->actingAs($this->user)->get(route('internal-activities.show', $activity))
            ->assertOk()
            ->assertSee('Semanal hasta el 30/12/2026');
    }
}
