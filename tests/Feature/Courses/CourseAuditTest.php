<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Contracts\Courses\{PdfRenderer, QrRenderer};
use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseAttendance;
use App\Models\Courses\CourseCertificateTemplate;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEditionTeacher;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseGrade;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseAlertService;
use App\Services\Courses\CourseAttendanceService;
use App\Services\Courses\CourseCertificateTemplateService;
use App\Services\Courses\CourseCommercialDocumentService;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\CourseEditionService;
use App\Services\Courses\CourseEnrollmentService;
use App\Services\Courses\CourseGradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Slice 7 unit 7.c — the audit regression suite.
 *
 * Every change the Slice 7 audit rows enumerate is asserted against what the
 * spec actually requires of it: the entry EXISTS, it carries a `course-*`
 * description or event name, it names the RESPONSIBLE ACTOR, and a modification
 * carries the old and the new value. The assertions are about the state the rule
 * is about — a correction is asserted through its `course-updated` entry with
 * `old`/`attributes`, not through the creation that preceded it — and the actor
 * assertions distinguish the two ways an actor may reach an entry:
 *
 *  - the service received an explicit actor: the actor is asserted WITHOUT an
 *    authenticated session, because that is the case that silently produced an
 *    actor-less entry (and, with a different user authenticated, the wrong one);
 *  - the path has no explicit actor parameter: the authenticated user is the
 *    responsible actor, and the session is the fixture the assertion needs.
 */
class CourseAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create();
    }

    // ---------------------------------------------------------------------
    // Activity, edition, enrollment
    // ---------------------------------------------------------------------

    public function test_an_activity_code_change_is_audited_with_the_actor_and_the_old_and_new_code(): void
    {
        $this->actingAs($actor = $this->userWith());

        $activity = CourseActivity::factory()->create(['code' => 'CUR-AUD-001']);
        $activity->update(['code' => 'CUR-AUD-002']);

        $properties = $this->properties($this->auditEntry(CourseActivity::class, $activity->id, 'course-updated', $actor->id));

        $this->assertSame('CUR-AUD-001', $properties['old']['code']);
        $this->assertSame('CUR-AUD-002', $properties['attributes']['code']);
    }

    public function test_an_edition_state_change_is_audited_with_the_actor_and_the_old_and_new_state(): void
    {
        $this->actingAs($actor = $this->userWith());

        $edition = CourseEdition::factory()->create(['state' => CourseEditionState::Draft]);
        (new CourseEditionService())->transitionState($edition, CourseEditionState::Scheduled);

        $properties = $this->properties($this->auditEntry(CourseEdition::class, $edition->id, 'course-updated', $actor->id));

        $this->assertSame('draft', $properties['old']['state']);
        $this->assertSame('scheduled', $properties['attributes']['state']);
    }

    public function test_an_enrollment_change_is_audited_with_the_actor_and_the_affected_edition_and_participant(): void
    {
        $this->actingAs($actor = $this->userWith());

        $edition = $this->courseEdition();
        $enrollment = (new CourseEnrollmentService())->enroll($edition, [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'document_type' => 'dni',
            'document_number' => '87654321',
            'email' => 'ana.perez@example.test',
            'mobile' => '+51987654321',
        ]);

        $properties = $this->properties($this->auditEntry(CourseEnrollment::class, $enrollment->id, 'course-created', $actor->id));

        $this->assertSame($edition->id, (int) $properties['attributes']['course_edition_id']);
        $this->assertSame($enrollment->course_participant_id, (int) $properties['attributes']['course_participant_id']);
    }

    public function test_a_payment_completion_change_is_audited_with_the_actor_and_the_old_and_new_status(): void
    {
        [, , $enrollment] = $this->courseEditionWithSession(['payment_status' => PaymentStatus::Partial]);
        $this->actingAs($actor = $this->userWith());

        (new CourseEnrollmentService())->changePaymentStatus($enrollment, PaymentStatus::Paid);

        $properties = $this->properties($this->auditEntry(CourseEnrollment::class, $enrollment->id, 'course-updated', $actor->id));

        $this->assertSame('partial', $properties['old']['payment_status']);
        $this->assertSame('paid', $properties['attributes']['payment_status']);
    }

    public function test_an_edition_teacher_create_update_and_remove_follow_course_audit_conventions(): void
    {
        $this->actingAs($actor = $this->userWith('course-talks.editions.manage'));
        $edition = $this->courseEdition();
        $service = new CourseEditionService();

        $service->syncTeachers($edition, [
            ['display_name' => 'Ana Docente', 'email' => 'ana@example.test'],
        ]);
        $teacher = CourseEditionTeacher::query()->sole();

        $created = $this->properties($this->auditEntry(CourseEditionTeacher::class, $teacher->id, 'course-created', $actor->id));
        $this->assertSame($edition->id, (int) $created['attributes']['course_edition_id']);
        $this->assertSame($actor->id, (int) $teacher->created_by);

        $service->syncTeachers($edition, [
            ['id' => $teacher->id, 'display_name' => 'Ana Actualizada', 'email' => 'ana.nueva@example.test'],
        ]);

        $updated = $this->properties($this->auditEntry(CourseEditionTeacher::class, $teacher->id, 'course-updated', $actor->id));
        $this->assertSame('Ana Docente', $updated['old']['display_name']);
        $this->assertSame('Ana Actualizada', $updated['attributes']['display_name']);

        $service->syncTeachers($edition, [
            ['id' => $teacher->id, 'remove' => true],
        ]);

        $deleted = $this->properties($this->auditEntry(CourseEditionTeacher::class, $teacher->id, 'course-deleted', $actor->id));
        $this->assertSame('Ana Actualizada', $deleted['old']['display_name']);
        $this->assertSoftDeleted('course_edition_teachers', ['id' => $teacher->id]);
    }

    // ---------------------------------------------------------------------
    // Attendance
    // ---------------------------------------------------------------------

    public function test_an_attendance_change_is_audited_with_the_responsible_actor(): void
    {
        [, $session, $enrollment] = $this->courseEditionWithSession();
        $actor = $this->userWith('course-talks.attendance.manage');

        // NO authenticated session: the responsible actor is the one the
        // service received, and that is exactly the case an actor-less entry
        // would hide.
        (new CourseAttendanceService())->mark($session, $enrollment, 'present', $actor);

        $attendance = CourseAttendance::query()->sole();
        $properties = $this->properties($this->auditEntry(CourseAttendance::class, $attendance->id, 'course-created', $actor->id));

        $this->assertSame('present', $properties['attributes']['status']);
        $this->assertSame($actor->id, (int) $properties['attributes']['marked_by']);
    }

    public function test_an_attendance_correction_keeps_the_previous_and_the_new_status(): void
    {
        [, $session, $enrollment] = $this->courseEditionWithSession();
        $actor = $this->userWith('course-talks.attendance.manage');
        $service = new CourseAttendanceService();

        $service->mark($session, $enrollment, 'present', $actor);
        $service->mark($session, $enrollment, 'absent', $actor);

        $properties = $this->properties($this->auditEntry(CourseAttendance::class, CourseAttendance::query()->sole()->id, 'course-updated', $actor->id));

        $this->assertSame('present', $properties['old']['status']);
        $this->assertSame('absent', $properties['attributes']['status']);
    }

    // ---------------------------------------------------------------------
    // Grades and result recalculation
    // ---------------------------------------------------------------------

    public function test_a_grade_correction_records_who_changed_it_when_the_previous_and_new_value_and_the_affected_enrollment(): void
    {
        [$edition, $session, $enrollment] = $this->courseEditionWithSession();
        $actor = $this->userWith('course-talks.grades.manage');
        $service = new CourseGradeService();

        $recorded = $service->record($session, $enrollment, '13.00', 'Examen final', $actor);
        $corrected = $service->record($session, $enrollment, '18.00', 'Examen final corregido', $actor);

        // The domain's own actor column records the same responsible user the
        // audit entry names.
        $created = $this->properties($this->auditEntry(CourseGrade::class, $recorded->id, 'course-created', $actor->id));
        $this->assertSame('13.00', $created['attributes']['grade']);
        $this->assertSame($actor->id, (int) $created['attributes']['entered_by']);

        $row = $this->auditEntry(CourseGrade::class, $corrected->id, 'course-updated', $actor->id);
        $properties = $this->properties($row);

        // WHO: the acting user. WHEN: the entry timestamp.
        $this->assertSame($actor->id, (int) $row->causer_id);
        $this->assertNotNull($row->created_at);

        // PREVIOUS and NEW value of the corrected grade.
        $this->assertSame('13.00', $properties['old']['grade']);
        $this->assertSame('18.00', $properties['attributes']['grade']);

        // AFFECTED participant/edition: the audited subject is that enrollment's grade.
        $audited = CourseGrade::query()->with('enrollment')->findOrFail((int) $row->subject_id);
        $this->assertSame($enrollment->id, (int) $audited->course_enrollment_id);
        $this->assertSame($enrollment->course_participant_id, (int) $audited->enrollment->course_participant_id);
        $this->assertSame($edition->id, (int) $audited->enrollment->course_edition_id);
    }

    public function test_a_grade_entered_by_an_explicit_actor_is_not_attributed_to_a_different_authenticated_user(): void
    {
        [, $session, $enrollment] = $this->courseEditionWithSession();
        $actor = $this->userWith('course-talks.grades.manage');
        $sessionUser = $this->userWith('course-talks.grades.manage');

        $this->actingAs($sessionUser);
        $grade = (new CourseGradeService())->record($session, $enrollment, '15.00', 'Examen final', $actor);

        $row = $this->auditEntry(CourseGrade::class, $grade->id, 'course-created', $actor->id);
        $this->assertSame($actor->id, (int) $row->causer_id);
        $this->assertNotSame($sessionUser->id, (int) $row->causer_id);
    }

    public function test_a_result_recalculation_is_audited_on_the_enrollment_with_the_actor_and_the_recomputed_values(): void
    {
        [$edition, $session, $enrollment] = $this->courseEditionWithSession();
        $secondSession = CourseSession::factory()->for($edition, 'edition')->create(['sort_order' => 2]);
        $actor = $this->userWith('course-talks.grades.manage');
        $service = new CourseGradeService();

        $service->record($session, $enrollment, '13.00', 'Examen final', $actor);
        $service->record($secondSession, $enrollment, '9.00', 'Trabajo final', $actor);
        // Correction: (13.00 + 9.00) / 2 = 11.00 -> participation, and the
        // corrected (13.00 + 18.00) / 2 = 15.50 -> 16 -> approved.
        $service->record($secondSession, $enrollment, '18.00', 'Trabajo final corregido', $actor);

        $properties = $this->properties($this->auditEntry(CourseEnrollment::class, $enrollment->id, 'course-updated', $actor->id));

        // The last recalculation, with the values it replaced and the ones it
        // produced, including the result decision it changed.
        $this->assertSame('11.0000', $properties['old']['exact_average']);
        $this->assertSame('15.5000', $properties['attributes']['exact_average']);
        $this->assertSame('15.50', $properties['attributes']['display_average']);
        $this->assertSame(11, (int) $properties['old']['rounded_result']);
        $this->assertSame(16, (int) $properties['attributes']['rounded_result']);
        $this->assertSame('participation', $properties['old']['final_result']);
        $this->assertSame('approved', $properties['attributes']['final_result']);
    }

    // ---------------------------------------------------------------------
    // Academic documents: generation, annulment, regeneration
    // ---------------------------------------------------------------------

    public function test_document_generation_is_audited_with_the_responsible_actor_and_the_new_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        [$enrollment, $actor] = $this->eligibleEnrollment();

        $academic = $this->generationService(new CertificateQrTokenService($this->qrRenderer($payloads)))
            ->generate($enrollment, $actor);

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-created', $actor->id));

        $this->assertSame(AcademicDocumentType::ApprovalCertificate->value, $properties['attributes']['type']);
        $this->assertSame($academic->code, $properties['attributes']['code']);
        $this->assertSame($enrollment->id, (int) $properties['attributes']['course_enrollment_id']);
    }

    public function test_document_annulment_is_audited_with_the_actor_the_reason_and_the_previous_status(): void
    {
        Storage::fake('docs');
        $payloads = [];
        [$enrollment, $actor] = $this->eligibleEnrollment();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.revoke'));
        $academic = $this->generationService(new CertificateQrTokenService($this->qrRenderer($payloads)))
            ->generate($enrollment, $actor);

        (new CertificateQrTokenService())->revoke($academic, $actor, 'Datos del participante incorrectos');

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-updated', $actor->id));

        $this->assertSame(AcademicDocumentStatus::Current->value, $properties['old']['status']);
        $this->assertSame(AcademicDocumentStatus::Annulled->value, $properties['attributes']['status']);
        $this->assertSame('Datos del participante incorrectos', $properties['attributes']['annul_reason']);
        $this->assertSame($actor->id, (int) $properties['attributes']['annulled_by']);
        $this->assertNotNull($properties['attributes']['qr_token_revoked_at']);
    }

    public function test_document_regeneration_is_audited_with_the_actor_the_replacement_and_the_replaced_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        [$enrollment, $actor] = $this->eligibleEnrollment();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.revoke'));
        $service = $this->generationService(new CertificateQrTokenService($this->qrRenderer($payloads)));
        $old = $service->generate($enrollment, $actor);

        $replacement = $service->regenerate($old, $actor, 'Corrección del nombre del participante');

        $createdProperties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $replacement->id, 'course-created', $actor->id));
        $this->assertSame($replacement->code, $createdProperties['attributes']['code']);

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $old->id, 'course-updated', $actor->id));
        $this->assertSame(AcademicDocumentStatus::Current->value, $properties['old']['status']);
        $this->assertSame(AcademicDocumentStatus::Replaced->value, $properties['attributes']['status']);
        $this->assertSame('Corrección del nombre del participante', $properties['attributes']['annul_reason']);
        $this->assertSame($replacement->id, (int) $properties['attributes']['replaced_by_id']);
    }

    // ---------------------------------------------------------------------
    // Certificate templates
    // ---------------------------------------------------------------------

    public function test_a_template_change_is_audited_with_the_actor_and_the_old_and_new_configuration(): void
    {
        $this->actingAs($actor = $this->userWith('course-talks.templates.manage'));

        $template = CourseCertificateTemplate::query()->create([
            'name' => 'Plantilla original',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'course-talks.certificates.reference',
            'settings_json' => ['title' => 'Título original'],
        ]);

        (new CourseCertificateTemplateService())->update($template, [
            'name' => 'Plantilla corregida',
            'settings_json' => ['title' => 'Título corregido'],
        ]);

        $properties = $this->properties($this->auditEntry(CourseCertificateTemplate::class, $template->id, 'course-updated', $actor->id));

        $this->assertSame('Plantilla original', $properties['old']['name']);
        $this->assertSame('Plantilla corregida', $properties['attributes']['name']);
        $this->assertSame('Título original', $properties['old']['settings_json']['title']);
        $this->assertSame('Título corregido', $properties['attributes']['settings_json']['title']);
        $this->assertSame(1, (int) $properties['old']['version']);
        $this->assertSame(2, (int) $properties['attributes']['version']);
    }

    // ---------------------------------------------------------------------
    // Commercial documents
    // ---------------------------------------------------------------------

    public function test_a_commercial_document_registration_is_audited_with_the_responsible_actor_and_the_registered_amounts(): void
    {
        $actor = $this->userWith('course-talks.commercial-documents.manage');
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'series' => 'F001',
            'number' => '00001234',
        ], $actor);

        $properties = $this->properties($this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-created', $actor->id));

        $this->assertSame('120.00', $properties['attributes']['subtotal_amount']);
        $this->assertSame('21.60', $properties['attributes']['igv_amount']);
        $this->assertSame('141.60', $properties['attributes']['total_amount']);
    }

    public function test_a_commercial_document_attachment_change_is_audited_with_the_actor_and_the_replaced_document(): void
    {
        Storage::fake('docs');
        $actor = $this->userWith('course-talks.commercial-documents.manage');
        $service = app(CourseCommercialDocumentService::class);
        $commercial = $service->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'payer_name' => 'Pagador',
        ], $actor);

        $service->upload($commercial, UploadedFile::fake()->create('boleta-1.pdf', 4, 'application/pdf'), $actor);
        $firstDocumentId = (int) $commercial->fresh()->document_id;
        $service->upload($commercial->fresh(), UploadedFile::fake()->create('boleta-2.pdf', 4, 'application/pdf'), $actor);

        $attached = $this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-commercial-document-attached', $actor->id);
        $this->assertNull($this->properties($attached)['previous_document_id']);
        $this->assertSame($firstDocumentId, (int) $this->properties($attached)['document_id']);

        $replaced = $this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-commercial-document-replaced', $actor->id);
        $this->assertSame($firstDocumentId, (int) $this->properties($replaced)['previous_document_id']);

        // The first model-level change is the attachment: it took the comprobante
        // from "pending file" to "registered" and it carries the same actor.
        $registered = DB::table('activity_log')
            ->where('subject_type', CourseCommercialDocument::class)
            ->where('subject_id', $commercial->id)
            ->where('description', 'course-updated')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($registered, 'The attachment must be recorded on the comprobante itself.');
        $this->assertSame($actor->id, (int) $registered->causer_id);
        $properties = $this->properties($registered);
        $this->assertSame('pending_file', $properties['old']['status']);
        $this->assertSame('registered', $properties['attributes']['status']);
    }

    // ---------------------------------------------------------------------
    // Delivery attempts and manual confirmations
    // ---------------------------------------------------------------------

    public function test_a_successful_email_delivery_attempt_is_audited_with_the_responsible_actor(): void
    {
        $actor = $this->userWith('course-talks.documents.send');
        $academic = $this->academicDocument();
        $sentAt = now()->startOfSecond();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => $sentAt))
            ->sendAcademicEmail($academic, 'recipient@example.test', $actor, 'audit-email-sent-001');

        $attempt = $this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-document-email-sent', $actor->id);
        $this->assertSame($delivery->id, (int) $this->properties($attempt)['delivery_id']);

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-updated', $actor->id));
        $this->assertSame('pending', $properties['old']['delivery_status']);
        $this->assertSame('sent', $properties['attributes']['delivery_status']);
    }

    public function test_a_failed_email_delivery_attempt_is_audited_with_the_responsible_actor(): void
    {
        $actor = $this->userWith('course-talks.documents.send');
        $academic = $this->academicDocument();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => false))
            ->sendAcademicEmail($academic, 'recipient@example.test', $actor, 'audit-email-failed-001');

        $attempt = $this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-document-email-failed', $actor->id);
        $this->assertSame($delivery->id, (int) $this->properties($attempt)['delivery_id']);

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-updated', $actor->id));
        $this->assertSame('pending', $properties['old']['delivery_status']);
        $this->assertSame('failed', $properties['attributes']['delivery_status']);
    }

    public function test_a_commercial_email_delivery_attempt_is_audited_with_the_responsible_actor(): void
    {
        $actor = $this->userWith('course-talks.documents.send');
        $commercial = $this->commercialDocument();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => now()->startOfSecond()))
            ->sendCommercialEmail($commercial, 'billing@example.test', $actor, 'audit-commercial-email-001');

        $attempt = $this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-commercial-document-email-sent', $actor->id);
        $properties = $this->properties($attempt);
        $this->assertSame($delivery->id, (int) $properties['delivery_id']);
        // The attempt is audited without the recipient or the private file: the
        // ledger keeps the recipient snapshot, the audit does not.
        $this->assertStringNotContainsString('billing@example.test', (string) $attempt->properties);

        $snapshot = $this->properties($this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-updated', $actor->id));
        $this->assertSame('pending', $snapshot['old']['delivery_status']);
        $this->assertSame('sent', $snapshot['attributes']['delivery_status']);
    }

    public function test_a_manual_academic_whatsapp_confirmation_is_audited_with_the_responsible_actor(): void
    {
        Storage::fake('docs');
        $actor = $this->userWith('course-talks.documents.send');
        $academic = $this->academicDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => now()->startOfSecond());
        $handoff = $service->openAcademicWhatsAppHandoff($academic, '+51999888777', $actor, 'audit-wa-handoff-001')['delivery'];

        $confirmation = $service->confirmAcademicWhatsAppSent($academic, $handoff, '+51999888777', $actor, 'audit-wa-confirm-001');

        $entry = $this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-document-whatsapp-confirmed', $actor->id);
        $this->assertSame($confirmation->id, (int) $this->properties($entry)['delivery_id']);

        $properties = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-updated', $actor->id));
        $this->assertSame('sent', $properties['attributes']['delivery_status']);
    }

    public function test_a_manual_commercial_whatsapp_confirmation_is_audited_with_the_responsible_actor(): void
    {
        Storage::fake('docs');
        $actor = $this->userWith('course-talks.documents.send');
        $commercial = $this->commercialDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => now()->startOfSecond());
        $handoff = $service->openCommercialWhatsAppHandoff($commercial, '+51999888777', $actor, 'audit-wa-commercial-handoff-001')['delivery'];

        $confirmation = $service->confirmCommercialWhatsAppSent($commercial, $handoff, '+51999888777', $actor, 'audit-wa-commercial-confirm-001');

        $entry = $this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-commercial-document-whatsapp-confirmed', $actor->id);
        $this->assertSame($confirmation->id, (int) $this->properties($entry)['delivery_id']);

        $properties = $this->properties($this->auditEntry(CourseCommercialDocument::class, $commercial->id, 'course-updated', $actor->id));
        $this->assertSame('sent', $properties['attributes']['delivery_status']);
    }

    // ---------------------------------------------------------------------
    // Alert closures
    // ---------------------------------------------------------------------

    public function test_an_alert_discard_is_audited_with_the_actor_the_reason_and_the_previous_status(): void
    {
        $actor = $this->userWith('course-talks.documents.send');
        $academic = $this->academicDocument();

        (new CourseAlertService())->discard($academic, 'El participante pidió no recibirlo', $actor);

        $entry = $this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-delivery-alert-discarded', $actor->id);
        $properties = $this->properties($entry);
        $this->assertSame('El participante pidió no recibirlo', $properties['reason']);
        $this->assertSame('pending', $properties['previous_delivery_status']);

        // The same change is visible on the document itself, with the same actor.
        $snapshot = $this->properties($this->auditEntry(CourseAcademicDocument::class, $academic->id, 'course-updated', $actor->id));
        $this->assertSame('pending', $snapshot['old']['delivery_status']);
        $this->assertSame('discarded', $snapshot['attributes']['delivery_status']);
        $this->assertNull($snapshot['old']['delivery_discard_reason'] ?? null);
        $this->assertSame('El participante pidió no recibirlo', $snapshot['attributes']['delivery_discard_reason']);
    }

    // ---------------------------------------------------------------------
    // Privacy: no raw QR material in the audit trail
    // ---------------------------------------------------------------------

    public function test_the_audit_trail_records_the_qr_token_hash_and_never_the_raw_qr_token(): void
    {
        Storage::fake('docs');
        $payloads = [];
        [$enrollment, $actor] = $this->eligibleEnrollment();

        $academic = $this->generationService(new CertificateQrTokenService($this->qrRenderer($payloads)))
            ->generate($enrollment, $actor);
        $academic = $academic->fresh();

        // The QR payload carries the RAW token, which is never persisted: this
        // is the value the log must not contain, and it is a real token, not a
        // placeholder — the assertion below would pass vacuously otherwise.
        $rawToken = basename($payloads[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rawToken);
        $this->assertSame(hash_hmac('sha256', $rawToken, (string) config('app.key')), $academic->qr_token_hash);
        $this->assertNotSame($rawToken, $academic->qr_token_hash);

        // What the trail records is the HASH, which is not the token and cannot
        // be replayed to obtain the PDF: asserting the hash is present and the
        // raw token absent is the assertion the spec's rule is about.
        $hashRows = DB::table('activity_log')
            ->where('subject_type', CourseAcademicDocument::class)
            ->where('subject_id', $academic->id)
            ->orderBy('id')
            ->get()
            ->filter(fn ($row): bool => ($this->properties($row)['attributes']['qr_token_hash'] ?? null) !== null);
        $this->assertCount(1, $hashRows, 'Exactly one entry must record the QR token hash of the document.');
        $hashEntry = $hashRows->first();
        $this->assertSame($actor->id, (int) $hashEntry->causer_id);
        $hashProperties = $this->properties($hashEntry);
        $this->assertSame($academic->qr_token_hash, $hashProperties['attributes']['qr_token_hash']);
        $this->assertNotSame($rawToken, $hashProperties['attributes']['qr_token_hash']);
        $this->assertNull($hashProperties['old']['qr_token_hash']);

        // And the raw token appears in NO column of ANY activity row, not merely
        // in a payload someone happened to look at.
        foreach (DB::table('activity_log')->get() as $row) {
            $this->assertStringNotContainsString($rawToken, (string) json_encode($row), "Raw QR token leaked into activity_log row #{$row->id}.");
        }
    }

    public function test_no_activity_payload_carries_a_private_document_path_or_a_signed_link(): void
    {
        Storage::fake('docs');
        $payloads = [];
        [$enrollment, $actor] = $this->eligibleEnrollment();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.send'));

        $service = $this->generationService(new CertificateQrTokenService($this->qrRenderer($payloads)));
        $academic = $service->generate($enrollment, $actor);
        $path = $academic->fresh()->document->path;

        (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->sendAcademicEmail($academic, 'recipient@example.test', $actor, 'audit-privacy-email-001');

        // The private storage location and the signed link live in `documents`
        // and in the message, not in the audit trail.
        $log = (string) json_encode(DB::table('activity_log')->get());
        $this->assertStringNotContainsString($path, $log);
        $this->assertStringNotContainsString('course-academic-documents/', $log);
        $this->assertStringNotContainsString('signature=', $log);
        $this->assertStringNotContainsString('recipient@example.test', $log);
    }

    // ---------------------------------------------------------------------
    // The two audit mechanisms are not the same thing
    // ---------------------------------------------------------------------

    public function test_the_activity_causer_is_the_domain_actor_while_the_audit_columns_follow_the_authenticated_session(): void
    {
        [, $session, $enrollment] = $this->courseEditionWithSession();
        $explicitActor = $this->userWith('course-talks.grades.manage');
        $sessionUser = $this->userWith('course-talks.grades.manage');
        $this->actingAs($sessionUser);

        $grade = (new CourseGradeService())->record($session, $enrollment, '15.00', 'Examen final', $explicitActor);
        $grade = $grade->fresh();

        // The activity causer is the responsible actor the service received.
        $row = $this->auditEntry(CourseGrade::class, $grade->id, 'course-created', $explicitActor->id);
        $this->assertSame($explicitActor->id, (int) $row->causer_id);

        // `HasAuditColumns` is a different rule: it fills created_by/updated_by
        // from the AUTHENTICATED user only, and the domain's own actor column is
        // the one that carries the responsible actor here.
        $this->assertSame($explicitActor->id, (int) $grade->entered_by);
        $this->assertSame($sessionUser->id, (int) $grade->created_by);
        $this->assertNotSame((int) $grade->created_by, (int) $row->causer_id);
    }

    // ---------------------------------------------------------------------
    // Assertions helpers
    // ---------------------------------------------------------------------

    /**
     * One entry by the convention the domain uses: model-backed changes carry a
     * `course-*` DESCRIPTION with a raw event name, changes written by a service
     * carry a `course-*` EVENT name, so the rule "the entry is named course-*"
     * is asserted the same way for both.
     */
    private function auditEntry(string $subjectType, int $subjectId, string $name, ?int $causerId = null): object
    {
        $row = DB::table('activity_log')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where(function ($query) use ($name): void {
                $query->where('event', $name)->orWhere('description', $name);
            })
            ->when($causerId !== null, fn ($query) => $query->where('causer_id', $causerId))
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            $this->fail(sprintf(
                'No audit entry "%s" (causer %s) for %s#%d. Entries found: %s',
                $name,
                $causerId === null ? 'any' : (string) $causerId,
                class_basename($subjectType),
                $subjectId,
                $this->auditDiagnostics($subjectType, $subjectId),
            ));
        }

        return $row;
    }

    private function auditDiagnostics(string $subjectType, int $subjectId): string
    {
        $rows = DB::table('activity_log')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->get(['id', 'event', 'description', 'causer_id'])
            ->map(fn ($row): string => sprintf(
                '#%s event=%s description=%s causer=%s',
                $row->id,
                $row->event ?? 'NULL',
                $row->description ?? 'NULL',
                $row->causer_id ?? 'NULL',
            ))
            ->implode(' | ');

        return $rows === '' ? '(no audit rows for this subject)' : $rows;
    }

    /** @return array<string, mixed> */
    private function properties(object $row): array
    {
        return json_decode((string) $row->properties, true) ?? [];
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function courseEdition(): CourseEdition
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);

        return CourseEdition::factory()->for($activity, 'activity')->create(['state' => CourseEditionState::Draft]);
    }

    /**
     * @param  array<string, mixed>  $enrollmentAttributes
     * @return array{0: CourseEdition, 1: CourseSession, 2: CourseEnrollment}
     */
    private function courseEditionWithSession(array $enrollmentAttributes = []): array
    {
        $edition = $this->courseEdition();
        $session = CourseSession::factory()->for($edition, 'edition')->create(['sort_order' => 1]);
        $participant = CourseParticipant::factory()->create();

        $enrollment = CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create($enrollmentAttributes);

        return [$edition, $session, $enrollment];
    }

    /** @return array{0: CourseEnrollment, 1: User} */
    private function eligibleEnrollment(): array
    {
        $actor = $this->userWith('course-talks.documents.generate');
        $activity = CourseActivity::factory()->create([
            'type' => CourseActivityType::Course,
            'name' => 'Curso Avanzado de Saneamiento Ambiental',
            'official_academic_hours' => '24.00',
        ]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-04',
            'validations_completed_at' => now(),
            'responsible_user_id' => $actor->id,
        ]);
        $participant = CourseParticipant::factory()->create(['first_name' => 'Alvaro Segundo', 'last_name' => 'Alama Silva']);
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        CourseSession::factory()->for($edition, 'edition')->create(['topic' => 'Marco normativo', 'sort_order' => 1]);

        $enrollment = CourseEnrollment::factory()
            ->for($edition, 'edition')
            ->for($participant, 'participant')
            ->create([
                'course_enrollment_group_id' => $group->id,
                'state' => CourseEnrollmentState::Completed,
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::Approved,
            ]);

        return [$enrollment, $actor];
    }

    private function generationService(?CertificateQrTokenService $qrTokens = null): CourseDocumentGenerationService
    {
        return new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer(),
            qrTokens: $qrTokens,
        );
    }

    /** @param array<int, string> $payloads */
    private function qrRenderer(array &$payloads): QrRenderer
    {
        return new class($payloads) implements QrRenderer {
            public function __construct(private array &$payloads) {}

            public function renderSvg(string $payload): string
            {
                $this->payloads[] = $payload;

                return '<svg>QR</svg>';
            }
        };
    }

    private function pdfRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $view, array $data): string
            {
                return '%PDF '.$view;
            }
        };
    }

    private function academicDocument(): CourseAcademicDocument
    {
        return CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-'.str()->upper(str()->random(8)),
            'issue_date' => now()->toDateString(),
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }

    private function academicDocumentWithPdf(): CourseAcademicDocument
    {
        $academic = $this->academicDocument();
        $path = "course-academic-documents/{$academic->course_enrollment_id}/{$academic->code}.pdf";
        Storage::disk('docs')->put($path, '%PDF academic certificate', ['visibility' => 'private']);
        $document = Document::query()->create([
            'docable_type' => CourseAcademicDocument::class,
            'docable_id' => $academic->id,
            'name' => $academic->code.'.pdf',
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 25,
            'uploaded_by' => $this->actor->id,
            'uploaded_at' => now(),
        ]);
        $academic->forceFill([
            'document_id' => $document->id,
            'qr_token_hash' => hash_hmac('sha256', 'raw-token-'.$academic->id, (string) config('app.key')),
        ])->save();

        return $academic->fresh();
    }

    private function commercialDocument(): CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => (string) random_int(100000, 999999),
            'issue_date' => now()->toDateString(),
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Pagador',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }

    private function commercialDocumentWithPdf(): CourseCommercialDocument
    {
        $commercial = $this->commercialDocument();
        $path = "course-commercial-documents/{$commercial->id}/{$commercial->series}-{$commercial->number}.pdf";
        Storage::disk('docs')->put($path, '%PDF commercial document', ['visibility' => 'private']);
        $document = Document::query()->create([
            'docable_type' => CourseCommercialDocument::class,
            'docable_id' => $commercial->id,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 25,
            'uploaded_by' => $this->actor->id,
            'uploaded_at' => now(),
        ]);
        $commercial->forceFill(['document_id' => $document->id])->save();

        return $commercial->fresh();
    }
}
