<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\User;
use App\Services\DataScopeService;

/**
 * B12-UI — PR 4 / Stage 4 — add_note action widget.
 *
 * Spec: REQ-ACT-02 (add_note row). The engine auto-creates the ActivityType
 * slug='nota' on first run (explore §8.9) — a non-blocking info note in the
 * view explains that.
 *
 * Payload keys: body (required), priority?, owner_id?
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class AddNoteWidget extends AbstractActionWidget
{
    public ?string $body = null;

    public ?string $priority = 'media';

    public ?int $owner_id = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->body = isset($payload['body']) ? (string) $payload['body'] : null;
        $this->priority = isset($payload['priority']) ? (string) $payload['priority'] : 'media';
        $this->owner_id = isset($payload['owner_id']) ? (int) $payload['owner_id'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'body' => $this->body !== null && $this->body !== '' ? $this->body : null,
            'priority' => $this->priority,
            'owner_id' => $this->owner_id,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    /**
     * @return array<int, string>
     */
    public function getVisibleUsersProperty(): array
    {
        $editor = User::find($this->editorUserId);
        if ($editor === null) {
            return [];
        }

        /** @var DataScopeService $scope */
        $scope = app(DataScopeService::class);
        $visibleIds = $scope->visibleOwnerIds($editor);

        if ($visibleIds === null) {
            return User::query()->orderBy('name')->pluck('name', 'id')->all();
        }

        return User::query()
            ->whereIn('id', $visibleIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.add-note-widget');
    }
}
