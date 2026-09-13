<?php

namespace App\Http\Requests\CourseTalks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates exactly the attributes the existing
 * CourseEditionService::syncTeachers() contract consumes: a full teacher list
 * where every entry names its teacher, while `email` and `user_id` are optional
 * and must be usable when present.
 *
 * Two Blade affordances are resolved here and never reach the service as data:
 *
 * - `teachers.*.remove` marks an existing row for deletion. Removed rows are
 *   dropped while preparing the input, so a removed teacher's fields never have
 *   to be filled in and the submitted list is the new full list (the service
 *   replaces the whole teacher list instead of merging into it).
 * - `new_teacher[...]` is the optional "add another teacher" slot. It is
 *   appended to the list only when it carries a value, so re-submitting the form
 *   untouched never fails validation and never adds a blank teacher.
 *
 * `sort_order` is intentionally absent: the service derives it from the array
 * position, so posting it has no effect (FormRequest::validated() only returns
 * keys that have rules here).
 */
class SyncEditionTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.editions.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $teachers = $this->input('teachers');

        // Leave a non-array payload untouched so the `array` rule reports it.
        if (! is_array($teachers)) {
            return;
        }

        $submitted = [];

        foreach ($teachers as $teacher) {
            // Keep non-array entries so the wildcard rules report them.
            if (! is_array($teacher)) {
                $submitted[] = $teacher;

                continue;
            }

            if (filter_var($teacher['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            unset($teacher['remove']);

            $submitted[] = $teacher;
        }

        $newTeacher = $this->input('new_teacher');

        if (is_array($newTeacher) && array_filter($newTeacher, fn ($value): bool => $value !== null && $value !== '') !== []) {
            $submitted[] = $newTeacher;
        }

        // Nulling the slot keeps the flashed input consistent with the list that
        // was actually validated: on a failed submit the filled-in new teacher is
        // rendered (and re-submitted) as a regular list row, not twice.
        $this->merge(['teachers' => $submitted, 'new_teacher' => null]);
    }

    public function rules(): array
    {
        return [
            'teachers' => ['nullable', 'array'],
            'teachers.*' => ['array'],
            'teachers.*.display_name' => ['required', 'string', 'max:255'],
            'teachers.*.email' => ['nullable', 'email', 'max:255'],
            'teachers.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * The exact array CourseEditionService::syncTeachers() consumes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function teachersForSync(): array
    {
        return array_map(static fn (array $teacher): array => [
            'display_name' => $teacher['display_name'],
            'email' => $teacher['email'] ?? null,
            'user_id' => $teacher['user_id'] ?? null,
        ], $this->validated('teachers') ?? []);
    }
}
