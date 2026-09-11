<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the regeneration payload: the correction reason the user typed.
 *
 * Only the form contract is validated here. Whether the document may be
 * regenerated at all (it must be vigente), the replacement bookkeeping, the new
 * code, the new QR token and the revocation of the old one are owned by
 * CourseDocumentGenerationService, which also keeps its own reason rule as the
 * authoritative one.
 *
 * The route this request serves is authorized with the `generate` ability, the
 * same one the generation action uses; the service additionally authorizes
 * `revoke`, which is why the list only offers the control to holders of both.
 */
class RegenerateAcademicDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate', CourseAcademicDocument::class) ?? false;
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
            'reason' => 'motivo de la regeneración',
        ];
    }
}
