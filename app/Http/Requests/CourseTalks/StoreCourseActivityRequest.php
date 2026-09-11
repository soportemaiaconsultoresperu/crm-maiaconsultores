<?php

namespace App\Http\Requests\CourseTalks;

use App\Enums\Courses\CourseActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates exactly the attributes the existing CourseActivityService::create()
 * contract consumes. Domain rules stay in the service: `code` uniqueness is not
 * re-implemented here, and `slug`, `official_academic_hours`, `base_syllabus_json`,
 * `talk_includes_certificate`, `talk_certificate_price` and `is_active` keep their
 * service-side defaults when the request omits them.
 */
class StoreCourseActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.activities.manage') ?? false;
    }

    /**
     * The Blade form submits the base syllabus as a repeatable text list; blank
     * and whitespace-only rows are dropped so only real topics reach
     * `base_syllabus_json`.
     *
     * Genuine type-shape violations are deliberately preserved so the declared
     * `array` and `string` rules report them normally: a non-array payload is
     * left untouched, and non-string entries are kept as submitted instead of
     * being coerced into an empty (and silently dropped) string.
     */
    protected function prepareForValidation(): void
    {
        $syllabus = $this->input('base_syllabus_json');

        // Leave a non-array payload untouched so the `array` rule reports it.
        if (! is_array($syllabus)) {
            return;
        }

        $topics = array_map(
            fn ($topic) => is_string($topic) ? trim($topic) : $topic,
            $syllabus,
        );

        // Drop the blank rows that TrimStrings + ConvertEmptyStringsToNull turn
        // into null/empty strings, but keep other non-strings (arrays, ints) so
        // the `string` rule reports them.
        $this->merge([
            'base_syllabus_json' => array_values(array_filter(
                $topics,
                fn ($topic): bool => $topic !== null && $topic !== '',
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CourseActivityType::class)],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'official_academic_hours' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'base_syllabus_json' => ['nullable', 'array'],
            'base_syllabus_json.*' => ['string', 'max:255'],
            'talk_includes_certificate' => ['nullable', 'boolean'],
            'talk_certificate_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
