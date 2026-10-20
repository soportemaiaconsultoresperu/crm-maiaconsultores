<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Enums\Courses\PaymentStatus;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Exceptions\Courses\InvalidCourseEditionTransition;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseSession;
use App\Models\User;
use App\Services\Courses\CourseActivityService;
use App\Services\Courses\CourseAttendanceService;
use App\Services\Courses\CourseEditionService;
use App\Services\Courses\CourseEnrollmentService;
use App\Services\Courses\CourseGradeCalculator;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fixes found while writing the "Cursos y charlas" user guide:
 *
 *  - S2  editing course/talk activities and dictados, and deleting them only when
 *        nothing hangs off them (an activity without dictados, a dictado without
 *        enrollments);
 *  - S16 domain and validation messages in Spanish with Spanish field names;
 *  - S18 the dictado's "Responsable" can be set from the form;
 *  - S19 the "Activa" flag hides an activity from the default catalog and stops new
 *        dictados, leaving the existing ones untouched;
 *  - S20 no discount and no unreachable enrollment state on screen;
 *  - S4  "usted" in the enrollment form.
 *
 * The edit/update/destroy routes live in routes/course-catalog.php, which is
 * required inside the `course-talks.` group of routes/web.php.
 */
class CourseCatalogGuideFixesTest extends TestCase
{
    use RefreshDatabase;

    private function manager(array $permissions = ['course-talks.view', 'course-talks.activities.manage', 'course-talks.editions.manage']): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function viewer(): User
    {
        return $this->manager(['course-talks.view']);
    }

