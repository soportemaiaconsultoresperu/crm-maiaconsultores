<?php

namespace App\Http\Requests\CourseTalks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a certificate template payload, and nothing else.
 *
 * Every business rule stays in CourseCertificateTemplateService: the
 * `type_scope` allowlist (which values exist at all), which `settings_json`
 * keys a certificate view consumes, how many signatures it renders, what a
 * valid signature looks like, the once-active-template-per-scope rule, the
 * derived version and the refusal of attributes the domain does not own. This
 * class only says what the FORM can express and what type each field is, so
 * there is exactly one place that decides a payload is unacceptable for a
 * business reason: the domain. An invalid scope or an unknown settings key
 * posted here therefore reaches the domain and is refused by it, with its own
 * reason, instead of being silently filtered by a second, drifting allowlist.
 *
 * The two attributes that must never be configurable from the web are absent on
 * purpose: `blade_view` has no rule (the render allowlist has exactly one
 * member, so a selector would be theatre) and `html_template` has no rule
 * (raw administrator HTML inside a generated PDF is an injection path). A
 * tampered payload carrying either one is simply not part of `validated()`,
 * and `payload()` below builds the domain payload key by key, so neither can
 * reach the service or the model through this surface.
 */
class StoreCourseCertificateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.templates.manage') ?? false;
    }

    /**
     * Drops the settings entries the user left empty, at any depth.
     *
     * The form always submits every field, and untouched optional inputs arrive
     * as null once ConvertEmptyStringsToNull has run. Passing those through
     * would turn "I did not configure a company" into "the company must be a
     * non-empty string", and a blank signature slot into a signature without a
     * name, so every save of a template that does not override every key would
     * be refused. Only BLANK entries are dropped — never a key the user filled,
     * and never unknown keys, which must reach the domain so it can refuse them
     * with its own reason. A pruned list is re-indexed because the domain
     * requires a list of signature maps, while a map keeps its keys. Same shape
     * of fix the activity form applies to its blank syllabus rows.
     */
    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings_json');

        // Leave a non-array payload untouched so the `array` rule reports it as
        // the shape error it is.
        if (! is_array($settings)) {
            return;
        }

        $this->merge(['settings_json' => $this->withoutBlankEntries($settings)]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function withoutBlankEntries(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $inner = $this->withoutBlankEntries($value);

                if ($inner === []) {
                    continue;
                }

                $value = $inner;
            } elseif ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $kept[$key] = $value;
        }

        // The signature rows are a list: dropping a blank slot must not leave a
        // gap, or the domain's own "a list of signature maps" rule would refuse
        // a configuration the user did enter.
        return array_is_list($values) ? array_values($kept) : $kept;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Shape only: which values are legal is the domain's allowlist.
            'type_scope' => ['required', 'string', 'max:60'],

            // The settings travel as the map the domain validates. The whole
            // map is forwarded (unknown keys included) so the domain's
            // `unknown_setting_keys` refusal is what the user reads.
            'settings_json' => ['nullable', 'array'],
            'settings_json.title' => ['nullable', 'string', 'max:255'],
            'settings_json.intro_text' => ['nullable', 'string', 'max:255'],
            'settings_json.company' => ['nullable', 'string', 'max:255'],
            'settings_json.signatures' => ['nullable', 'array'],
            'settings_json.signatures.*' => ['array'],
            'settings_json.signatures.*.name' => ['nullable', 'string', 'max:255'],
            'settings_json.signatures.*.role' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Spanish field names for the framework's own messages. */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'type_scope' => 'alcance',
            'settings_json' => 'configuración',
            'settings_json.title' => 'título',
            'settings_json.intro_text' => 'texto de introducción',
            'settings_json.company' => 'empresa',
            'settings_json.signatures' => 'firmas',
            'settings_json.signatures.*' => 'firma',
            'settings_json.signatures.*.name' => 'nombre de la firma',
            'settings_json.signatures.*.role' => 'cargo de la firma',
        ];
    }

    /**
     * The domain payload, assembled key by key.
     *
     * The settings map is read from the (already pruned) request input rather
     * than from `validated()`, on purpose: Laravel drops the value of an `array`
     * key as soon as that key has nested rules (`excludeUnvalidatedArrayKeys`),
     * so `validated('settings_json')` returns the declared keys only. That would
     * silently swallow an unknown settings key — the exact "a configuration the
     * administrator believes took effect when it did not" the domain refuses —
     * and it would fabricate `null` entries for optional keys the form never
     * sent. The domain validates every key and value it receives either way, so
     * an unknown key reaches it and is refused by it.
     *
     * Only the attributes the domain owns reach it: an absent `settings_json`
     * is omitted rather than sent as null, so an update that did not submit the
     * configuration leaves the stored one alone instead of clearing it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [
            'name' => $this->validated('name'),
            'type_scope' => $this->validated('type_scope'),
        ];

        $settings = $this->input('settings_json');

        if ($settings !== null) {
            $payload['settings_json'] = $settings;
        }

        return $payload;
    }
}
