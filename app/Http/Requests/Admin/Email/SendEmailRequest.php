<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Email;

/**
 * B13 Pasada B — Validation contract for the "test send" endpoint
 * `POST /admin/email/templates/{template}/send` (decision 11b).
 */
class SendEmailRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('email.send') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'email', 'max:191'],
            'subject' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['nullable'],
            'account_id' => ['nullable', 'integer', 'exists:integration_accounts,id'],
        ];
    }
}