    private function activity(array $attributes = []): CourseActivity
    {
        return CourseActivity::factory()->create(array_merge([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-GUIA-001',
            'name' => 'Curso de la guía',
            'official_academic_hours' => '12.00',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function activityPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-GUIA-001',
            'name' => 'Curso de la guía editado',
            'official_academic_hours' => '16',
            'base_syllabus_json' => ['Tema editado'],
            'talk_includes_certificate' => '0',
            'is_active' => '1',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function editionPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ED-GUIA-001',
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test/guia',
            'price_amount' => '150',
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-05',
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // S2 — activity edit and guarded delete (service)
    // ---------------------------------------------------------------------

    public function test_activity_service_updates_an_activity_keeping_its_own_code(): void
    {
        $activity = $this->activity();

        $updated = app(CourseActivityService::class)->update($activity, $this->activityPayload());

        $this->assertSame('Curso de la guía editado', $updated->name);
        $this->assertSame('16.00', $updated->official_academic_hours);
        $this->assertSame(['Tema editado'], $updated->base_syllabus_json);
        $this->assertSame('CUR-GUIA-001', $updated->code);
    }

    public function test_activity_service_rejects_a_code_used_by_another_activity_in_spanish(): void
    {
        $this->activity(['code' => 'CUR-OTRO-001', 'name' => 'Otro curso']);
        $activity = $this->activity();

        try {
            app(CourseActivityService::class)->update($activity, $this->activityPayload(['code' => 'CUR-OTRO-001']));
            $this->fail('A duplicate code must be rejected.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('code', $exception->field());
            $this->assertSame('El código «CUR-OTRO-001» ya está en uso por otro curso o charla.', $exception->getMessage());
        }
    }

    public function test_activity_type_cannot_change_once_it_has_dictados(): void
    {
        $activity = $this->activity();
        CourseEdition::factory()->for($activity, 'activity')->create();

        try {
            app(CourseActivityService::class)->update($activity, $this->activityPayload(['type' => CourseActivityType::Talk->value]));
            $this->fail('The type of an activity with dictados must not change.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('type', $exception->field());
            $this->assertStringContainsString('ya tiene dictados', $exception->getMessage());
        }

        $this->assertSame(CourseActivityType::Course, $activity->fresh()->type);
    }

    public function test_activity_with_dictados_cannot_be_deleted(): void
    {
        $activity = $this->activity();
        CourseEdition::factory()->for($activity, 'activity')->create();

        try {
            app(CourseActivityService::class)->delete($activity);
            $this->fail('An activity with dictados must not be deleted.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('No se puede eliminar el curso o charla porque tiene dictados registrados. Elimine primero sus dictados.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('course_activities', ['id' => $activity->id]);
    }

    public function test_activity_without_dictados_is_soft_deleted(): void
    {
        $activity = $this->activity();

        app(CourseActivityService::class)->delete($activity);

        $this->assertSoftDeleted('course_activities', ['id' => $activity->id]);
    }

    // ---------------------------------------------------------------------
    // S2 / S18 — dictado edit and guarded delete (service)
    // ---------------------------------------------------------------------

    public function test_edition_service_updates_a_dictado_including_its_responsible(): void
    {
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Rosa Responsable']);
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create(['code' => 'ED-GUIA-001', 'responsible_user_id' => null]);

        $updated = app(CourseEditionService::class)->update($edition, $this->editionPayload([
            'modality' => CourseModality::Presential->value,
            'address' => 'Av. Arequipa 123',
            'access_url' => null,
            'price_amount' => '180.50',
            'responsible_user_id' => $responsible->id,
        ]));

        $this->assertSame(CourseModality::Presential, $updated->modality);
        $this->assertSame('Av. Arequipa 123', $updated->address);
        $this->assertNull($updated->access_url);
        $this->assertSame('180.50', $updated->price_amount);
        $this->assertSame($responsible->id, $updated->responsible_user_id);
        $this->assertSame('ED-GUIA-001', $updated->code);
        // The lifecycle is not part of the edit form.
        $this->assertSame(CourseEditionState::Draft, $updated->state);
    }

    public function test_edition_service_rejects_a_code_used_by_another_dictado_in_spanish(): void
    {
        $activity = $this->activity();
        CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-OTRO-001']);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-GUIA-001']);

        try {
            app(CourseEditionService::class)->update($edition, $this->editionPayload(['code' => 'ED-OTRO-001']));
            $this->fail('A duplicate code must be rejected.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('code', $exception->field());
            $this->assertSame('El código de dictado «ED-OTRO-001» ya está en uso.', $exception->getMessage());
        }
    }

    public function test_dictado_with_enrollments_cannot_be_deleted(): void
    {
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();
        CourseEnrollment::factory()->for($edition, 'edition')->create();

        try {
            app(CourseEditionService::class)->delete($edition);
            $this->fail('A dictado with enrollments must not be deleted.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('No se puede eliminar el dictado porque tiene participantes inscritos.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('course_editions', ['id' => $edition->id]);
    }

    public function test_dictado_without_enrollments_is_soft_deleted(): void
    {
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();

        app(CourseEditionService::class)->delete($edition);

        $this->assertSoftDeleted('course_editions', ['id' => $edition->id]);
    }

    // ---------------------------------------------------------------------
    // S19 — inactive activities (service)
    // ---------------------------------------------------------------------

    public function test_an_inactive_activity_cannot_get_new_dictados_but_existing_ones_still_update(): void
    {
        $activity = $this->activity(['is_active' => false]);
        $existing = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-VIEJO-001']);

        try {
            app(CourseEditionService::class)->create($activity, $this->editionPayload());
            $this->fail('An inactive activity must not get new dictados.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('No se pueden crear dictados de un curso o charla inactivo. Actívelo primero desde «Editar».', $exception->getMessage());
        }

        $this->assertSame(1, CourseEdition::query()->count());

        $updated = app(CourseEditionService::class)->update($existing, $this->editionPayload(['code' => 'ED-VIEJO-001', 'price_amount' => '99']));
        $this->assertSame('99.00', $updated->price_amount);
    }

    // ---------------------------------------------------------------------
    // S16 — Spanish domain messages
    // ---------------------------------------------------------------------

    public function test_modality_location_rules_speak_spanish(): void
    {
        $service = app(CourseEditionService::class);

        $messages = [];
        foreach ([
            [CourseModality::Presential, null, null],
            [CourseModality::Virtual, null, null],
            [CourseModality::Hybrid, 'Av. Siempre Viva 742', null],
        ] as [$modality, $address, $url]) {
            try {
                $service->validateModality($modality, $address, $url);
            } catch (InvalidCourseEditionData $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame([
            'Para un dictado presencial, la dirección es obligatoria.',
            'Para un dictado virtual, el enlace de acceso es obligatorio.',
            'Para un dictado híbrido, la dirección y el enlace de acceso son obligatorios.',
        ], $messages);
    }

    public function test_state_transition_rejection_speaks_spanish(): void
    {
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create(['state' => CourseEditionState::Draft]);

        $this->expectException(InvalidCourseEditionTransition::class);
        $this->expectExceptionMessage('No se puede cambiar el estado del dictado de «Borrador» a «Finalizada».');

        app(CourseEditionService::class)->transitionState($edition, CourseEditionState::Finished);
    }

    public function test_enrollment_and_payment_rejections_speak_spanish(): void
    {
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();
        $service = app(CourseEnrollmentService::class);
        $participant = [
            'first_name' => 'Luz', 'last_name' => 'Ramos', 'document_type' => 'DNI',
            'document_number' => '12345678', 'email' => 'luz@example.test', 'mobile' => '+51 999 111 222',
        ];
        $enrollment = $service->enroll($edition, $participant);

        $messages = [];
        foreach ([
            fn () => $service->enroll($edition, $participant),
            fn () => $service->enroll($edition, ['first_name' => 'Sin datos']),
            fn () => $service->changePaymentStatus($enrollment, PaymentStatus::Waived),
            fn () => $service->changePaymentStatus($enrollment, PaymentStatus::Refunded),
            fn () => $service->enrollGroup($edition, ['payer_name' => 'Empresa'], []),
        ] as $attempt) {
            try {
                $attempt();
            } catch (InvalidCourseEditionData $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame([
            'Este participante ya está inscrito en este dictado.',
            'Cada participante necesita nombres, apellidos, tipo y número de documento, correo y un celular con código de país (por ejemplo, +51).',
            'Exonerar el pago requiere autorización.',
            'No se permite cambiar el estado de pago de «Pendiente» a «Reembolsado».',
            'La inscripción de grupo necesita al menos un participante.',
        ], $messages);
    }

    public function test_grade_and_attendance_rejections_speak_spanish(): void
    {
        try {
            CourseGradeCalculator::calculate(['25']);
            $this->fail('A grade above 20 must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('La nota «25» no es válida: debe ser un número entre 0 y 20 con hasta dos decimales.', $exception->getMessage());
        }

        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();
        $session = CourseSession::factory()->for($edition, 'edition')->create(['sort_order' => 1]);
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->create();

        try {
            app(CourseAttendanceService::class)->mark($session, $enrollment, 'tal-vez');
            $this->fail('An unknown attendance status must be rejected.');
        } catch (InvalidCourseEditionData $exception) {
            $this->assertSame('El estado de asistencia no es válido.', $exception->getMessage());
        }
    }

    public function test_validation_errors_use_spanish_field_names(): void
    {
        $user = $this->manager();
        $activity = $this->activity();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), $this->activityPayload(['code' => 'CUR-NUEVO', 'official_academic_hours' => '']))
            ->assertSessionHasErrors('official_academic_hours');
        $this->assertStringContainsString('horas académicas', session('errors')->first('official_academic_hours'));

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), $this->editionPayload(['price_amount' => '', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01']))
            ->assertSessionHasErrors(['price_amount', 'ends_on']);
        $errors = session('errors');
        $this->assertStringContainsString('precio', $errors->first('price_amount'));
        $this->assertStringNotContainsString('price amount', $errors->first('price_amount'));
        $this->assertStringContainsString('fecha de fin', $errors->first('ends_on'));
        $this->assertStringContainsString('fecha de inicio', $errors->first('ends_on'));
    }

    // ---------------------------------------------------------------------
    // S19 — catalog list and dictado creation over HTTP (existing routes)
    // ---------------------------------------------------------------------

    public function test_the_catalog_hides_inactive_activities_unless_asked_to_show_them(): void
    {
        $viewer = $this->viewer();
        $this->activity(['code' => 'CUR-ACTIVO', 'name' => 'Curso activo']);
        $this->activity(['code' => 'CUR-INACTIVO', 'name' => 'Curso apagado', 'is_active' => false]);

        $this->actingAs($viewer)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('Curso activo')
            ->assertDontSee('Curso apagado')
            ->assertSee('Mostrar inactivas');

        $this->actingAs($viewer)->get(route('course-talks.activities.index', ['show_inactive' => 1]))
            ->assertOk()
            ->assertSee('Curso activo')
            ->assertSee('Curso apagado')
            ->assertSee('data-testid="course-talks-activity-inactive-badge-', false);
    }

    public function test_an_inactive_activity_offers_no_new_dictado_and_refuses_one_over_http(): void
    {
        $user = $this->manager();
        $activity = $this->activity(['is_active' => false]);

        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertDontSee('data-testid="course-talks-edition-create-link"', false)
            ->assertSee('no admite nuevos dictados');

        $this->actingAs($user)->get(route('course-talks.editions.create', $activity))
            ->assertRedirect(route('course-talks.activities.show', $activity))
            ->assertSessionHas('error', 'No se pueden crear dictados de un curso o charla inactivo. Actívelo primero desde «Editar».');

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), $this->editionPayload())
            ->assertSessionHasErrors('edition');

        $this->assertDatabaseCount('course_editions', 0);
    }

    // ---------------------------------------------------------------------
    // S18 — the dictado's responsible from the form (existing routes)
    // ---------------------------------------------------------------------

    public function test_the_dictado_form_offers_and_stores_a_responsible(): void
    {
        $user = $this->manager();
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Rosa Responsable']);
        $activity = $this->activity();

        $this->actingAs($user)->get(route('course-talks.editions.create', $activity))
            ->assertOk()
            ->assertSee('name="responsible_user_id"', false)
            ->assertSee('Rosa Responsable');

        $this->actingAs($user)
            ->post(route('course-talks.editions.store', $activity), $this->editionPayload(['responsible_user_id' => $responsible->id]))
            ->assertRedirect();

        $edition = CourseEdition::query()->firstOrFail();
        $this->assertSame($responsible->id, $edition->responsible_user_id);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Rosa Responsable');
    }

    // ---------------------------------------------------------------------
    // S20 / S4 — what the enrollment screens show (existing routes)
    // ---------------------------------------------------------------------

    public function test_the_enrollment_screens_show_no_discount_no_unreachable_state_and_use_usted(): void
    {
        $user = $this->manager(['course-talks.view', 'course-talks.participants.manage']);
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();
        CourseEnrollment::factory()->for($edition, 'edition')->create();

        $index = $this->actingAs($user)->get(route('course-talks.enrollments.index', $edition))->assertOk();
        $index->assertSee('Inscrito')->assertDontSee('Descuento');
        foreach (['Confirmado', 'Completado', 'Retirado', 'No asistió'] as $unreachable) {
            $index->assertDontSee($unreachable);
        }

        $create = $this->actingAs($user)->get(route('course-talks.enrollments.create', $edition))->assertOk();
        foreach (['Elegí', 'tenés', 'Cargá', 'Seleccioná'] as $voseo) {
            $create->assertDontSee($voseo);
        }
        $create->assertSee('Elija esta opción');
    }

    // ---------------------------------------------------------------------
    // S2 — HTTP edit/update/destroy (routes/course-catalog.php)
    // ---------------------------------------------------------------------

    public function test_activity_edit_form_update_and_guarded_destroy_over_http(): void
    {
        $user = $this->manager();
        $activity = $this->activity();

        $this->actingAs($user)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertSee(route('course-talks.activities.edit', $activity), false);

        $this->actingAs($user)->get(route('course-talks.activities.edit', $activity))
            ->assertOk()
            ->assertSee('value="CUR-GUIA-001"', false)
            ->assertSee('Guardar cambios');

        $this->actingAs($user)
            ->put(route('course-talks.activities.update', $activity), $this->activityPayload(['is_active' => '0']))
            ->assertRedirect(route('course-talks.activities.show', $activity))
            ->assertSessionHas('status');

        $activity->refresh();
        $this->assertSame('Curso de la guía editado', $activity->name);
        $this->assertFalse($activity->is_active);

        $edition = CourseEdition::factory()->for($activity, 'activity')->create();

        $this->actingAs($user)
            ->delete(route('course-talks.activities.destroy', $activity))
            ->assertRedirect(route('course-talks.activities.show', $activity))
            ->assertSessionHas('error', 'No se puede eliminar el curso o charla porque tiene dictados registrados. Elimine primero sus dictados.');
        $this->assertNotSoftDeleted('course_activities', ['id' => $activity->id]);

        $edition->delete();

        $this->actingAs($user)
            ->delete(route('course-talks.activities.destroy', $activity))
            ->assertRedirect(route('course-talks.activities.index'))
            ->assertSessionHas('status');
        $this->assertSoftDeleted('course_activities', ['id' => $activity->id]);
    }

    public function test_activity_edit_surfaces_require_the_activity_manage_permission(): void
    {
        $viewer = $this->viewer();
        $activity = $this->activity();

        $this->actingAs($viewer)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertDontSee(route('course-talks.activities.edit', $activity), false);
        $this->actingAs($viewer)->get(route('course-talks.activities.edit', $activity))->assertForbidden();
        $this->actingAs($viewer)->put(route('course-talks.activities.update', $activity), $this->activityPayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('course-talks.activities.destroy', $activity))->assertForbidden();

        $this->assertNotSoftDeleted('course_activities', ['id' => $activity->id]);
        $this->assertSame('Curso de la guía', $activity->fresh()->name);
    }

    public function test_dictado_edit_form_update_and_guarded_destroy_over_http(): void
    {
        $user = $this->manager();
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Beto Responsable']);
        $activity = $this->activity();
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-GUIA-001', 'responsible_user_id' => null]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee(route('course-talks.editions.edit', $edition), false);

        $this->actingAs($user)->get(route('course-talks.editions.edit', $edition))
            ->assertOk()
            ->assertSee('value="ED-GUIA-001"', false)
            ->assertSee('name="responsible_user_id"', false)
            ->assertSee('Beto Responsable');

        $this->actingAs($user)
            ->put(route('course-talks.editions.update', $edition), $this->editionPayload(['price_amount' => '210', 'responsible_user_id' => $responsible->id]))
            ->assertRedirect(route('course-talks.editions.show', $edition))
            ->assertSessionHas('status');

        $edition->refresh();
        $this->assertSame('210.00', $edition->price_amount);
        $this->assertSame($responsible->id, $edition->responsible_user_id);

        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->create();

        $this->actingAs($user)
            ->delete(route('course-talks.editions.destroy', $edition))
            ->assertRedirect(route('course-talks.editions.show', $edition))
            ->assertSessionHas('error', 'No se puede eliminar el dictado porque tiene participantes inscritos.');
        $this->assertNotSoftDeleted('course_editions', ['id' => $edition->id]);

        $enrollment->forceDelete();

        $this->actingAs($user)
            ->delete(route('course-talks.editions.destroy', $edition))
            ->assertRedirect(route('course-talks.activities.show', $activity))
            ->assertSessionHas('status');
        $this->assertSoftDeleted('course_editions', ['id' => $edition->id]);
    }

    public function test_dictado_edit_reports_modality_errors_under_the_field(): void
    {
        $user = $this->manager();
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create(['code' => 'ED-GUIA-001']);

        $this->actingAs($user)
            ->from(route('course-talks.editions.edit', $edition))
            ->put(route('course-talks.editions.update', $edition), $this->editionPayload(['access_url' => '']))
            ->assertRedirect(route('course-talks.editions.edit', $edition))
            ->assertSessionHasErrors(['access_url' => 'Para un dictado virtual, el enlace de acceso es obligatorio.']);
    }

    public function test_dictado_edit_surfaces_require_the_edition_manage_permission(): void
    {
        $viewer = $this->viewer();
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create();

        $this->actingAs($viewer)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertDontSee(route('course-talks.editions.edit', $edition), false);
        $this->actingAs($viewer)->get(route('course-talks.editions.edit', $edition))->assertForbidden();
        $this->actingAs($viewer)->put(route('course-talks.editions.update', $edition), $this->editionPayload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('course-talks.editions.destroy', $edition))->assertForbidden();

        $this->assertNotSoftDeleted('course_editions', ['id' => $edition->id]);
    }
}
