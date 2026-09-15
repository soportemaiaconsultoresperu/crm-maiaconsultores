<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finishing a delivery is the step that completes its validations, which is one of the
 * eligibility conditions `CourseEligibilityService` checks before a certificate may be
 * issued. Before this action existed, `CourseEditionService::completeValidations()` had
 * no caller anywhere in the application, so every enrollment stayed permanently
 * ineligible and no certificate could ever be generated from the interface.
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
            'state' => CourseEditionState::InProgress,
        ]);
    }

    private function finishUrl(): string
    {
        return route('course-talks.editions.finish', $this->edition);
    }

    private function showUrl(): string
    {
        return route('course-talks.editions.show', $this->edition);
    }

    public function test_guests_are_redirected_to_login_and_nothing_is_completed(): void
    {
        $this->post($this->finishUrl())->assertRedirect(route('login'));

        $this->assertNull($this->edition->fresh()->validations_completed_at);
    }

    public function test_a_user_without_the_edition_manage_permission_cannot_finish(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->givePermissionTo('course-talks.view');

        $this->actingAs($outsider)->post($this->finishUrl())->assertForbidden();

        $this->assertNull($this->edition->fresh()->validations_completed_at);
    }

    /**
     * The point of the whole action: it completes the edition validations, which is the
     * condition nothing in the application could previously set.
     */
    public function test_finishing_marks_the_edition_finished_and_completes_its_validations(): void
    {
        $this->actingAs($this->manager)
            ->post($this->finishUrl())
            ->assertRedirect($this->showUrl());

        $this->edition->refresh();

        $this->assertSame(CourseEditionState::Finished, $this->edition->state);
        $this->assertNotNull($this->edition->validations_completed_at);
    }

    public function test_the_action_is_offered_only_while_the_transition_is_allowed(): void
    {
        $this->actingAs($this->manager)
            ->get($this->showUrl())
            ->assertOk()
            ->assertSee('Finalizar dictado');

        // `finished` is terminal, so the action must not be offered again.
        $this->edition->forceFill(['state' => CourseEditionState::Finished])->save();

        $this->actingAs($this->manager)
            ->get($this->showUrl())
            ->assertOk()
            ->assertDontSee('Finalizar dictado');
    }

    public function test_finishing_from_a_state_that_may_not_be_finished_is_refused(): void
    {
        // A draft may only become scheduled or cancelled.
        $this->edition->forceFill(['state' => CourseEditionState::Draft])->save();

        $this->actingAs($this->manager)
            ->from($this->showUrl())
            ->post($this->finishUrl())
            ->assertSessionHasErrors('edition');

        $this->edition->refresh();

        $this->assertSame(CourseEditionState::Draft, $this->edition->state);
        $this->assertNull($this->edition->validations_completed_at);
    }

    public function test_finishing_twice_does_not_move_the_validation_timestamp(): void
    {
        $this->actingAs($this->manager)->post($this->finishUrl());

        $completedAt = $this->edition->fresh()->validations_completed_at;

        $this->assertNotNull($completedAt);

        // The second attempt is refused, and must not touch the stamp that was already set.
        $this->actingAs($this->manager)
            ->from($this->showUrl())
            ->post($this->finishUrl())
            ->assertSessionHasErrors('edition');

        $this->assertEquals($completedAt, $this->edition->fresh()->validations_completed_at);
    }
}
