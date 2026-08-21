<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Document upload validation (RF-DOC-001, RNF-SEG-002).
 *
 * The FormRequest is the FIRST line of defence for upload security:
 *   - size cap mirrors the documents.max_size setting (10 MB default).
 *   - MIME whitelist mirrors DocumentService::ALLOWED_EXTENSIONS; the
 *     service then cross-checks extension against MIME.
 *   - messages are in Spanish (lang/es/validation.php for shared rules).
 */
class DocumentUploadRequest extends FormRequest
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
        // Service-level cap (KB) — matches the default 10 MB.
        $maxKb = (int) (\App\Models\Setting::query()
            ->where('key', 'documents.max_size')
            ->value('value') ?: (10 * 1024 * 1024)) / 1024;

        if ($maxKb <= 0) {
            $maxKb = 10240;
        }

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimes:'.implode(',', \App\Services\DocumentService::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    /**
     * Spanish messages for the per-form context. Shared rules fall back to
     * lang/es/validation.php (e.g. `max.file` for KB units).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Debe seleccionar un archivo para subir.',
            'file.file' => 'El archivo enviado no es válido.',
            'file.max' => 'El archivo no debe pesar más de :max KB.',
            'file.mimes' => 'Tipo de archivo no permitido. Solo se aceptan: '
                .implode(', ', \App\Services\DocumentService::ALLOWED_EXTENSIONS).'.',
        ];
    }

    /**
     * Friendlier attribute name in the validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'archivo',
        ];
    }
}