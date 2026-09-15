<?php

namespace App\Http\Requests\CourseTalks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates exactly the attributes the existing
 * CourseEditionService::syncTeachers() contract consumes: a full teacher list
 * where every entry names its teacher, while `email` and `user_id` are optional
 * and must be usable when present.
 *
 * Two Blade affordances are resolved here and never reach the service as data:
 *
 * - `teachers.*.remove` marks an existing row for explicit deletion. Removed
 *   rows keep their hidden id in the payload so the service deletes exactly that
 *   teacher; omission alone is not removal.
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

        // Leave a present non-array payload untouched so the `array` rule reports it.
        if ($teachers !== null && ! is_array($teachers)) {
            return;
        }

        $submitted = [];

        foreach ($teachers ?? [] as $teacher) {
            // Keep non-array entries so the wildcard rules report them.
            if (! is_array($teacher)) {
                $submitted[] = $teacher;

                continue;
            }

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
        $edition = $this->route('edition');
        $editionId = is_object($edition) ? $edition->getKey() : $edition;

        return [
            'teachers' => ['nullable', 'array'],
            'teachers.*' => ['array'],
            'teachers.*.id' => [
                'nullable',
                'integer',
                Rule::exists('course_edition_teachers', 'id')
                    ->where('course_edition_id', $editionId)
                    ->whereNull('deleted_at'),
            ],
            'teachers.*.display_name' => ['nullable', 'string', 'max:255'],
            'teachers.*.email' => ['nullable', 'email', 'max:255'],
            'teachers.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'teachers.*.remove' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('teachers', []) as $index => $teacher) {
                if (! is_array($teacher)) {
                    continue;
                }

                $removed = filter_var($teacher['remove'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $name = trim((string) ($teacher['display_name'] ?? ''));

                if (! $removed && $name === '') {
                    $validator->errors()->add("teachers.{$index}.display_name", 'El nombre del docente es obligatorio.');
                }
            }
        });
    }

    /**
     * The exact array CourseEditionService::syncTeachers() consumes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function teachersForSync(): array
    {
        return array_map(static fn (array $teacher): array => [
            'id' => $teacher['id'] ?? null,
            'display_name' => $teacher['display_name'] ?? null,
            'email' => $teacher['email'] ?? null,
            'user_id' => $teacher['user_id'] ?? null,
            'remove' => filter_var($teacher['remove'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $this->validated('teachers') ?? []);
    }
}
