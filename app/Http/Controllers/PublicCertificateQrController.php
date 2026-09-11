<?php

namespace App\Http\Controllers;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Services\Courses\CertificateQrTokenService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;

class PublicCertificateQrController extends Controller
{
    public function show(string $token, CertificateQrTokenService $tokens): Response|StreamedResponse
    {
        $academic = $tokens->findCurrentByToken($token);
        if (! $this->canStream($academic)) {
            return response('Documento no vigente o no disponible.', 404);
        }

        $academic->forceFill(['qr_token_last_used_at' => now()])->save();

        return $this->streamPdf($academic);
    }

    public function showSigned(CourseAcademicDocument $academicDocument): Response|StreamedResponse
    {
        $academicDocument->loadMissing('document');
        if (! $this->canStream($academicDocument)) {
            return response('Documento no vigente o no disponible.', 404);
        }

        return $this->streamPdf($academicDocument);
    }

    public function showSignedCommercial(CourseCommercialDocument $commercialDocument): Response|StreamedResponse
    {
        $commercialDocument->loadMissing('document');
        if (! $this->canStreamCommercial($commercialDocument)) {
            return response('Documento no vigente o no disponible.', 404);
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

    private function streamPdf(CourseAcademicDocument $academic): StreamedResponse
    {
        return response()->stream(function () use ($academic): void {
            echo Storage::disk($academic->document->disk)->get($academic->document->path);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate.pdf"',
        ]);
    }
}
