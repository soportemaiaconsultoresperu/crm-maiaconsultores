<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Automations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * B12-UI — PR 1 skeleton for `POST /admin/automations/{automation}/actions/{action}/simulate`.
 *
 * The `automations.test` authorize hook runs BEFORE any
 * `ActionRegistry::resolveForAction()` call (PERM-04, SCN-PERM-02). The
 * payload contract lives in design §4.4 and is filled in PR 5 (Chunk 4b)
 * where the real `ActionContract::simulate()` invocation lands.
 */
class SimulateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('automations.test') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}