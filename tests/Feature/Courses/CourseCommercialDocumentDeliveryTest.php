<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseDocumentDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseCommercialDocumentDeliveryTest extends TestCase
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

    public function test_it_sends_an_enrollment_commercial_document_and_appends_a_recipient_snapshot_only_to_the_ledger(): void
    {
        $commercial = $this->commercialDocument();
        $sentAt = now()->startOfSecond();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => $sentAt))
            ->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'commercial-email-001');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('billing@example.test', $delivery->recipient_ref);
        $this->assertSame(CourseCommercialDocument::class, $delivery->related_entity_type);
        $this->assertSame($commercial->id, $delivery->related_entity_id);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
        $this->assertTrue($commercial->fresh()->last_sent_at->equalTo($sentAt));
        $this->assertDatabaseMissing('activity_log', ['properties' => 'billing@example.test']);
    }

    public function test_it_keeps_failed_or_unconfirmed_enrollment_commercial_email_pending_for_follow_up(): void
    {
        $commercial = $this->commercialDocument();

        $delivery = (new CourseDocumentDeliveryService(static function (): bool {
            throw new RuntimeException('provider secret');
        }))->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'commercial-email-002');

        $this->assertSame(OutboundDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('No fue posible enviar el correo.', $delivery->last_error);
        $this->assertSame(DeliveryStatus::Failed, $commercial->fresh()->delivery_status);
        $this->assertNull($commercial->fresh()->last_sent_at);
    }

    public function test_it_keeps_assisted_whatsapp_pending_until_manual_confirmation_for_an_enrollment_commercial_document(): void
    {
        Storage::fake('docs');
        $commercial = $this->commercialDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => now()->startOfSecond());

        $handoff = $service->openCommercialWhatsAppHandoff($commercial, '+51 999 123 456', $this->actor, 'commercial-whatsapp-001');

        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff['delivery']->status);
        $this->assertSame(DeliveryStatus::Pending, $commercial->fresh()->delivery_status);
        $this->assertNull($commercial->fresh()->last_sent_at);
        $this->assertStringContainsString('/commercial-documents/'.$commercial->id.'/download', $handoff['text']);
        $this->assertStringContainsString('signature=', $handoff['text']);
        $this->assertStringNotContainsString('Payer', $handoff['text']);
        $this->assertStringStartsWith('https://wa.me/51999123456?text=', $handoff['url']);

        $confirmation = $service->confirmCommercialWhatsAppSent($commercial, $handoff['delivery'], '+51 999 123 456', $this->actor, 'commercial-whatsapp-002');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $confirmation->status);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
        $this->assertNotNull($commercial->fresh()->last_sent_at);
    }

    public function test_signed_commercial_document_link_requires_a_valid_signature_and_streams_only_registered_private_files(): void
    {
        Storage::fake('docs');
        $commercial = $this->commercialDocumentWithPdf('%PDF commercial document');
        $url = URL::temporarySignedRoute('commercial-documents.documents.show', now()->addMinutes(5), [
            'commercialDocument' => $commercial->id,
        ]);

        $this->get('/commercial-documents/'.$commercial->id.'/download')->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('%PDF commercial document', $response->streamedContent());
    }

    public function test_signed_commercial_document_link_denies_expired_missing_unavailable_or_non_current_documents_without_private_data(): void
    {
        Storage::fake('docs');
        $expired = $this->commercialDocumentWithPdf('%PDF expired commercial document');
        $missingFile = $this->commercialDocumentWithPdf('%PDF missing commercial document');
        $pending = $this->commercialDocumentWithPdf('%PDF pending commercial document');
        $discarded = $this->commercialDocumentWithPdf('%PDF discarded commercial document');
        $mismatched = $this->commercialDocumentWithPdf('%PDF mismatched commercial document');
        Storage::disk('docs')->delete($missingFile->document->path);
        $pending->forceFill(['status' => 'pending_file'])->save();
        $discarded->forceFill(['status' => 'discarded'])->save();
        $mismatched->document->forceFill(['docable_id' => $pending->id])->save();

        $this->get(URL::temporarySignedRoute('commercial-documents.documents.show', now()->subMinute(), [
            'commercialDocument' => $expired->id,
        ]))->assertForbidden();

        foreach ([$missingFile, $pending, $discarded, $mismatched] as $commercial) {
            $response = $this->get(URL::temporarySignedRoute('commercial-documents.documents.show', now()->addMinutes(5), [
                'commercialDocument' => $commercial->id,
            ]));

            $response->assertStatus(404)->assertSee('Documento no vigente o no disponible.');
            foreach (['Payer', '20123456789', 'FAC', 'B001'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
    }

    public function test_it_reuses_an_email_operation_only_for_the_same_commercial_entity_channel_and_normalized_recipient(): void
    {
        $commercial = $this->commercialDocument();
        $calls = 0;
        $service = new CourseDocumentDeliveryService(static function () use (&$calls): bool {
            $calls++;

            return true;
        });

        $first = $service->sendCommercialEmail($commercial, ' Billing@Example.Test ', $this->actor, 'commercial-email-003');
        $duplicate = $service->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'commercial-email-003');
        $resend = $service->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'commercial-email-004');

        $this->assertSame($first->id, $duplicate->id);
        $this->assertNotSame($first->id, $resend->id);
        $this->assertSame(2, $calls);
        $this->assertDatabaseCount('outbound_deliveries', 2);
    }

    public function test_it_rejects_commercial_operation_key_reuse_for_a_different_entity_or_recipient_without_leaking_delivery_data(): void
    {
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $service->sendCommercialEmail($this->commercialDocument(), 'billing@example.test', $this->actor, 'commercial-email-005');

        foreach ([[$this->commercialDocument(), 'billing@example.test'], [$this->commercialDocument(), 'other@example.test']] as [$commercial, $recipient]) {
            try {
                $service->sendCommercialEmail($commercial, $recipient, $this->actor, 'commercial-email-005');
                $this->fail('Expected idempotency mismatch rejection.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('Invalid delivery operation.', $exception->getMessage());
                $this->assertStringNotContainsString('billing@example.test', $exception->getMessage());
                $this->assertStringNotContainsString((string) $commercial->id, $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('outbound_deliveries', 1);
    }

    public function test_it_rejects_commercial_whatsapp_handoff_for_missing_private_file_without_creating_history(): void
    {
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->openCommercialWhatsAppHandoff($this->commercialDocument(), '+51 999 123 456', $this->actor, 'commercial-whatsapp-missing-file');
        } finally {
            $this->assertDatabaseMissing('outbound_deliveries', ['idempotency_key' => 'commercial-whatsapp-missing-file']);
        }
    }

    public function test_it_rejects_whatsapp_operation_key_reuse_without_returning_another_commercial_handoff_url(): void
    {
        Storage::fake('docs');
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $service->openCommercialWhatsAppHandoff($this->commercialDocumentWithPdf(), '+51 999 123 456', $this->actor, 'commercial-whatsapp-003');

        try {
            $service->openCommercialWhatsAppHandoff($this->commercialDocumentWithPdf(), '+51 988 000 000', $this->actor, 'commercial-whatsapp-003');
            $this->fail('Expected idempotency mismatch rejection.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Invalid delivery operation.', $exception->getMessage());
            $this->assertStringNotContainsString('51999123456', $exception->getMessage());
            $this->assertStringNotContainsString('wa.me', $exception->getMessage());
        }

        $this->assertDatabaseCount('outbound_deliveries', 1);
    }

    public function test_group_commercial_documents_share_the_same_email_delivery_contract(): void
    {
        $commercial = $this->groupCommercialDocument();
        $sentAt = now()->startOfSecond();

        $delivery = (new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => $sentAt))
            ->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'commercial-email-006');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(CourseCommercialDocument::class, $delivery->related_entity_type);
        $this->assertSame($commercial->id, $delivery->related_entity_id);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
        $this->assertTrue($commercial->fresh()->last_sent_at->equalTo($sentAt));
    }

    public function test_group_commercial_documents_share_the_same_assisted_whatsapp_confirmation_contract(): void
    {
        Storage::fake('docs');
        $commercial = $this->groupCommercialDocumentWithPdf();
        $service = new CourseDocumentDeliveryService(static fn (): bool => true, static fn () => now()->startOfSecond());

        $handoff = $service->openCommercialWhatsAppHandoff($commercial, '+51 999 123 456', $this->actor, 'commercial-whatsapp-004');

        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff['delivery']->status);
        $this->assertSame(DeliveryStatus::Pending, $commercial->fresh()->delivery_status);

        $confirmation = $service->confirmCommercialWhatsAppSent($commercial, $handoff['delivery'], '+51 999 123 456', $this->actor, 'commercial-whatsapp-005');

        $this->assertSame(OutboundDelivery::STATUS_SENT, $confirmation->status);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
        $this->assertNotNull($commercial->fresh()->last_sent_at);
    }

    public function test_it_rejects_unauthorized_commercial_delivery_without_creating_a_ledger_entry(): void
    {
        $service = new CourseDocumentDeliveryService(static fn (): bool => true);
        $unauthorized = User::factory()->create();

        try {
            $service->sendCommercialEmail($this->commercialDocument(), 'billing@example.test', $unauthorized, 'commercial-email-007');
            $this->fail('Expected authorization rejection.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }

        $this->assertDatabaseMissing('outbound_deliveries', ['idempotency_key' => 'commercial-email-007']);
    }

    private function groupCommercialDocument(): CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->create([
            'course_enrollment_group_id' => CourseEnrollmentGroup::factory()->create()->id,
            'type' => CommercialDocumentType::Boleta,
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Payer',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }

    private function commercialDocument(): CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => '000123',
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Payer',
            'payer_document_number' => '20123456789',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
    }

    private function commercialDocumentWithPdf(string $contents = '%PDF commercial document'): CourseCommercialDocument
    {
        return $this->attachPdf($this->commercialDocument(), $contents);
    }

    private function groupCommercialDocumentWithPdf(string $contents = '%PDF commercial document'): CourseCommercialDocument
    {
        return $this->attachPdf($this->groupCommercialDocument(), $contents);
    }

    private function attachPdf(CourseCommercialDocument $commercial, string $contents): CourseCommercialDocument
    {
        $series = $commercial->series ?? 'COM';
        $number = $commercial->number ?? (string) $commercial->id;
        $path = "course-commercial-documents/{$commercial->id}/{$series}-{$number}.pdf";
        Storage::disk('docs')->put($path, $contents, ['visibility' => 'private']);
        $document = Document::query()->create([
            'docable_type' => CourseCommercialDocument::class,
            'docable_id' => $commercial->id,
            'name' => $series.'-'.$number.'.pdf',
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'uploaded_by' => User::factory()->create()->id,
            'uploaded_at' => now(),
        ]);
        $commercial->forceFill(['document_id' => $document->id])->save();

        return $commercial->fresh('document');
    }
}
