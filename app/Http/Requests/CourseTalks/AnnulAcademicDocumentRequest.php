<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the annulment payload: the reason the user typed.
 *
 * Only the form contract is validated here. Revoking the QR token, marking the
 * document annulled and persisting the actor, the timestamp and the reason are
 * owned by CertificateQrTokenService, which also keeps its own reason rule as the
 * authoritative one; the controller refuses a document that is no longer vigente
 * because the service itself has no such guard.
 */
class AnnulAcademicDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('academicDocument');

        return $document instanceof CourseAcademicDocument
            && ($this->user()?->can('revoke', $document) ?? false);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'motivo de la anulación',
        ];
    }
}
