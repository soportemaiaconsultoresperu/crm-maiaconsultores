<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseDocumentWhatsAppDeliveryTest extends TestCase
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

    public function test_it_creates_an_idempotent_whatsapp_handoff_with_a_secure_document_link_and_pending_snapshot(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();

        $handoff = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->openAcademicWhatsAppHandoff($academic, '+51 (999) 123-456', $this->actor, 'academic-whatsapp-001');

        $this->assertSame('51999123456', $handoff['phone']);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff['delivery']->status);
        $this->assertSame(OutboundDelivery::CHANNEL_WHATSAPP, $handoff['delivery']->channel);
        $this->assertSame('51999123456', $handoff['delivery']->recipient_ref);
        $this->assertSame($academic->id, $handoff['delivery']->related_entity_id);
        $this->assertSame('academic-whatsapp-001', $handoff['delivery']->idempotency_key);
        $this->assertStringStartsWith('https://wa.me/51999123456?text=', $handoff['url']);
        $this->assertStringContainsString('/certificate/documents/'.$academic->id, $handoff['text']);
        $this->assertStringContainsString('signature=', $handoff['text']);
        $this->assertStringNotContainsString((string) $academic->qr_token_hash, $handoff['text']);
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->last_sent_at);
        $this->assertDatabaseMissing('outbound_deliveries', ['idempotency_key' => 'academic-whatsapp-001', 'last_error' => $handoff['text']]);
        Queue::assertNothingPushed();
    }

    public function test_it_requires_existing_send_authorization(): void
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->openAcademicWhatsAppHandoff($this->academicDocument(), '+51 999 123 456', User::factory()->create(), 'academic-whatsapp-unauthorized');
    }

    public function test_it_returns_the_existing_handoff_for_an_exact_duplicate_key_and_appends_for_a_new_key(): void
    {
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);

        $first = $service->openAcademicWhatsAppHandoff($academic, '+51 999 123 456', $this->actor, 'academic-whatsapp-002');
        $duplicate = $service->openAcademicWhatsAppHandoff($academic, '+51 999 123 456', $this->actor, 'academic-whatsapp-002');
        $resend = $service->openAcademicWhatsAppHandoff($academic, '+51 999 123 456', $this->actor, 'academic-whatsapp-003');

        $this->assertSame($first['delivery']->id, $duplicate['delivery']->id);
        $this->assertNotSame($first['delivery']->id, $resend['delivery']->id);
        $this->assertDatabaseCount('outbound_deliveries', 2);
    }

    public function test_it_rejects_whatsapp_operation_key_reuse_for_a_different_academic_document_or_recipient(): void
    {
        Storage::fake('docs');
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $service->openAcademicWhatsAppHandoff($this->academicDocumentWithPdf(), '+51 999 123 456', $this->actor, 'academic-whatsapp-mismatch');

        foreach ([[$this->academicDocumentWithPdf(), '+51 999 123 456'], [$this->academicDocumentWithPdf(), '+51 988 000 000']] as [$academic, $recipient]) {
            try {
                $service->openAcademicWhatsAppHandoff($academic, $recipient, $this->actor, 'academic-whatsapp-mismatch');
                $this->fail('Expected idempotency mismatch rejection.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid delivery operation.', $exception->getMessage());
                $this->assertStringNotContainsString('51999123456', $exception->getMessage());
                $this->assertStringNotContainsString((string) $academic->id, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('outbound_deliveries', 1);
    }

    public function test_it_confirms_an_existing_handoff_with_actor_and_recipient_before_marking_sent(): void
    {
        Queue::fake();
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true, static fn (): \DateTimeInterface => now()->setTime(10, 30));
        $handoff = $service->openAcademicWhatsAppHandoff($academic, '+51 999 123 456', $this->actor, 'academic-whatsapp-004');

        $confirmation = $service->confirmAcademicWhatsAppSent($academic, $handoff['delivery'], '+51 999 123 456', $this->actor, 'academic-whatsapp-005');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $confirmation->status);
        $this->assertSame('51999123456', $confirmation->recipient_ref);
        $this->assertSame(2, OutboundDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertNotNull($academic->fresh()->last_sent_at);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_confirmation_when_the_recipient_does_not_match_the_existing_handoff(): void
    {
        Storage::fake('docs');
        $academic = $this->academicDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $handoff = $service->openAcademicWhatsAppHandoff($academic, '+51 999 123 456', $this->actor, 'academic-whatsapp-006');

        $this->expectException(InvalidArgumentException::class);

        $service->confirmAcademicWhatsAppSent($academic, $handoff['delivery'], '+51 988 123 456', $this->actor, 'academic-whatsapp-007');
    }

    public function test_it_rejects_an_invalid_recipient_phone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->openAcademicWhatsAppHandoff($this->academicDocument(), '555', $this->actor, 'academic-whatsapp-008');
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

    private function academicDocumentWithPdf(): CourseAcademicDocument
    {
        $academic = $this->academicDocument();
        $path = "course-academic-documents/{$academic->course_enrollment_id}/{$academic->code}.pdf";
        Storage::disk('docs')->put($path, '%PDF whatsapp certificate', ['visibility' => 'private']);
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
            'qr_token_hash' => hash_hmac('sha256', 'raw-token-never-sent', config('app.key')),
        ])->save();

        return $academic->fresh();
    }
}
