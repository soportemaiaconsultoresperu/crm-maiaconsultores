<?php

namespace App\Http\Requests\CourseTalks;

use App\Enums\Courses\CourseModality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates exactly the attributes the existing
 * CourseEditionService::create() contract consumes.
 *
 * Domain rules stay in the service: `code` uniqueness is not re-implemented
 * here, and the modality/location requirements are enforced by
 * CourseEditionService::validateModality() (delegated), not by duplicated
 * `required_if` rules. `state`, `currency` and `delivery_due_days` are
 * intentionally absent: the service owns those defaults, so posting them has no
 * effect (FormRequest::validated() only returns keys that have rules here).
 *
 * `certificate_charge_amount` is DELIVERY money that only applies to a talk that
 * includes a certificate, but the "does it apply" rule is deliberately NOT
 * repeated here (no `required_if` over the route's activity):
 * CourseActivity::issuesTalkCertificate() owns it and CourseEdition's write
 * guard enforces it, so a course's submission is accepted and zeroed rather than
 * rejected by a second, drifting copy of the same rule.
 */
class StoreCourseEditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.editions.manage') ?? false;
    }

    /**
     * The Blade form submits the edition syllabus as a repeatable text list;
     * blank and whitespace-only rows are dropped. Non-array payloads and
     * non-string entries are preserved so the declared `array`/`string` rules
     * can report genuine type-shape violations.
     */
    protected function prepareForValidation(): void
    {
        $syllabus = $this->input('syllabus_override_json');

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
            'syllabus_override_json' => array_values(array_filter(
                $topics,
                fn ($topic): bool => $topic !== null && $topic !== '',
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:60'],
            'modality' => ['required', Rule::enum(CourseModality::class)],
            'address' => ['nullable', 'string', 'max:255'],
            'access_url' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            // The spec requires an edition to carry a price and the column is NOT NULL.
            // While this was `nullable`, a blank value travelled all the way to the database
            // and died there as an uncaught QueryException instead of telling the operator what
            // was missing. Zero stays valid, because a free edition is a real edition.
            'price_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            // Optional DELIVERY money, same bounds as every other amount in the module.
            // Money is REJECTED, never clamped: silently turning a typo into the
            // maximum, or a negative into zero, would change what the operator typed.
            // `nullable` because it genuinely does not apply to most deliveries (a
            // course, or a talk without a certificate), and the model's write guard
            // — not this rule — is what keeps it at '0.00' when it does not apply.
            'certificate_charge_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'syllabus_override_json' => ['nullable', 'array'],
            'syllabus_override_json.*' => ['string', 'max:255'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
