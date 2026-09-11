<?php

namespace App\Services\Courses;

use App\Contracts\Courses\PdfRenderer;
use App\Enums\Courses\{AcademicDocumentStatus, AcademicDocumentType, DeliveryStatus};
use App\Models\Courses\{CourseAcademicDocument, CourseEnrollment};
use App\Models\{Document, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CourseDocumentGenerationService
{
    public function __construct(
        private readonly ?CourseEligibilityService $eligibility = null,
        private readonly ?CourseCertificateTemplateService $templates = null,
        private readonly ?CourseCertificateFilenameService $filenames = null,
        private readonly ?PdfRenderer $pdfRenderer = null,
        private readonly ?CertificateQrTokenService $qrTokens = null,
        private readonly mixed $documentCreator = null,
    ) {}

    public function generate(CourseEnrollment $enrollment, User $actor): CourseAcademicDocument
    {
        return $this->generateDocument($enrollment, $actor);
    }

    public function regenerate(CourseAcademicDocument $document, User $actor, string $reason): CourseAcademicDocument
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A regeneration reason is required.');
        }

        Gate::forUser($actor)->authorize('revoke', $document);

        $document->loadMissing('enrollment');

        return $this->generateDocument($document->enrollment, $actor, function (CourseAcademicDocument $replacement) use ($document, $actor, $reason): void {
            $old = CourseAcademicDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($old->status !== AcademicDocumentStatus::Current || $old->qr_token_revoked_at !== null) {
                throw new InvalidArgumentException('Only a current academic document may be regenerated.');
            }

            $old->forceFill([
                'status' => AcademicDocumentStatus::Replaced,
                'qr_token_revoked_at' => now(),
                'annulled_at' => now(),
                'annulled_by' => $actor->id,
                'annul_reason' => trim($reason),
                'replaced_by_id' => $replacement->id,
            ])->save();
        });
    }

    private function generateDocument(CourseEnrollment $enrollment, User $actor, ?callable $afterAcademicCreated = null): CourseAcademicDocument
    {
        Gate::forUser($actor)->authorize('generate', CourseAcademicDocument::class);

        $enrollment->loadMissing('edition.activity', 'edition.sessions', 'participant', 'group');
        $eligibility = ($this->eligibility ?? new CourseEligibilityService())->evaluate($enrollment);
        if (! $eligibility->eligible || ! $eligibility->documentType instanceof AcademicDocumentType) {
            throw new InvalidArgumentException('La matrícula todavía no es elegible para generar documento académico.');
        }

        $filename = ($this->filenames ?? new CourseCertificateFilenameService())->build(
            $this->participantName($enrollment),
            (string) $enrollment->edition->activity->name,
            $enrollment->edition->starts_on,
            $enrollment->edition->ends_on,
            $this->companyName($enrollment),
        );
        $transactionBaseline = app()->runningUnitTests() ? 1 : 0;
        $deferStorageUntilOuterCommit = DB::transactionLevel() > $transactionBaseline;

        return DB::transaction(function () use ($enrollment, $actor, $eligibility, $filename, $afterAcademicCreated, $deferStorageUntilOuterCommit): CourseAcademicDocument {
            $academic = CourseAcademicDocument::query()->create([
                'course_enrollment_id' => $enrollment->id,
                'type' => $eligibility->documentType,
                'status' => AcademicDocumentStatus::PendingGeneration,
                'code' => $this->nextCode($eligibility->documentType),
                'issue_date' => now()->toDateString(),
                'filename' => $filename,
                'delivery_status' => DeliveryStatus::Pending,
            ]);
            $tokenService = $this->qrTokens ?? app(CertificateQrTokenService::class);
            $token = $tokenService->createFor($academic);
            $qrSvg = $tokenService->renderSvg(route('certificates.qr.show', ['token' => $token]));
            $path = "course-academic-documents/{$enrollment->id}/{$academic->code}.pdf";
            $pdf = $this->renderPdf($enrollment, $academic, $qrSvg);
            $attributes = [
                'docable_type' => $academic->getMorphClass(),
                'docable_id' => $academic->id,
                'name' => $filename,
                'disk' => 'docs',
                'path' => $path,
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => strlen($pdf),
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ];

            if ($deferStorageUntilOuterCommit) {
                DB::afterCommit(function () use ($academic, $attributes, $pdf, $path, $afterAcademicCreated): void {
                    $this->storeDeferredDocument($academic->id, $attributes, $pdf, $path, $afterAcademicCreated);
                });

                return $academic;
            }

            return $this->storeAndRegisterDocument($academic->id, $attributes, $pdf, $path, $afterAcademicCreated);
        });
    }

    private function storeDeferredDocument(int $academicId, array $attributes, string $pdf, string $path, ?callable $afterAcademicCreated): void
    {
        try {
            $this->storeAndRegisterDocument($academicId, $attributes, $pdf, $path, $afterAcademicCreated);
        } catch (\Throwable $exception) {
            Storage::disk('docs')->delete($path);
            DB::transaction(function () use ($academicId): void {
                CourseAcademicDocument::query()->lockForUpdate()->whereKey($academicId)
                    ->where('status', AcademicDocumentStatus::PendingGeneration)
                    ->update(['status' => AcademicDocumentStatus::Failed]);
            });
            logger()->error('Deferred academic document storage failed.', [
                'course_academic_document_id' => $academicId,
                'exception' => $exception,
            ]);
        }
    }

    private function storeAndRegisterDocument(int $academicId, array $attributes, string $pdf, string $path, ?callable $afterAcademicCreated): CourseAcademicDocument
    {
        if (! Storage::disk('docs')->put($path, $pdf, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to store the academic document privately.');
        }

        try {
            return DB::transaction(function () use ($academicId, $attributes, $afterAcademicCreated): CourseAcademicDocument {
                $academic = CourseAcademicDocument::query()->lockForUpdate()->findOrFail($academicId);
                if ($academic->status !== AcademicDocumentStatus::PendingGeneration) {
                    throw new RuntimeException('Academic document is not pending generation.');
                }
                if ($afterAcademicCreated !== null) {
                    $afterAcademicCreated($academic);
                }
                $document = is_callable($this->documentCreator)
                    ? call_user_func($this->documentCreator, $attributes)
                    : Document::query()->create($attributes);
                if (! $document instanceof Document) {
                    throw new RuntimeException('Academic document persistence did not return a Document.');
                }

                $academic->forceFill(['document_id' => $document->id, 'status' => AcademicDocumentStatus::Current])->save();

                return $academic->refresh()->load('document');
            });
        } catch (\Throwable $exception) {
            Storage::disk('docs')->delete($path);

            throw $exception;
        }
    }

    private function renderPdf(CourseEnrollment $enrollment, CourseAcademicDocument $academic, string $qrSvg): string
    {
        $view = 'course-talks.certificates.reference';
        $viewModel = ($this->templates ?? new CourseCertificateTemplateService())->makeViewModel([
            'title' => $this->titleFor($academic->type),
            'participant' => $this->participantName($enrollment),
            'activity' => (string) $enrollment->edition->activity->name,
            'modality' => $enrollment->edition->modality->name,
            'date_range' => $this->certificateDateRange($enrollment),
            'academic_hours' => (string) $enrollment->edition->activity->official_academic_hours,
            'issue_location_date' => 'Lima, '.now()->isoFormat('D [de] MMMM [de] YYYY'),
            'certificate_code' => $academic->code,
            'qr_svg' => $qrSvg,
            'syllabus' => $enrollment->edition->sessions->map(fn ($session): array => [
                'class' => 'Clase '.$session->sort_order,
                'topic' => (string) $session->topic,
                'speaker' => (string) ($session->teacher_name ?: 'Maia Consultores'),
                'date' => optional($session->session_date)->format('d.m.y') ?? '',
            ])->all(),
        ], ['company' => $this->companyName($enrollment)]);

        return ($this->pdfRenderer ?? new DomPdfRenderer())->render($view, ['certificate' => $viewModel]);
    }

    private function nextCode(AcademicDocumentType $type): string
    {
        $prefix = match ($type) {
            AcademicDocumentType::ApprovalCertificate => 'CERT-APR',
            AcademicDocumentType::ParticipationConstancy => 'CONST-PAR',
            AcademicDocumentType::TalkCertificate => 'CERT-CHARLA',
        };

        return $prefix.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
    }

    private function titleFor(AcademicDocumentType $type): string
    {
        return match ($type) {
            AcademicDocumentType::ApprovalCertificate => 'Certificado de aprobación',
            AcademicDocumentType::ParticipationConstancy => 'Constancia de participación',
            AcademicDocumentType::TalkCertificate => 'Certificado de charla',
        };
    }

    private function participantName(CourseEnrollment $enrollment): string
    {
        return trim($enrollment->participant->first_name.' '.$enrollment->participant->last_name);
    }

    private function companyName(CourseEnrollment $enrollment): string
    {
        return (string) ($enrollment->group?->payer_name ?: 'Maia Consultores');
    }

    private function certificateDateRange(CourseEnrollment $enrollment): string
    {
        $filename = ($this->filenames ?? new CourseCertificateFilenameService())->build('', '', $enrollment->edition->starts_on, $enrollment->edition->ends_on, '');

        return explode('_', $filename)[3];
    }
}
