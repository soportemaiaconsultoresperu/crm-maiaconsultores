<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\PdfRenderer;
use App\Contracts\Email\EmailProvider;
use App\Contracts\Email\EmailProviderFactory;
use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\CourseModality;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Jobs\Courses\EvaluateCourseDocumentEligibility;
use App\Jobs\V2\SendEmailMessage;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\Document;
use App\Models\Email\EmailMessage;
use App\Models\IntegrationAccount;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\CourseEditionService;
use App\Services\Courses\CourseEligibilityService;
use App\Services\Courses\CourseEligibilityTriggerService;
use App\Services\QuotationService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Guide findings fixes (odd/tasks/guide-findings-fixes.md, T2): every behaviour
 * the "Cursos y charlas" user guide had to describe as a defect of the documents
 * and comprobantes surfaces.
 */
class CourseDocumentGuideFixesTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '51999888777';

    // ---- S12 / S13: what the certificate prints -------------------------------

    public function test_the_certificate_prints_every_modality_with_its_spanish_label(): void
    {
        Storage::fake('docs');

        foreach (CourseModality::cases() as $modality) {
            $calls = [];
            [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $modality);

            $this->generationService($calls)->generate($enrollment, $actor);

            $html = $calls[0]['html'];
            $this->assertSame($modality->label(), $calls[0]['data']['certificate']->modality);
            $this->assertStringContainsString('desarrollado en modalidad '.$modality->label().',', $html);
            $this->assertStringContainsString('en modalidad '.$modality->label().', con una duración', $html);
            if ($modality->name !== $modality->label()) {
                $this->assertStringNotContainsString('modalidad '.$modality->name, $html, 'The internal enum name must never be printed.');
            }
        }

        $this->assertSame(['Presencial', 'Virtual', 'Híbrida'], array_map(fn (CourseModality $m): string => $m->label(), CourseModality::cases()));
    }

    public function test_the_second_page_title_matches_the_document_type(): void
    {
        Storage::fake('docs');
        $actor = User::factory()->create();

        $expectations = [
            [FinalResult::Participation, CourseActivityType::Course, 'CONSTANCIA DE PARTICIPACIÓN'],
            [FinalResult::Approved, CourseActivityType::Course, 'CERTIFICADO'],
            [FinalResult::NotApplicable, CourseActivityType::Talk, 'CERTIFICADO'],
        ];

        foreach ($expectations as [$result, $type, $title]) {
            $calls = [];
            [$enrollment] = $this->eligibleEnrollment($result, $type, CourseModality::Virtual, $actor);

            $this->generationService($calls)->generate($enrollment, $actor);

            $this->assertStringContainsString('<h2 class="syllabus-title">'.$title.'</h2>', $calls[0]['html']);
        }
    }

    // ---- S14: comprobante emails use the system SMTP path ---------------------

    public function test_a_comprobante_email_without_a_bound_account_is_sent_through_the_system_smtp(): void
    {
        $commercial = $this->commercialRow();
        [$message, $delivery] = $this->queuedMessageFor(CourseCommercialDocument::class, $commercial->id);
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider_message_id' => 'smtp-commercial']);
        $factory = Mockery::mock(EmailProviderFactory::class);
        $factory->shouldReceive('make')->once()->with('smtp')->andReturn($provider);
        $factory->shouldReceive('for')->never();

        (new SendEmailMessage($message->id))->handle($factory, app(QuotationService::class));

        $this->assertSame(EmailMessage::STATUS_SENT, $message->fresh()->status);
        $this->assertSame(OutboundDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
        $this->assertNotNull($commercial->fresh()->last_sent_at);
    }

    public function test_a_comprobante_email_with_a_bound_account_still_uses_the_system_smtp_like_academic_documents(): void
    {
        $commercial = $this->commercialRow();
        $account = IntegrationAccount::query()->create(['provider' => 'gmail', 'label' => 'Bound', 'is_shared' => true, 'is_active' => true, 'test_mode' => true]);
        [$message] = $this->queuedMessageFor(CourseCommercialDocument::class, $commercial->id, $account->id);
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider_message_id' => 'smtp-commercial-bound']);
        $factory = Mockery::mock(EmailProviderFactory::class);
        $factory->shouldReceive('make')->once()->with('smtp')->andReturn($provider);
        $factory->shouldReceive('for')->never();

        (new SendEmailMessage($message->id))->handle($factory, app(QuotationService::class));

        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
    }

    // ---- S15: talk certificates and talk eligibility --------------------------

    public function test_a_talk_without_certificate_and_confirmed_attendance_is_never_reported_as_unconfirmed_participation(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $viewer = $this->userWith(['course-talks.view']);
        $edition = $this->edition(CourseActivityType::Talk, talkIncludesCertificate: false);
        $enrollment = $this->enrollmentIn($edition, ['participation_confirmed_at' => now(), 'final_result' => FinalResult::NotApplicable]);

        $result = app(CourseEligibilityService::class)->evaluate($enrollment);

        $this->assertFalse($result->eligible);
        $this->assertNotContains('participation', $result->missingConditions);
        $this->assertSame(['talk_without_certificate'], $result->missingConditions);

        $this->actingAs($viewer)->get(route('course-talks.documents.index', $edition))
            ->assertOk()
            ->assertDontSee('Participación de la charla sin confirmar')
            ->assertSee('La charla no emite certificado');
    }

    public function test_a_talk_with_certificate_still_reports_missing_attendance(): void
    {
        $edition = $this->edition(CourseActivityType::Talk, talkIncludesCertificate: true);
        $enrollment = $this->enrollmentIn($edition, ['participation_confirmed_at' => null, 'final_result' => FinalResult::NotApplicable]);

        $this->assertSame(['participation'], app(CourseEligibilityService::class)->evaluate($enrollment)->missingConditions);
    }

    public function test_finishing_an_edition_can_issue_documents_for_courses_and_talks_with_certificate_only(): void
    {
        $trigger = app(CourseEligibilityTriggerService::class);

        $this->assertTrue($trigger->editionCompletionCanIssueDocuments(CourseActivity::factory()->make(['type' => CourseActivityType::Course])));
        $this->assertTrue($trigger->editionCompletionCanIssueDocuments(CourseActivity::factory()->make(['type' => CourseActivityType::Talk, 'talk_includes_certificate' => true])));
        $this->assertFalse($trigger->editionCompletionCanIssueDocuments(CourseActivity::factory()->make(['type' => CourseActivityType::Talk, 'talk_includes_certificate' => false])));
    }

    /**
     * End to end through the finish flow. Requires the one-line hook in
     * CourseEditionService::completeValidations() that asks
     * CourseEligibilityTriggerService::editionCompletionCanIssueDocuments().
     */
    public function test_finishing_a_talk_with_certificate_requests_automatic_generation(): void
    {
        Queue::fake();
        $edition = $this->edition(CourseActivityType::Talk, talkIncludesCertificate: true, validated: false);
        $enrollment = $this->enrollmentIn($edition, ['participation_confirmed_at' => now(), 'final_result' => FinalResult::NotApplicable]);

        app(CourseEditionService::class)->completeValidations($edition);

        Queue::assertPushed(EvaluateCourseDocumentEligibility::class, fn ($job): bool => $job->enrollmentId === $enrollment->id && $job->reason === 'edition_validation');
    }

    // ---- S17: invalid WhatsApp phone -----------------------------------------

    public function test_an_invalid_whatsapp_phone_gets_a_phone_message_instead_of_not_deliverable(): void
    {
        Storage::fake('docs');
        Queue::fake();
        $this->seed(CoursePermissionsSeeder::class);
        $manager = $this->userWith(['course-talks.view', 'course-talks.documents.send']);
        $edition = $this->edition(CourseActivityType::Course);
        $enrollment = $this->enrollmentIn($edition);
        $academic = $this->academicWithFile($enrollment, $manager);
        $commercial = $this->commercialWithFile($enrollment, $manager);

        $academicResponse = $this->actingAs($manager)->from(route('course-talks.documents.index', $edition))
            ->post(route('course-talks.documents.whatsapp', $academic), ['recipient_phone' => '12 34', 'operation_key' => 'bad-phone-academic']);
        $commercialResponse = $this->actingAs($manager)->from(route('course-talks.commercial-documents.index', $edition))
            ->post(route('course-talks.commercial-documents.whatsapp', $commercial), ['recipient_phone' => '12 34', 'operation_key' => 'bad-phone-commercial']);

        foreach ([$academicResponse, $commercialResponse] as $response) {
            $response->assertRedirect();
            $response->assertSessionHasErrors('recipient_phone');
            $error = session('errors')->first('recipient_phone');
            $this->assertStringContainsString('entre 8 y 15 dígitos', $error);
            $this->assertStringNotContainsString('archivo privado', $error);
        }
        $this->assertDatabaseCount('outbound_deliveries', 0);
    }

    // ---- S3: WhatsApp links last as long as email links ----------------------

    public function test_whatsapp_links_last_as_long_as_email_links(): void
    {
        Storage::fake('docs');
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        Permission::findOrCreate('course-talks.documents.send');
        $actor = User::factory()->create();
        $actor->givePermissionTo('course-talks.documents.send');
        $enrollment = $this->enrollmentIn($this->edition(CourseActivityType::Course));
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $expected = now()->addMinutes((int) config('courses.email_document_link_minutes'))->timestamp;

        $academic = $service->openAcademicWhatsAppHandoff($this->academicWithFile($enrollment, $actor), self::PHONE, $actor, 'ttl-academic');
        $commercial = $service->openCommercialWhatsAppHandoff($this->commercialWithFile($enrollment, $actor), self::PHONE, $actor, 'ttl-commercial');

        $this->assertSame(10080, (int) config('courses.email_document_link_minutes'));
        foreach ([$academic, $commercial] as $handoff) {
            preg_match('#https?://[^\s]+#', $handoff['text'], $matches);
            parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);
            $this->assertSame($expected, (int) $query['expires']);
        }
    }

    // ---- S21 / S4 / S20: comprobante listing wording -------------------------

    public function test_the_pending_whatsapp_notices_use_usted_and_the_human_comprobante_type(): void
    {
        Storage::fake('docs');
        $this->seed(CoursePermissionsSeeder::class);
        $manager = $this->userWith(['course-talks.view', 'course-talks.documents.send']);
        $edition = $this->edition(CourseActivityType::Course);
        $enrollment = $this->enrollmentIn($edition);
        $academic = $this->academicWithFile($enrollment, $manager);
        $commercial = $this->commercialWithFile($enrollment, $manager);
        $handoffs = [
            $this->openHandoff(CourseAcademicDocument::class, $academic->id, 'pending-academic'),
            $this->openHandoff(CourseCommercialDocument::class, $commercial->id, 'pending-commercial'),
        ];

        $documentsHtml = (string) $this->actingAs($manager)->get(route('course-talks.documents.index', $edition))->assertOk()->getContent();
        $commercialHtml = (string) $this->actingAs($manager)->get(route('course-talks.commercial-documents.index', $edition))->assertOk()->getContent();

        foreach ([$documentsHtml, $commercialHtml] as $html) {
            $this->assertStringContainsString('Tiene un envío por WhatsApp pendiente de confirmar.', $html);
            $this->assertStringContainsString('Marque cada uno como enviado cuando lo haya enviado desde WhatsApp, o descártelo si no lo va a usar.', $html);
            foreach (['Tenés', 'Marcá', 'descartalo', 'hayas'] as $voseo) {
                $this->assertStringNotContainsString($voseo, $html);
            }
        }

        $block = $this->block($commercialHtml, 'course-talks-commercial-documents-whatsapp-pending-'.$handoffs[1]->id);
        $this->assertStringContainsString('<strong>Factura</strong>', $block);
        $this->assertStringNotContainsString('<strong>factura</strong>', $block);
    }

    public function test_the_comprobante_listing_no_longer_knows_unreachable_states(): void
    {
        // No action ever writes the comprobante status `sent` or `discarded` (delivery
        // outcomes live in `delivery_status`), so the status map only knows the two
        // reachable states.
        $view = (string) file_get_contents(resource_path('views/course-talks/editions/commercial-documents.blade.php'));
        $start = strpos($view, '$statusMeta = [');
        $statusMap = substr($view, (int) $start, strpos($view, '];', (int) $start) - (int) $start);

        $this->assertStringContainsString("'pending_file' => ['Pendiente de archivo'", $statusMap);
        $this->assertStringContainsString("'registered' => ['Registrado'", $statusMap);
        $this->assertStringNotContainsString("'sent'", $statusMap);
        $this->assertStringNotContainsString("'discarded'", $statusMap);
    }

    // ---- S22: "Ver PDF" inside the module ------------------------------------

    public function test_a_deliverable_document_and_comprobante_offer_a_working_view_link(): void
    {
        $this->assertViewRoutesRegistered();
        Storage::fake('docs');
        $this->seed(CoursePermissionsSeeder::class);
        $viewer = $this->userWith(['course-talks.view']);
        $edition = $this->edition(CourseActivityType::Course);
        $enrollment = $this->enrollmentIn($edition);
        $academic = $this->academicWithFile($enrollment, $viewer);
        $commercial = $this->commercialWithFile($enrollment, $viewer);
        $withoutFile = $this->enrollmentIn($edition, [], '22222222');
        $orphan = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $withoutFile->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-NO-FILE',
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        $documentsHtml = (string) $this->actingAs($viewer)->get(route('course-talks.documents.index', $edition))->assertOk()->getContent();
        $commercialHtml = (string) $this->actingAs($viewer)->get(route('course-talks.commercial-documents.index', $edition))->assertOk()->getContent();

        $academicLink = $this->linkHref($documentsHtml, 'course-talks-document-view-'.$academic->id);
        $this->assertSame(route('course-talks.documents.pdf', $academic), $academicLink);
        $this->assertStringContainsString('Ver PDF', $this->block($documentsHtml, 'course-talks-document-view-'.$academic->id));
        $this->assertStringNotContainsString('course-talks-document-view-'.$orphan->id, $documentsHtml);
        $commercialLink = $this->linkHref($commercialHtml, 'course-talks-commercial-view-'.$commercial->id);
        $this->assertSame(route('course-talks.commercial-documents.pdf', $commercial), $commercialLink);
        $this->assertStringContainsString('Ver PDF', $this->block($commercialHtml, 'course-talks-commercial-view-'.$commercial->id));
        // The listings never carry a signed link: it is minted at click time.
        $this->assertStringNotContainsString('signature=', $documentsHtml.$commercialHtml);

        foreach ([$academicLink, $commercialLink] as $link) {
            $signed = (string) $this->actingAs($viewer)->get($link)->assertRedirect()->headers->get('Location');
            $this->assertStringContainsString('signature=', $signed);
            $this->get($signed)->assertOk()->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_the_view_action_is_refused_to_outsiders_and_for_undeliverable_documents(): void
    {
        $this->assertViewRoutesRegistered();
        Storage::fake('docs');
        $this->seed(CoursePermissionsSeeder::class);
        $viewer = $this->userWith(['course-talks.view']);
        $outsider = $this->userWith([]);
        $edition = $this->edition(CourseActivityType::Course);
        $academic = $this->academicWithFile($this->enrollmentIn($edition), $viewer);
        $commercial = $this->commercialWithFile($this->enrollmentIn($edition, [], '33333333'), $viewer);

        $this->actingAs($outsider)->get(route('course-talks.documents.pdf', $academic))->assertForbidden();
        $this->actingAs($outsider)->get(route('course-talks.commercial-documents.pdf', $commercial))->assertForbidden();

        $academic->forceFill(['status' => AcademicDocumentStatus::Annulled, 'qr_token_revoked_at' => now()])->save();
        Storage::disk('docs')->delete($commercial->document->path);

        $this->actingAs($viewer)->get(route('course-talks.documents.pdf', $academic))
            ->assertRedirect(route('course-talks.documents.index', $edition))
            ->assertSessionHasErrors('documents');
        $this->actingAs($viewer)->get(route('course-talks.commercial-documents.pdf', $commercial))
            ->assertRedirect(route('course-talks.commercial-documents.index', $edition))
            ->assertSessionHasErrors('commercial_document');
    }

    public function test_the_view_link_disappears_once_the_document_is_annulled(): void
    {
        Storage::fake('docs');
        $this->seed(CoursePermissionsSeeder::class);
        $viewer = $this->userWith(['course-talks.view']);
        $edition = $this->edition(CourseActivityType::Course);
        $academic = $this->academicWithFile($this->enrollmentIn($edition), $viewer);
        $academic->forceFill(['status' => AcademicDocumentStatus::Annulled, 'qr_token_revoked_at' => now()])->save();

        $this->actingAs($viewer)->get(route('course-talks.documents.index', $edition))
            ->assertOk()
            ->assertDontSee('course-talks-document-view-'.$academic->id);
    }

    // ---- S16: comprobante form field names -----------------------------------

    public function test_comprobante_validation_names_the_fields_in_spanish(): void
    {
        app()->setLocale('es');
        $this->seed(CoursePermissionsSeeder::class);
        $manager = $this->userWith(['course-talks.view', 'course-talks.commercial-documents.manage']);
        $edition = $this->edition(CourseActivityType::Course);
        $enrollment = $this->enrollmentIn($edition);

        $this->actingAs($manager)->from(route('course-talks.commercial-documents.index', $edition))
            ->post(route('course-talks.commercial-documents.store', $enrollment), [
                'type' => 'factura',
                'course_enrollment_id' => $enrollment->id,
                'payer_name' => '',
                'currency' => 'SOLES',
                'issue_date' => 'mañana',
            ])
            ->assertSessionHasErrors(['payer_name', 'currency', 'issue_date']);

        $errors = session('errors');
        $this->assertStringContainsString('pagador', $errors->first('payer_name'));
        $this->assertStringContainsString('moneda', $errors->first('currency'));
        $this->assertStringContainsString('fecha de emisión', $errors->first('issue_date'));
        $this->assertStringNotContainsString('payer name', $errors->first('payer_name'));
    }

    // ---- fixtures ------------------------------------------------------------

    private function generationService(array &$calls): CourseDocumentGenerationService
    {
        $renderer = new class($calls) implements PdfRenderer {
            public function __construct(private array &$calls) {}

            public function render(string $view, array $data): string
            {
                $html = view($view, $data)->render();
                $this->calls[] = compact('view', 'data', 'html');

                return '%PDF '.$html;
            }
        };

        return new CourseDocumentGenerationService(pdfRenderer: $renderer);
    }

    private function eligibleEnrollment(FinalResult $result, CourseActivityType $type, CourseModality $modality, ?User $actor = null): array
    {
        $actor ??= User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        $activity = CourseActivity::factory()->create(['type' => $type, 'talk_includes_certificate' => $type === CourseActivityType::Talk]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'modality' => $modality,
            'address' => 'Sede Lima',
            'access_url' => 'https://meet.example.test/x',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-04',
            'validations_completed_at' => now(),
        ]);
        $participant = CourseParticipant::factory()->create();
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        CourseSession::factory()->for($edition, 'edition')->create(['topic' => 'Tema uno', 'sort_order' => 1]);

        return [CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create([
            'course_enrollment_group_id' => $group->id,
            'state' => CourseEnrollmentState::Completed,
            'payment_status' => PaymentStatus::Paid,
            'final_result' => $result,
            'participation_confirmed_at' => $type === CourseActivityType::Talk ? now() : null,
        ]), $actor];
    }

    private function edition(CourseActivityType $type, bool $talkIncludesCertificate = false, bool $validated = true): CourseEdition
    {
        return CourseEdition::factory()
            ->for(CourseActivity::factory()->create(['type' => $type, 'talk_includes_certificate' => $talkIncludesCertificate]), 'activity')
            ->create(['validations_completed_at' => $validated ? now() : null]);
    }

    private function enrollmentIn(CourseEdition $edition, array $attributes = [], string $documentNumber = '11111111'): CourseEnrollment
    {
        $participant = CourseParticipant::factory()->create([
            'document_number' => $documentNumber,
            'document_number_norm' => $documentNumber,
            'mobile' => self::PHONE,
            'mobile_norm' => self::PHONE,
        ]);

        return CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create($attributes + [
            'payment_status' => PaymentStatus::Paid,
            'final_result' => FinalResult::Approved,
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function academicWithFile(CourseEnrollment $enrollment, User $uploader): CourseAcademicDocument
    {
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-GUIDE-'.$enrollment->id,
            'issue_date' => now()->toDateString(),
            'filename' => 'certificado.pdf',
            'qr_token_hash' => hash('sha256', 'guide-'.$enrollment->id),
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $academic->forceFill(['document_id' => $this->storedFile($academic, "course-academic-documents/{$enrollment->id}/{$academic->code}.pdf", $uploader)->id])->save();

        return $academic->refresh();
    }

    private function commercialWithFile(CourseEnrollment $enrollment, User $uploader): CourseCommercialDocument
    {
        $commercial = $this->commercialRow($enrollment);
        $commercial->forceFill(['document_id' => $this->storedFile($commercial, "course-commercial-documents/{$commercial->id}/factura.pdf", $uploader)->id])->save();

        return $commercial->refresh();
    }

    private function commercialRow(?CourseEnrollment $enrollment = null): CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->create([
            'course_enrollment_id' => ($enrollment ?? CourseEnrollment::factory()->create())->id,
            'type' => CommercialDocumentType::Factura,
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Pagador',
            'series' => 'F001',
            'number' => '000123',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }

    private function storedFile(CourseAcademicDocument|CourseCommercialDocument $owner, string $path, User $uploader): Document
    {
        Storage::disk('docs')->put($path, '%PDF guía');

        return Document::query()->create([
            'docable_type' => $owner->getMorphClass(),
            'docable_id' => $owner->id,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 9,
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);
    }

    private function openHandoff(string $type, int $id, string $key): OutboundDelivery
    {
        return OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
            'recipient_ref' => self::PHONE,
            'related_entity_type' => $type,
            'related_entity_id' => $id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 1,
            'idempotency_key' => $key,
        ]);
    }

    /** @return array{0: EmailMessage, 1: OutboundDelivery} */
    private function queuedMessageFor(string $type, int $id, ?int $accountId = null): array
    {
        $message = EmailMessage::query()->create([
            'account_id' => $accountId,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'local-'.str()->random(8),
            'from_email' => 'sender@example.test',
            'subject' => 'Comprobante',
            'status' => EmailMessage::STATUS_QUEUED,
        ]);
        $delivery = OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'billing@example.test',
            'related_entity_type' => $type,
            'related_entity_id' => $id,
            'email_message_id' => $message->id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 1,
            'idempotency_key' => str()->random(64),
        ]);

        return [$message, $delivery];
    }

    private function assertViewRoutesRegistered(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has(['course-talks.documents.pdf', 'course-talks.commercial-documents.pdf']),
            'routes/course-documents.php must be required from routes/web.php.',
        );
    }

    private function block(string $html, string $testId): string
    {
        $start = strpos($html, 'data-testid="'.$testId.'"');
        $this->assertNotFalse($start, "{$testId} must be rendered.");

        return substr($html, (int) $start, 600);
    }

    private function linkHref(string $html, string $testId): string
    {
        $this->assertMatchesRegularExpression('/<a [^>]*href="([^"]+)"[^>]*data-testid="'.preg_quote($testId, '/').'"/', $html);
        preg_match('/<a [^>]*href="([^"]+)"[^>]*data-testid="'.preg_quote($testId, '/').'"/', $html, $matches);

        return html_entity_decode($matches[1]);
    }
}
