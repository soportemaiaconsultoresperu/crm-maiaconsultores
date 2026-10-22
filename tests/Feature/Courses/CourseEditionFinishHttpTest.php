<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The delivery lifecycle, and the step that unlocks certificates.
 *
 * `CourseEditionService::completeValidations()` sets `validations_completed_at`, which
 * `CourseEligibilityService` requires before any certificate may be issued. Nothing in the
 * application ever called it — only tests did — so every enrollment stayed permanently in
 * the "not eligible: edition validations pending" state. `transitionState()` had no caller
 * either, which meant the path to `finished` was unreachable too: a delivery created as a
 * draft could not even be moved to `scheduled`, so exposing only the last step would have
 * left the gate just as shut.
 */
class CourseEditionFinishHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['course-talks.view', 'course-talks.editions.manage']);

        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-FIN-001',
            'name' => 'Curso para finalizar',
        ]);

        $this->edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-FIN-001',
            'state' => CourseEditionState::Draft,
        ]);
    }

    private function showUrl(): string
    {
        return route('course-talks.editions.show', $this->edition);
    }

    private function stateUrl(): string
    {
        return route('course-talks.editions.state.update', $this->edition);
    }

    private function moveTo(CourseEditionState $target): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->showUrl())
            ->post($this->stateUrl(), ['state' => $target->value]);
    }

    private function state(): CourseEditionState
    {
        return $this->edition->fresh()->state;
    }

    public function test_guests_are_redirected_to_login_and_nothing_changes(): void
    {
        $this->post($this->stateUrl(), ['state' => CourseEditionState::Scheduled->value])
            ->assertRedirect(route('login'));

        $this->assertSame(CourseEditionState::Draft, $this->state());
        $this->assertNull($this->edition->fresh()->validations_completed_at);
    }

    public function test_a_user_without_the_edition_manage_permission_cannot_move_the_state(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->givePermissionTo('course-talks.view');

        $this->actingAs($outsider)
            ->post($this->stateUrl(), ['state' => CourseEditionState::Scheduled->value])
            ->assertForbidden();

        $this->assertSame(CourseEditionState::Draft, $this->state());
    }

    /**
     * The whole path, because the last step alone is useless: a delivery is created as a
     * draft and the state machine only allows one step at a time.
     */
    public function test_a_delivery_can_be_walked_from_draft_to_finished(): void
    {
        $this->moveTo(CourseEditionState::Scheduled)->assertRedirect($this->showUrl());
        $this->assertSame(CourseEditionState::Scheduled, $this->state());
        $this->assertNull($this->edition->fresh()->validations_completed_at);

        $this->moveTo(CourseEditionState::InProgress)->assertRedirect($this->showUrl());
        $this->assertSame(CourseEditionState::InProgress, $this->state());
        $this->assertNull($this->edition->fresh()->validations_completed_at);

        $this->moveTo(CourseEditionState::Finished)->assertRedirect($this->showUrl());
        $this->assertSame(CourseEditionState::Finished, $this->state());

        // Reaching `finished` is what completes them.
        $this->assertNotNull($this->edition->fresh()->validations_completed_at);
    }

    public function test_a_transition_the_state_machine_forbids_is_refused(): void
    {
        // A draft may only become scheduled or cancelled.
        $this->moveTo(CourseEditionState::Finished)->assertSessionHasErrors('edition');

        $this->assertSame(CourseEditionState::Draft, $this->state());
        $this->assertNull($this->edition->fresh()->validations_completed_at);
    }

    public function test_an_unknown_state_value_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->from($this->showUrl())
            ->post($this->stateUrl(), ['state' => 'not-a-state'])
            ->assertSessionHasErrors('state');

        $this->assertSame(CourseEditionState::Draft, $this->state());
    }

    public function test_only_the_transitions_the_state_machine_allows_are_offered(): void
    {
        $this->actingAs($this->manager)
            ->get($this->showUrl())
            ->assertOk()
            ->assertSee('data-testid="btn-edition-state-scheduled"', false)
            ->assertSee('data-testid="btn-edition-state-cancelled"', false)
            // Not offered from a draft.
            ->assertDontSee('data-testid="btn-edition-state-finished"', false)
            ->assertDontSee('data-testid="btn-edition-state-in_progress"', false);
    }

    public function test_the_finish_step_is_offered_once_the_delivery_is_in_progress(): void
    {
        $this->moveTo(CourseEditionState::Scheduled);
        $this->moveTo(CourseEditionState::InProgress);

        $this->actingAs($this->manager)
            ->get($this->showUrl())
            ->assertOk()
            ->assertSee('data-testid="btn-edition-state-finished"', false);
    }

    public function test_a_finished_delivery_offers_no_further_transition(): void
    {
        $this->moveTo(CourseEditionState::Scheduled);
        $this->moveTo(CourseEditionState::InProgress);
        $this->moveTo(CourseEditionState::Finished);

        $this->actingAs($this->manager)
            ->get($this->showUrl())
            ->assertOk()
            ->assertDontSee('data-testid="btn-edition-state-', false);
    }
}
