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
use App\Services\Courses\CourseEnrollmentService;
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

    /**
     * The only activity kind a certificate charge applies to: a TALK that
     * includes a certificate.
     */
    private function certificateTalk(string $code = 'CHA-CERT-001'): CourseActivity
    {
        return CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => $code,
            'name' => 'Charla con certificado',
            'talk_includes_certificate' => true,
        ]);
    }

    private function talkWithoutCertificate(string $code = 'CHA-NOCERT-001'): CourseActivity
    {
        return CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'code' => $code,
            'name' => 'Charla sin certificado',
            'talk_includes_certificate' => false,
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
            'official_academic_hours' => '8.00',
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

    // --- The certificate charge lives on the delivery -------------------------
    //
    // The certificate price moved from the ACTIVITY (the reusable template, whose
    // `talk_certificate_price` column was dropped) to the DELIVERY (the concrete
    // one, whose `certificate_charge_amount` column was added), so the create-form
    // for a delivery is the only place an operator can still enter it.
    //
    // WHERE the field is offered is decided on the SERVER: `$activity` is already
    // bound when `CourseEditionController::create()` renders the view, so the view
    // simply does not render a control that cannot apply. Script-based hiding was
    // rejected here because a course, or a talk without a certificate, is answered
    // by `CourseActivity::issuesTalkCertificate()` before any JavaScript runs, and
    // `CourseEdition`'s write guard zeroes the column anyway — offering the field
    // would be a lie about what the operator can change.

    public function test_the_edition_form_does_not_offer_a_certificate_charge_for_a_course(): void
    {
        $user = $this->editionManager();

        $this->actingAs($user)->get(route('course-talks.editions.create', $this->activity()))
            ->assertOk()
            ->assertDontSee('certificate_charge_amount');
    }

    public function test_the_edition_form_does_not_offer_a_certificate_charge_for_a_talk_without_one(): void
    {
        $user = $this->editionManager();

        $this->actingAs($user)->get(route('course-talks.editions.create', $this->talkWithoutCertificate()))
            ->assertOk()
            ->assertDontSee('certificate_charge_amount');
    }

    public function test_the_edition_form_offers_a_certificate_charge_for_a_talk_that_includes_one(): void
    {
        $user = $this->editionManager();

        $this->actingAs($user)->get(route('course-talks.editions.create', $this->certificateTalk()))
            ->assertOk()
            ->assertSee('certificate_charge_amount');
    }

    public function test_a_certificate_talk_edition_stores_the_submitted_certificate_charge(): void
    {
        $user = $this->editionManager();
        $activity = $this->certificateTalk();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '25.00',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_editions', [
            'course_activity_id' => $activity->id,
            'certificate_charge_amount' => '25.00',
        ]);
    }

    public function test_a_course_edition_ignores_a_certificate_charge_the_payload_tries_to_set(): void
    {
        // The form no longer renders the control for a course, so only a crafted
        // payload (curl, a stale tab, a future consumer of the service) can try.
        // This is the test that proves the CourseEdition write guard is reachable
        // from the CREATE path and not bypassed there.
        $user = $this->editionManager();
        $activity = $this->activity();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '50.00',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $edition = CourseEdition::query()->latest('id')->firstOrFail();

        $this->assertSame('0.00', $edition->certificate_charge_amount);
        $this->assertDatabaseHas('course_editions', [
            'id' => $edition->id,
            'certificate_charge_amount' => '0.00',
        ]);
    }

    public function test_a_talk_without_a_certificate_ignores_a_certificate_charge_the_payload_tries_to_set(): void
    {
        $user = $this->editionManager();
        $activity = $this->talkWithoutCertificate();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '50.00',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_editions', [
            'course_activity_id' => $activity->id,
            'certificate_charge_amount' => '0.00',
        ]);
    }

    public function test_an_omitted_certificate_charge_stores_zero_instead_of_dying_on_a_not_null_column(): void
    {
        // `course_editions.certificate_charge_amount` is NOT NULL with a default of 0.
        // A blank value used to be the exact shape that killed `price_amount`: the
        // request let a NULL through and the insert died as a QueryException instead
        // of storing the zero it means. Both the absent key and the blank string are
        // enumerated here for that reason.
        $user = $this->editionManager();
        $activity = $this->certificateTalk();

        foreach ([['k' => null], ['k' => ''], ['k' => '0']] as $case) {
            $payload = [
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'price_amount' => '150.00',
            ];

            if ($case['k'] !== null) {
                $payload['certificate_charge_amount'] = $case['k'];
            }

            $this->actingAs($user)->post(route('course-talks.editions.store', $activity), $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('course_editions', 3);
        $this->assertDatabaseHas('course_editions', ['certificate_charge_amount' => '0.00']);
    }

    public function test_the_certificate_charge_is_rejected_when_it_is_not_money_within_bounds(): void
    {
        // Money is REJECTED, never clamped: silently turning 999999999 into the
        // maximum, or -1 into 0, would change what the operator typed.
        $user = $this->editionManager();
        $activity = $this->certificateTalk();

        foreach (['-1', 'no-es-un-monto', '999999999.99'] as $invalid) {
            $this->actingAs($user)
                ->from(route('course-talks.editions.create', $activity))
                ->post(route('course-talks.editions.store', $activity), [
                    'modality' => CourseModality::Virtual->value,
                    'access_url' => 'https://meet.example.test',
                    'price_amount' => '150.00',
                    'certificate_charge_amount' => $invalid,
                ])
                ->assertRedirect(route('course-talks.editions.create', $activity))
                ->assertSessionHasErrors('certificate_charge_amount');
        }

        $this->assertDatabaseCount('course_editions', 0);
    }

    public function test_the_certificate_charge_entered_on_the_form_is_what_the_enrollment_is_charged(): void
    {
        // The whole point of moving the money to the delivery: what the operator
        // types on this form is what a participant is charged, and it is part of the
        // subtotal the commercial document taxes. The form is the only INPUT; the
        // charge itself is still resolved by the edition.
        $user = $this->editionManager();
        $activity = $this->certificateTalk();

        $this->actingAs($user)->post(route('course-talks.editions.store', $activity), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '25.00',
        ])->assertRedirect();

        $edition = CourseEdition::query()->latest('id')->firstOrFail();

        $enrollment = app(CourseEnrollmentService::class)->enroll($edition, [
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'document_type' => 'dni',
            'document_number' => '70999001',
            'email' => 'ana.edition-certificate@example.test',
            'mobile' => '+51 999 000 111',
        ]);

        $this->assertSame('25.00', $enrollment->certificate_charge_amount);
        $this->assertSame('175.00', $enrollment->subtotal_amount);
    }

    public function test_the_write_guard_is_reachable_on_the_create_path_for_callers_that_bypass_http(): void
    {
        // The previous commit claims "the write guard forces a course's charge to
        // 0.00 on write". This calls the SERVICE directly, so the FormRequest is not
        // in the way at all: if the value comes back as 0.00 it is because the MODEL
        // enforced it, not because a request happened to drop the key. This is the
        // answer to "is the write guard reachable from the create path?" — yes.
        $edition = app(CourseEditionService::class)->create($this->activity(), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '50.00',
        ]);

        $this->assertSame('0.00', $edition->fresh()->certificate_charge_amount);
    }

    public function test_the_service_passes_the_certificate_charge_through_for_a_certificate_talk(): void
    {
        $edition = app(CourseEditionService::class)->create($this->certificateTalk(), [
            'modality' => CourseModality::Virtual->value,
            'access_url' => 'https://meet.example.test',
            'price_amount' => '150.00',
            'certificate_charge_amount' => '25.00',
        ]);

        $this->assertSame('25.00', $edition->fresh()->certificate_charge_amount);
    }

    // --- The delivery syllabus is pre-filled from the activity --------------
    //
    // The owner's decision: the course feeds the delivery, and the certificate
    // reads the delivery. The pre-fill is SERVER-SIDE on purpose — the activity is
    // already bound when `CourseEditionController::create()` renders the view, so
    // the form arrives pre-filled without a single line of JavaScript and a user
    // without scripts gets exactly the same form. `old()` keeps its precedence, so
    // a rejected submission re-renders what the operator typed instead of the
    // activity template.

    public function test_the_edition_create_form_prefills_the_delivery_syllabus_from_the_activity_base_syllabus(): void
    {
        $user = $this->editionManager();
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-PRE-001',
            'name' => 'Curso Prellenado',
            'base_syllabus_json' => ['Tema A', 'Tema B'],
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.create', $activity))
            ->assertOk()
            ->assertSee('name="syllabus_override_json[]" value="Tema A"', false)
            ->assertSee('name="syllabus_override_json[]" value="Tema B"', false)
            ->assertSee('Temario del dictado');
    }

    public function test_the_edition_create_form_keeps_the_operators_own_syllabus_on_a_rejected_submission(): void
    {
        $user = $this->editionManager();
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-PRE-002',
            'name' => 'Curso Prellenado Fallido',
            'base_syllabus_json' => ['Tema del curso'],
        ]);

        // The payload omits the required price on purpose, so the submission is
        // rejected and the form is re-rendered from the operator's own input.
        $this->actingAs($user)
            ->from(route('course-talks.editions.create', $activity))
            ->followingRedirects()
            ->post(route('course-talks.editions.store', $activity), [
                'modality' => CourseModality::Virtual->value,
                'access_url' => 'https://meet.example.test',
                'syllabus_override_json' => ['Tema editado por el operador'],
            ])
            ->assertOk()
            ->assertSee('name="syllabus_override_json[]" value="Tema editado por el operador"', false)
            ->assertDontSee('name="syllabus_override_json[]" value="Tema del curso"', false);

        $this->assertDatabaseCount('course_editions', 0);
    }

    // --- The delivery detail shows the delivery's own syllabus --------------
    //
    // The edition HTTP surface is covered by this class in this slice; the detail
    // screen itself is reached through the read-only route. Before this change a
    // delivery's edited syllabus was invisible after saving.

    public function test_the_edition_detail_shows_the_deliverys_own_syllabus(): void
    {
        $user = $this->editionManager();
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create([
            'code' => 'ED-SYL-001',
            'syllabus_override_json' => ['Tema A', 'Tema B'],
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Temario del dictado')
            ->assertSee('Tema A')
            ->assertSee('Tema B');
    }

    public function test_the_edition_detail_marks_a_delivery_without_its_own_syllabus(): void
    {
        $user = $this->editionManager();
        $edition = CourseEdition::factory()->for($this->activity(), 'activity')->create([
            'code' => 'ED-SYL-002',
            'syllabus_override_json' => [],
        ]);

        $this->actingAs($user)->get(route('course-talks.editions.show', $edition))
            ->assertOk()
            ->assertSee('Temario del dictado')
            ->assertSee('data-testid="course-talks-edition-syllabus"', false)
            ->assertSee('—');
    }
}
