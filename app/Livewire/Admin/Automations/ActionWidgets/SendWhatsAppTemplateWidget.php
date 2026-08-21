<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

/**
 * B12-UI — PR 4 / Stage 4 — send_whatsapp_template action widget (B14 stub).
 *
 * Spec: REQ-ACT-02 (send_whatsapp_template row), REQ-ACT-06 (B14 stub banner).
 *
 * Payload keys: template_name, phone_number, language?, variables?, account_id?
 *
 * The widget renders the B14 disabled-state banner but still allows emit() to
 * save the typed (possibly incomplete) state — engine rejects with
 * NotImplementedException at run time.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class SendWhatsAppTemplateWidget extends AbstractActionWidget
{
    public ?string $template_name = null;

    public ?string $phone_number = null;

    public ?string $language = null;

    /**
     * @var array<int|string, string>
     */
    public array $variables = [];

    public ?string $account_id = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->template_name = isset($payload['template_name']) ? (string) $payload['template_name'] : null;
        $this->phone_number = isset($payload['phone_number']) ? (string) $payload['phone_number'] : null;
        $this->language = isset($payload['language']) ? (string) $payload['language'] : null;
        $this->variables = isset($payload['variables']) && is_array($payload['variables'])
            ? array_values((array) $payload['variables'])
            : [];
        $this->account_id = isset($payload['account_id']) ? (string) $payload['account_id'] : null;
    }

    public function addVariable(): void
    {
        $this->variables[] = ['key' => '', 'value' => ''];
    }

    public function removeVariable(int $index): void
    {
        if (! isset($this->variables[$index])) {
            return;
        }
        array_splice($this->variables, $index, 1);
    }

    public function emit(): void
    {
        $vars = [];
        foreach ($this->variables as $row) {
            if (is_array($row)) {
                $key = (string) ($row['key'] ?? '');
                $value = (string) ($row['value'] ?? '');
                if ($key !== '') {
                    $vars[$key] = $value;
                }
            }
        }

        $payload = [
            'template_name' => $this->template_name,
            'phone_number' => $this->phone_number,
            'language' => $this->language,
            'variables' => $vars !== [] ? $vars : null,
            'account_id' => $this->account_id,
        ];

        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.send-whatsapp-template-widget');
    }
}
