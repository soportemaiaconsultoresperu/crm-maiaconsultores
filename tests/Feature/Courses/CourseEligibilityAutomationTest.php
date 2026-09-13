<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\PdfRenderer;
use App\Contracts\Courses\QrRenderer;
use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Events\Courses\CourseEligibilityEvaluationRequested;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCertificateTemplate;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\CourseEligibilityService;
use App\Services\Courses\CourseEligibilityTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseEligibilityAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_changes_request_eligibility_after_commit_without_running_generation_inline(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = $this->eligibleSeat();

        app(CourseEligibilityTriggerService::class)->paymentChanged($enrollment);

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new CourseEligibilityEvaluationRequested($enrollment->id, 'payment'));
        Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
            $event->enrollmentId === $enrollment->id && $event->reason === 'payment'
        );
        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
            $job->enrollmentId === $enrollment->id
            && $job->reason === 'payment'
            && $job->afterCommit === true
        );
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_grade_participation_and_edition_validation_changes_can_request_evaluation(): void
    {
        Queue::fake();
        Event::fake([CourseEligibilityEvaluationRequested::class]);
        $enrollment = $this->eligibleSeat();
        $service = app(CourseEligibilityTriggerService::class);

        $service->gradeChanged($enrollment);
        $service->participationChanged($enrollment);
        $service->editionValidationChanged($enrollment);

        foreach (['grade', 'participation', 'edition_validation'] as $reason) {
            Event::assertDispatched(CourseEligibilityEvaluationRequested::class, fn ($event): bool =>
                $event->enrollmentId === $enrollment->id && $event->reason === $reason
            );
            Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool =>
                $job->enrollmentId === $enrollment->id && $job->reason === $reason
            );
        }
    }

    /**
     * The delta spec's requirement, asserted on the state it is about: "WHEN the
     * final missing condition becomes complete THEN the system MUST generate the
     * corresponding PDF document automatically." The job is the only thing that
     * reacts to that completion, so what must hold afterwards is a real document —
     * a registered row in `current` status whose private PDF exists and whose QR
     * link streams it — and not a job internals detail.
     *
     * The two drivers with real side effects are faked at the service boundary
     * (exactly as the generation suite does); every rule — eligibility, document
     * type, filename, code, QR hashing, private path — is the production one.
     */
    public function test_completing_the_final_condition_generates_the_certificate_automatically_and_the_qr_route_streams_it(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        CourseCertificateTemplate::query()->create([
            'name' => 'Plantilla automática',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'course-talks.certificates.reference',
            'settings_json' => ['title' => 'Certificado automático'],
        ]);
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        // The probe must really be eligible: an ineligible enrollment would make
        // the job return early and this test would prove nothing at all.
        $this->assertTrue(
            app(CourseEligibilityService::class)->evaluate($enrollment->fresh())->eligible,
            'The probe enrollment must be eligible, otherwise the job returns early and this test proves nothing.'
        );
        $this->assertSame(0, CourseAcademicDocument::query()->count(), 'Nothing may exist before the trigger.');

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(
            1,
            CourseAcademicDocument::query()->count(),
            'Completing the last missing condition must generate the corresponding document automatically.'
        );

        $document = CourseAcademicDocument::query()->sole();
        $this->assertSame(AcademicDocumentType::ApprovalCertificate, $document->type);
        $this->assertSame(AcademicDocumentStatus::Current, $document->status);
        $this->assertNotNull($document->document_id);
        $this->assertNotNull($document->course_certificate_template_id, 'The document must record the template that produced it.');
        $this->assertNotNull($document->qr_token_hash);
        $this->assertSame(CourseAcademicDocument::class, $document->document->docable_type);
        $this->assertTrue(Storage::disk('docs')->exists($document->document->path), 'The generated PDF must be stored privately.');
        $this->assertStringContainsString('Certificado automático', Storage::disk('docs')->get($document->document->path));

        // Reachable the way the domain promises it: the public QR route streams
        // the private PDF, and it is the token minted for THIS document.
        $this->get('/certificate/qr/'.basename($payloads[0]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * The job's other half: it must not touch what it is not asked to touch. The
     * original test asserted the SAME claim while also pinning the missing
     * generation as intended behaviour (`assertDatabaseCount('course_academic_documents', 0)`)
     * — that is the assertion that hid the defect, and this is its replacement.
     */
    public function test_job_generates_the_current_document_without_mutating_enrollment_data(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
            'exact_average' => '15.2500',
            'display_average' => '15.25',
            'rounded_result' => 15,
        ]);
        $before = $enrollment->fresh()->only([
            'state', 'payment_status', 'exact_average', 'display_average', 'rounded_result', 'final_result', 'participation_confirmed_at',
        ]);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(
            1,
            CourseAcademicDocument::query()->count(),
            'The job must generate the document the completed conditions entitle the participant to.'
        );
        $this->assertSame($before, $enrollment->fresh()->only(array_keys($before)), 'The job must not mutate the enrollment beyond generating its document.');
    }

    /**
     * A re-run of the SAME job (a queue retry, a duplicated dispatch, a second
     * condition completing later) must not mint a second certificate: the
     * already-current document is the domain's answer, and no second row, second
     * code or second private file may appear.
     */
    public function test_a_re_trigger_cannot_mint_a_second_certificate(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));
        $this->assertSame(1, CourseAcademicDocument::query()->count(), 'The first trigger must generate the document.');
        $first = CourseAcademicDocument::query()->sole();
        $firstCode = $first->code;
        $firstPath = $first->document->path;

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle(app(CourseEligibilityService::class));
        (new EvaluateCourseDocumentEligibility($enrollment->id, 'edition_validation'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(1, CourseAcademicDocument::query()->count(), 'A re-trigger must never create a second document.');
        $this->assertSame(1, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Current)->count());
        $this->assertSame($firstCode, CourseAcademicDocument::query()->sole()->code, 'The original code must survive the re-trigger.');
        $this->assertCount(1, Storage::disk('docs')->allFiles(), 'A re-trigger must not leave a second private PDF behind.');
        $this->assertTrue(Storage::disk('docs')->exists($firstPath));
    }

    /**
     * The negative half of the requirement: while a condition is missing, the job
     * must generate nothing at all — no row, no file, no code.
     */
    public function test_an_ineligible_enrollment_generates_nothing_on_a_trigger(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        $enrollment = $this->eligibleSeat(['payment_status' => PaymentStatus::Pending]);

        $this->assertFalse(app(CourseEligibilityService::class)->evaluate($enrollment->fresh())->eligible);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(0, CourseAcademicDocument::query()->count());
        $this->assertSame([], Storage::disk('docs')->allFiles());
        $this->assertSame([], $pdfCalls);
    }

    /**
     * A talk versus a course must select the right document type through the
     * existing eligibility service, not through new logic in the job.
     */
    public function test_a_talk_trigger_generates_the_talk_certificate_through_the_eligibility_service(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        $enrollment = $this->talkSeat();

        $this->assertSame(AcademicDocumentType::TalkCertificate, app(CourseEligibilityService::class)->evaluate($enrollment->fresh())->documentType);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'participation'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(1, CourseAcademicDocument::query()->count());
        $this->assertSame(AcademicDocumentType::TalkCertificate, CourseAcademicDocument::query()->sole()->type);
    }

    /**
     * WHO is responsible now that nobody is: the automatic generation is
     * attributed to the dedicated non-human SYSTEM account, and the trail says in
     * the same write that the generation was automatic and which condition
     * completed. The alternative the module rejected — a null causer, or a
     * fabricated human — would leave a material change unattributed.
     */
    public function test_the_automatic_generation_names_the_system_author_and_states_that_it_was_automatic(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $author = $this->systemAuthor();
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));

        $document = CourseAcademicDocument::query()->sole();

        // The model's own `course-created` entry names the SYSTEM account, not a
        // null causer and not whoever happened to be authenticated.
        $created = $this->activityRow(CourseAcademicDocument::class, $document->id, 'course-created');
        $this->assertSame($author->id, (int) $created->causer_id);

        $auto = $this->activityRow(CourseAcademicDocument::class, $document->id, 'course-academic-document-auto-generated');
        $this->assertSame($author->id, (int) $auto->causer_id);
        $properties = json_decode((string) $auto->properties, true);
        $this->assertSame('system', $properties['actor_type']);
        $this->assertSame('course-eligibility-job', $properties['system_action']);
        $this->assertSame('grade', $properties['trigger_reason']);
        $this->assertSame($enrollment->id, (int) $properties['course_enrollment_id']);
        $this->assertSame($document->code, $properties['document_code']);
        $this->assertSame(AcademicDocumentType::ApprovalCertificate->value, $properties['document_type']);
        $this->assertArrayNotHasKey('path', $properties);
        $this->assertStringNotContainsString('signature=', (string) $auto->properties);
    }

    /**
     * The rollback switch. Automatic generation is asynchronous, so the module
     * needs one switch that stops it: with the flag off, completing an eligible
     * condition generates nothing — and the enrollment is left untouched, still
     * eligible and still generatable by the operator's own action.
     */
    public function test_the_stop_switch_prevents_automatic_generation_without_disabling_the_operator_path(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);
        $this->systemAuthor();
        config(['courses.automatic_document_generation_enabled' => false]);
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(0, CourseAcademicDocument::query()->count(), 'With the switch off the job must generate nothing.');
        $this->assertSame([], Storage::disk('docs')->allFiles());
        $this->assertSame([], $pdfCalls);
        $this->assertTrue(
            app(CourseEligibilityService::class)->evaluate($enrollment->fresh())->eligible,
            'The switch stops the job; it must not change the enrollment.'
        );

        // The switch is the job's, not the domain's: the operator still generates.
        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        $generated = app(CourseDocumentGenerationService::class)->generate($enrollment->fresh(), $operator);

        $this->assertSame(AcademicDocumentStatus::Current, $generated->status);
        $this->assertSame(1, CourseAcademicDocument::query()->count());
    }

    /**
     * Failure behaviour. Generation renders a PDF and stores a private file, so it
     * can fail; when it does, the job lets the exception escape — the queue records
     * the failed attempt and its retry policy applies — and the transaction rollback
     * leaves no half-generated document and no orphan file behind. The enrollment
     * stays eligible, so a later trigger (or the operator) can try again. The
     * failure is surfaced twice: in the queue and in the log, with the context the
     * queue record does not carry.
     */
    public function test_a_failed_automatic_generation_escapes_the_job_and_leaves_nothing_behind(): void
    {
        Storage::fake('docs');
        Log::spy();
        $this->systemAuthor();
        $this->app->instance(CourseDocumentGenerationService::class, new CourseDocumentGenerationService(
            pdfRenderer: new class implements PdfRenderer
            {
                public function render(string $view, array $data): string
                {
                    throw new \RuntimeException('El generador de PDF no está disponible.');
                }
            },
        ));
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        $escaped = null;
        try {
            (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));
        } catch (\RuntimeException $exception) {
            $escaped = $exception;
        }

        $this->assertNotNull($escaped, 'A failed generation must not be swallowed: the queue has to see it.');
        $this->assertSame('El generador de PDF no está disponible.', $escaped->getMessage());
        $this->assertSame(0, CourseAcademicDocument::query()->count(), 'A failed generation must leave no document row behind.');
        $this->assertSame([], Storage::disk('docs')->allFiles(), 'A failed generation must leave no private file behind.');
        Log::shouldHaveReceived('error')->once();
        $this->assertTrue(
            app(CourseEligibilityService::class)->evaluate($enrollment->fresh())->eligible,
            'The enrollment must stay eligible so the next trigger can try again.'
        );
    }

    /**
     * A generation failure is not the same thing as a document that exists but
     * whose file could not be stored: `AcademicDocumentStatus::Failed` is the
     * instrument for the latter. This locks that the refused-generation case leaves
     * no such row (there is no document to mark), so a later trigger retries
     * instead of being blocked by a phantom `failed` row.
     */
    public function test_a_failed_generation_does_not_leave_a_failed_document_row_blocking_the_next_trigger(): void
    {
        Storage::fake('docs');
        $this->systemAuthor();
        $this->app->instance(CourseDocumentGenerationService::class, new CourseDocumentGenerationService(
            pdfRenderer: new class implements PdfRenderer
            {
                public function render(string $view, array $data): string
                {
                    throw new \RuntimeException('El generador de PDF no está disponible.');
                }
            },
        ));
        $enrollment = $this->eligibleSeat([
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);

        try {
            (new EvaluateCourseDocumentEligibility($enrollment->id, 'grade'))->handle(app(CourseEligibilityService::class));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, CourseAcademicDocument::query()->where('status', AcademicDocumentStatus::Failed)->count());

        // The same job succeeds once the failure clears: nothing was left behind
        // that would make the domain refuse the retry.
        $payloads = [];
        $pdfCalls = [];
        $this->bindGenerationWithFakeDrivers($payloads, $pdfCalls);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle(app(CourseEligibilityService::class));

        $this->assertSame(1, CourseAcademicDocument::query()->count());
        $this->assertSame(AcademicDocumentStatus::Current, CourseAcademicDocument::query()->sole()->status);
    }

    public function test_job_is_idempotent_when_current_document_already_exists(): void
    {
        $this->systemAuthor();
        $enrollment = $this->eligibleSeat();
        CourseAcademicDocument::create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CUR-EXISTING-001',
            'delivery_status' => 'pending',
        ]);

        $eligibility = app(CourseEligibilityService::class);

        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle($eligibility);
        (new EvaluateCourseDocumentEligibility($enrollment->id, 'payment'))->handle($eligibility);

        $this->assertDatabaseCount('course_academic_documents', 1);
        $this->assertDatabaseHas('course_academic_documents', [
            'course_enrollment_id' => $enrollment->id,
            'code' => 'CUR-EXISTING-001',
            'status' => AcademicDocumentStatus::Current->value,
        ]);
    }

    /**
     * Binds the real generation service with the two drivers that touch the
     * outside world faked, so the job's generation runs the production rules
     * (eligibility, type, filename, code, QR hash, private path) and the
     * assertions below observe real rows and real files.
     */
    private function bindGenerationWithFakeDrivers(array &$payloads, array &$calls): void
    {
        $this->app->instance(CourseDocumentGenerationService::class, new CourseDocumentGenerationService(
            pdfRenderer: new class($calls) implements PdfRenderer
            {
                public function __construct(private array &$calls) {}

                public function render(string $view, array $data): string
                {
                    $this->calls[] = compact('view', 'data');

                    return '%PDF '.$view.' '.view($view, $data)->render();
                }
            },
            qrTokens: new CertificateQrTokenService(new class($payloads) implements QrRenderer
            {
                public function __construct(private array &$payloads) {}

                public function renderSvg(string $payload): string
                {
                    $this->payloads[] = $payload;

                    return '<svg>QR '.$payload.'</svg>';
                }
            }),
        ));
    }

    /**
     * The SYSTEM author of automatic generation, as the module ships it: an
     * explicitly non-human account holding exactly the one ability the generation
     * gate asks for. The seeder itself is exercised by the rollout suite, which
     * runs the real full seed.
     */
    private function systemAuthor(): User
    {
        $author = User::factory()->create([
            'name' => 'Sistema (generación automática de certificados)',
            'email' => 'sistema.certificados@crm-maia.invalid',
            'is_active' => false,
        ]);
        $author->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        config(['courses.system_author_email' => $author->email]);

        return $author;
    }

    private function activityRow(string $subjectType, int $subjectId, string $name): object
    {
        $row = DB::table('activity_log')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where(function ($query) use ($name): void {
                $query->where('event', $name)->orWhere('description', $name);
            })
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, "No activity entry \"{$name}\" for {$subjectType}#{$subjectId}.");

        return $row;
    }

    private function talkSeat(): CourseEnrollment
    {
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Talk,
            'talk_includes_certificate' => true,
        ]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['validations_completed_at' => now()]);
        $participant = CourseParticipant::factory()->create();

        return CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create([
                'state' => CourseEnrollmentState::Completed,
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::NotApplicable,
                'participation_confirmed_at' => now(),
            ]);
    }

    private function eligibleSeat(array $enrollment = []): CourseEnrollment
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['validations_completed_at' => now()]);
        $participant = CourseParticipant::factory()->create();

        return CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create(array_merge([
                'state' => CourseEnrollmentState::Completed,
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::Approved,
            ], $enrollment));
    }
}
