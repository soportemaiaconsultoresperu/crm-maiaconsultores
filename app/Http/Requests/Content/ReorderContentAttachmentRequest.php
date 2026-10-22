<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for changing an attachment's manual order (T7).
 *
 * Ordering is metadata only: it never touches the stored file. Reachability is
 * the `contentProject` binding and the mutation requires the `update` ability.
 */
class ReorderContentAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('contentProject');

        return $project instanceof ContentProject
            && ($this->user()?->can('update', $project) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sort' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sort.required' => 'Indique la nueva posición del adjunto.',
            'sort.integer' => 'La posición debe ser un número entero.',
        ];
    }
}
