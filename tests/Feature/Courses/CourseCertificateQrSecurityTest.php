<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CertificateQrTokenService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseCertificateQrSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_creation_persists_only_hmac_hash_and_public_qr_streams_current_private_pdf(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF current certificate');

        $token = app(CertificateQrTokenService::class)->createFor($academic);

        $this->assertNotSame('', $token);
        $this->assertDatabaseHas('course_academic_documents', [
            'id' => $academic->id,
            'qr_token_hash' => hash_hmac('sha256', $token, config('app.key')),
            'qr_token_revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('course_academic_documents', ['qr_token_hash' => $token]);

        $response = $this->get("/certificate/qr/{$token}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('%PDF current certificate', $response->streamedContent());
        $this->assertNotNull($academic->fresh()->qr_token_last_used_at);
    }

    public function test_signed_document_link_requires_a_valid_unexpired_signature_and_streams_only_current_private_pdf(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF signed certificate');
        $url = URL::temporarySignedRoute('certificates.documents.show', now()->addMinutes(5), [
            'academicDocument' => $academic->id,
        ]);

        $this->get("/certificate/documents/{$academic->id}")->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        $response = $this->get($url);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('%PDF signed certificate', $response->streamedContent());
    }

    public function test_signed_document_link_denies_expired_revoked_or_replaced_documents_without_personal_data(): void
    {
        Storage::fake('docs');
        $expired = $this->currentAcademicDocument('%PDF expired certificate');
        $revoked = $this->currentAcademicDocument('%PDF revoked certificate');
        $replaced = $this->currentAcademicDocument('%PDF replaced certificate');
        $expiredUrl = URL::temporarySignedRoute('certificates.documents.show', now()->subMinute(), ['academicDocument' => $expired->id]);
        $revokedUrl = URL::temporarySignedRoute('certificates.documents.show', now()->addMinutes(5), ['academicDocument' => $revoked->id]);
        $replacedUrl = URL::temporarySignedRoute('certificates.documents.show', now()->addMinutes(5), ['academicDocument' => $replaced->id]);
        $revoked->forceFill(['qr_token_revoked_at' => now()])->save();
        $replaced->forceFill(['status' => AcademicDocumentStatus::Replaced])->save();

        $this->get($expiredUrl)->assertForbidden();
        foreach ([$revokedUrl, $replacedUrl] as $url) {
            $response = $this->get($url);
            $response->assertStatus(404)->assertSee('Documento no vigente o no disponible.');
            foreach (['87654321', 'alvaro@example.test', '+51999999999', '15.50', 'Alvaro Segundo'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
    }

    public function test_qr_route_is_rate_limited_after_sixty_requests_without_leaking_private_data(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF current certificate');
        $token = app(CertificateQrTokenService::class)->createFor($academic);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->get("/certificate/qr/{$token}")->assertOk();
        }

        $response = $this->get("/certificate/qr/{$token}");

        $response->assertStatus(429);
        foreach (['87654321', 'alvaro@example.test', '+51999999999', '15.50', 'Alvaro Segundo'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_qr_revocation_requires_a_non_empty_reason(): void
    {
        $academic = $this->currentAcademicDocument('%PDF current certificate');

        $this->expectException(InvalidArgumentException::class);

        app(CertificateQrTokenService::class)->revoke($academic, User::factory()->create(), '');
    }

    public function test_qr_revocation_denies_an_unauthorized_actor(): void
    {
        $academic = $this->currentAcademicDocument('%PDF current certificate');

        $this->expectException(AuthorizationException::class);

        app(CertificateQrTokenService::class)->revoke($academic, User::factory()->create(), 'Correction required');
    }

    public function test_authorized_qr_revocation_persists_actor_and_reason(): void
    {
        $academic = $this->currentAcademicDocument('%PDF current certificate');
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.revoke'));

        app(CertificateQrTokenService::class)->revoke($academic, $actor, 'Correction required');

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $academic->status);
        $this->assertNotNull($academic->qr_token_revoked_at);
        $this->assertSame($actor->id, $academic->annulled_by);
        $this->assertSame('Correction required', $academic->annul_reason);
    }

    public function test_qr_token_hash_has_a_database_index_for_current_token_lookups(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('course_academic_documents')"));

        $this->assertTrue($indexes->contains(fn (object $index): bool => $index->name === 'course_academic_documents_qr_token_hash_index'));
    }

    public function test_missing_revoked_or_replaced_tokens_return_same_generic_response_without_personal_data(): void
    {
        Storage::fake('docs');
        $current = $this->currentAcademicDocument('%PDF current certificate');
        $revoked = $this->currentAcademicDocument('%PDF old certificate');
        $replaced = $this->currentAcademicDocument('%PDF replaced certificate');
        $service = app(CertificateQrTokenService::class);
        $currentToken = $service->createFor($current);
        $revokedToken = $service->createFor($revoked);
        $replacedToken = $service->createFor($replaced);

        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.revoke'));
        $service->revoke($revoked, $actor, 'Corrección solicitada');
        $replaced->forceFill(['status' => AcademicDocumentStatus::Replaced])->save();

        foreach (['missing-token', $revokedToken, $replacedToken] as $token) {
            $response = $this->get("/certificate/qr/{$token}");
            $response->assertStatus(404)->assertSee('Documento no vigente o no disponible.');
            $body = $response->getContent();

            foreach (['87654321', 'alvaro@example.test', '+51999999999', '15.50', 'Alvaro Segundo'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
        }

        $this->assertSame(AcademicDocumentStatus::Current, $current->fresh()->status);
        $this->assertNotNull($revoked->fresh()->qr_token_revoked_at);
        $this->assertSame(AcademicDocumentStatus::Replaced, $replaced->fresh()->status);
        $this->assertSame($current->id, $service->findCurrentByToken($currentToken)?->id);
    }

    /**
     * A document that has already been annulled keeps the record of that
     * annulment. A second annulment must not overwrite the reason, the actor or
     * the timestamp: all three are the audit trace of the first one, and losing
     * them loses the fact that two annulments ever happened.
     */
    public function test_a_second_annulment_is_rejected_and_the_first_annulment_survives(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF current certificate');
        $firstActor = $this->revoker();
        $secondActor = $this->revoker();
        $service = app(CertificateQrTokenService::class);

        $service->revoke($academic, $firstActor, 'Primera anulación registrada');
        $firstAnnulledAt = $academic->fresh()->annulled_at?->toDateTimeString();

        $this->travel(5)->minutes();

        try {
            $service->revoke($academic->fresh(), $secondActor, 'Segunda anulación');
            $this->fail('An annulled document must not be annulled a second time.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            $this->travelBack();
        }

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $academic->status);
        $this->assertSame('Primera anulación registrada', $academic->annul_reason);
        $this->assertSame($firstActor->id, $academic->annulled_by);
        $this->assertSame($firstAnnulledAt, $academic->annulled_at?->toDateTimeString());
    }

    /**
     * A replaced document carries the trace of the document that succeeded it.
     * Flipping it to annulled would contradict that trace: the row would claim
     * to be annulled while `replaced_by_id` still points at the successor.
     */
    public function test_annulling_a_replaced_document_is_rejected_and_preserves_the_replacement_trace(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF replaced certificate');
        $successor = $this->currentAcademicDocument('%PDF successor certificate');
        $preReplacement = now()->subDay();
        $this->markReplacedBy($academic, $successor, 'Regenerado por corrección de nota', $preReplacement);

        try {
            app(CertificateQrTokenService::class)->revoke(
                $academic->fresh(),
                $this->revoker(),
                'Anulación posterior al reemplazo',
            );
            $this->fail('A replaced document must not be annulled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $academic->status);
        $this->assertSame('Regenerado por corrección de nota', $academic->annul_reason);
        $this->assertSame($successor->id, $academic->replaced_by_id);
        $this->assertSame($successor->id, $academic->replacement?->id);
        $this->assertNull($academic->annulled_by);
        $this->assertSame($preReplacement->toDateTimeString(), $academic->annulled_at?->toDateTimeString());
        $this->assertSame(AcademicDocumentStatus::Current, $successor->fresh()->status);
    }

    /**
     * `Current` is the only status an annulment may start from. Every other
     * status is rejected before any write, so the row is left completely
     * untouched.
     */
    public function test_annulment_is_rejected_for_every_persisted_status_other_than_current(): void
    {
        Storage::fake('docs');

        foreach ([AcademicDocumentStatus::PendingGeneration, AcademicDocumentStatus::Failed, AcademicDocumentStatus::Annulled, AcademicDocumentStatus::Replaced] as $status) {
            $academic = $this->currentAcademicDocument('%PDF current certificate');
            $academic->forceFill(['status' => $status, 'annul_reason' => 'Motivo original'])->save();

            try {
                app(CertificateQrTokenService::class)->revoke($academic->fresh(), $this->revoker(), 'Anulación no permitida');
                $this->fail("A {$status->value} document must not be annulled.");
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }

            $academic = $academic->fresh();
            $this->assertSame($status, $academic->status);
            $this->assertSame('Motivo original', $academic->annul_reason);
            $this->assertNull($academic->annulled_by);
            $this->assertNull($academic->qr_token_revoked_at);
        }
    }

    /**
     * The caller may hold a snapshot read while the document was still vigente.
     * The persisted status is the only one that may decide the write, otherwise
     * a concurrent replacement or annulment is overwritten by a stale instance.
     */
    public function test_annulment_refuses_a_stale_instance_whose_persisted_status_is_no_longer_current(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF current certificate');
        $successor = $this->currentAcademicDocument('%PDF successor certificate');

        $stale = CourseAcademicDocument::query()->findOrFail($academic->id);
        $this->assertSame(AcademicDocumentStatus::Current, $stale->status);

        $this->markReplacedBy($academic, $successor, 'Reemplazo concurrente');

        try {
            app(CertificateQrTokenService::class)->revoke($stale, $this->revoker(), 'Anulación tardía');
            $this->fail('The persisted status, not the caller snapshot, decides whether a document may be annulled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $academic->status);
        $this->assertSame('Reemplazo concurrente', $academic->annul_reason);
        $this->assertSame($successor->id, $academic->replaced_by_id);
        $this->assertNull($academic->annulled_by);
    }

    /**
     * The HTTP boundary guard checks the status the request bound, so a document
     * that stopped being vigente between that check and the write reaches the
     * service guard instead. That rejection must still render a visible Spanish
     * error, never an HTTP 500 and never a write.
     */
    public function test_an_annulment_rejected_by_the_service_in_a_race_renders_a_visible_spanish_error(): void
    {
        Storage::fake('docs');
        $academic = $this->currentAcademicDocument('%PDF current certificate');
        $successor = $this->currentAcademicDocument('%PDF successor certificate');
        $actor = $this->revoker();
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.view'));

        // Race simulation: this request already bound the document while it was
        // still vigente, and a concurrent request replaced it afterwards. The
        // instance the surface validated is stale, which is exactly the case the
        // boundary guard cannot see.
        $stale = CourseAcademicDocument::query()->findOrFail($academic->id);
        $this->markReplacedBy($academic, $successor, 'Reemplazo concurrente');
        // The premise of the race: the instance this request holds still says
        // `Current` while the row it was read from no longer does.
        $this->assertSame(AcademicDocumentStatus::Current, $stale->status);
        $this->assertSame(AcademicDocumentStatus::Replaced, $academic->fresh()->status);
        Route::bind('academicDocument', fn () => $stale);

        $response = $this->actingAs($actor)->post(
            route('course-talks.documents.annul', $academic->id),
            ['reason' => 'Anulación tardía'],
        );

        $response->assertRedirect(route('course-talks.documents.index', $academic->enrollment->edition));
        $response->assertSessionHasErrors('documents');

        $this->actingAs($actor)
            ->followingRedirects()
            ->post(route('course-talks.documents.annul', $academic->id), ['reason' => 'Anulación tardía'])
            ->assertOk()
            ->assertSee('Solo un documento vigente puede anularse.');

        $academic = $academic->fresh();
        $this->assertSame(AcademicDocumentStatus::Replaced, $academic->status);
        $this->assertSame('Reemplazo concurrente', $academic->annul_reason);
        $this->assertSame($successor->id, $academic->replaced_by_id);
        $this->assertNull($academic->annulled_by);
    }

    /**
     * The guard must not disturb the ordering the service already had: empty
     * reason first, then authorization, and only then the status guard. An
     * unauthorized actor therefore never learns the persisted status of a
     * document they may not annul, and an empty reason keeps its own message.
     */
    public function test_revocation_keeps_the_reason_then_authorization_then_status_ordering(): void
    {
        Storage::fake('docs');
        $service = app(CertificateQrTokenService::class);
        $nonCurrent = $this->currentAcademicDocument('%PDF current certificate');
        $nonCurrent->forceFill(['status' => AcademicDocumentStatus::Annulled, 'annul_reason' => 'Motivo original'])->save();
        $nonCurrent = $nonCurrent->fresh();

        try {
            $service->revoke($nonCurrent, User::factory()->create(), '   ');
            $this->fail('An empty reason must still be rejected first.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A revocation reason is required.', $exception->getMessage());
        }

        try {
            $service->revoke($nonCurrent, User::factory()->create(), 'Corrección requerida');
            $this->fail('An unauthorized actor must still be denied before the status guard runs.');
        } catch (AuthorizationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        try {
            $service->revoke($nonCurrent, $this->revoker(), 'Corrección solicitada');
            $this->fail('A non-current document must not be annulled.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Only a current academic document may be annulled.', $exception->getMessage());
        }

        $nonCurrent = $nonCurrent->fresh();
        $this->assertSame(AcademicDocumentStatus::Annulled, $nonCurrent->status);
        $this->assertSame('Motivo original', $nonCurrent->annul_reason);
        $this->assertNull($nonCurrent->annulled_by);
    }

    /**
     * The stored state a replacement leaves behind: the previous document keeps
     * `Replaced`, the reason of the replacement and the pointer to its successor.
     * Mirrors what CourseDocumentGenerationService::regenerate() persists.
     */
    private function markReplacedBy(
        CourseAcademicDocument $document,
        CourseAcademicDocument $successor,
        string $reason,
        ?\DateTimeInterface $at = null,
    ): void {
        $document->forceFill([
            'status' => AcademicDocumentStatus::Replaced,
            'qr_token_revoked_at' => $at,
            'annulled_at' => $at,
            'annulled_by' => null,
            'annul_reason' => $reason,
            'replaced_by_id' => $successor->id,
        ])->save();
    }

    private function revoker(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(Permission::findOrCreate('course-talks.documents.revoke'));

        return $actor;
    }

    private function currentAcademicDocument(string $contents): CourseAcademicDocument
    {
        $activity = CourseActivity::factory()->create();
        $edition = CourseEdition::factory()->for($activity, 'activity')->create();
        $participant = CourseParticipant::factory()->create([
            'first_name' => 'Alvaro Segundo',
            'last_name' => 'Alama Silva',
            'document_number' => '87654321',
            'email' => 'alvaro@example.test',
            'mobile' => '+51999999999',
        ]);
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create([
            'display_average' => '15.50',
        ]);
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-'.str()->upper(str()->random(8)),
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $path = "course-academic-documents/{$enrollment->id}/{$academic->code}.pdf";
        Storage::disk('docs')->put($path, $contents, ['visibility' => 'private']);
        $document = Document::query()->create([
            'docable_type' => CourseAcademicDocument::class,
            'docable_id' => $academic->id,
            'name' => $academic->code.'.pdf',
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'uploaded_by' => User::factory()->create()->id,
            'uploaded_at' => now(),
        ]);
        $academic->forceFill(['document_id' => $document->id])->save();

        return $academic->fresh();
    }
}
