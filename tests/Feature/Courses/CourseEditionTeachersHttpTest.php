<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseModality;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEditionTeacher;
use App\Models\Courses\CourseSession;
use App\Models\User;
use App\Services\Courses\CourseEditionService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6 — authenticated management of an edition's teachers.
 *
 * The controller stays thin: SyncEditionTeachersRequest validates only the
 * attributes CourseEditionService::syncTeachers() consumes, the
 * CourseEditionPolicy `update` ability authorizes, and the service owns the
 * replace-the-whole-list rule plus the `sort_order` derived from the array
 * position.
 *
 * Payloads here are the shape the Blade form submits, including its two
 * UI-only affordances (`teachers.*.remove` and the optional `new_teacher`
 * slot) which never reach the service as data.
 */
class CourseEditionTeachersHttpTest extends TestCase
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
            'code' => 'CUR-TCH-001',
            'name' => 'Curso de Docentes',
        ]);

        $this->edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-TCH-001']);
    }

    /** @param array<string, mixed> $attributes */
    private function teacher(string $name, array $attributes = []): array
    {
        return array_merge(['display_name' => $name], $attributes);
    }

    private function teachersUrl(): string
    {
        return route('course-talks.editions.teachers', $this->edition);
    }

    /** @param array<string, mixed> $payload */
    private function sync(array $payload): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->teachersUrl())
            ->post(route('course-talks.editions.teachers.sync', $this->edition), $payload);
    }

    private function seedTeachers(): void
    {
        app(CourseEditionService::class)->syncTeachers($this->edition, [
            $this->teacher('Ana Docente', ['email' => 'ana@example.test']),
            $this->teacher('Luis Docente'),
        ]);
    }

    private function seededTeacherModels(): array
    {
        $this->seedTeachers();

        return $this->edition->teachers()->orderBy('sort_order')->get()->all();
    }

    public function test_guests_are_redirected_to_login_from_teacher_management_routes(): void
    {
        $this->get($this->teachersUrl())->assertRedirect(route('login'));

        $this->post(route('course-talks.editions.teachers.sync', $this->edition), [
            'teachers' => [$this->teacher('Ana Docente')],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_edition_teachers', 0);
    }

    public function test_users_without_edition_manage_permission_cannot_view_or_sync_teachers(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');
        $this->seedTeachers();

        $this->actingAs($viewer)->get($this->teachersUrl())->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.editions.teachers.sync', $this->edition), [
            'teachers' => [$this->teacher('Intruso')],
        ])->assertForbidden();

        $this->assertDatabaseHas('course_edition_teachers', ['display_name' => 'Ana Docente']);
        $this->assertDatabaseMissing('course_edition_teachers', ['display_name' => 'Intruso']);
    }

    public function test_view_shows_the_current_teachers_and_identity_preserving_copy(): void
    {
        $this->seedTeachers();

        $this->actingAs($this->manager)->get($this->teachersUrl())
            ->assertOk()
            ->assertSee('ED-TCH-001')
            ->assertSee('Ana Docente')
            ->assertSee('ana@example.test')
            ->assertSee('Luis Docente')
            ->assertSee('Guardar actualiza docentes por identidad')
            ->assertSee('name="teachers[0][id]"', false);

        // The teachers routes must not shadow the edition detail binding.
        $this->actingAs($this->manager)->get(route('course-talks.editions.show', $this->edition))
            ->assertOk()
            ->assertSee('ED-TCH-001');
    }

    public function test_authorized_sync_persists_the_full_list_in_submitted_order(): void
    {
        $internal = User::factory()->create(['is_active' => true]);

        $this->sync(['teachers' => [
            $this->teacher('Ana Docente', ['email' => 'ana@example.test', 'user_id' => $internal->id]),
            $this->teacher('Luis Docente'),
        ]])
            ->assertRedirect($this->teachersUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseCount('course_edition_teachers', 2);
        $this->assertDatabaseHas('course_edition_teachers', [
            'course_edition_id' => $this->edition->id,
            'display_name' => 'Ana Docente',
            'email' => 'ana@example.test',
            'user_id' => $internal->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_edition_teachers', [
            'course_edition_id' => $this->edition->id,
            'display_name' => 'Luis Docente',
            'email' => null,
            'user_id' => null,
            'sort_order' => 2,
        ]);
    }

    public function test_sync_omits_without_removing_and_updates_existing_teacher_in_place(): void
    {
        [$ana, $luis] = $this->seededTeacherModels();

        $this->sync(['teachers' => [
            $this->teacher('Ana Actualizada', ['id' => $ana->id, 'email' => 'ana.nueva@example.test']),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertDatabaseCount('course_edition_teachers', 2);
        $this->assertDatabaseHas('course_edition_teachers', [
            'id' => $ana->id,
            'display_name' => 'Ana Actualizada',
            'email' => 'ana.nueva@example.test',
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_edition_teachers', [
            'id' => $luis->id,
            'display_name' => 'Luis Docente',
            'sort_order' => 2,
            'deleted_at' => null,
        ]);
    }

    public function test_reordering_preserves_teacher_ids(): void
    {
        [$ana, $luis] = $this->seededTeacherModels();

        $this->sync(['teachers' => [
            $this->teacher('Luis Docente', ['id' => $luis->id]),
            $this->teacher('Ana Docente', ['id' => $ana->id, 'email' => 'ana@example.test']),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertDatabaseHas('course_edition_teachers', ['id' => $luis->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('course_edition_teachers', ['id' => $ana->id, 'sort_order' => 2]);
    }

    public function test_teacher_payloads_are_validated_per_entry(): void
    {
        $this->seedTeachers();

        // A whitespace-only name and an entry that carries data without a name
        // are both rejected: `display_name` is required per entry.
        $this->sync(['teachers' => [$this->teacher('   '), ['email' => 'sin-nombre@example.test']]])
            ->assertRedirect($this->teachersUrl())
            ->assertSessionHasErrors(['teachers.0.display_name', 'teachers.1.display_name']);

        $this->sync(['teachers' => [$this->teacher('Ana Docente', ['email' => 'correo-invalido'])]])
            ->assertSessionHasErrors('teachers.0.email');

        $this->sync(['teachers' => [$this->teacher('Ana Docente', ['user_id' => 999999])]])
            ->assertSessionHasErrors('teachers.0.user_id');

        // No partial write survived any rejected payload.
        $this->assertDatabaseCount('course_edition_teachers', 2);
        $this->assertDatabaseHas('course_edition_teachers', ['display_name' => 'Ana Docente', 'sort_order' => 1]);
    }

    public function test_malformed_teacher_payloads_are_reported_instead_of_coerced(): void
    {
        $this->seedTeachers();

        $this->sync(['teachers' => 'Ana Docente'])->assertSessionHasErrors('teachers');

        $this->sync(['teachers' => ['Ana Docente']])->assertSessionHasErrors('teachers.0');

        $this->assertDatabaseCount('course_edition_teachers', 2);
    }

        /**
         * The `user_id` link has no meaning yet, so it must not be offered as a
         * visible field — as a bare number input it asked the operator for an
         * internal user id with no lookup. It must still round-trip, or every
         * save would silently null an existing link (both the request and the
         * service read it as `$teacher['user_id'] ?? null`).
         */
        public function test_user_id_is_hidden_from_the_form_but_survives_a_save(): void
        {
            $internal = User::factory()->create(['is_active' => true]);

            $this->sync(['teachers' => [$this->teacher('Ana Docente', ['user_id' => $internal->id])]])
                ->assertRedirect($this->teachersUrl());

            $ana = $this->edition->teachers()->sole();

            $this->assertSame($internal->id, $ana->user_id);

            $this->actingAs($this->manager)
                ->get($this->teachersUrl())
                ->assertOk()
                ->assertDontSee('Usuario interno')
                ->assertSee('type="hidden" name="teachers[0][user_id]" value="'.$internal->id.'"', false);

            // Re-submitting the row exactly as the form renders it keeps the link.
            $this->sync(['teachers' => [[
                'id' => $ana->id,
                'display_name' => 'Ana Docente',
                'email' => null,
                'user_id' => $internal->id,
            ]]])->assertRedirect($this->teachersUrl());

            $this->assertSame($internal->id, $ana->fresh()->user_id);
        }

        public function test_client_supplied_sort_order_is_ignored_and_the_array_position_wins(): void
    {
        $this->sync(['teachers' => [
            $this->teacher('Ana Docente', ['sort_order' => 99]),
            $this->teacher('Luis Docente', ['sort_order' => 1]),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertDatabaseHas('course_edition_teachers', ['display_name' => 'Ana Docente', 'sort_order' => 1]);
        $this->assertDatabaseHas('course_edition_teachers', ['display_name' => 'Luis Docente', 'sort_order' => 2]);
        $this->assertDatabaseMissing('course_edition_teachers', ['sort_order' => 99]);
    }

    public function test_form_affordances_add_and_remove_teachers_without_adding_blank_rows(): void
    {
        $this->sync([
            'new_teacher' => $this->teacher('Pedro Docente', ['email' => 'pedro@example.test']),
        ])->assertRedirect($this->teachersUrl());

        $this->assertDatabaseHas('course_edition_teachers', ['display_name' => 'Pedro Docente', 'sort_order' => 1]);
        [$pedro] = $this->edition->teachers()->orderBy('sort_order')->get()->all();

        // ...and ignored when the form is re-submitted untouched.
        $this->sync([
            'teachers' => [$this->teacher('Pedro Docente', ['id' => $pedro->id])],
            'new_teacher' => ['display_name' => '', 'email' => '', 'user_id' => ''],
        ])->assertRedirect($this->teachersUrl());

        // `remove` drops the row while preparing the input, so a removed
        // teacher's name never has to be filled in.
        $this->sync(['teachers' => [
            $this->teacher('Pedro Docente', ['id' => $pedro->id, 'remove' => '1']),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertSame(0, CourseEditionTeacher::query()->count());
        $this->assertSoftDeleted('course_edition_teachers', ['id' => $pedro->id]);
    }

    public function test_removing_teacher_assigned_to_active_session_is_blocked_without_mutating_rows(): void
    {
        [$ana, $luis] = $this->seededTeacherModels();
        CourseSession::factory()->for($this->edition, 'edition')->create([
            'teacher_id' => $ana->id,
            'teacher_name' => 'Texto legado',
            'topic' => 'Sesión con docente',
            'sort_order' => 1,
        ]);

        $this->sync(['teachers' => [
            $this->teacher('Ana Docente', ['id' => $ana->id, 'remove' => '1']),
            $this->teacher('Luis Docente', ['id' => $luis->id]),
        ]])->assertRedirect($this->teachersUrl())
            ->assertSessionHasErrors('teachers');

        $this->assertDatabaseHas('course_edition_teachers', [
            'id' => $ana->id,
            'display_name' => 'Ana Docente',
            'sort_order' => 1,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('course_sessions', [
            'course_edition_id' => $this->edition->id,
            'teacher_id' => $ana->id,
            'teacher_name' => 'Texto legado',
        ]);
    }

    public function test_removing_teacher_succeeds_after_session_assignment_is_cleared(): void
    {
        [$ana] = $this->seededTeacherModels();
        CourseSession::factory()->for($this->edition, 'edition')->create([
            'teacher_id' => null,
            'teacher_name' => 'Texto legado',
            'topic' => 'Sesión liberada',
            'sort_order' => 1,
        ]);

        $this->sync(['teachers' => [
            $this->teacher('Ana Docente', ['id' => $ana->id, 'remove' => '1']),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertSoftDeleted('course_edition_teachers', ['id' => $ana->id]);
    }

    public function test_removing_every_teacher_leaves_the_edition_without_teachers(): void
    {
        [$ana, $luis] = $this->seededTeacherModels();

        $this->sync(['teachers' => [
            $this->teacher('Ana Docente', ['id' => $ana->id, 'remove' => '1']),
            $this->teacher('Luis Docente', ['id' => $luis->id, 'remove' => '1']),
        ]])->assertRedirect($this->teachersUrl());

        $this->assertSame(0, CourseEditionTeacher::query()->count());
        $this->assertSame(2, CourseEditionTeacher::withTrashed()->count());
    }

    public function test_foreign_hidden_teacher_id_is_rejected_without_mutating_that_teacher(): void
    {
        $otherEdition = CourseEdition::factory()->create();
        $foreign = CourseEditionTeacher::query()->create([
            'course_edition_id' => $otherEdition->id,
            'display_name' => 'Docente de otro dictado',
            'sort_order' => 1,
        ]);

        $this->sync(['teachers' => [
            $this->teacher('Intruso Editado', ['id' => $foreign->id]),
        ]])->assertRedirect($this->teachersUrl())
            ->assertSessionHasErrors('teachers.0.id');

        $this->assertDatabaseMissing('course_edition_teachers', [
            'id' => $foreign->id,
            'display_name' => 'Intruso Editado',
        ]);
        $this->assertDatabaseHas('course_edition_teachers', [
            'id' => $foreign->id,
            'course_edition_id' => $otherEdition->id,
            'display_name' => 'Docente de otro dictado',
            'sort_order' => 1,
            'deleted_at' => null,
        ]);
    }

    public function test_field_less_service_failure_is_visible_on_the_show_view(): void
    {
        $this->app->instance(CourseEditionService::class, new class extends CourseEditionService
        {
            public function syncTeachers(CourseEdition $edition, array $teachers): void
            {
                throw new InvalidCourseEditionData('No se pudieron actualizar los docentes.');
            }
        });

        $this->sync(['teachers' => [$this->teacher('Ana Docente')]])
            ->assertRedirect($this->teachersUrl());

        // Asserted on the rendered page: the `edition` error bag key is what the
        // reported LOW gap lacked, so if the message reaches the user, both the
        // mapping and the view are correct.
        $this->actingAs($this->manager)->get($this->teachersUrl())
            ->assertOk()
            ->assertSee('No se pudieron actualizar los docentes.');
    }

    public function test_field_less_service_failure_is_visible_on_the_create_view(): void
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'name' => 'Curso Sin Edición',
        ]);

        $this->app->instance(CourseEditionService::class, new class extends CourseEditionService
        {
            public function create(CourseActivity $activity, array $attributes): CourseEdition
            {
                throw new InvalidCourseEditionData('La edición no pudo ser creada.');
            }
        });

        $this->actingAs($this->manager)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity));

        $this->actingAs($this->manager)->get(route('course-talks.editions.create', $activity))
            ->assertOk()
            ->assertSee('La edición no pudo ser creada.');
    }
}
