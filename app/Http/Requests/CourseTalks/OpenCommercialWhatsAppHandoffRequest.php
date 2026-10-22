<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseCommercialDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the assisted WhatsApp handoff payload: the phone to open WhatsApp
 * with and the idempotency key of the rendered form.
 *
 * The phone is checked against the service's digit rule, with the same message
 * as the academic handoff, so a mistyped number is reported as a phone problem
 * instead of "not deliverable". Normalizing it stays with the service.
 */
class OpenCommercialWhatsAppHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', CourseCommercialDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'recipient_phone' => ['required', 'string', 'max:30', OpenAcademicWhatsAppHandoffRequest::phoneDigitsRule()],
            // Presence only: the service owns the idempotency contract itself.
            'operation_key' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'recipient_phone' => 'teléfono del destinatario',
            'operation_key' => 'clave de idempotencia del formulario',
        ];
    }
}
