<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the academic document email payload: the edited recipient and the
 * idempotency key of the rendered form.
 *
 * The form contract only guarantees the shape of the payload. The service owns
 * the delivery rules: it authorizes `send` again, validates the recipient and the
 * operation key, writes the ledger row and decides the delivery outcome. The
 * ability checked here is the same one the route requires, so a rendered control
 * never answers 403.
 */
class SendAcademicDocumentEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', CourseAcademicDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'email', 'max:255'],
            // Presence only: the service owns the idempotency contract itself
            // (non-empty and at most 64 characters) and reports its refusal.
            'operation_key' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'recipient' => 'correo del destinatario',
            'operation_key' => 'clave de idempotencia del formulario',
        ];
    }
}
