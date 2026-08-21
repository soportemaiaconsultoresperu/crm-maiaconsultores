<?php

namespace App\Http\Requests;

use App\Models\ActivityType;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LossReason;
use App\Models\PipelineStage;
use App\Models\ProductCategory;
use App\Models\Tax;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the admin "edit catalog row" form. Mirrors
 * CatalogStoreRequest's structure but every rule becomes optional and
 * uniqueness ignores the current row.
 */
class CatalogUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $model = $this->resolvedModel();
        $row = $this->route('row');
        // The unique-ignore rule needs the PK column name so it doesn't
        // default to `id` — Currency uses `code` as PK, the rest use `id`.
        $keyName = (new $model())->getKeyName();

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];

        if ($model === Currency::class) {
            $rules['code'] = [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::unique('currencies', 'code')->ignore($row, $keyName),
            ];
            $rules['symbol'] = ['sometimes', 'required', 'string', 'max:10'];
            $rules['decimals'] = ['sometimes', 'integer', 'min:0', 'max:6'];

            return $rules;
        }

        $rules['slug'] = [
            'sometimes',
            'nullable',
            'string',
            'max:100',
            'regex:/^[a-z0-9\-]+$/',
            Rule::unique($this->tableFor($model), 'slug')->ignore($row, $keyName),
        ];

        if ($model === LeadStatus::class) {
            $rules['is_final'] = ['sometimes', 'boolean'];
        }

        if ($model === PipelineStage::class) {
            $rules['stage_type'] = ['sometimes', Rule::in(['open', 'won', 'lost'])];
            $rules['default_probability'] = ['sometimes', 'numeric', 'min:0', 'max:100'];
        }

        if ($model === Tax::class) {
            $rules['rate'] = ['sometimes', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'El código de moneda debe estar en ISO 4217 (3 letras mayúsculas).',
            'slug.regex' => 'El slug solo puede contener letras minúsculas, números y guiones.',
            'code.unique' => 'Ya existe una moneda con ese código.',
            'slug.unique' => 'Ya existe un registro con ese slug en este catálogo.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

private function resolvedModel(): string
    {
        $allowed = [
            'lead-sources' => LeadSource::class,
            'lead-statuses' => LeadStatus::class,
            'loss-reasons' => LossReason::class,
            'activity-types' => ActivityType::class,
            'pipeline-stages' => PipelineStage::class,
            'product-categories' => ProductCategory::class,
            'currencies' => Currency::class,
            'taxes' => Tax::class,
        ];

        $key = (string) ($this->route('kind') ?? $this->route('model'));

        if (! isset($allowed[$key])) {
            throw new \InvalidArgumentException(
                "El catálogo \"{$key}\" no es administrable."
            );
        }

        return $allowed[$key];
    }

    private function tableFor(string $model): string
    {
        return (new $model())->getTable();
    }
}