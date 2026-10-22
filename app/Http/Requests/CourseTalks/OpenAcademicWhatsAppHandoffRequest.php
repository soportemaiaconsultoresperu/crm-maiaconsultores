<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseAcademicDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the assisted WhatsApp handoff payload: the phone to open WhatsApp
 * with and the idempotency key of the rendered form.
 *
 * The phone is checked here against the same digit rule the service applies
 * (8 to 15 digits once spaces and symbols are removed), so a mistyped number is
 * reported as a phone problem instead of the generic "not deliverable" refusal.
 * Normalizing it stays with CourseDocumentDeliveryService.
 */
class OpenAcademicWhatsAppHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send', CourseAcademicDocument::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'recipient_phone' => ['required', 'string', 'max:30', self::phoneDigitsRule()],
            // Presence only: the service owns the idempotency contract itself.
            'operation_key' => ['required', 'string'],
        ];
    }

    /** Shared with the comprobante handoff, so both channels word it the same way. */
    public static function phoneDigitsRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            $digits = strlen(preg_replace('/\D+/', '', (string) $value) ?? '');

            if ($digits < 8 || $digits > 15) {
                $fail('El teléfono de WhatsApp debe tener entre 8 y 15 dígitos, incluido el código de país (por ejemplo, 51999999999).');
            }
        };
    }

    public function attributes(): array
    {
        return [
            'recipient_phone' => 'teléfono del destinatario',
            'operation_key' => 'clave de idempotencia del formulario',
        ];
    }
}
