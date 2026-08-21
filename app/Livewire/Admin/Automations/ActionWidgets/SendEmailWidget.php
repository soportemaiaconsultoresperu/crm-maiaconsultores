<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

/**
 * B12-UI — PR 4 / Stage 4 — send_email action widget.
 *
 * Spec: REQ-ACT-02 (send_email row). B13 introduces the email template
 * catalog — v1 leaves subject/body as free-text literals.
 *
 * Payload keys: to, subject, body, queue? (bool, default true).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class SendEmailWidget extends AbstractActionWidget
{
    public ?string $to = null;

    public ?string $subject = null;

    public ?string $body = null;

    public bool $queue = true;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->to = isset($payload['to']) ? (string) $payload['to'] : null;
        $this->subject = isset($payload['subject']) ? (string) $payload['subject'] : null;
        $this->body = isset($payload['body']) ? (string) $payload['body'] : null;
        $this->queue = isset($payload['queue']) ? (bool) $payload['queue'] : true;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'to' => $this->to !== null && $this->to !== '' ? $this->to : null,
            'subject' => $this->subject !== null && $this->subject !== '' ? $this->subject : null,
            'body' => $this->body !== null && $this->body !== '' ? $this->body : null,
            'queue' => $this->queue,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.send-email-widget');
    }
}
