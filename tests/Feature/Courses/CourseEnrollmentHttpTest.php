<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\PaymentStatus;
use App\Models\Contact;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Customer;
use App\Models\User;
use App\Services\Courses\CourseEnrollmentService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6.b — authenticated enrollment and participant surface of one edition.
 *
 * The controller stays thin: StoreCourseEnrollmentRequest /
 * StoreCourseEnrollmentGroupRequest validate only the attributes
 * CourseEnrollmentService::enroll() and ::enrollGroup() consume,
 * CourseEnrollmentPolicy (through CourseEditionPolicy::view for the list)
 * authorizes, and the service owns participant deduplication, minimum-data
 * requirements, duplicate-enrollment rejection, group atomicity and payment
 * transitions.
 */
class CourseEnrollmentHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['course-talks.view', 'course-talks.participants.manage']);

        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'code' => 'CUR-ENR-001',
            'name' => 'Curso de Inscripciones',
        ]);

        $this->edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-ENR-001',
            'price_amount' => '100.00',
            'currency' => 'PEN',
        ]);
    }

    /**
     * The minimum participant dataset the service requires.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function minimumParticipant(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Luz',
            'last_name' => 'Ramos',
            'document_type' => 'dni',
            'document_number' => '12345678',
            'email' => 'luz@example.test',
            'mobile' => '+51 999 111 222',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function contact(array $overrides = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'email' => 'ana@example.test',
            'phone' => '+51 999 111 222',
        ], $overrides));
    }

    private function indexUrl(): string
    {
        return route('course-talks.enrollments.index', $this->edition);
    }

    private function createUrl(): string
    {
        return route('course-talks.enrollments.create', $this->edition);
    }

    /** @param array<string, mixed> $payload */
    private function storeIndividual(array $payload): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->createUrl())
            ->post(route('course-talks.enrollments.store', $this->edition), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function storeGroup(array $payload): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->createUrl())
            ->post(route('course-talks.enrollments.groups.store', $this->edition), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function changePayment(CourseEnrollment $enrollment, array $payload): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from($this->indexUrl())
            ->patch(route('course-talks.enrollments.payment-status.update', $enrollment), $payload);
    }

    /**
     * KNOWN DEFECT, documented on purpose — invert this test when it is fixed.
     *
     * Deduplication runs per SOURCE, so the same human can end up enrolled twice.
     *
     * A participant built from a CRM contact is matched by `contact_id` and carries a
     * fabricated document. The same person entered by hand is matched by
     * `(document_type, document_number_norm)`. Those two keys cannot collide, and the
     * duplicate guard compares `course_participant_id`, so nothing stops one person from
     * holding two participants — and therefore two enrollments — in a single delivery.
     *
     * The assertions below describe the CURRENT, WRONG behaviour. When the defect is
     * fixed they must be changed to expect one participant and one enrollment; a failure
     * here then means progress, not a regression.
     */
    public function test_known_defect_the_same_person_can_be_enrolled_twice_through_different_sources(): void
    {
        $contact = $this->contact();

        // Enrolled from the CRM contact, with no document of her own.
        $this->storeIndividual([
            'participant_source' => 'contact',
            'contact_id' => $contact->id,
        ])->assertRedirect($this->indexUrl());

        // The same woman, entered by hand with her real document.
        $this->storeIndividual(array_merge(
            ['participant_source' => 'new'],
            $this->minimumParticipant([
                'first_name' => 'Ana',
                'last_name' => 'Torres',
                'document_type' => 'dni',
                'document_number' => '87654321',
                'email' => 'ana@example.test',
                'mobile' => '+51 999 111 222',
            ]),
        ))->assertRedirect($this->indexUrl());

        $this->assertSame(2, CourseParticipant::count(), 'One person became two participants.');
        $this->assertSame(2, CourseEnrollment::count(), 'One person holds two enrollments in one delivery.');
    }

    /**
     * The document fabricated for a contact-derived participant is an internal key. It
     * must not be rendered as though the person carried that number, on either surface
     * that lists participants.
     */
    public function test_a_contact_participant_does_not_show_the_fabricated_document(): void
    {
        $contact = $this->contact();

        $this->storeIndividual([
            'participant_source' => 'contact',
            'contact_id' => $contact->id,
        ])->assertRedirect($this->indexUrl());

        $participant = CourseParticipant::sole();
        $this->assertSame(CourseParticipant::SYNTHETIC_DOCUMENT_TYPE, $participant->document_type);
        $this->assertNull($participant->displayDocument());

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Torres')
            ->assertDontSee('contact-'.$contact->id);

        $this->actingAs($this->manager)->get($this->createUrl())
            ->assertOk()
            ->assertSee('Torres')
            ->assertDontSee('contact-'.$contact->id);
    }

    public function test_guests_are_redirected_to_login_from_enrollment_routes(): void
    {
        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create();

        $this->get($this->indexUrl())->assertRedirect(route('login'));
        $this->get($this->createUrl())->assertRedirect(route('login'));

        $this->post(route('course-talks.enrollments.store', $this->edition), [
            'participant_source' => 'new',
        ] + $this->minimumParticipant())->assertRedirect(route('login'));

        $this->post(route('course-talks.enrollments.groups.store', $this->edition), [
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [$this->minimumParticipant()],
        ])->assertRedirect(route('login'));

        $this->patch(route('course-talks.enrollments.payment-status.update', $enrollment), [
            'payment_status' => PaymentStatus::Paid->value,
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_enrollments', 1);
        $this->assertDatabaseCount('course_enrollment_groups', 0);
    }

    public function test_users_without_module_permission_cannot_reach_the_enrollment_surface(): void
    {
        $stranger = User::factory()->create(['is_active' => true]);
        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create();

        $this->actingAs($stranger)->get($this->indexUrl())->assertForbidden();
        $this->actingAs($stranger)->get($this->createUrl())->assertForbidden();
        $this->actingAs($stranger)->post(route('course-talks.enrollments.store', $this->edition), [
            'participant_source' => 'new',
        ] + $this->minimumParticipant())->assertForbidden();
        $this->actingAs($stranger)->post(route('course-talks.enrollments.groups.store', $this->edition), [
            'payer_name' => 'Intruso S.A.C.',
            'participants' => [$this->minimumParticipant()],
        ])->assertForbidden();
        $this->actingAs($stranger)->patch(route('course-talks.enrollments.payment-status.update', $enrollment), [
            'payment_status' => PaymentStatus::Paid->value,
        ])->assertForbidden();

        $this->assertDatabaseCount('course_enrollments', 1);
        // Only the factory-created enrollment's participant exists: no
        // unauthorized payload reached the service.
        $this->assertDatabaseCount('course_participants', 1);
        $this->assertDatabaseMissing('course_enrollments', ['payment_status' => PaymentStatus::Paid->value]);
    }

    public function test_the_responsible_user_can_read_the_list_but_cannot_enroll(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $this->edition->forceFill(['responsible_user_id' => $owner->id])->save();

        $this->actingAs($owner)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('ED-ENR-001')
            ->assertDontSee('Actualizar pago');

        $this->actingAs($owner)->get($this->createUrl())->assertForbidden();
        $this->actingAs($owner)->post(route('course-talks.enrollments.store', $this->edition), [
            'participant_source' => 'new',
        ] + $this->minimumParticipant())->assertForbidden();

        $this->assertDatabaseCount('course_enrollments', 0);
    }

    public function test_viewers_without_participants_manage_permission_cannot_write(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('course-talks.view');

        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create();

        $this->actingAs($viewer)->get($this->indexUrl())
            ->assertOk()
            ->assertDontSee('Actualizar pago');

        $this->actingAs($viewer)->get($this->createUrl())->assertForbidden();
        $this->actingAs($viewer)->post(route('course-talks.enrollments.store', $this->edition), [
            'participant_source' => 'new',
        ] + $this->minimumParticipant())->assertForbidden();
        $this->actingAs($viewer)->post(route('course-talks.enrollments.groups.store', $this->edition), [
            'payer_name' => 'Intruso S.A.C.',
            'participants' => [$this->minimumParticipant()],
        ])->assertForbidden();
        $this->actingAs($viewer)->patch(route('course-talks.enrollments.payment-status.update', $enrollment), [
            'payment_status' => PaymentStatus::Paid->value,
        ])->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $enrollment->fresh()->payment_status);
        // Only the factory-created enrollment's participant exists.
        $this->assertDatabaseCount('course_participants', 1);
    }

    public function test_index_lists_participant_enrollment_state_payment_and_group_payer(): void
    {
        $service = app(CourseEnrollmentService::class);
        $service->enroll($this->edition, ['contact_id' => $this->contact()->id]);
        $service->enrollGroup($this->edition, [
            'payer_name' => 'Maia Empresa S.A.C.',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
        ], [
            $this->minimumParticipant([
                'first_name' => 'Marco',
                'last_name' => 'Diaz',
                'document_number' => '87654321',
                'email' => 'marco@example.test',
            ]),
        ]);

        $response = $this->actingAs($this->manager)->get($this->indexUrl());

        $response->assertOk()
            ->assertSee('ED-ENR-001')
            ->assertSee('Curso de Inscripciones')
            ->assertSee('Torres, Ana')
            ->assertSee('ana@example.test')
            ->assertSee('Diaz, Marco')
            ->assertSee('Inscrito')
            ->assertSee('Pendiente')
            ->assertSee('100.00 PEN')
            ->assertSee('Maia Empresa S.A.C.')
            ->assertSee('Actualizar pago');

        // The payer belongs to the grouped enrollment only: the ungrouped
        // participant must not inherit the group's payer.
        $this->assertSame(1, substr_count($response->getContent(), 'Maia Empresa S.A.C.'));
        // One payment form per authorized row (two enrollments here).
        $this->assertSame(2, substr_count($response->getContent(), 'Actualizar pago'));
    }

    public function test_index_shows_an_empty_state_and_does_not_list_other_editions(): void
    {
        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Todavía no hay participantes inscritos en este dictado.');

        $otherEdition = CourseEdition::factory()->create(['code' => 'ED-ENR-002']);
        app(CourseEnrollmentService::class)->enroll($otherEdition, $this->minimumParticipant([
            'first_name' => 'Ajeno',
            'last_name' => 'Invitado',
            'document_number' => '99999999',
            'email' => 'ajeno@example.test',
        ]));

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertDontSee('Ajeno');
    }

    public function test_individual_enrollment_links_an_existing_contact(): void
    {
        $contact = $this->contact();

        $this->storeIndividual([
            'participant_source' => 'contact',
            'contact_id' => $contact->id,
        ])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $participant = CourseParticipant::sole();

        $this->assertSame($contact->id, $participant->contact_id);
        $this->assertSame($contact->customer_id, $participant->customer_id);
        $this->assertSame('Ana', $participant->first_name);

        $this->assertDatabaseHas('course_enrollments', [
            'course_edition_id' => $this->edition->id,
            'course_participant_id' => $participant->id,
            'activity_price_amount' => '100.00',
            'subtotal_amount' => '100.00',
            'currency' => 'PEN',
            'state' => 'enrolled',
            'payment_status' => PaymentStatus::Pending->value,
        ]);
    }

    public function test_individual_enrollment_creates_a_minimum_participant_record(): void
    {
        $this->storeIndividual([
            'participant_source' => 'new',
        ] + $this->minimumParticipant([
            'document_number' => '12-345-678',
            'email' => 'LUZ@example.test',
            'mobile' => '+51 999 111 222',
        ]))
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseHas('course_participants', [
            // The service owns normalization: punctuation stripped, lowercase email.
            'document_type' => 'dni',
            'document_number' => '12-345-678',
            'document_number_norm' => '12345678',
            'email' => 'luz@example.test',
            'email_norm' => 'luz@example.test',
            'mobile' => '+51 999 111 222',
            'mobile_norm' => '51999111222',
        ]);
        $this->assertDatabaseCount('course_enrollments', 1);
    }

    public function test_individual_enrollment_reuses_an_existing_participant(): void
    {
        $participant = CourseParticipant::factory()->create(['first_name' => 'Elena', 'last_name' => 'Paz']);

        $this->storeIndividual([
            'participant_source' => 'participant',
            'course_participant_id' => $participant->id,
        ])
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseHas('course_enrollments', [
            'course_edition_id' => $this->edition->id,
            'course_participant_id' => $participant->id,
        ]);
        $this->assertDatabaseCount('course_participants', 1);
    }

    public function test_individual_enrollment_validates_the_selected_participant_source(): void
    {
        $this->storeIndividual([])->assertSessionHasErrors('participant_source');

        $this->storeIndividual(['participant_source' => 'desconocido'])
            ->assertSessionHasErrors('participant_source');

        $this->storeIndividual(['participant_source' => 'contact'])
            ->assertSessionHasErrors('contact_id');

        $this->storeIndividual(['participant_source' => 'contact', 'contact_id' => 999999])
            ->assertSessionHasErrors('contact_id');

        $this->storeIndividual(['participant_source' => 'participant'])
            ->assertSessionHasErrors('course_participant_id');

        $this->storeIndividual(['participant_source' => 'new'])
            ->assertSessionHasErrors([
                'first_name',
                'last_name',
                'document_type',
                'document_number',
                'email',
                'mobile',
            ]);

        $this->storeIndividual(['participant_source' => 'new'] + $this->minimumParticipant(['email' => 'no-es-correo']))
            ->assertSessionHasErrors('email');

        // Every rejected payload redirected back to the create form.
        $this->assertDatabaseCount('course_participants', 0);
        $this->assertDatabaseCount('course_enrollments', 0);
    }

    public function test_duplicate_enrollment_is_reported_from_the_service_on_the_create_view(): void
    {
        app(CourseEnrollmentService::class)->enroll($this->edition, $this->minimumParticipant());

        $this->storeIndividual([
            'participant_source' => 'new',
        ] + $this->minimumParticipant(['document_number' => '12-345-678']))
            ->assertRedirect($this->createUrl());

        // The service throws a field-less exception, which the controller maps to
        // the generic `enrollment` bag entry: if the message is visible, both the
        // mapping and the view are correct.
        $this->actingAs($this->manager)->get($this->createUrl())
            ->assertOk()
            ->assertSee('already enrolled');

        $this->assertDatabaseCount('course_enrollments', 1);
        $this->assertDatabaseCount('course_participants', 1);
    }

    public function test_group_enrollment_creates_the_payer_group_and_one_enrollment_per_participant(): void
    {
        $this->storeGroup([
            'payer_name' => 'Maia Empresa S.A.C.',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
            'notes' => 'Pago corporativo',
            'participants' => [
                $this->minimumParticipant(),
                $this->minimumParticipant([
                    'first_name' => 'Marco',
                    'last_name' => 'Diaz',
                    'document_number' => '87654321',
                    'email' => 'marco@example.test',
                ]),
            ],
        ])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseCount('course_enrollment_groups', 1);
        $this->assertDatabaseHas('course_enrollment_groups', [
            'course_edition_id' => $this->edition->id,
            'payer_name' => 'Maia Empresa S.A.C.',
            'payer_document_number' => '20123456789',
            'notes' => 'Pago corporativo',
        ]);

        $this->assertDatabaseCount('course_enrollments', 2);
        $this->assertDatabaseCount('course_participants', 2);

        $enrollments = CourseEnrollment::with('participant')->get();
        $group = $enrollments->first()->group;

        $this->assertNotNull($group);
        $this->assertSame($group->id, $enrollments->last()->course_enrollment_group_id);
        $this->assertNotSame(
            $enrollments->first()->participant->id,
            $enrollments->last()->participant->id,
        );
    }

    public function test_group_enrollment_ignores_empty_participant_rows(): void
    {
        $this->storeGroup([
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [
                $this->minimumParticipant(),
                ['first_name' => '', 'last_name' => '', 'document_type' => '', 'document_number' => '', 'email' => '', 'mobile' => ''],
                ['first_name' => '', 'last_name' => '', 'document_type' => '', 'document_number' => '', 'email' => '', 'mobile' => ''],
            ],
        ])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $this->assertDatabaseCount('course_enrollment_groups', 1);
        $this->assertDatabaseCount('course_enrollments', 1);
    }

    public function test_group_enrollment_validates_the_payer_and_the_participant_rows(): void
    {
        $this->storeGroup(['participants' => [$this->minimumParticipant()]])
            ->assertSessionHasErrors('payer_name');

        $this->storeGroup(['payer_name' => 'Maia Empresa S.A.C.'])
            ->assertSessionHasErrors('participants');

        $this->storeGroup([
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [['first_name' => 'Solo nombre']],
        ])->assertSessionHasErrors([
            'participants.0.last_name',
            'participants.0.document_type',
            'participants.0.document_number',
            'participants.0.email',
            'participants.0.mobile',
        ]);

        // Nothing partial survived a rejected payload.
        $this->assertDatabaseCount('course_enrollment_groups', 0);
        $this->assertDatabaseCount('course_participants', 0);
        $this->assertDatabaseCount('course_enrollments', 0);
    }

    public function test_group_row_validation_errors_are_visible_on_the_create_view(): void
    {
        // The render check must be the first request after the rejected POST:
        // TestResponse::assertSessionHasErrors() starts the session store out of
        // band and loses the pending flash for the next render, so this test
        // deliberately asserts on the page instead of the session bag.
        $this->storeGroup([
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [['first_name' => 'Solo nombre']],
        ])->assertRedirect($this->createUrl());

        $this->actingAs($this->manager)->get($this->createUrl())
            ->assertOk()
            // Reported with the Spanish display names declared by
            // StoreCourseEnrollmentGroupRequest::attributes().
            ->assertSee('apellidos del participante')
            ->assertSee('correo del participante');
    }

    public function test_group_enrollment_records_the_selected_payer_customer(): void
    {
        $customer = Customer::factory()->create();

        $this->storeGroup([
            'payer_customer_id' => $customer->id,
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [$this->minimumParticipant()],
        ])
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseHas('course_enrollment_groups', [
            'course_edition_id' => $this->edition->id,
            'payer_customer_id' => $customer->id,
            'payer_name' => 'Maia Empresa S.A.C.',
        ]);
    }

    public function test_service_minimum_data_failure_is_visible_on_the_create_view(): void
    {
        // The mobile country-code rule belongs to the service: the request only
        // checks shape, so the service message must reach the user.
        $this->storeIndividual([
            'participant_source' => 'new',
        ] + $this->minimumParticipant(['mobile' => '999111222']))
            ->assertRedirect($this->createUrl());

        $this->assertDatabaseCount('course_enrollments', 0);

        $this->actingAs($this->manager)->get($this->createUrl())
            ->assertOk()
            ->assertSee('country code');
    }

    public function test_group_enrollment_rolls_back_when_a_participant_is_already_enrolled(): void
    {
        $alreadyEnrolled = $this->minimumParticipant();
        app(CourseEnrollmentService::class)->enroll($this->edition, $alreadyEnrolled);

        $this->storeGroup([
            'payer_name' => 'Maia Empresa S.A.C.',
            'participants' => [
                $this->minimumParticipant([
                    'first_name' => 'Nuevo',
                    'last_name' => 'Uno',
                    'document_number' => '11111111',
                    'email' => 'nuevo@example.test',
                ]),
                $alreadyEnrolled,
            ],
        ])->assertRedirect($this->createUrl());

        // The service owns group atomicity: the payer group and the valid
        // enrollment of the same request must not survive the rejection.
        $this->assertDatabaseCount('course_enrollment_groups', 0);
        $this->assertDatabaseCount('course_enrollments', 1);

        $this->actingAs($this->manager)->get($this->createUrl())
            ->assertOk()
            ->assertSee('already enrolled');
    }

    public function test_payment_status_change_goes_through_the_service(): void
    {
        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create([
            'payment_status' => PaymentStatus::Pending,
        ]);

        $this->changePayment($enrollment, ['payment_status' => PaymentStatus::Partial->value])
            ->assertRedirect($this->indexUrl())
            ->assertSessionHas('status');

        $this->assertSame(PaymentStatus::Partial, $enrollment->fresh()->payment_status);

        $this->changePayment($enrollment, ['payment_status' => PaymentStatus::Paid->value])
            ->assertRedirect($this->indexUrl());

        $this->assertSame(PaymentStatus::Paid, $enrollment->fresh()->payment_status);
    }

    public function test_payment_status_change_reports_a_rejected_transition_on_the_list_view(): void
    {
        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create([
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->changePayment($enrollment, ['payment_status' => PaymentStatus::Partial->value])
            ->assertRedirect($this->indexUrl());

        $this->assertSame(PaymentStatus::Paid, $enrollment->fresh()->payment_status);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('not permitted');
    }

    public function test_payment_status_change_validates_the_target_status_and_authorizes_the_waiver(): void
    {
        $enrollment = CourseEnrollment::factory()->for($this->edition, 'edition')->create([
            'payment_status' => PaymentStatus::Pending,
        ]);

        $this->changePayment($enrollment, [])->assertSessionHasErrors('payment_status');
        $this->changePayment($enrollment, ['payment_status' => 'no-existe'])->assertSessionHasErrors('payment_status');

        $this->assertSame(PaymentStatus::Pending, $enrollment->fresh()->payment_status);

        // A policy-authorized actor is passed to the service as waiver-authorized;
        // any other actor is stopped by the policy before reaching the service.
        $this->changePayment($enrollment, ['payment_status' => PaymentStatus::Waived->value])
            ->assertRedirect($this->indexUrl());

        $this->assertSame(PaymentStatus::Waived, $enrollment->fresh()->payment_status);
    }
}
