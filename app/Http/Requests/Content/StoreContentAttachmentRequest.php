<?php

namespace App\Http\Requests\Content;

use App\Models\Content\ContentProject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Input contract for uploading a media attachment to a content (T7).
 *
 * Reachability is resolved by the `contentProject` route binding (404 before
 * validation for an unreachable project) and the mutation requires the `update`
 * ability. Extension, real-content sniffing, size ceiling and the per-content
 * ceiling are service rules, so they are not duplicated here; this request only
 * guarantees a genuine uploaded file reached the controller.
 */
class StoreContentAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Seleccione un archivo para adjuntar.',
            'file.file' => 'El archivo adjunto no es válido.',
        ];
    }
}
