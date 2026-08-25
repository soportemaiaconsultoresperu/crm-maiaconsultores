<?php

namespace App\Http\Requests\Admin;

use App\Services\DemoData\DemoDataDependencyPreview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DemoDataGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('demo-data.manage') === true;
    }

    public function rules(): array
    {
        return [
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(DemoDataDependencyPreview::ALL_MODULES)],
        ];
    }

    /** @return list<string> */
    public function modules(): array
    {
        /** @var list<string> $modules */
        $modules = $this->validated('modules', []);

        return $modules;
    }
}
