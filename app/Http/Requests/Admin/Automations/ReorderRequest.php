<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Automations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * B12-UI — PR 1 skeleton for `PATCH /admin/automations/reorder`.
 *
 * The dispatcher field `kind ∈ {rules, conditions, actions}` and the
 * `order: [{id, order}, ...]` payload arrive in PR 3 (design §4.3). PR 1
 * only ships the class shell so the route handler can type-hint a
 * `ReorderRequest` instead of `Request`.
 */
class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}