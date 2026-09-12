<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the annulment payload: the reason the user typed.
 *
 * Only the form contract is validated here. Revoking the QR token, marking the
 * document annulled and persisting the actor, the timestamp and the reason are
 * owned by CertificateQrTokenService, which keeps its own reason rule and its own
 * persisted-status guard as the authoritative ones: inside a locked transaction
 * it re-reads the stored status and refuses to annul anything that is not still
 * current, so a document that stopped being vigente between the check and the
 * write keeps its original reason, actor and timestamp.
 *
 * The controller's boundary check is therefore defence in depth alongside that
 * service guard, not the only one: it spares the user an attempted write and owns
 * the Spanish sentence for the case, while the service remains the authority that
 * can see the persisted status.
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
