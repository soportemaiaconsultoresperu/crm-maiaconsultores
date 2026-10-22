<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Exceptions\Courses\InvalidCourseEditionData;
use App\Models\Courses\CourseActivity;
use App\Models\User;
use App\Services\Courses\CourseActivityService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 6 — authenticated "create activity" HTTP surface.
 *
 * The controller stays thin: the FormRequest validates the attributes the
 * existing CourseActivityService::create() consumes, the policy authorizes,
 * and the service owns the domain rules (code uniqueness, defaults).
 */
class CourseActivityCreateHttpTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['course-talks.view', 'course-talks.activities.manage']);

        return $user;
    }

    public function test_guests_are_redirected_to_login_from_activity_create_and_store(): void
    {
        $this->get(route('course-talks.activities.create'))->assertRedirect(route('login'));

        $this->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-GUEST-001',
            'name' => 'Curso invitado',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_users_without_manage_permission_cannot_create_or_store_activities(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $this->actingAs($viewer)->get(route('course-talks.activities.create'))->assertForbidden();

        $this->actingAs($viewer)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-DENIED-001',
            'name' => 'Curso no autorizado',
        ])->assertForbidden();

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_authorized_user_can_create_an_activity_and_see_it_in_the_index(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->get(route('course-talks.activities.create'))
            ->assertOk()
            ->assertSee('Nuevo curso o charla')
            ->assertSee('Curso')
            ->assertSee('Charla');

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-NEW-001',
            'name' => 'Curso de Alta',
            'official_academic_hours' => '16.00',
            'base_syllabus_json' => ['Modulo 1', '', 'Modulo 2'],
            'talk_includes_certificate' => '0',
            'is_active' => '1',
        ])->assertRedirect(route('course-talks.activities.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-NEW-001',
            'type' => CourseActivityType::Course->value,
        ]);

        $activity = CourseActivity::query()->where('code', 'CUR-NEW-001')->firstOrFail();
        $this->assertSame('Curso de Alta', $activity->name);
        $this->assertSame('curso-de-alta', $activity->slug);
        $this->assertSame('16.00', $activity->official_academic_hours);
        $this->assertSame(['Modulo 1', 'Modulo 2'], $activity->base_syllabus_json);
        $this->assertTrue($activity->is_active);

        $this->actingAs($user)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee('CUR-NEW-001')
            ->assertSee('Curso de Alta');
    }

    public function test_validation_failure_returns_errors_without_persisting(): void
    {
        $user = $this->manager();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => 'not-a-valid-type',
                'name' => '',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors(['type', 'code', 'name', 'official_academic_hours']);

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_talk_activity_flags_are_persisted_through_the_service(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Talk->value,
            'code' => 'CHA-NEW-001',
            'name' => 'Charla de Alta',
            'official_academic_hours' => '4.00',
            'talk_includes_certificate' => '1',
            'talk_certificate_price' => '25.00',
            'is_active' => '1',
        ])->assertRedirect(route('course-talks.activities.index'));

        $activity = CourseActivity::query()->where('code', 'CHA-NEW-001')->firstOrFail();
        $this->assertSame(CourseActivityType::Talk, $activity->type);
        $this->assertTrue($activity->talk_includes_certificate);
        // `talk_certificate_price` still travels in the payload because an
        // operator's cached form can still post it, but it is no longer an
        // activity property: the price moved to the delivery
        // (`course_editions.certificate_charge_amount`) and neither this request
        // nor the service owns an activity-level certificate price any more.
        $this->assertSame([], $activity->base_syllabus_json);
    }

    public function test_duplicate_activity_code_is_rejected_without_creating_a_second_record(): void
    {
        $user = $this->manager();
        CourseActivity::factory()->create(['code' => 'CUR-DUP-001', 'name' => 'Curso Original']);

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
                'type' => CourseActivityType::Course->value,
                'code' => 'CUR-DUP-001',
                'name' => 'Curso Duplicado',
                // The hours are required, so this payload must be valid in every other
                // respect for the duplicate-code rule to be the error under test.
                'official_academic_hours' => '8.00',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('course_activities', 1);
        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-DUP-001',
            'name' => 'Curso Original',
        ]);
    }

    public function test_activity_index_shows_the_create_affordance_only_to_authorized_users(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo(['course-talks.view', 'course-talks.activities.manage']);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $createUrl = route('course-talks.activities.create');

        $this->actingAs($manager)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertSee($createUrl, false);

        $this->actingAs($viewer)->get(route('course-talks.activities.index'))
            ->assertOk()
            ->assertDontSee($createUrl, false);
    }

    // --- The academic hours are required -------------------------------------
    //
    // Found while answering a question about the create form, not by this suite:
    // `official_academic_hours` is NOT NULL in the column with no default, but the
    // form request declared it `nullable` and the form did not require it. Leaving it
    // blank sent an empty string, ConvertEmptyStringsToNull made it a NULL, and the
    // insert died as an uncaught QueryException instead of telling the operator what
    // was missing — the same defect the edition price had, in a different column.
    // Every test above posted the hours, which is exactly why none of them could see it.

    public function test_creating_an_activity_without_academic_hours_is_a_validation_error_not_a_database_error(): void
    {
        $user = $this->manager();

        $this->actingAs($user)
            ->from(route('course-talks.activities.create'))
            ->post(route('course-talks.activities.store'), [
'type' => CourseActivityType::Course->value,
'code' => 'CUR-NOHOURS-001',
'name' => 'Curso sin horas',
            ])
            ->assertRedirect(route('course-talks.activities.create'))
            ->assertSessionHasErrors('official_academic_hours');

        $this->assertDatabaseCount('course_activities', 0);
    }

    public function test_the_service_refuses_an_activity_without_academic_hours_for_callers_that_bypass_http(): void
    {
        $this->expectException(InvalidCourseEditionData::class);

        app(CourseActivityService::class)->create([
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-NOHOURS-002',
            'name' => 'Curso sin horas',
        ]);
    }

    // --- A course cannot carry talk certificate data --------------------------
    //
    // The talk-only flag (`talk_includes_certificate`) is meaningless on a course:
    // a course does not issue a per-activity certificate at its own price. The form
    // now hides it for a course, but a
    // HIDDEN field is only a presentation detail — the payload is the thing that
    // reaches the database, and a payload can be built by anything (curl, a
    // stale browser tab, a future consumer of the service). The invariant
    // therefore belongs to the service, where every caller passes through.

    public function test_a_course_posted_with_talk_certificate_data_is_persisted_without_it(): void
    {
        $user = $this->manager();

        // This payload bypasses the form entirely and passes validation as
        // declared (`boolean` + `numeric|min:0`), so nothing before the service
        // has any reason to object to it.
        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-TALKFLAGS-001',
            'name' => 'Curso con datos de charla',
            'official_academic_hours' => '12.00',
            'talk_includes_certificate' => '1',
            'talk_certificate_price' => '999',
            'is_active' => '1',
        ])->assertRedirect(route('course-talks.activities.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-TALKFLAGS-001',
            'talk_includes_certificate' => 0,
        ]);

        $activity = CourseActivity::query()->where('code', 'CUR-TALKFLAGS-001')->firstOrFail();
        $this->assertSame(CourseActivityType::Course, $activity->type);
        $this->assertFalse($activity->talk_includes_certificate);
    }

    public function test_the_service_strips_talk_certificate_data_from_a_course_for_callers_that_bypass_http(): void
    {
        // Real PHP types here, not the strings an HTML form sends: a caller of
        // the service hands over `true`/`0.01`, and the invariant has to hold for
        // the type as well as for the value.
        $activity = app(CourseActivityService::class)->create([
            'type' => CourseActivityType::Course->value,
            'code' => 'CUR-TALKFLAGS-002',
            'name' => 'Curso con datos de charla (servicio)',
            'official_academic_hours' => '6.00',
            'talk_includes_certificate' => true,
            'talk_certificate_price' => 0.01,
        ]);

        $this->assertFalse($activity->talk_includes_certificate);

        $this->assertDatabaseHas('course_activities', [
            'code' => 'CUR-TALKFLAGS-002',
            'talk_includes_certificate' => 0,
        ]);
    }

    public function test_a_talk_created_through_the_service_keeps_its_talk_certificate_data(): void
    {
        // The negative control: the invariant is a REJECTION of course data, not
        // a blanket wipe. A talk keeps what the operator declared, so a fix that
        // simply zeroed both fields everywhere would fail here.
        $activity = app(CourseActivityService::class)->create([
            'type' => CourseActivityType::Talk->value,
            'code' => 'CHA-TALKFLAGS-001',
            'name' => 'Charla con certificado (servicio)',
            'official_academic_hours' => '3.00',
            'talk_includes_certificate' => true,
            'talk_certificate_price' => 12.50,
        ]);

        $this->assertSame(CourseActivityType::Talk, $activity->type);
        $this->assertTrue($activity->talk_includes_certificate);
    }

    // --- The dead certificate price is gone from the activity form -------------
    //
    // `course_activities.talk_certificate_price` was dropped when the certificate
    // charge moved to the delivery, but the create form kept rendering the input:
    // the operator typed a price, the browser posted it, and the server silently
    // ignored it. A control that cannot change anything is worse than no control,
    // because it makes the operator believe the price they typed was saved. The
    // field that IS still meaningful (`talk_includes_certificate`) stays, and stays
    // talk-only for the existing script.

    public function test_the_activity_form_no_longer_offers_the_dead_certificate_price_field(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->get(route('course-talks.activities.create'))
            ->assertOk()
            ->assertDontSee('talk_certificate_price');
    }

    public function test_the_talk_certificate_checkbox_survives_the_removal_of_the_dead_field(): void
    {
        // The negative control for the removal: the checkbox is a real talk field
        // (it drives certificate eligibility) and must not have been swept away with
        // the dead price. `data-talk-only` is the DOM hook the view's script reads to
        // hide it for a course.
        $user = $this->manager();

        $this->actingAs($user)->get(route('course-talks.activities.create'))
            ->assertOk()
            ->assertSee('talk_includes_certificate')
            ->assertSee('data-talk-only', false)
            ->assertSee('Charla con certificado');
    }

    public function test_a_payload_carrying_the_removed_certificate_price_is_still_accepted(): void
    {
        // A cached form (rendered before the deploy) can still post the removed key.
        // The request no longer declares a rule for it, so it must be IGNORED rather
        // than rejected — otherwise every operator holding a stale tab would get a
        // validation error they cannot fix.
        $user = $this->manager();

        $this->actingAs($user)->post(route('course-talks.activities.store'), [
            'type' => CourseActivityType::Talk->value,
            'code' => 'CHA-STALEPRICE-001',
            'name' => 'Charla con precio obsoleto',
            'official_academic_hours' => '3.00',
            'talk_includes_certificate' => '1',
            'talk_certificate_price' => '999.00',
        ])->assertRedirect(route('course-talks.activities.index'))
            ->assertSessionHasNoErrors();

        $activity = CourseActivity::query()->where('code', 'CHA-STALEPRICE-001')->firstOrFail();

        $this->assertSame(CourseActivityType::Talk, $activity->type);
        $this->assertTrue($activity->talk_includes_certificate);
    }
}
