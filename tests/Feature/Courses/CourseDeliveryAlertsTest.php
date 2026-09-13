<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseAlertService;
use App\Services\Courses\CourseDocumentDeliveryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Slice 7 unit 7.a — the delivery alert domain.
 *
 * The service owns every alert rule so no dashboard, controller or view has to
 * recompute one. These tests assert the state each rule is about (which
 * documents are outstanding, which are overdue, what a discarded follow-up
 * leaves behind) rather than the state the fixtures start in.
 */
class CourseDeliveryAlertsTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Pending and overdue counts
    // ---------------------------------------------------------------------

    public function test_a_fresh_follow_up_is_pending_but_not_overdue(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocument();
        $commercial = $this->commercialDocument();

        $alerts = new CourseAlertService();

        $this->assertSame(1, (int) config('courses.delivery_due_days'));
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(0, $alerts->overdueCount());
        $this->assertSame([$academic->id], $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertSame([$commercial->id], $this->ids($alerts->pendingCommercialDocuments()));
        $this->assertSame([], $this->ids($alerts->overdueAcademicDocuments()));
        $this->assertSame([], $this->ids($alerts->overdueCommercialDocuments()));
    }

    public function test_a_follow_up_turns_overdue_only_after_the_configured_calendar_days_elapse(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocument(['issue_date' => '2026-01-09']);

        $alerts = new CourseAlertService();

        // Boundary: a document issued exactly one calendar day ago is pending,
        // not overdue — the threshold is "beyond one calendar day", not "one
        // calendar day or more".
        $this->assertSame(1, $alerts->pendingCount());
        $this->assertSame(0, $alerts->overdueCount());

        // The comparison is on calendar days, so the last hour of the boundary
        // day does not push it over.
        Carbon::setTestNow('2026-01-10 23:59:59');
        $this->assertSame(0, $alerts->overdueCount());

        // The next calendar day the same document is overdue.
        Carbon::setTestNow('2026-01-11 00:00:00');
        $this->assertSame(1, $alerts->pendingCount());
        $this->assertSame(1, $alerts->overdueCount());
        $this->assertSame([$academic->id], $this->ids($alerts->overdueAcademicDocuments()));
    }

    public function test_the_configured_due_days_change_which_follow_ups_are_overdue(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $recent = $this->academicDocument(['issue_date' => '2026-01-08']);
        $older = $this->academicDocument(['issue_date' => '2026-01-03']);

        $alerts = new CourseAlertService();

        config(['courses.delivery_due_days' => 5]);
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(1, $alerts->overdueCount());
        $this->assertSame([$older->id], $this->ids($alerts->overdueAcademicDocuments()));

        config(['courses.delivery_due_days' => 1]);
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(2, $alerts->overdueCount());
        $this->assertSame([$recent->id, $older->id], $this->ids($alerts->overdueAcademicDocuments()));

        config(['courses.delivery_due_days' => 10]);
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(0, $alerts->overdueCount());
    }

    // ---------------------------------------------------------------------
    // What counts as outstanding
    // ---------------------------------------------------------------------

    public function test_a_sent_delivery_is_closed_by_the_send_and_leaves_the_follow_up_list(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocument(['issue_date' => '2026-01-05']);
        $commercial = $this->commercialDocument(['issue_date' => '2026-01-05']);
        $stillOutstanding = $this->academicDocument(['issue_date' => '2026-01-05']);

        $alerts = new CourseAlertService();
        $this->assertSame(3, $alerts->pendingCount());
        $this->assertSame(3, $alerts->overdueCount());

        $deliveries = new CourseDocumentDeliveryService(static fn (): bool => true);
        $deliveries->sendAcademicEmail($academic, 'academic@example.test', $this->actor, 'alert-send-001');
        $deliveries->sendCommercialEmail($commercial, 'billing@example.test', $this->actor, 'alert-send-002');

        // The state the rule is about: the delivery domain really reached sent.
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);

        $this->assertSame([$stillOutstanding->id], $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertSame([], $this->ids($alerts->pendingCommercialDocuments()));
        $this->assertSame(1, $alerts->pendingCount());
        $this->assertSame(1, $alerts->overdueCount());
    }

    public function test_a_failed_send_still_demands_the_operator_action(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $failed = $this->academicDocument(['issue_date' => '2026-01-05']);
        $sent = $this->commercialDocument(['issue_date' => '2026-01-05']);

        $deliveries = new CourseDocumentDeliveryService(static function (): bool {
            throw new \RuntimeException('provider down');
        });
        $delivery = $deliveries->sendAcademicEmail($failed, 'academic@example.test', $this->actor, 'alert-send-003');
        $sentDelivery = (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->sendCommercialEmail($sent, 'billing@example.test', $this->actor, 'alert-send-004');

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(DeliveryStatus::Failed, $failed->fresh()->delivery_status);
        $this->assertSame('sent', $sentDelivery->status);

        $alerts = new CourseAlertService();

        // A failed send never closed the alert: the operator still has to act.
        $this->assertSame([$failed->id], $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertSame([], $this->ids($alerts->pendingCommercialDocuments()));
        $this->assertSame(1, $alerts->pendingCount());
        $this->assertSame(1, $alerts->overdueCount());
    }

    public function test_a_document_that_can_no_longer_be_served_does_not_demand_a_delivery_follow_up(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $current = $this->academicDocument(['issue_date' => '2026-01-05']);
        $annulled = $this->academicDocument(['issue_date' => '2026-01-05', 'status' => AcademicDocumentStatus::Annulled, 'annulled_at' => now(), 'annul_reason' => 'Error de datos']);
        $replaced = $this->academicDocument(['issue_date' => '2026-01-05', 'status' => AcademicDocumentStatus::Replaced]);
        $neverGenerated = $this->academicDocument(['issue_date' => '2026-01-05', 'status' => AcademicDocumentStatus::PendingGeneration]);
        $generationFailed = $this->academicDocument(['issue_date' => '2026-01-05', 'status' => AcademicDocumentStatus::Failed]);
        $revokedQr = $this->academicDocument(['issue_date' => '2026-01-05', 'qr_token_revoked_at' => now()]);
        $registered = $this->commercialDocument(['issue_date' => '2026-01-05']);
        $withoutFile = $this->commercialDocument(['issue_date' => '2026-01-05', 'status' => 'pending_file']);
        $discardedDocument = $this->commercialDocument(['issue_date' => '2026-01-05', 'status' => 'discarded']);

        $alerts = new CourseAlertService();

        $this->assertSame([$current->id], $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertSame([$registered->id], $this->ids($alerts->pendingCommercialDocuments()));
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(2, $alerts->overdueCount());

        // Named one by one so a predicate that silently widens is caught here.
        $this->assertNotContains($annulled->id, $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertNotContains($replaced->id, $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertNotContains($neverGenerated->id, $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertNotContains($generationFailed->id, $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertNotContains($revokedQr->id, $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertNotContains($withoutFile->id, $this->ids($alerts->pendingCommercialDocuments()));
        $this->assertNotContains($discardedDocument->id, $this->ids($alerts->pendingCommercialDocuments()));
    }

    // ---------------------------------------------------------------------
    // Discarding the follow-up without annulling the document
    // ---------------------------------------------------------------------

    public function test_the_commercial_table_mirrors_the_academic_discard_reason_column(): void
    {
        $academic = collect(Schema::getColumns('course_academic_documents'))->keyBy('name');
        $commercial = collect(Schema::getColumns('course_commercial_documents'))->keyBy('name');

        $this->assertArrayHasKey('delivery_discard_reason', $academic->all());
        $this->assertArrayHasKey('delivery_discard_reason', $commercial->all());
        $this->assertTrue((bool) $commercial['delivery_discard_reason']['nullable']);
        $this->assertNull($commercial['delivery_discard_reason']['default']);
        $this->assertSame(
            $academic['delivery_discard_reason']['type_name'],
            $commercial['delivery_discard_reason']['type_name'],
        );
    }

    public function test_a_discarded_follow_up_keeps_a_reason_on_both_channels(): void
    {
        $academic = $this->academicDocumentWithPdf();
        $commercial = $this->commercialDocumentWithPdf();

        $commercial->fill(['delivery_discard_reason' => 'Motivo comercial persistido'])->save();

        $this->assertSame('Motivo comercial persistido', $commercial->fresh()->delivery_discard_reason);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
    }

    public function test_discarding_a_follow_up_requires_a_non_empty_reason(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocument(['issue_date' => '2026-01-05']);
        $commercial = $this->commercialDocument(['issue_date' => '2026-01-05']);
        $alerts = new CourseAlertService();

        $refusals = 0;
        foreach (['', '   ', "\t\n"] as $blankReason) {
            foreach ([$academic, $commercial] as $document) {
                try {
                    $alerts->discard($document, $blankReason, $this->actor);
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('Descartar la alerta requiere un motivo.', $exception->getMessage());
                    $refusals++;
                }
            }
        }

        $this->assertSame(6, $refusals);
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Pending, $commercial->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
        $this->assertNull($commercial->fresh()->delivery_discard_reason);
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(0, DB::table('activity_log')->where('event', 'course-delivery-alert-discarded')->count());
    }

    public function test_discarding_a_follow_up_requires_the_document_send_permission(): void
    {
        $academic = $this->academicDocument();
        $outsider = User::factory()->create();
        $refused = null;

        try {
            (new CourseAlertService())->discard($academic, 'Motivo válido para descartar', $outsider);
        } catch (AuthorizationException $exception) {
            $refused = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $refused);
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'course-delivery-alert-discarded')->count());

        // The same rule with the permission in hand does not refuse.
        $discarded = (new CourseAlertService())->discard($academic, 'Motivo válido para descartar', $this->actor);
        $this->assertSame(DeliveryStatus::Discarded, $discarded->fresh()->delivery_status);
    }

    public function test_discarding_closes_the_alert_and_records_the_reason_the_actor_and_the_audit_entry(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocumentWithPdf(['issue_date' => '2026-01-05']);
        $commercial = $this->commercialDocumentWithPdf(['issue_date' => '2026-01-05']);
        $alerts = new CourseAlertService();

        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(2, $alerts->overdueCount());

        $academicReason = 'El cliente pidió no enviarlo por correo';
        $commercialReason = 'Comprobante emitido por error, no se envía';

        $alerts->discard($academic, $academicReason, $this->actor);
        $alerts->discard($commercial, $commercialReason, $this->actor);

        $this->assertSame(DeliveryStatus::Discarded, $academic->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Discarded, $commercial->fresh()->delivery_status);
        $this->assertSame($academicReason, $academic->fresh()->delivery_discard_reason);
        $this->assertSame($commercialReason, $commercial->fresh()->delivery_discard_reason);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CourseAcademicDocument::class,
            'subject_id' => $academic->id,
            'causer_id' => $this->actor->id,
            'event' => 'course-delivery-alert-discarded',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => CourseCommercialDocument::class,
            'subject_id' => $commercial->id,
            'causer_id' => $this->actor->id,
            'event' => 'course-delivery-alert-discarded',
        ]);

        $properties = json_decode((string) DB::table('activity_log')
            ->where('event', 'course-delivery-alert-discarded')
            ->where('subject_type', CourseCommercialDocument::class)
            ->value('properties'), true);
        $this->assertSame($commercialReason, $properties['reason']);
        $this->assertSame('pending', $properties['previous_delivery_status']);

        // Closed by discard: it stops counting as outstanding on both counts.
        $this->assertSame(0, $alerts->pendingCount());
        $this->assertSame(0, $alerts->overdueCount());
        $this->assertSame([], $this->ids($alerts->pendingAcademicDocuments()));
        $this->assertSame([], $this->ids($alerts->pendingCommercialDocuments()));
    }

    public function test_discarding_the_follow_up_leaves_the_certificate_and_its_qr_token_untouched(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocumentWithPdf(['issue_date' => '2026-01-05']);
        $tokenHash = $academic->qr_token_hash;
        $filePath = $academic->document->path;
        $documentId = $academic->document_id;
        $alerts = new CourseAlertService();

        $this->assertSame(1, $alerts->pendingCount());

        $alerts->discard($academic, 'No se enviará: el participante retiró su consentimiento', $this->actor);

        // The discard really happened before anything is concluded about the
        // certificate, so this test cannot pass on a no-op discard.
        $fresh = $academic->fresh();
        $this->assertSame(DeliveryStatus::Discarded, $fresh->delivery_status);
        $this->assertSame('No se enviará: el participante retiró su consentimiento', $fresh->delivery_discard_reason);
        $this->assertSame(0, $alerts->pendingCount());

        // The certificate is still valid and its QR path was not revoked.
        $this->assertSame(AcademicDocumentStatus::Current, $fresh->status);
        $this->assertNull($fresh->qr_token_revoked_at);
        $this->assertSame($tokenHash, $fresh->qr_token_hash);
        $this->assertNull($fresh->annulled_at);
        $this->assertNull($fresh->annul_reason);
        $this->assertNull($fresh->replaced_by_id);
        $this->assertSame($documentId, $fresh->document_id);
        $this->assertTrue(Storage::disk('docs')->exists($filePath));

        // The delivery domain itself still considers it deliverable.
        $this->assertTrue(
            (new CourseDocumentDeliveryService(static fn (): bool => true))->hasDeliverableAcademicDocument($fresh),
        );
    }

    public function test_discarding_the_follow_up_leaves_the_comprobante_usable(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $commercial = $this->commercialDocumentWithPdf(['issue_date' => '2026-01-05']);
        $filePath = $commercial->document->path;
        $documentId = $commercial->document_id;
        $alerts = new CourseAlertService();

        $this->assertSame(1, $alerts->pendingCount());

        $alerts->discard($commercial, 'El comprobante se entregó en oficina', $this->actor);

        $fresh = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Discarded, $fresh->delivery_status);
        $this->assertSame('El comprobante se entregó en oficina', $fresh->delivery_discard_reason);
        $this->assertSame(0, $alerts->pendingCount());

        $this->assertSame('registered', $fresh->status);
        $this->assertSame($documentId, $fresh->document_id);
        $this->assertSame('118.00', $fresh->total_amount);
        $this->assertTrue(Storage::disk('docs')->exists($filePath));
        $this->assertTrue(
            (new CourseDocumentDeliveryService(static fn (): bool => true))->hasStreamableCommercialDocument($fresh),
        );
    }

    public function test_a_follow_up_already_closed_by_a_send_cannot_be_discarded(): void
    {
        $academic = $this->academicDocument();
        (new CourseDocumentDeliveryService(static fn (): bool => true))
            ->sendAcademicEmail($academic, 'academic@example.test', $this->actor, 'alert-send-005');
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);

        $refused = null;
        try {
            (new CourseAlertService())->discard($academic, 'Motivo posterior al envío', $this->actor);
        } catch (InvalidArgumentException $exception) {
            $refused = $exception;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $refused);
        $this->assertSame('Una entrega enviada no se puede descartar.', $refused?->getMessage());
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
    }

    public function test_the_alert_queries_write_nothing_so_a_dashboard_can_read_them_on_every_load(): void
    {
        Carbon::setTestNow('2026-01-10 09:00:00');
        $academic = $this->academicDocumentWithPdf(['issue_date' => '2026-01-05']);
        $commercial = $this->commercialDocumentWithPdf(['issue_date' => '2026-01-05']);
        $before = [
            $academic->fresh()->updated_at->toDateTimeString(),
            $commercial->fresh()->updated_at->toDateTimeString(),
            DB::table('activity_log')->count(),
            DB::table('outbound_deliveries')->count(),
        ];

        $alerts = new CourseAlertService();
        for ($load = 0; $load < 3; $load++) {
            $this->assertSame(2, $alerts->pendingCount());
            $this->assertSame(2, $alerts->overdueCount());
            $this->assertSame([$academic->id], $this->ids($alerts->pendingAcademicDocuments()));
            $this->assertSame([$commercial->id], $this->ids($alerts->pendingCommercialDocuments()));
            $this->assertSame([$academic->id], $this->ids($alerts->overdueAcademicDocuments()));
            $this->assertSame([$commercial->id], $this->ids($alerts->overdueCommercialDocuments()));
        }

        $this->assertSame($before, [
            $academic->fresh()->updated_at->toDateTimeString(),
            $commercial->fresh()->updated_at->toDateTimeString(),
            DB::table('activity_log')->count(),
            DB::table('outbound_deliveries')->count(),
        ]);
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Pending, $commercial->fresh()->delivery_status);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /** @return list<int> */
    private function ids(Builder $query): array
    {
        return $query->orderBy('id')->pluck('id')->map(intval(...))->all();
    }

    private function academicDocument(array $attributes = []): CourseAcademicDocument
    {
        return CourseAcademicDocument::query()->create(array_merge([
            'course_enrollment_id' => $this->enrollmentId(),
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-'.str()->upper(str()->random(8)),
            'issue_date' => now()->toDateString(),
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes));
    }

    private function commercialDocument(array $attributes = []): CourseCommercialDocument
    {
        return CourseCommercialDocument::query()->create(array_merge([
            'course_enrollment_id' => $this->enrollmentId(),
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => (string) random_int(100000, 999999),
            'issue_date' => now()->toDateString(),
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Payer',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes));
    }

    private function academicDocumentWithPdf(array $attributes = []): CourseAcademicDocument
    {
        $academic = $this->academicDocument($attributes);
        $path = "course-academic-documents/{$academic->course_enrollment_id}/{$academic->code}.pdf";
        $document = $this->privateFile($academic, $path, '%PDF academic certificate');
        $academic->forceFill([
            'document_id' => $document->id,
            'qr_token_hash' => hash_hmac('sha256', 'alert-raw-token-'.$academic->id, (string) config('app.key')),
        ])->save();

        return $academic->fresh();
    }

    private function commercialDocumentWithPdf(array $attributes = []): CourseCommercialDocument
    {
        $commercial = $this->commercialDocument($attributes);
        $path = "course-commercial-documents/{$commercial->id}/{$commercial->series}-{$commercial->number}.pdf";
        $document = $this->privateFile($commercial, $path, '%PDF commercial document');
        $commercial->forceFill(['document_id' => $document->id])->save();

        return $commercial->fresh();
    }

    private function enrollmentId(): int
    {
        return CourseEnrollment::factory()->create()->id;
    }

    private function privateFile(Model $document, string $path, string $contents): Document
    {
        Storage::disk('docs')->put($path, $contents, ['visibility' => 'private']);

        return Document::query()->create([
            'docable_type' => $document::class,
            'docable_id' => $document->id,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'uploaded_by' => $this->actor->id,
            'uploaded_at' => now(),
        ]);
    }
}
