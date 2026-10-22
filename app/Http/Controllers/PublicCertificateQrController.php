<?php

namespace App\Http\Controllers;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Services\Courses\CertificateQrTokenService;
use App\Services\Storage\PrivateFileResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;

class PublicCertificateQrController extends Controller
{
    public function __construct(
        private readonly PrivateFileResponder $responder,
    ) {
    }

    public function show(string $token, CertificateQrTokenService $tokens): Response|StreamedResponse|RedirectResponse
    {
        $academic = $tokens->findCurrentByToken($token);
        if (! $this->canStream($academic)) {
            return response('Documento no vigente o no disponible.', 404);
        }

        $academic->forceFill(['qr_token_last_used_at' => now()])->save();

        return $this->streamPdf($academic);
    }

    public function showSigned(CourseAcademicDocument $academicDocument): Response|StreamedResponse|RedirectResponse
    {
        $academicDocument->loadMissing('document');
        if (! $this->canStream($academicDocument)) {
            return response('Documento no vigente o no disponible.', 404);
        }

        return $this->streamPdf($academicDocument);
    }

    public function showSignedCommercial(CourseCommercialDocument $commercialDocument): Response|StreamedResponse|RedirectResponse
    {
        $commercialDocument->loadMissing('document');
        if (! $this->canStreamCommercial($commercialDocument)) {
            return response('Documento no vigente o no disponible.', 404);
        }

        // Authorization/visibility already passed above, so a signed URL is
        // only ever issued for a file this request may read. It carries the
        // same inline name and type the streamed response below would send.
        $redirect = $this->responder->signedUrlRedirect(
            $commercialDocument->document->disk,
            $commercialDocument->document->path,
            'commercial-document.pdf',
            'application/pdf',
            PrivateFileResponder::DISPOSITION_INLINE,
        );

        if ($redirect !== null) {
            return $redirect;
        }

        return response()->stream(function () use ($commercialDocument): void {
            echo Storage::disk($commercialDocument->document->disk)->get($commercialDocument->document->path);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="commercial-document.pdf"',
        ]);
    }

    private function canStream(?CourseAcademicDocument $academic): bool
    {
        return $academic?->document !== null
            && $academic->status === AcademicDocumentStatus::Current
            && $academic->qr_token_revoked_at === null
            && Storage::disk($academic->document->disk)->exists($academic->document->path);
    }

    private function canStreamCommercial(CourseCommercialDocument $commercial): bool
    {
        return $commercial->document !== null
            && in_array($commercial->status, ['registered', 'sent'], true)
            && $commercial->document->docable_type === CourseCommercialDocument::class
            && (int) $commercial->document->docable_id === (int) $commercial->id
            && Storage::disk($commercial->document->disk)->exists($commercial->document->path);
    }

    private function streamPdf(CourseAcademicDocument $academic): StreamedResponse|RedirectResponse
    {
        // The status, revocation and existence checks in canStream() already
        // passed, so a signed URL is only ever issued for a file this request
        // may read. It carries the same inline name and type the streamed
        // response below would send.
        $redirect = $this->responder->signedUrlRedirect(
            $academic->document->disk,
            $academic->document->path,
            'certificate.pdf',
            'application/pdf',
            PrivateFileResponder::DISPOSITION_INLINE,
        );

        if ($redirect !== null) {
            return $redirect;
        }

        return response()->stream(function () use ($academic): void {
            echo Storage::disk($academic->document->disk)->get($academic->document->path);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate.pdf"',
        ]);
    }
}
