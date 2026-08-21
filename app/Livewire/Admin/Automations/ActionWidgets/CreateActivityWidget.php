<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\ActivityType;
use App\Models\User;
use App\Services\DataScopeService;

/**
 * B12-UI — PR 4 / Stage 4 — create_activity action widget.
 *
 * Spec: REQ-ACT-02 (create_activity row).
 *
 * Payload keys: type_id, title, description?, scheduled_at?, priority?, owner_id?
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class CreateActivityWidget extends AbstractActionWidget
{
    public ?int $type_id = null;

    public ?string $title = null;

    public ?string $description = null;

    public ?string $scheduled_at = null;

    public ?string $priority = 'media';

    public ?int $owner_id = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->type_id = isset($payload['type_id']) ? (int) $payload['type_id'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->description = isset($payload['description']) ? (string) $payload['description'] : null;
        $this->scheduled_at = isset($payload['scheduled_at']) ? (string) $payload['scheduled_at'] : null;
        $this->priority = isset($payload['priority']) ? (string) $payload['priority'] : 'media';
        $this->owner_id = isset($payload['owner_id']) ? (int) $payload['owner_id'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'type_id' => $this->type_id,
            'title' => $this->title !== null && $this->title !== '' ? $this->title : null,
            'description' => $this->description !== null && $this->description !== '' ? $this->description : null,
            'scheduled_at' => $this->scheduled_at !== null && $this->scheduled_at !== '' ? $this->scheduled_at : null,
            'priority' => $this->priority,
            'owner_id' => $this->owner_id,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    /**
     * Available ActivityType rows as [id => name].
     *
     * @return array<int, string>
     */
    public function getActivityTypesProperty(): array
    {
        return ActivityType::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Owner picker with the same DataScope pre-filter as AssignOwnerWidget.
     *
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
        return view('livewire.admin.automations.widgets.create-activity-widget');
    }
}
