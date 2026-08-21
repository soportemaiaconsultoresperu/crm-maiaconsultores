<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\ActivityType;
use App\Models\User;
use App\Services\DataScopeService;

/**
 * B12-UI — PR 4 / Stage 4 — create_follow_up_activity action widget.
 *
 * Spec: REQ-ACT-02 (create_follow_up_activity row), SCN-ACT-08
 * (next_scheduled_at required).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class CreateFollowUpActivityWidget extends AbstractActionWidget
{
    public ?int $type_id = null;

    public ?string $title = null;

    public ?string $next_scheduled_at = null;

    public ?string $description = null;

    public ?string $priority = 'media';

    public ?int $owner_id = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->type_id = isset($payload['type_id']) ? (int) $payload['type_id'] : null;
        $this->title = isset($payload['title']) ? (string) $payload['title'] : null;
        $this->next_scheduled_at = isset($payload['next_scheduled_at'])
            ? (string) $payload['next_scheduled_at']
            : null;
        $this->description = isset($payload['description']) ? (string) $payload['description'] : null;
        $this->priority = isset($payload['priority']) ? (string) $payload['priority'] : 'media';
        $this->owner_id = isset($payload['owner_id']) ? (int) $payload['owner_id'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'type_id' => $this->type_id,
            'title' => $this->title !== null && $this->title !== '' ? $this->title : null,
            'next_scheduled_at' => $this->next_scheduled_at,
            'description' => $this->description !== null && $this->description !== '' ? $this->description : null,
            'priority' => $this->priority,
            'owner_id' => $this->owner_id,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    /**
     * SCN-ACT-08 — UI flag if next_scheduled_at is missing when emitting.
     */
    public function hasMissingRequiredField(): bool
    {
        return $this->next_scheduled_at === null || $this->next_scheduled_at === '';
    }

    /**
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
        return view('livewire.admin.automations.widgets.create-follow-up-activity-widget');
    }
}
