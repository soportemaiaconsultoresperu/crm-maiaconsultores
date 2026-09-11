<?php

declare(strict_types=1);

namespace App\Jobs\Courses;

use App\Models\Courses\CourseAcademicDocument;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Email\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendCourseDocumentEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $academicDocumentId,
        public readonly string $recipient,
        public readonly int $actorId,
        public readonly string $operationKey,
    ) {
        $this->afterCommit();
    }

    public function handle(EmailService $email): void
    {
        $academic = CourseAcademicDocument::query()->find($this->academicDocumentId);
        $actor = User::query()->find($this->actorId);

        if ($academic === null || $actor === null) {
            return;
        }

        (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->queueAcademicEmail($academic, $this->recipient, $actor, $this->operationKey, $email);
    }
}
