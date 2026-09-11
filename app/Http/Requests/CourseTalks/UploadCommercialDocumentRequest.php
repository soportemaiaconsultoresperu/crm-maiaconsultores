<?php

namespace App\Http\Requests\CourseTalks;

use App\Services\DocumentService;
use Illuminate\Foundation\Http\FormRequest;

class UploadCommercialDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.commercial-documents.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:registered'],
            'file' => [
                'required',
                'file',
                'max:'.$this->maxSizeKb(),
                'mimes:'.implode(',', DocumentService::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    private function maxSizeKb(): int
    {
        $bytes = (int) \App\Models\Setting::query()
            ->where('key', 'documents.max_size')
            ->value('value');

        return $bytes > 0 ? intdiv($bytes, 1024) : 10 * 1024;
    }
}
