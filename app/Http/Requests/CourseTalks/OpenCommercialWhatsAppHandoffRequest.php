<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseCommercialDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the assisted WhatsApp handoff payload: the phone to open WhatsApp
 * with and the idempotency key of the rendered form.
 *
 * The phone is only checked for presence and shape here. Normalizing it and
 * deciding which phone is acceptable belong to CourseDocumentDeliveryService,
 * which refuses a phone it cannot use with a visible error.
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
            'recipient_phone' => ['required', 'string', 'max:30'],
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
