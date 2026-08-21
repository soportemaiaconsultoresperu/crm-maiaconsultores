<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

/**
 * B12-UI — PR 4 / Stage 4 — add_tag action widget.
 *
 * Spec: REQ-ACT-02 (add_tag row).
 *
 * Payload keys (per explore §1.5):
 *   tag_slug (required), tag_name (optional, used when auto-creating), color (optional)
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class AddTagWidget extends AbstractActionWidget
{
    public ?string $tag_slug = null;

    public ?string $tag_name = null;

    public ?string $color = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->tag_slug = isset($payload['tag_slug']) ? (string) $payload['tag_slug'] : null;
        $this->tag_name = isset($payload['tag_name']) ? (string) $payload['tag_name'] : null;
        $this->color = isset($payload['color']) ? (string) $payload['color'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'tag_slug' => $this->tag_slug !== null && $this->tag_slug !== '' ? $this->tag_slug : null,
            'tag_name' => $this->tag_name !== null && $this->tag_name !== '' ? $this->tag_name : null,
            'color' => $this->color !== null && $this->color !== '' ? $this->color : null,
        ], fn ($v) => $v !== null);

        $this->dispatchUpdate($payload);
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.add-tag-widget');
    }
}
