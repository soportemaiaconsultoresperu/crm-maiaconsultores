<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the individual enrollment payload: exactly one of the three
 * participant sources CourseEnrollmentService::enroll() resolves — an existing
 * CRM contact, an existing course participant, or the minimum participant data.
 *
 * `participant_source` is a UI-only selector and never reaches the service; it
 * only decides which participant payload is required. `sort`-style derived
 * fields are intentionally absent, and participant deduplication (normalized
 * document), required-data enforcement and duplicate-enrollment rejection stay
 * in the service so the rule lives in exactly one place.
 */
class StoreCourseEnrollmentRequest extends FormRequest
{
    /** @var list<string> */
    private const SOURCES = ['contact', 'participant', 'new'];

    /** @var list<string> */
    private const ACTIONS = ['enroll', 'enroll_and_add_another'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', CourseEnrollment::class) ?? false;
    }

    public function rules(): array
    {
        $source = $this->input('participant_source');

        return [
            'participant_source' => ['required', Rule::in(self::SOURCES)],
            'enrollment_action' => ['nullable', Rule::in(self::ACTIONS)],
            'contact_id' => [
                Rule::requiredIf($source === 'contact'), 'nullable', 'integer', 'exists:contacts,id',
            ],
            'course_participant_id' => [
                Rule::requiredIf($source === 'participant'), 'nullable', 'integer', 'exists:course_participants,id',
            ],
            'first_name' => [Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:255'],
            'last_name' => [Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:255'],
            // Free string on purpose: the domain stores whatever label the
            // organization uses (DNI, CE, Pasaporte…) and only requires presence.
            'document_type' => [Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:50'],
            'document_number' => [Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:50'],
            'email' => [Rule::requiredIf($source === 'new'), 'nullable', 'email', 'max:255'],
            // The country-code rule belongs to the service, which rejects a
            // mobile without one; validating the format here as well would put
            // the same rule in two places.
            'mobile' => [Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'participant_source' => 'origen del participante',
            'enrollment_action' => 'acción de inscripción',
            'contact_id' => 'contacto',
            'course_participant_id' => 'participante registrado',
            'first_name' => 'nombres',
            'last_name' => 'apellidos',
            'document_type' => 'tipo de documento',
            'document_number' => 'número de documento',
            'email' => 'correo electrónico',
            'mobile' => 'celular',
        ];
    }

    public function shouldReturnToCreate(): bool
    {
        return $this->validated('enrollment_action') === 'enroll_and_add_another';
    }

    /**
     * The exact participant payload CourseEnrollmentService::enroll() consumes.
     *
     * @return array<string, mixed>
     */
    public function participantData(): array
    {
        return match ($this->validated('participant_source')) {
            'contact' => ['contact_id' => (int) $this->validated('contact_id')],
            'participant' => ['course_participant_id' => (int) $this->validated('course_participant_id')],
            default => [
                'first_name' => $this->validated('first_name'),
                'last_name' => $this->validated('last_name'),
                'document_type' => $this->validated('document_type'),
                'document_number' => $this->validated('document_number'),
                'email' => $this->validated('email'),
                'mobile' => $this->validated('mobile'),
            ],
        };
    }
}
