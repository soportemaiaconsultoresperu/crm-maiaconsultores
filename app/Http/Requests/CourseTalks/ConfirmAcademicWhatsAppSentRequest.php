<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the manual WhatsApp confirmation payload: which handoff is being
 * confirmed, the phone it was opened for and the idempotency key of the
 * rendered form.
 *
 * The handoff is looked up by the controller and matched by the service: only
 * the actor, the phone and the delivery row are required here. The service owns
 * the confirmation rule, appends the delivery-history entry and only then marks
 * the document sent.
 */
class ConfirmAcademicWhatsAppSentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', CourseAcademicDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'handoff' => ['required', 'integer'],
            'recipient_phone' => ['required', 'string', 'max:30'],
            // Presence only: the service owns the idempotency contract itself.
            'operation_key' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'handoff' => 'handoff de WhatsApp',
            'recipient_phone' => 'teléfono del destinatario',
            'operation_key' => 'clave de idempotencia del formulario',
        ];
    }
}
