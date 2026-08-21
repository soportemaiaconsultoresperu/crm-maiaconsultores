<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

/**
 * B12-UI — PR 4 / Stage 4 — change_status action widget.
 *
 * Spec: REQ-ACT-02 (change_status row).
 *
 * Payload keys: value (required), column? (defaults to subject-type-aware column).
 * The "column" dropdown is intentionally free-form-ish for v1; admins override
 * via the custom-column warning toggle.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class ChangeStatusWidget extends AbstractActionWidget
{
    public ?string $column = null;

    public ?string $value = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->column = isset($payload['column']) ? (string) $payload['column'] : 'status_id';
        $this->value = isset($payload['value']) ? (string) $payload['value'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'column' => $this->column,
            'value' => $this->value !== null && $this->value !== '' ? $this->value : null,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.change-status-widget');
    }
}
