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

    /**
     * The automatic entrance: the document the eligibility job generates because
     * the last missing condition of the delta spec completed, with no user behind
     * the act.
     *
     * It exists because the operator path cannot express this case. `generate()`
     * requires the human who asked for the document and authorizes that human;
     * here nobody asked, so `$systemAuthor` is the dedicated non-human account the
     * module attributes automatic generation to, and it passes the very same
     * `generate` gate an operator passes — deliberately, without a bypass: the
     * authorization control stays exactly where it was, and the account is granted
     * exactly that one ability.
     *
     * Every other rule is the operator path's, unchanged: the same
     * `generateDocument()` evaluates eligibility, selects the document type, builds
     * the filename and code, mints the QR token and stores the private PDF. The
     * only differences are the three this method owns:
     *
     * 1. WHO — the audit trail names the SYSTEM author, and the generation also
     *    writes an explicit `course-academic-document-auto-generated` entry saying
     *    that the document was produced automatically (with the trigger reason),
     *    so the trail is never silently anonymous.
     * 2. WHAT IS NOISE — `current_already_exists` and `not_eligible` are returned as
     *    "nothing to generate" instead of an exception. Both mean the condition
     *    stopped holding between the job's locked check and this call (a concurrent
     *    worker generated it first, or the enrollment stopped qualifying). A queue
     *    that failed on a benign race would be wrong; a queue that minted a second
     *    document would be worse. Neither happens: the duplicate refusal is not
     *    weakened, it is honoured — no second document is created either way.
     * 3. WHERE THE FILE IS DURABLE — the caller's transaction is this operation's
     *    boundary, so the PDF is stored inside it rather than deferred to a later
     *    commit (see the parameter's note in `generateDocument()`). The caller must
     *    therefore be the transaction that owns the write; the job is.
     */
    public function generateAutomatically(CourseEnrollment $enrollment, User $systemAuthor, string $triggerReason): ?CourseAcademicDocument
    {
        try {
            $document = CourseAuditActor::asActor($systemAuthor, fn (): CourseAcademicDocument => $this->generateDocument(
                $enrollment,
                $systemAuthor,
                deferStorageUntilOuterCommit: false,
            ));
        } catch (InvalidCourseDocumentState $exception) {
            if (in_array($exception->reason(), [
                InvalidCourseDocumentState::CURRENT_ALREADY_EXISTS,
                InvalidCourseDocumentState::NOT_ELIGIBLE,
            ], true)) {
                return null;
            }

            throw $exception;
        }

        // The explicit half of the WHO decision: the model's own `course-created`
        // entry names the SYSTEM account as causer, and this service-written entry
        // states that the generation was automatic and which condition completed.
        // It carries no private path, no signed link and no raw QR token.
        activity()
            ->performedOn($document)
            ->causedBy($systemAuthor)
            ->event('course-academic-document-auto-generated')
            ->withProperties([
                'course_enrollment_id' => $enrollment->id,
                'document_type' => $document->type->value,
                'document_code' => $document->code,
                'trigger_reason' => $triggerReason,
                'actor_type' => 'system',
                'system_action' => 'course-eligibility-job',
            ])
            ->log('Generación automática del documento académico al completarse la última condición');

        return $document;
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

    private function generateDocument(CourseEnrollment $enrollment, User $actor, ?callable $afterAcademicCreated = null, ?bool $deferStorageUntilOuterCommit = null): CourseAcademicDocument
    {
        Gate::forUser($actor)->authorize('generate', CourseAcademicDocument::class);

        $enrollment->loadMissing('edition.activity', 'edition.sessions.teacher', 'participant', 'group');
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
        // `null` keeps the historical rule: a caller that already opened a
        // transaction gets the private file written only after that transaction
        // commits, so a rollback downstream cannot leave a PDF behind. `false` is
        // the automatic path saying that the transaction it runs in IS the boundary
        // of the operation, so there is no later commit to wait for and the file is
        // stored with the rows it belongs to — that is what makes an asynchronously
        // generated document observable in the same request/test that triggered it.
        $deferStorageUntilOuterCommit ??= DB::transactionLevel() > $transactionBaseline;

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
            'syllabus' => $this->certificateSyllabus($enrollment),
        ], $settings);

        return ($this->pdfRenderer ?? new DomPdfRenderer())->render($view, ['certificate' => $viewModel]);
    }

    /**
     * WHICH syllabus the certificate prints.
     *
     * This rule lives HERE, in the method that builds the certificate's data,
     * because this is where the certificate's content is decided. The owner's
     * decision, in this order:
     *
     * 1. the DELIVERY's own syllabus (`course_editions.syllabus_override_json`).
     *    The create form pre-fills it from the activity's base syllabus and the
     *    operator may then edit it, so it is the most specific statement of what
     *    this delivery actually teaches.
     * 2. the SESSION topics, when the delivery carries none of its own. These
     *    are what THIS delivery actually taught, down to the date and the
     *    teacher of each class.
     * 3. the ACTIVITY's base syllabus (`course_activities.base_syllabus_json`)
     *    as the last resort, when neither the delivery nor its sessions carry
     *    anything — the case of a delivery created before the pre-fill existed.
     *
     * Sessions outrank the base syllabus on purpose. The base syllabus is a
     * reusable template; a delivery that already lists its own sessions is
     * holding a more specific record of what happened, and a template must not
     * overwrite it. Ordering the template first would have silently changed
     * what an already-issued certificate prints.
     *
     * Branch 2 is the behaviour the certificate had before this rule existed, and
     * it is preserved exactly: one row per session, `class` from `sort_order`, the
     * session's own date and its teacher (or the house speaker when it has none).
     *
     * A hand-typed syllabus has no class, no speaker and no date, so branches 1
     * and 3 synthesise the row shape the reference table expects — a sequential
     * `Clase N`, the house speaker and an em dash — instead of leaving a column
     * undefined (which the view would render as an empty cell) or reading a key
     * that is not there.
     *
     * @return list<array{class: string, topic: string, speaker: string, date: string}>
     */
    private function certificateSyllabus(CourseEnrollment $enrollment): array
    {
        $edition = $enrollment->edition;
        $delivery = $this->textTopicList($edition->syllabus_override_json ?? []);

        $sessions = $edition->sessions->map(fn ($session): array => [
            'class' => 'Clase '.$session->sort_order,
            'topic' => (string) $session->topic,
            'speaker' => (string) ($session->teacher?->display_name ?: (trim((string) $session->teacher_name) ?: 'Maia Consultores')),
            'date' => optional($session->session_date)->format('d.m.y') ?? '',
        ])->all();

        if ($delivery === [] && $sessions !== []) {
            return $sessions;
        }

        $topics = $delivery !== []
            ? $delivery
            : $this->textTopicList($edition->activity?->base_syllabus_json ?? []);

        $rows = [];
        foreach ($topics as $index => $topic) {
            $rows[] = [
                'class' => 'Clase '.($index + 1),
                'topic' => $topic,
                'speaker' => 'Maia Consultores',
                'date' => '—',
            ];
        }

        return $rows;
    }

    /**
     * A stored syllabus column as a clean list of non-empty topic strings.
     *
     * The columns are written through their form requests, which already drop
     * blank and whitespace-only rows, but a direct write, a restore or a legacy
     * row can still hold a null, a nested array or a blank string; those are
     * skipped instead of being rendered as an empty topic.
     *
     * @return list<string>
     */
    private function textTopicList(mixed $topics): array
    {
        if (! is_array($topics)) {
            return [];
        }

        $clean = [];
        foreach ($topics as $topic) {
            if (! is_scalar($topic)) {
                continue;
            }

            $text = trim((string) $topic);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean;
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
