<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Exceptions\Courses\InvalidCourseEditionTransition;
use App\Models\Courses\CourseEdition;
use App\Services\Courses\CourseEditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

class CourseEditionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_edition_exceptions_are_throwable_classes(): void
    {
        $this->assertTrue(is_subclass_of(InvalidCourseEditionData::class, Throwable::class));
        $this->assertTrue(is_subclass_of(InvalidCourseEditionTransition::class, Throwable::class));
    }

    public function test_presential_editions_require_an_address(): void
    {
        $service = new CourseEditionService;

        $this->expectException(InvalidCourseEditionData::class);
        $service->validateModality(CourseModality::Presential, null, null);
    }

    public function test_virtual_editions_require_an_access_url(): void
    {
        $service = new CourseEditionService;

        $this->expectException(InvalidCourseEditionData::class);
        $service->validateModality(CourseModality::Virtual, 'Av. Siempre Viva 123', '');
    }

    public function test_valid_modality_payloads_are_normalized(): void
    {
        $service = new CourseEditionService;

        $this->assertSame([
            'modality' => 'presential',
            'address' => 'Sede Lima',
            'access_url' => null,
        ], $service->validateModality(CourseModality::Presential, ' Sede Lima ', null));

        $this->assertSame([
            'modality' => 'virtual',
            'address' => null,
            'access_url' => 'https://meet.example.test',
        ], $service->validateModality(CourseModality::Virtual, null, ' https://meet.example.test '));
    }

    public function test_hybrid_editions_require_address_and_access_url(): void
    {
        $service = new CourseEditionService;

        foreach ([['Sede Lima', null], [null, 'https://meet.example.test']] as [$address, $url]) {
            try {
                $service->validateModality(CourseModality::Hybrid, $address, $url);
                $this->fail('Hybrid modality accepted incomplete location data.');
            } catch (InvalidCourseEditionData $exception) {
                $this->assertSame('Hybrid editions require both address and access URL.', $exception->getMessage());
            }
        }

        $this->assertSame([
            'modality' => 'hybrid',
            'address' => 'Sede Lima',
            'access_url' => 'https://meet.example.test',
        ], $service->validateModality(CourseModality::Hybrid, 'Sede Lima', 'https://meet.example.test'));
    }

    public function test_valid_edition_state_progression_is_persisted(): void
    {
        $service = new CourseEditionService;
        $edition = CourseEdition::factory()->create(['state' => CourseEditionState::Draft]);

        $service->transitionState($edition, CourseEditionState::Scheduled);
        $this->assertSame(CourseEditionState::Scheduled, $edition->refresh()->state);

        $service->transitionState($edition, CourseEditionState::InProgress);
        $this->assertSame(CourseEditionState::InProgress, $edition->refresh()->state);

        $service->transitionState($edition, CourseEditionState::Finished);
        $this->assertSame(CourseEditionState::Finished, $edition->refresh()->state);
    }

    public function test_cancellation_is_allowed_only_before_finished(): void
    {
        $service = new CourseEditionService;

        foreach ([CourseEditionState::Draft, CourseEditionState::Scheduled, CourseEditionState::InProgress] as $state) {
            $edition = CourseEdition::factory()->create(['state' => $state]);
            $service->transitionState($edition, CourseEditionState::Cancelled);
            $this->assertSame(CourseEditionState::Cancelled, $edition->refresh()->state);
        }
    }

    public function test_invalid_state_transitions_are_rejected_without_mutating_edition(): void
    {
        $service = new CourseEditionService;
        $edition = CourseEdition::factory()->create(['state' => CourseEditionState::Draft]);

        try {
            $service->transitionState($edition, CourseEditionState::InProgress);
            $this->fail('Draft edition skipped scheduled state.');
        } catch (InvalidCourseEditionTransition $exception) {
            $this->assertSame(CourseEditionState::Draft, $edition->refresh()->state);
            $this->assertStringContainsString('draft to in_progress', $exception->getMessage());
        }

        $finished = CourseEdition::factory()->create(['state' => CourseEditionState::Finished]);

        try {
            $service->transitionState($finished, CourseEditionState::Cancelled);
            $this->fail('Finished edition was cancelled.');
        } catch (InvalidCourseEditionTransition $exception) {
            $this->assertSame(CourseEditionState::Finished, $finished->refresh()->state);
            $this->assertStringContainsString('finished to cancelled', $exception->getMessage());
        }
    }
}
