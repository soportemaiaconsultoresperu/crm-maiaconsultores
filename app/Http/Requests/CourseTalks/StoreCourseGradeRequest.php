<?php

namespace App\Http\Requests\CourseTalks;

use App\Models\Courses\CourseEdition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of the grade-matrix submission: a non-empty list of cells,
 * each one identifying an enrollment, a session and the grade typed in it.
 *
 * Only shape is validated here. The accepted value range (0 to 20, up to two
 * decimals), the rule that the session and the enrollment must belong to the
 * same edition, the talk rejection and the average recalculation are owned by
 * CourseGradeService and CourseGradeCalculator; validating them here would put
 * the same rule in two places. `exists` is input existence, not edition
 * membership, so it never decides whether a cell is allowed — it only avoids
 * resolving an id that cannot be a model at all.
 */
class StoreCourseGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $edition = $this->route('edition');

        return $edition instanceof CourseEdition
            && ($this->user()?->can('manageGrades', $edition) ?? false);
    }

    public function rules(): array
    {
        return [
            'grades' => ['required', 'array', 'min:1'],
            'grades.*' => ['array'],
            'grades.*.enrollment_id' => ['required', 'integer', 'exists:course_enrollments,id'],
            'grades.*.session_id' => ['required', 'integer', 'exists:course_sessions,id'],
            // Nullable free string on purpose: an untouched cell means "no grade
            // submitted", and the value contract stays in CourseGradeCalculator.
            // The bound is a payload guard, never a range check.
            'grades.*.grade' => ['nullable', 'string', 'max:10'],
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
            'grades' => 'notas',
            'grades.*' => 'nota de la celda',
            'grades.*.enrollment_id' => 'participante',
            'grades.*.session_id' => 'sesión',
            'grades.*.grade' => 'nota',
        ];
    }

    /**
     * The exact cells CourseGradeService::record() is called for, one by one.
     *
     * A cell whose grade is empty is not a zero: it is a cell the user left
     * untouched, so it is dropped here and never reaches the service.
     *
     * @return array<int, array{enrollment_id: int, session_id: int, grade: string}>
     */
    public function cells(): array
    {
        $cells = [];

        foreach (array_values($this->validated('grades') ?? []) as $cell) {
            $grade = trim((string) ($cell['grade'] ?? ''));

            if ($grade === '') {
                continue;
            }

            $cells[] = [
                'enrollment_id' => (int) $cell['enrollment_id'],
                'session_id' => (int) $cell['session_id'],
                'grade' => $grade,
            ];
        }

        return $cells;
    }
}
