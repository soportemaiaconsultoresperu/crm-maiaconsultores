<?php

namespace App\Http\Requests\Content;

use App\Services\Content\ContentItemService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Shared input contract for the content grid and its exports (T10).
 *
 * The grid screen and both export routes read the SAME query string through
 * this request, so an export can never interpret a filter differently from the
 * screen. The rules are deliberately permissive and never required: the screen
 * has always tolerated blank or unknown values and ignored them, and
 * `ContentItemService::query()` still owns the semantic narrowing (it ignores an
 * unknown status, an empty month or a non-numeric catalog id). This request only
 * bounds the shape of the input and normalises it in one place.
 *
 * Reachability and authorization are not decided here: the `contentProject`
 * route binding answers 404 for an unreachable project and the controller asks
 * the policy for `view`.
 *
 * The month must be a real `YYYY-MM` month: `2026-13` or `2026-00` is refused
 * instead of reaching the date parser. A refused filter redirects to the grid
 * with the remaining filters and the error, never back to the same invalid URL
 * (which could loop) and never to an export without the filter the user asked
 * for.
 */
class ContentGridFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'month' => ['nullable', 'string', 'regex:'.ContentItemService::MONTH_PATTERN],
            'status' => ['nullable', 'string', 'max:64'],
            'pillar' => ['nullable', 'string', 'max:64'],
            'topic' => ['nullable', 'string', 'max:64'],
            'format' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'month.regex' => 'El mes indicado no es válido. Use el selector de mes (año y mes del 01 al 12).',
            'month.string' => 'El mes indicado no es válido. Use el selector de mes (año y mes del 01 al 12).',
        ];
    }

    /**
     * Send the user to the grid without the refused filters, keeping the rest.
     */
    protected function failedValidation(Validator $validator): void
    {
        $project = $this->route('contentProject');
        $query = Arr::except($this->query(), array_keys($validator->errors()->toArray()));
        $target = route('content.projects.contents.index', $project).($query === [] ? '' : '?'.Arr::query($query));

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($target);
    }

    /**
     * The normalised filter set, identical to what the grid renders and feeds to
     * ContentItemService::query().
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'q' => trim((string) $this->query('q', '')),
            'month' => trim((string) $this->query('month', '')),
            'status' => (string) $this->query('status', ''),
            'pillar' => (string) $this->query('pillar', ''),
            'topic' => (string) $this->query('topic', ''),
            'format' => (string) $this->query('format', ''),
            'channel' => (string) $this->query('channel', ''),
        ];
    }
}
