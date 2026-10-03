<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for attaching an external image/video link to a content (T7).
 *
 * The link must be a valid URL; whether it points to an accepted media format
 * is a service rule (`ContentAttachmentService::attachLink`) so the accepted
 * extensions stay in one place. Reachability is the `contentProject` binding
 * and the mutation requires the `update` ability.
 */
class StoreContentAttachmentLinkRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:2048', 'url'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Indique el enlace de la imagen o del video.',
            'url.url' => 'El enlace no es una URL válida.',
            'url.max' => 'El enlace no puede superar los 2048 caracteres.',
        ];
    }
}
