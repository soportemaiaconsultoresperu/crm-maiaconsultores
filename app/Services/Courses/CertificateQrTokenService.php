<?php

namespace App\Services\Courses;

use App\Contracts\Courses\QrRenderer;
use App\Enums\Courses\AcademicDocumentStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class CertificateQrTokenService
{
    public function __construct(private readonly ?QrRenderer $qrRenderer = null) {}

    public function createFor(CourseAcademicDocument $document): string
    {
        $token = bin2hex(random_bytes(32));
        $document->forceFill([
            'qr_token_hash' => $this->hash($token),
            'qr_token_revoked_at' => null,
        ])->save();

        return $token;
    }

    public function renderSvg(string $payload): string
    {
        return ($this->qrRenderer ?? new EndroidQrRenderer())->renderSvg($payload);
    }

    public function findCurrentByToken(string $token): ?CourseAcademicDocument
    {
        return CourseAcademicDocument::query()
            ->with('document')
            ->where('qr_token_hash', $this->hash($token))
            ->whereNull('qr_token_revoked_at')
            ->where('status', AcademicDocumentStatus::Current)
            ->first();
    }

    public function revoke(CourseAcademicDocument $document, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A revocation reason is required.');
        }

        Gate::forUser($actor)->authorize('revoke', $document);

        $document->forceFill([
            'status' => AcademicDocumentStatus::Annulled,
            'qr_token_revoked_at' => now(),
            'annulled_at' => now(),
            'annulled_by' => $actor->id,
            'annul_reason' => $reason,
        ])->save();
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, config('app.key'));
    }
}
