<?php

namespace Tests\Feature\Courses;

use App\Contracts\Courses\{PdfRenderer, QrRenderer};
use App\Enums\Courses\{AcademicDocumentStatus,AcademicDocumentType,CourseActivityType,CourseEnrollmentState,FinalResult,PaymentStatus};
use App\Models\Courses\{CourseActivity,CourseAcademicDocument,CourseEdition,CourseEnrollment,CourseEnrollmentGroup,CourseParticipant,CourseSession};
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseAcademicDocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_approved_certificate_pdf_in_private_storage_and_registers_document(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        $academic = $this->service()->generate($enrollment, $actor);

        $this->assertSame(AcademicDocumentType::ApprovalCertificate, $academic->type);
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertSame('Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf', $academic->filename);
        $this->assertSame("course-academic-documents/{$enrollment->id}/{$academic->code}.pdf", $academic->document->path);
        $this->assertSame('docs', $academic->document->disk);
        $this->assertSame(CourseAcademicDocument::class, $academic->document->docable_type);
        $this->assertTrue(Storage::disk('docs')->exists($academic->document->path));
        $pdf = Storage::disk('docs')->get($academic->document->path);
        foreach (['%PDF', 'Certificado de aprobación', 'Temario', $academic->code, '<svg'] as $expected) {
            $this->assertStringContainsString($expected, $pdf);
        }
    }

    public function test_generation_denies_an_actor_without_document_generation_permission(): void
    {
        Storage::fake('docs');
        [$enrollment] = $this->eligibleEnrollment(FinalResult::Approved);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->service()->generate($enrollment, User::factory()->create());
    }

    public function test_selects_participation_constancy_and_talk_certificate_from_eligibility_result(): void
    {
        Storage::fake('docs');
        $actor = User::factory()->create();
        [$participation] = $this->eligibleEnrollment(FinalResult::Participation, CourseActivityType::Course, $actor);
        [$talk] = $this->eligibleEnrollment(FinalResult::NotApplicable, CourseActivityType::Talk, $actor);

        $this->assertSame(AcademicDocumentType::ParticipationConstancy, $this->service()->generate($participation, $actor)->type);
        $this->assertSame(AcademicDocumentType::TalkCertificate, $this->service()->generate($talk, $actor)->type);
    }

    public function test_generated_documents_issue_unique_hash_only_tokens_embed_qr_output_and_stream_the_current_pdf(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        $actor = User::factory()->create();
        [$firstEnrollment] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $actor);
        [$secondEnrollment] = $this->eligibleEnrollment(FinalResult::Approved, CourseActivityType::Course, $actor);
        $pdfCalls = [];
        $service = $this->service($tokens, $pdfCalls);

        $first = $service->generate($firstEnrollment, $actor);
        $second = $service->generate($secondEnrollment, $actor);

        $this->assertCount(2, $payloads);
        $this->assertNotSame($payloads[0], $payloads[1]);
        $this->assertStringContainsString('/certificate/qr/', $payloads[0]);
        $this->assertStringContainsString($payloads[0], Storage::disk('docs')->get($first->document->path));
        $this->assertSame('course-talks.certificates.reference', $pdfCalls[0]['view']);
        $this->assertSame($first->code, $pdfCalls[0]['data']['certificate']->certificateCode);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->qr_token_hash);
        $this->assertNotSame($first->qr_token_hash, $second->qr_token_hash);
        $this->assertDatabaseMissing('course_academic_documents', ['qr_token_hash' => basename($payloads[0])]);

        $response = $this->get($payloads[0]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('%PDF', $response->streamedContent());
    }

    public function test_regeneration_requires_reason_and_authorized_actor_replaces_and_revokes_the_old_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = $this->service($tokens);
        $old = $service->generate($enrollment, $actor);
        $oldToken = basename($payloads[0]);

        $this->expectException(\InvalidArgumentException::class);
        $service->regenerate($old, $actor, '');
    }

    public function test_authorized_regeneration_preserves_old_private_pdf_and_issues_one_current_document(): void
    {
        Storage::fake('docs');
        $payloads = [];
        $tokens = new CertificateQrTokenService($this->qrRenderer($payloads));
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        Permission::create(['name' => 'course-talks.documents.revoke']);
        $actor->givePermissionTo('course-talks.documents.revoke');
        $service = $this->service($tokens);
        $old = $service->generate($enrollment, $actor);
        $oldToken = basename($payloads[0]);

        $replacement = $service->regenerate($old, $actor, 'Corrected participant name');

        $old = $old->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $old->status);
        $this->assertNotNull($old->qr_token_revoked_at);
        $this->assertSame($replacement->id, $old->replaced_by_id);
        $this->assertSame('Corrected participant name', $old->annul_reason);
        $this->assertTrue(Storage::disk('docs')->exists($old->document->path));
        $this->assertCount(1, CourseAcademicDocument::query()->where('course_enrollment_id', $enrollment->id)->where('status', AcademicDocumentStatus::Current)->get());
        $this->assertNotSame($old->qr_token_hash, $replacement->qr_token_hash);
        $this->assertDatabaseMissing('course_academic_documents', ['qr_token_hash' => basename($payloads[1])]);
        $this->get("/certificate/qr/{$oldToken}")->assertNotFound();
        $this->get($payloads[1])->assertOk();
    }

    public function test_generation_defers_private_storage_until_an_outer_transaction_commits(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        DB::beginTransaction();
        try {
            $academic = $this->service()->generate($enrollment, $actor);

            $this->assertSame(AcademicDocumentStatus::PendingGeneration, $academic->status);
            $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
            $this->assertDatabaseCount('course_academic_documents', 1);
        } finally {
            DB::rollBack();
        }

        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_outer_transaction_commit_writes_private_pdf_and_only_then_registers_document_metadata(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);

        DB::beginTransaction();
        $academic = $this->service()->generate($enrollment, $actor);
        $this->assertSame(AcademicDocumentStatus::PendingGeneration, $academic->status);
        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        DB::commit();

        $academic = $academic->fresh()->load('document');
        $this->assertSame(AcademicDocumentStatus::Current, $academic->status);
        $this->assertNotNull($academic->document);
        $this->assertTrue(Storage::disk('docs')->exists($academic->document->path));
    }

    public function test_deferred_storage_failure_marks_document_failed_without_metadata_or_private_file(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer(),
            documentCreator: fn (): never => throw new \RuntimeException('database persistence failed'),
        );

        DB::beginTransaction();
        $academic = $service->generate($enrollment, $actor);
        DB::commit();

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Failed, $academic->status);
        $this->assertNull($academic->document_id);
        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_generation_removes_new_private_file_when_document_persistence_fails(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $service = new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer(),
            documentCreator: fn (): never => throw new \RuntimeException('database persistence failed'),
        );

        try {
            $service->generate($enrollment, $actor);
            $this->fail('Expected document persistence failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database persistence failed', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('docs')->allFiles('course-academic-documents'));
        $this->assertDatabaseCount('course_academic_documents', 0);
    }

    public function test_document_service_keeps_private_download_authorization_for_course_academic_documents(): void
    {
        Storage::fake('docs');
        [$enrollment, $actor] = $this->eligibleEnrollment(FinalResult::Approved);
        $academic = $this->service()->generate($enrollment, $actor);
        Permission::create(['name' => 'documents.download']);
        $enrollment->edition->responsible->givePermissionTo('documents.download');

        $documents = app(DocumentService::class);

        $this->assertTrue($documents->canDownload($academic->document, $enrollment->edition->responsible));
        $this->assertFalse($documents->canDownload($academic->document, User::factory()->create()));
    }

    private function service(?CertificateQrTokenService $qrTokens = null, ?array &$pdfCalls = null): CourseDocumentGenerationService
    {
        return new CourseDocumentGenerationService(
            pdfRenderer: $this->pdfRenderer($pdfCalls),
            qrTokens: $qrTokens,
        );
    }

    private function qrRenderer(array &$payloads): QrRenderer
    {
        return new class($payloads) implements QrRenderer {
            public function __construct(private array &$payloads) {}

            public function renderSvg(string $payload): string
            {
                $this->payloads[] = $payload;

                return '<svg>QR '.$payload.'</svg>';
            }
        };
    }

    private function pdfRenderer(?array &$calls = null): PdfRenderer
    {
        return new class($calls) implements PdfRenderer {
            public function __construct(private ?array &$calls) {}

            public function render(string $view, array $data): string
            {
                if ($this->calls !== null) {
                    $this->calls[] = compact('view', 'data');
                }

                return '%PDF '.$view.' '.view($view, $data)->render();
            }
        };
    }

    private function eligibleEnrollment(FinalResult $result, CourseActivityType $type = CourseActivityType::Course, ?User $actor = null): array
    {
        $actor ??= User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.generate'));
        $activity = CourseActivity::factory()->create(['type' => $type, 'name' => $type === CourseActivityType::Talk ? 'Charla de Bioseguridad' : 'Curso Avanzado de Saneamiento Ambiental', 'official_academic_hours' => $type === CourseActivityType::Talk ? '2.50' : '24.00', 'talk_includes_certificate' => $type === CourseActivityType::Talk]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['starts_on' => '2026-07-01', 'ends_on' => $type === CourseActivityType::Talk ? '2026-07-01' : '2026-07-04', 'validations_completed_at' => now(), 'responsible_user_id' => $actor->id]);
        $participant = CourseParticipant::factory()->create(['first_name' => 'Alvaro Segundo', 'last_name' => 'Alama Silva']);
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        CourseSession::factory()->for($edition, 'edition')->create(['topic' => 'Marco normativo', 'sort_order' => 1]);

        return [CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create(['course_enrollment_group_id' => $group->id, 'state' => CourseEnrollmentState::Completed, 'payment_status' => PaymentStatus::Paid, 'final_result' => $result, 'participation_confirmed_at' => $type === CourseActivityType::Talk ? now() : null]), $actor];
    }
}
