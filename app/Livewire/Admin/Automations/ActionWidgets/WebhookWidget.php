<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

/**
 * B12-UI — PR 4 / Stage 4 — webhook action widget (B14 stub).
 *
 * Spec: REQ-ACT-02 (webhook row), REQ-ACT-05 (allow-list surface),
 * REQ-ACT-06 (B14 stub banner), REQ-ACT-08 (retry_policy_json hidden).
 *
 * The widget renders the B14 disabled-state banner above the form, then the
 * URL select populated from `config('integrations.webhooks.allowed_destinations')`,
 * then the method / body / headers editor. The form fields are visually
 * disabled so admins know the action will fail at execute time.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class WebhookWidget extends AbstractActionWidget
{
    public ?string $url = null;

    public string $method = 'POST';

    public ?string $body = null;

    /**
     * @var array<int, array{key:string,value:string}>
     */
    public array $headers = [];

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->url = isset($payload['url']) ? (string) $payload['url'] : null;
        $this->method = isset($payload['method']) ? strtoupper((string) $payload['method']) : 'POST';
        $this->body = isset($payload['body']) ? (string) $payload['body'] : null;
        $this->headers = isset($payload['headers']) && is_array($payload['headers'])
            ? array_values((array) $payload['headers'])
            : [];
    }

    public function addHeader(): void
    {
        $this->headers[] = ['key' => '', 'value' => ''];
    }

    public function removeHeader(int $index): void
    {
        if (! isset($this->headers[$index])) {
            return;
        }
        array_splice($this->headers, $index, 1);
    }

    /**
     * REQ-ACT-05 — read the allow-list from config; empty = deny.
     *
     * @return list<string>
     */
    public function getAllowedDestinationsProperty(): array
    {
        $configured = config('integrations.webhooks.allowed_destinations', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $configured), fn ($v) => $v !== ''));
    }

    public function emit(): void
    {
        $headers = [];
        foreach ($this->headers as $row) {
            if (is_array($row)) {
                $key = (string) ($row['key'] ?? '');
                $value = (string) ($row['value'] ?? '');
                if ($key !== '') {
                    $headers[$key] = $value;
                }
            }
        }

        $payload = [
            'url' => $this->url,
            'method' => strtoupper((string) $this->method),
            'body' => $this->body !== null && $this->body !== '' ? $this->body : null,
            'headers' => $headers !== [] ? $headers : null,
        ];

        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.webhook-widget');
    }
}
