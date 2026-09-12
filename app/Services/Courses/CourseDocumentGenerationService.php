<?php

namespace App\Services\Courses;

use App\Contracts\Courses\PdfRenderer;
use App\Enums\Courses\{AcademicDocumentStatus, AcademicDocumentType, DeliveryStatus};
use App\Exceptions\Courses\InvalidCourseDocumentState;
use App\Models\Courses\{CourseAcademicDocument, CourseCertificateTemplate, CourseEnrollment};
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
        return CourseAuditActor::asActor($actor, fn (): CourseAcademicDocument => $this->generateDocument($enrollment, $actor));
    }

    public function regenerate(CourseAcademicDocument $document, User $actor, string $reason): CourseAcademicDocument
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A regeneration reason is required.');
        }

        Gate::forUser($actor)->authorize('revoke', $document);

        $document->loadMissing('enrollment');

        // The replacement is announced with its callback, but every write it does
        // (the old row marked replaced, the new row created, the private file
        // registered) is still attributed to the acting user.
        return CourseAuditActor::asActor($actor, fn (): CourseAcademicDocument => $this->generateDocument($document->enrollment, $actor, function (CourseAcademicDocument $replacement) use ($document, $actor, $reason): void {
            $old = CourseAcademicDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($old->status !== AcademicDocumentStatus::Current || $old->qr_token_revoked_at !== null) {
                throw InvalidCourseDocumentState::notCurrent();
            }

            $old->forceFill([
                'status' => AcademicDocumentStatus::Replaced,
                'qr_token_revoked_at' => now(),
                'annulled_at' => now(),
                'annulled_by' => $actor->id,
                'annul_reason' => trim($reason),
                'replaced_by_id' => $replacement->id,
            ])->save();
        }));
    }

    private function generateDocument(CourseEnrollment $enrollment, User $actor, ?callable $afterAcademicCreated = null): CourseAcademicDocument
    {
        Gate::forUser($actor)->authorize('generate', CourseAcademicDocument::class);

        $enrollment->loadMissing('edition.activity', 'edition.sessions', 'participant', 'group');
        $eligibility = ($this->eligibility ?? new CourseEligibilityService())->evaluate($enrollment);
        if (! $eligibility->eligible || ! $eligibility->documentType instanceof AcademicDocumentType) {
            throw InvalidCourseDocumentState::notEligible('La matrícula todavía no es elegible para generar documento académico.');
        }

        // The plain generate path must never mint a second current certificate
        // for the same enrollment: a double click or a repeated POST would
        // otherwise produce a second, equally valid code and public QR. The
        // sanctioned route to a new current document is regeneration, which
        // marks the previous row replaced; it announces that intent with the
        // replacement callback and is therefore exempt here. The refusal is
        // raised before any side effect and in Spanish, so the controller can
        // surface it verbatim, exactly like the eligibility rejection above.
        if ($afterAcademicCreated === null && $this->hasCurrentDocument($enrollment)) {
            throw InvalidCourseDocumentState::currentAlreadyExists('La matrícula ya cuenta con un documento académico vigente. Para emitir uno nuevo, regenere el documento vigente indicando el motivo.');
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

        // Resolved once, before the transaction, so the view and the settings
        // that produced this PDF come from the same row that the document then
        // records. No template configured means null, and null means exactly the
        // pre-template behaviour of renderPdf().
        $template = ($this->templates ?? new CourseCertificateTemplateService())->resolveFor($eligibility->documentType);

        return DB::transaction(function () use ($enrollment, $actor, $eligibility, $filename, $template, $afterAcademicCreated, $deferStorageUntilOuterCommit): CourseAcademicDocument {
            $academic = CourseAcademicDocument::query()->create([
                'course_enrollment_id' => $enrollment->id,
                'course_certificate_template_id' => $template?->id,
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
            $pdf = $this->renderPdf($enrollment, $academic, $qrSvg, $template);
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
                DB::afterCommit(function () use ($academic, $attributes, $pdf, $path, $afterAcademicCreated, $actor): void {
                    // The deferred write happens when the OUTER transaction commits,
                    // long after this method returned, so the actor is re-established
                    // here instead of relying on a scope that no longer exists.
                    CourseAuditActor::asActor($actor, fn () => $this->storeDeferredDocument($academic->id, $attributes, $pdf, $path, $afterAcademicCreated));
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

    private function renderPdf(CourseEnrollment $enrollment, CourseAcademicDocument $academic, string $qrSvg, ?CourseCertificateTemplate $template): string
    {
        // The template decides the view and the configurable settings; without
        // one the reference view and the service defaults are used, exactly as
        // before templates resolved at all. The view name lives in one place
        // (CourseCertificateTemplateService::REFERENCE_BLADE_VIEW) so the
        // allowlist and this fallback cannot drift apart.
        $view = $template?->blade_view ?? CourseCertificateTemplateService::REFERENCE_BLADE_VIEW;
        $settings = array_merge(
            ['company' => $this->companyName($enrollment)],
            $template?->settings_json ?? [],
        );
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
        ], $settings);

        return ($this->pdfRenderer ?? new DomPdfRenderer())->render($view, ['certificate' => $viewModel]);
    }

    private function hasCurrentDocument(CourseEnrollment $enrollment): bool
    {
        return CourseAcademicDocument::query()
            ->where('course_enrollment_id', $enrollment->id)
            ->where('status', AcademicDocumentStatus::Current)
            ->exists();
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
