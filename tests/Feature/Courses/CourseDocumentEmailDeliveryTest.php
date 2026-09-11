<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Jobs\Courses\SendCourseDocumentEmail;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseDocumentEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('course-talks.documents.send');
        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo('course-talks.documents.send');
    }

    public function test_it_queues_the_exact_email_message_on_its_delivery_ledger(): void
    {
        Queue::fake();
        $academic = $this->academicDocument();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-correlation', app(EmailService::class));

        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertNotNull($delivery->email_message_id);
        $this->assertSame('recipient@example.test', $delivery->emailMessage->participants()->where('kind', 'to')->value('email'));
    }

    public function test_course_document_email_job_queues_email_through_the_existing_email_pipeline(): void
    {
        Queue::fake();
        $academic = $this->academicDocument();

        (new SendCourseDocumentEmail(
            $academic->id,
            'job-recipient@example.test',
            $this->actor->id,
            'academic-email-job-001',
        ))->handle(app(EmailService::class));

        $delivery = OutboundDelivery::query()
            ->where('idempotency_key', 'academic-email-job-001')
            ->firstOrFail();

        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('job-recipient@example.test', $delivery->recipient_ref);
        $this->assertSame(CourseAcademicDocument::class, $delivery->related_entity_type);
        $this->assertSame($academic->id, $delivery->related_entity_id);
        $this->assertNotNull($delivery->email_message_id);
        $this->assertSame('job-recipient@example.test', $delivery->emailMessage->participants()->where('kind', 'to')->value('email'));
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->last_sent_at);
    }

    public function test_it_does_not_publish_the_email_job_when_the_enclosing_transaction_rolls_back(): void
    {
        Queue::fake();
        $academic = $this->academicDocument();

        DB::beginTransaction();
        try {
            (new CourseDocumentDeliveryService(static fn (): bool => true))
                ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-outer-rollback', app(EmailService::class));

            Queue::assertNothingPushed();
            DB::rollBack();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('outbound_deliveries', ['idempotency_key' => 'academic-email-outer-rollback']);
    }

    public function test_it_publishes_the_email_job_only_after_the_enclosing_transaction_commits(): void
    {
        Queue::fake();
        $academic = $this->academicDocument();

        DB::beginTransaction();
        try {
            $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
                ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-outer-commit', app(EmailService::class));

            Queue::assertNothingPushed();
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        Queue::assertPushed(\App\Jobs\V2\SendEmailMessage::class, fn ($job) => $job->messageId === $delivery->email_message_id);
    }

    public function test_it_records_a_successful_email_attempt_with_recipient_override_snapshot_and_actor_activity(): void
    {
        $academic = $this->academicDocument();
        $sentAt = now()->startOfSecond();

        $delivery = (new CourseDocumentDeliveryService(
            static fn (): bool => true,
            static fn () => $sentAt,
        ))->sendAcademicEmail($academic, 'override@example.test', $this->actor, 'academic-email-001');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('override@example.test', $delivery->recipient_ref);
        $this->assertSame(CourseAcademicDocument::class, $delivery->related_entity_type);
        $this->assertSame($academic->id, $delivery->related_entity_id);
        $this->assertSame('academic-email-001', $delivery->idempotency_key);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertTrue($academic->fresh()->last_sent_at->equalTo($sentAt));
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CourseAcademicDocument::class,
            'subject_id' => $academic->id,
            'causer_id' => $this->actor->id,
            'event' => 'course-document-email-sent',
        ]);
        $properties = (string) DB::table('activity_log')
            ->where('event', 'course-document-email-sent')
            ->value('properties');
        $this->assertStringContainsString((string) $delivery->id, $properties);
        $this->assertStringNotContainsString('override@example.test', $properties);
    }

    public function test_it_sanitizes_email_failures_without_marking_the_document_sent(): void
    {
        $academic = $this->academicDocument();

        $delivery = (new CourseDocumentDeliveryService(
            static function (): bool {
                throw new RuntimeException('SMTP password=super-secret-token');
            },
        ))->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-002');

        $this->assertSame(OutboundDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('No fue posible enviar el correo.', $delivery->last_error);
        $this->assertStringNotContainsString('super-secret-token', (string) $delivery->last_error);
        $this->assertSame(DeliveryStatus::Failed, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->last_sent_at);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CourseAcademicDocument::class,
            'subject_id' => $academic->id,
            'causer_id' => $this->actor->id,
            'event' => 'course-document-email-failed',
        ]);
        $properties = (string) DB::table('activity_log')
            ->where('event', 'course-document-email-failed')
            ->value('properties');
        $this->assertStringContainsString((string) $delivery->id, $properties);
        $this->assertStringNotContainsString('recipient@example.test', $properties);
    }

    public function test_it_marks_an_unconfirmed_mail_operation_as_failed_without_a_send_timestamp(): void
    {
        $academic = $this->academicDocument();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => false))
            ->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-003');

        $this->assertSame(OutboundDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(DeliveryStatus::Failed, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->last_sent_at);
    }

    public function test_it_rolls_back_the_delivery_when_email_message_creation_fails(): void
    {
        $academic = $this->academicDocument();
        $email = \Mockery::mock(EmailService::class);
        $email->shouldReceive('send')->once()->andThrow(new RuntimeException('provider password=secret'));

        try {
            (new CourseDocumentDeliveryService(static fn (): bool => true))
                ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'atomic-email-creation', $email);
            $this->fail('Expected email message creation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider password=secret', $exception->getMessage());
        }

        $this->assertDatabaseMissing('outbound_deliveries', ['idempotency_key' => 'atomic-email-creation']);
    }

    public function test_it_short_circuits_an_exact_duplicate_but_appends_a_resend_with_a_new_key(): void
    {
        $academic = $this->academicDocument();
        $calls = 0;
        $service = new CourseDocumentDeliveryService(static function () use (&$calls): bool {
            $calls++;

            return true;
        });

        $first = $service->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-003');
        $duplicate = $service->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-003');
        $resend = $service->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-004');

        $this->assertSame($first->id, $duplicate->id);
        $this->assertNotSame($first->id, $resend->id);
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('outbound_deliveries', 2);
    }

    public function test_failed_email_history_remains_intact_when_a_later_resend_succeeds(): void
    {
        $academic = $this->academicDocument();
        $sentAt = now()->startOfSecond();
        $calls = 0;
        $service = new CourseDocumentDeliveryService(static function () use (&$calls): bool {
            $calls++;

            return $calls > 1;
        }, static fn () => $sentAt);

        $failed = $service->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-failed-before-resend');
        $sent = $service->sendAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-sent-after-failure');

        $this->assertSame(OutboundDelivery::STATUS_FAILED, $failed->fresh()->status);
        $this->assertSame('No fue posible enviar el correo.', $failed->fresh()->last_error);
        $this->assertSame(OutboundDelivery::STATUS_SENT, $sent->fresh()->status);
        $this->assertNull($sent->fresh()->last_error);
        $this->assertNotSame($failed->id, $sent->id);
        $this->assertDatabaseCount('outbound_deliveries', 2);
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertTrue($academic->fresh()->last_sent_at->equalTo($sentAt));
    }

    public function test_academic_email_operation_key_reuse_is_limited_to_the_same_document_and_recipient(): void
    {
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $service->sendAcademicEmail($this->academicDocument(), 'recipient@example.test', $this->actor, 'academic-email-mismatch');

        foreach ([[$this->academicDocument(), 'recipient@example.test'], [$this->academicDocument(), 'other@example.test']] as [$academic, $recipient]) {
            try {
                $service->sendAcademicEmail($academic, $recipient, $this->actor, 'academic-email-mismatch');
                $this->fail('Expected idempotency mismatch rejection.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Invalid delivery operation.', $exception->getMessage());
                $this->assertStringNotContainsString('recipient@example.test', $exception->getMessage());
                $this->assertStringNotContainsString((string) $academic->id, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('outbound_deliveries', 1);
    }

    private function academicDocument(): CourseAcademicDocument
    {
        return CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-'.str()->upper(str()->random(8)),
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }
}
