<?php

namespace App\Http\Requests\CourseTalks;

use App\Enums\Courses\CommercialDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCommercialDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('course-talks.commercial-documents.manage') ?? false;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('course_enrollment_id') && $this->filled('course_enrollment_group_id')) {
                $validator->errors()->add('course_enrollment_id', 'Seleccione exactamente una matrícula o grupo de matrícula.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CommercialDocumentType::class)],
            'course_enrollment_id' => ['nullable', 'integer', 'exists:course_enrollments,id', 'required_without:course_enrollment_group_id'],
            'course_enrollment_group_id' => ['nullable', 'integer', 'exists:course_enrollment_groups,id', 'required_without:course_enrollment_id'],
            'payer_name' => ['required', 'string', 'max:255'],
            'payer_document_type' => ['nullable', 'string', 'max:30'],
            'payer_document_number' => ['nullable', 'string', 'max:50'],
            'series' => ['nullable', 'string', 'max:30'],
            'number' => ['nullable', 'string', 'max:50'],
            'issue_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'observations' => ['nullable', 'string'],
        ];
    }
}
