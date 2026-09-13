<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseModality;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use App\Services\Courses\CourseEditionService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 6 — authenticated "create edition under an activity" HTTP surface.
 *
 * The controller stays thin: the FormRequest validates only the attributes the
 * existing CourseEditionService::create() consumes, the CourseEditionPolicy
 * authorizes, and the service owns every domain rule (code uniqueness, modality
 * requirements, state/currency/delivery-due defaults).
 *
 * The final block covers the hardening of StoreCourseActivityRequest's
 * prepareForValidation(): genuine type-shape violations must reach validation
 * and fail normally, while blank/whitespace-only syllabus rows are still dropped.
 */
class CourseEditionCreateHttpTest extends TestCase
{
    use RefreshDatabase;

    private function editionManager(): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['course-talks.view', 'course-talks.editions.manage']);

        return $user;
    }

    private function activityManager(): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['course-talks.view', 'course-talks.activities.manage']);

        return $user;
    }

    private function activity(): CourseActivity
    {
        return CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-EDX-001',
            'name' => 'Curso Base',
        ]);
    }

    public function test_guests_are_redirected_to_login_from_edition_create_and_store(): void
    {
        $activity = $this->activity();

        $this->get(route('course-talks.editions.create', $activity))
            ->assertRedirect(route('login'));

        $this->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_users_without_edition_manage_permission_cannot_create_or_store_editions(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $activity = $this->activity();

        $this->actingAs($viewer)->get(route('course-talks.editions.create', $activity))
            ->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
        ])->assertForbidden();

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_authorized_user_can_open_the_form_and_create_an_edition(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)->get(route('course-talks.editions.create', $activity))
            ->assertOk()
            ->assertSee('Nuevo dictado')
            ->assertSee('Presencial')
            ->assertSee('Virtual')
            ->assertSee('Híbrida');

        $response = $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'code' => 'ED-NEW-001',
            'modality' => CourseModality::Hybrid->value,
            'address' => 'Sede Lima',
            'access_url' => 'https://meet.example.test',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'price_amount' => '150.00',
            'syllabus_override_json' => ['Intro', '', '   ', 'Cierre'],
        ]);

        $edition = CourseEdition::query()->where('code', 'ED-NEW-001')->firstOrFail();

        $response->assertRedirect(route('course-talks.editions.show', $edition))
            ->assertSessionHas('status');

        $this->assertSame($activity->id, $edition->course_activity_id);
        $this->assertSame(CourseModality::Hybrid, $edition->modality);
        $this->assertSame('Sede Lima', $edition->address);
        $this->assertSame('https://meet.example.test', $edition->access_url);
        $this->assertSame('150.00', $edition->price_amount);
        $this->assertSame(['Intro', 'Cierre'], $edition->syllabus_override_json);
        $this->assertSame('2026-09-01', $edition->starts_on?->toDateString());
        $this->assertSame('2026-09-30', $edition->ends_on?->toDateString());
    }

    public function test_service_owned_defaults_cannot_be_injected_through_the_request(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'state' => CourseEditionState::Finished->value,
            'currency' => 'USD',
            'delivery_due_days' => 90,
        ])->assertRedirect();

        $edition = CourseEdition::query()->latest('id')->firstOrFail();

        $this->assertSame(CourseEditionState::Draft, $edition->state);
        $this->assertSame('PEN', $edition->currency);
        $this->assertSame(1, $edition->delivery_due_days);
    }

    public function test_duplicate_edition_code_is_surfaced_as_a_code_field_error(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-DUP-001']);

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'code' => 'ED-DUP-001',
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('course_editions', 1);
    }

    public function test_presential_edition_without_address_is_rejected_on_the_address_field(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Presential->value,
                'address' => '   ',
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors('address');

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_hybrid_edition_without_access_link_is_rejected_on_the_access_url_field(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Hybrid->value,
                'address' => 'Sede Lima',
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors('access_url');

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_virtual_edition_without_access_url_is_rejected_on_the_access_url_field(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        // The field must come from the exception, not from whichever submitted
        // location field happens to be blank: address is optional for Virtual.
        foreach ([[], ['address' => 'Sede Lima']] as $location) {
            $this->actingAs($user)
                ->from(route('course-talks.editions.create', $activity))
                ->post(route('course-talks.editions.store', $activity), array_merge([
                    'modality' => CourseModality::Virtual->value,
                    'price_amount' => '150.00',
                ], $location))
                ->assertRedirect(route('course-talks.editions.create', $activity))
                ->assertSessionHasErrors(['access_url' => 'Virtual editions require an access URL.'])
                ->assertSessionDoesntHaveErrors('address');
        }

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_hybrid_edition_without_any_location_field_is_rejected_on_the_address_field(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Hybrid->value,
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors(['address' => 'Hybrid editions require both address and access URL.']);

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_service_failure_without_a_target_field_is_not_attributed_to_a_location_field(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->app->instance(CourseEditionService::class, new class extends CourseEditionService
        {
            public function create(CourseActivity $activity, array $attributes): CourseEdition
            {
                throw new InvalidCourseEditionData('La edición no pudo ser creada.');
            }
        });

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Virtual->value,
                'price_amount' => '150.00',
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors(['edition' => 'La edición no pudo ser creada.'])
            ->assertSessionDoesntHaveErrors(['code', 'address', 'access_url']);

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_edition_exception_carries_an_optional_target_field(): void
    {
        $legacy = new InvalidCourseEditionData('Sin campo.');

        $this->assertNull($legacy->field());
        $this->assertSame('Sin campo.', $legacy->getMessage());

        $targeted = InvalidCourseEditionData::forField('access_url', 'Falta el enlace.');

        $this->assertSame('access_url', $targeted->field());
        $this->assertSame('Falta el enlace.', $targeted->getMessage());
        $this->assertInstanceOf(\InvalidArgumentException::class, $targeted);
    }

    public function test_virtual_edition_without_address_succeeds_and_drops_blank_syllabus_rows(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'syllabus_override_json' => ['Intro', '', '   '],
        ])->assertRedirect();

        $edition = CourseEdition::query()->latest('id')->firstOrFail();

        $this->assertSame(CourseModality::Virtual, $edition->modality);
        $this->assertNull($edition->address);
        $this->assertSame('https://meet.example.test', $edition->access_url);
        $this->assertSame(['Intro'], $edition->syllabus_override_json);
    }

    public function test_edition_code_is_optional_and_defaults_to_null(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
        ])->assertRedirect();

        $edition = CourseEdition::query()->latest('id')->firstOrFail();

        $this->assertNull($edition->code);
        $this->assertSame($activity->id, $edition->course_activity_id);
    }

    public function test_edition_request_rejects_nested_syllabus_entries_instead_of_dropping_them(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'syllabus_override_json' => ['Intro', ['A', 'B']],
            ])
            ->assertRedirect(route('course-talks.editions.create', $activity))
            ->assertSessionHasErrors('syllabus_override_json.1');

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_edition_create_view_rerenders_a_rejected_nested_syllabus_instead_of_crashing(): void
    {
        $user = $this->editionManager();
        $activity = $this->activity();

        // Follow the redirect instead of only asserting it: the re-rendered form
        // used to pass the surviving nested entry straight to `htmlspecialchars()`
        // as `value="{{ $topic }}"`, which threw a TypeError and returned a 500
        // instead of the intended validation error.
        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->followingRedirects()
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'syllabus_override_json' => ['Intro', ['A', 'B']],
            ])
            ->assertOk()
            ->assertSee('debe ser una cadena de texto');

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_activity_create_view_rerenders_a_rejected_nested_syllabus_instead_of_crashing(): void
    {
        $user = $this->activityManager();

        // Same reachable defect as the edition form: the activity form renders
        // its repopulated syllabus the exact same unguarded way.
        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->followingRedirects()
            ->post(route('course-talks.activities.store'), [
                'type' => CourseActivityType::Course->value,
                'code' => 'CUR-SYL-R1',
                'name' => 'Curso Syllabus Anidado Render',
                'base_syllabus_json' => ['Modulo 1', ['Modulo 2', 'Modulo 3']],
            ])
            ->assertOk()
            ->assertSee('debe ser una cadena de texto');

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_activity_manager_without_edition_permission_is_still_denied(): void
    {
        $activityManager = $this->activityManager();
        $activity = $this->activity();

        $this->actingAs($activityManager)->get(route('course-talks.editions.create', $activity))
            ->assertForbidden();

        $this->actingAs($activityManager)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
        ])->assertForbidden();

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_activity_detail_shows_the_edition_create_affordance_only_when_authorized(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $activity = $this->activity();

        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo(['course-talks.view', 'course-talks.editions.manage']);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $createUrl = route('course-talks.editions.create', $activity);

        $this->actingAs($manager)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertSee($createUrl, false);

        $this->actingAs($viewer)->get(route('course-talks.activities.show', $activity))
            ->assertOk()
            ->assertDontSee($createUrl, false);
    }

    public function test_activity_request_rejects_a_scalar_syllabus_payload_instead_of_coercing_it(): void
    {
        $user = $this->activityManager();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => CourseActivityType::Course->value,
                'code' => 'CUR-SYL-A1',
                'name' => 'Curso Syllabus Escalar',
                'base_syllabus_json' => 'Modulo 1',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors('base_syllabus_json');

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_activity_request_rejects_nested_syllabus_entries_instead_of_silently_dropping_them(): void
    {
        $user = $this->activityManager();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => CourseActivityType::Course->value,
                'code' => 'CUR-SYL-A2',
                'name' => 'Curso Syllabus Anidado',
                'base_syllabus_json' => ['Modulo 1', ['Modulo 2', 'Modulo 3'], 'Modulo 4'],
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors('base_syllabus_json.1');

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_activity_request_still_drops_blank_and_whitespace_syllabus_entries(): void
    {
        $user = $this->activityManager();

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-SYL-A3',
            'name' => 'Curso Syllabus Limpio',
            'base_syllabus_json' => ['Modulo 1', '', '   ', 'Modulo 2'],
        ])->assertRedirect(route('course-talks.activities.index'));

        $activity = CourseActivity::query()->where('code', 'CUR-SYL-A3')->firstOrFail();

        $this->assertSame(['Modulo 1', 'Modulo 2'], $activity->base_syllabus_json);
    }

    // --- The price is required ---------------------------------------------
    //
    // Found in production, not by this suite: the form request declared the price
    // nullable while the column is NOT NULL, so a blank value travelled all the way to
    // the database and surfaced as an uncaught QueryException (1048, cannot be null)
    // instead of a message telling the operator what was missing. Every test above
    // posts a price, which is exactly why none of them could see it.

    public function test_creating_an_edition_without_a_price_is_a_validation_error_not_a_database_error(): void
    {
        $user = $this->editionManager();

        $this->actingAs($user)->post(route('course-talks.editions.store', $this->activity()), [
'modality' => CourseModality::Virtual->value,
'access_url' => 'https://example.test/clase',
'starts_on' => '2026-09-13',
'ends_on' => '2026-09-14',
        ])->assertSessionHasErrors('price_amount');

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_a_free_edition_is_allowed_because_zero_is_a_real_price(): void
    {
        $user = $this->editionManager();

        $this->actingAs($user)->post(route('course-talks.editions.store', $this->activity()), [
'modality' => CourseModality::Virtual->value,
'access_url' => 'https://example.test/clase',
'starts_on' => '2026-09-13',
'ends_on' => '2026-09-14',
'price_amount' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('course_editions', ['price_amount' => '0.00']);
    }

    public function test_the_service_refuses_an_edition_without_a_price_for_callers_that_bypass_http(): void
    {
        $this->expectException(InvalidCourseEditionData::class);

        app(CourseEditionService::class)->create($this->activity(), [
'modality' => CourseModality::Virtual->value,
'access_url' => 'https://example.test/clase',
        ]);
    }
}
