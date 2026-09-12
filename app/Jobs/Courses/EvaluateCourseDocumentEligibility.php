<?php

namespace App\Jobs\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseAuditActor;
use App\Services\Courses\CourseDocumentGenerationService;
use App\Services\Courses\CourseEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * The delta spec's automation: "WHEN the final missing condition becomes complete
 * THEN the system MUST generate the corresponding PDF document automatically."
 *
 * The trigger services dispatch this job after commit for every condition that
 * can be the last one — payment, grade/result, talk participation and edition
 * validation — and the job both evaluates eligibility and, when the conditions
 * are met and no current document of that type exists yet, generates it through
 * `CourseDocumentGenerationService`, exactly as the operator's generate action
 * does. No eligibility rule, document type, filename, code, QR or storage rule
 * is reimplemented here; the job decides only WHEN generation is due.
 */
final class EvaluateCourseDocumentEligibility implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $enrollmentId,
        public readonly string $reason,
    ) {
        $this->afterCommit();
    }

    public function handle(CourseEligibilityService $eligibility): void
    {
        // The module's stop switch (config/courses.php). Generation happens
        // automatically, so it needs one switch that stops a worker from producing
        // documents at all: revoking `course-talks.*` permissions hides the module
        // and stops human actions, but it cannot recall a dispatch that already
        // happened and it does not stop the trigger services. With this flag off the
        // job returns before it evaluates anything, leaving the enrollment exactly
        // where it was: eligible, document-less, and generatable by the operator.
        if (! (bool) config('courses.automatic_document_generation_enabled')) {
            return;
        }

        // WHO: the job has no user, so the responsible party is the SYSTEM account
        // the module attributes automatic writes to — never a fabricated human and
        // never an anonymous trail. A missing account is a configuration error, not
        // a reason to generate anonymously: `documents.uploaded_by` is a NOT NULL
        // reference to `users`, so without an author the private PDF cannot be
        // registered at all. Fail closed, and say so where an operator will see it.
        $systemAuthor = CourseAuditActor::systemAuthor();

        if ($systemAuthor === null) {
            logger()->error('Automatic academic document generation is enabled but the SYSTEM author does not exist; nothing was generated.', [
                'course_enrollment_id' => $this->enrollmentId,
                'trigger_reason' => $this->reason,
                'system_author_email' => (string) config('courses.system_author_email'),
            ]);

            return;
        }

        $documents = app(CourseDocumentGenerationService::class);

        try {
            DB::transaction(function () use ($eligibility, $documents, $systemAuthor): void {
                $enrollment = CourseEnrollment::query()
                    ->whereKey($this->enrollmentId)
                    ->lockForUpdate()
                    ->first();

                if ($enrollment === null) {
                    return;
                }

                $result = $eligibility->evaluate($enrollment);

                if (! $result->eligible || $result->documentType === null) {
                    return;
                }

                // Idempotency, first line of defence: a current document of the type
                // the conditions call for means there is nothing to generate — a queue
                // retry, a duplicated dispatch or a second condition completing later
                // all land here and must not mint a second certificate. The check runs
                // under the enrollment lock, so two workers cannot both pass it, and
                // the generation service refuses a second current document anyway.
                if ($enrollment->academicDocuments()
                    ->where('type', $result->documentType)
                    ->where('status', AcademicDocumentStatus::Current)
                    ->exists()) {
                    return;
                }

                $documents->generateAutomatically($enrollment, $systemAuthor, $this->reason);
            });
        } catch (\Throwable $exception) {
            // FAILURE (a render or storage failure): the exception escapes so the
            // queue owns the outcome — the attempt is recorded as failed and the
            // configured retry policy applies — and it is logged here with the
            // context the queue record does not carry. Nothing half-generated
            // survives: the escaping exception rolls the transaction back, so no
            // document row and no private file are left behind, and the enrollment
            // stays eligible for a later trigger. `AcademicDocumentStatus::Failed` is
            // deliberately NOT used here: it is the instrument for a document row
            // that already exists and whose file could not be stored (which the
            // deferred storage path in the generation service marks itself), and a
            // failure before that row exists has no row to mark — inventing one would
            // mean duplicating generation's own rules.
            logger()->error('Automatic academic document generation failed.', [
                'course_enrollment_id' => $this->enrollmentId,
                'trigger_reason' => $this->reason,
                'system_author_id' => $systemAuthor->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
