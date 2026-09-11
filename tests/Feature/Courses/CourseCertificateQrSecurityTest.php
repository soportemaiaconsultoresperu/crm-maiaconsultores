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
