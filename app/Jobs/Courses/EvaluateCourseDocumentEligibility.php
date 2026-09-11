<?php

namespace App\Jobs\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Models\Courses\CourseEnrollment;
use App\Services\Courses\CourseEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () use ($eligibility): void {
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

            if ($enrollment->academicDocuments()
                ->where('type', $result->documentType)
                ->where('status', AcademicDocumentStatus::Current)
                ->exists()) {
                return;
            }

            // Future slice: dispatch document generation here. This slice only
            // evaluates eligibility and intentionally leaves records unchanged.
        });
    }
}
