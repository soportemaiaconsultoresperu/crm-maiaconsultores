<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Jobs\Courses\SendCourseDocumentEmail;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-correlation', app(EmailService::class));

        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertNotNull($delivery->email_message_id);
        $this->assertSame('recipient@example.test', $delivery->emailMessage->participants()->where('kind', 'to')->value('email'));
    }

    public function test_course_document_email_job_queues_email_through_the_existing_email_pipeline(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();

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
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();

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
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();

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
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();
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

    /**
     * A document the queued email path may actually deliver: current, not
     * QR-revoked, with its private PDF present on the configured disk. The
     * deliverability precondition moved into the service in the corrective unit
     * `academic-email-document-and-precondition`, so every queued-email fixture
     * now has to carry a real private file.
     */
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
            'uploaded_by' => User::factory()->create()->id,
            'uploaded_at' => now(),
        ]);
        $academic->forceFill([
            'document_id' => $document->id,
            'qr_token_hash' => hash_hmac('sha256', 'email-raw-token-'.$academic->id, config('app.key')),
        ])->save();

        return $academic->fresh();
    }

    /**
     * D1 regression: the emailed message used to be the fixed one-line placeholder
     * `Documento académico disponible.` with an empty attachment list, so the
     * recipient was told a document existed but never shown how to reach it.
     */
    public function test_the_queued_email_carries_the_document_through_a_working_signed_link(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $this->travelTo(now()->startOfSecond());
        $academic = $this->academicDocumentWithPdf();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-content', app(EmailService::class));

        $message = $delivery->emailMessage;

        // The subject identifies the document instead of the generic placeholder.
        $this->assertNotSame('Documento académico disponible', $message->subject);
        $this->assertStringContainsString('Certificado de aprobación', $message->subject);
        $this->assertStringContainsString($academic->code, $message->subject);

        $body = $message->body_text[0];
        // A real person-readable message, not the single-line placeholder.
        $this->assertNotSame('Documento académico disponible.', $body);
        $this->assertStringContainsString('Hola,', $body);
        $this->assertStringContainsString('/certificate/documents/'.$academic->id, $body);
        $this->assertStringContainsString('signature=', $body);

        // The emailed link is usable hours or days later, not only inside the
        // WhatsApp 60-minute window.
        preg_match('#https?://[^\s]+#', $body, $matches);
        $this->assertNotEmpty($matches, 'The email body must carry a download URL.');
        $query = [];
        parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertSame(now()->addMinutes((int) config('courses.email_document_link_minutes'))->timestamp, (int) $query['expires']);
        $this->assertGreaterThan(now()->addHour()->timestamp, (int) $query['expires']);

        // No raw QR material, private storage path or unrelated PII in the payload.
        $this->assertStringNotContainsString((string) $academic->qr_token_hash, $body);
        $this->assertStringNotContainsString('course-academic-documents/', $body);
        $this->assertStringContainsString('/certificate/documents/'.$academic->id, $message->body_html[0]);
    }

    /**
     * Triangulation: the subject/body identify the specific document type through
     * a real mapping instead of a single hard-coded string.
     */
    public function test_the_email_names_the_specific_document_type(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();
        $academic->forceFill(['type' => AcademicDocumentType::TalkCertificate])->save();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, 'academic-email-talk-label', app(EmailService::class));

        $message = $delivery->emailMessage;
        $this->assertStringContainsString('Certificado de charla', $message->subject);
        $this->assertStringNotContainsString('Certificado de aprobación', $message->subject);
        $this->assertStringContainsString('Certificado de charla', $message->body_text[0]);
        $this->assertStringContainsString($academic->code, $message->body_text[0]);
    }

    /**
     * D2 regression: the queued path had no document precondition, so it queued a
     * message for an annulled document or one whose private file is gone. The
     * rule now lives in the service and must leave no ledger row and no queued
     * message behind.
     */
    public function test_a_non_deliverable_document_is_refused_before_any_ledger_row_or_queued_message(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);

        $annulled = $this->academicDocumentWithPdf();
        $annulled->forceFill([
            'status' => AcademicDocumentStatus::Annulled,
            'qr_token_revoked_at' => now(),
            'annul_reason' => 'Error en los datos del participante',
        ])->save();

        $withoutFile = $this->academicDocumentWithPdf();
        Storage::disk('docs')->delete($withoutFile->document->path);

        foreach ([[$annulled, 'refused-annulled-email'], [$withoutFile, 'refused-missing-file-email']] as [$academic, $key]) {
            try {
                $service->queueAcademicEmail($academic, 'recipient@example.test', $this->actor, $key, app(EmailService::class));
                $this->fail('A non-deliverable document must be refused by the service.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertDatabaseCount('email_messages', 0);
    }
}
