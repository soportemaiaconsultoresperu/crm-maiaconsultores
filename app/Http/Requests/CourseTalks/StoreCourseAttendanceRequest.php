<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseEdition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of the attendance-matrix submission: a non-empty list of
 * cells, each one identifying an enrollment, a session and a status.
 *
 * Only shape is validated here. The set of valid statuses and the rule that the
 * session and the enrollment must belong to the same edition are owned by
 * CourseAttendanceService, which throws and whose message the controller turns
 * into a visible error; validating them here would put the same rule in two
 * places. `exists` is input existence, not edition membership, so it never
 * decides whether a cell is allowed — it only avoids resolving an id that
 * cannot be a model at all.
 */
class StoreCourseAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edition = $this->route('edition');

        return $edition instanceof CourseEdition
            && ($this->user()?->can('manageAttendance', $edition) ?? false);
    }

    public function rules(): array
    {
        return [
            'cells' => ['required', 'array', 'min:1'],
            'cells.*' => ['array'],
            'cells.*.enrollment_id' => ['required', 'integer', 'exists:course_enrollments,id'],
            'cells.*.session_id' => ['required', 'integer', 'exists:course_sessions,id'],
            // Free string on purpose: the service owns which values are valid.
            'cells.*.status' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * Spanish display names, including the wildcard form the validator resolves
     * for every submitted cell.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cells' => 'celdas de asistencia',
            'cells.*' => 'celda de asistencia',
            'cells.*.enrollment_id' => 'participante',
            'cells.*.session_id' => 'sesión',
            'cells.*.status' => 'estado de asistencia',
        ];
    }

    /**
     * The exact cells CourseAttendanceService::mark() is called for, one by one.
     *
     * @return array<int, array{enrollment_id: int, session_id: int, status: string}>
     */
    public function cells(): array
    {
        return array_map(static fn (array $cell): array => [
            'enrollment_id' => (int) $cell['enrollment_id'],
            'session_id' => (int) $cell['session_id'],
            'status' => (string) $cell['status'],
        ], array_values($this->validated('cells') ?? []));
    }
}
