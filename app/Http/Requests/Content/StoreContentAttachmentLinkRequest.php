<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for attaching an external link to a content (T7).
 *
 * Any absolute http or https URL is accepted; whether its path names an accepted
 * media format is a service rule (`ContentAttachmentService::attachLink`) so the
 * classification and the accepted extensions stay in one place. Only a
 * non-http scheme, a malformed value or an empty value is refused. Reachability
 * is the `contentProject` binding and the mutation requires the `update` ability.
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
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Indique el enlace que desea adjuntar.',
            'url.string' => 'El enlace no es válido.',
            'url.url' => 'El enlace no es una URL válida. Debe ser una dirección completa que empiece con http:// o https://.',
            'url.max' => 'El enlace no puede superar los 2048 caracteres.',
        ];
    }
}
