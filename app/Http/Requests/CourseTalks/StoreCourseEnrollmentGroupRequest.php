<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseEnrollment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the group enrollment payload: one payer record plus the participant
 * rows CourseEnrollmentService::enrollGroup() enrolls under that payer.
 *
 * The Blade form renders a fixed block of participant rows, so blank rows are
 * dropped while preparing the input — a partially filled block never has to be
 * cleaned up by hand and never reaches the service as an empty participant.
 * Payer requirements, duplicate-participant rejection and the all-or-nothing
 * group transaction stay in the service.
 */
class StoreCourseEnrollmentGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CourseEnrollment::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $participants = $this->input('participants');

        // Leave a non-array payload untouched so the `array` rule reports it.
        if (! is_array($participants)) {
            return;
        }

        $submitted = [];

        foreach ($participants as $participant) {
            // Keep non-array entries so the wildcard rules report them.
            if (! is_array($participant)) {
                $submitted[] = $participant;

                continue;
            }

            if (array_filter($participant, static fn ($value): bool => $value !== null && $value !== '') === []) {
                continue;
            }

            $submitted[] = $participant;
        }

        $this->merge(['participants' => $submitted]);
    }

    public function rules(): array
    {
        return [
            'payer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payer_name' => ['required', 'string', 'max:255'],
            'payer_document_type' => ['nullable', 'string', 'max:50'],
            'payer_document_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*' => ['array'],
            'participants.*.first_name' => ['required', 'string', 'max:255'],
            'participants.*.last_name' => ['required', 'string', 'max:255'],
            'participants.*.document_type' => ['required', 'string', 'max:50'],
            'participants.*.document_number' => ['required', 'string', 'max:50'],
            'participants.*.email' => ['required', 'email', 'max:255'],
            'participants.*.mobile' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * Spanish display names; the wildcard form is supported by the validator's
     * custom-attribute lookup, so every participant row reports readable
     * messages instead of raw `participants.0.last name` keys.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payer_customer_id' => 'cliente pagador',
            'payer_name' => 'nombre o razón social del pagador',
            'payer_document_type' => 'tipo de documento del pagador',
            'payer_document_number' => 'número de documento del pagador',
            'notes' => 'notas',
            'participants' => 'participantes',
            'participants.*.first_name' => 'nombres del participante',
            'participants.*.last_name' => 'apellidos del participante',
            'participants.*.document_type' => 'tipo de documento del participante',
            'participants.*.document_number' => 'número de documento del participante',
            'participants.*.email' => 'correo del participante',
            'participants.*.mobile' => 'celular del participante',
        ];
    }

    /**
     * The exact payer payload CourseEnrollmentService::enrollGroup() consumes.
     *
     * @return array<string, mixed>
     */
    public function payerData(): array
    {
        return [
            'payer_customer_id' => $this->validated('payer_customer_id'),
            'payer_name' => $this->validated('payer_name'),
            'payer_document_type' => $this->validated('payer_document_type'),
            'payer_document_number' => $this->validated('payer_document_number'),
            'notes' => $this->validated('notes'),
        ];
    }

    /**
     * The exact participant list CourseEnrollmentService::enrollGroup() consumes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function participantsForEnrollment(): array
    {
        return array_map(static fn (array $participant): array => [
            'first_name' => $participant['first_name'],
            'last_name' => $participant['last_name'],
            'document_type' => $participant['document_type'],
            'document_number' => $participant['document_number'],
            'email' => $participant['email'],
            'mobile' => $participant['mobile'],
        ], $this->validated('participants') ?? []);
    }
}
