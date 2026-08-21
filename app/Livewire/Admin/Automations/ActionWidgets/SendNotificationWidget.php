<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\User;
use App\Services\DataScopeService;

/**
 * B12-UI — PR 4 / Stage 4 — send_notification action widget.
 *
 * Spec: REQ-ACT-02 (send_notification row).
 *
 * Payload keys: user_id?, title, body, level? (info|warning|error, default info).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class SendNotificationWidget extends AbstractActionWidget
{
    public ?int $user_id = null;

    public ?string $title = null;

    public ?string $body = null;

    public string $level = 'info';

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->user_id = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->body = isset($payload['body']) ? (string) $payload['body'] : null;
        $this->level = isset($payload['level']) ? (string) $payload['level'] : 'info';
    }

    public function emit(): void
    {
        $payload = array_filter([
            'user_id' => $this->user_id,
            'title' => $this->title !== null && $this->title !== '' ? $this->title : null,
            'body' => $this->body !== null && $this->body !== '' ? $this->body : null,
            'level' => $this->level,
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
        return view('livewire.admin.automations.widgets.send-notification-widget');
    }
}
