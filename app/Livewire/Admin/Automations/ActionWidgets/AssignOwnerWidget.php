<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\Team;
use App\Models\User;
use App\Services\DataScopeService;
use Livewire\Attributes\Computed;

/**
 * B12-UI — PR 4 / Stage 4 — assign_owner action widget.
 *
 * Spec: REQ-ACT-02 (assign_owner row), REQ-ACT-03 (unified recipient_strategy
 * control), REQ-ACT-04 (DataScope pre-filter — compensates the engine-side
 * operator-precedence bug in AssignOwnerAction::execute per explore §8.5).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class AssignOwnerWidget extends AbstractActionWidget
{
    public string $recipient_strategy = 'current';

    public ?int $user_id = null;

    public ?int $team_id = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->recipient_strategy = isset($payload['recipient_strategy'])
            ? (string) $payload['recipient_strategy']
            : 'current';
        $this->user_id = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $this->team_id = isset($payload['team_id']) ? (int) $payload['team_id'] : null;
    }

    public function emit(): void
    {
        $payload = [
            'recipient_strategy' => $this->recipient_strategy,
            'team_id' => $this->recipient_strategy === 'team' || $this->recipient_strategy === 'round_robin'
                ? $this->team_id
                : null,
            'user_id' => $this->recipient_strategy === 'user'
                ? $this->user_id
                : null,
        ];

        // Strip nulls so payload_json stays compact.
        $payload = array_filter($payload, fn ($v) => $v !== null);

        $this->dispatchUpdate($payload);
    }

    /**
     * REQ-ACT-04 — user picker pre-filtered by DataScopeService::visibleOwnerIds
     * of the editor. Returns a [id => name] map ready for the <x-select> options.
     *
     * @return array<int, string>
     */
    #[Computed]
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
            // Unrestricted — show everyone.
            return User::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return User::query()
            ->whereIn('id', $visibleIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * All teams — no DataScope filtering (teams are a global catalog).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function getTeamsProperty(): array
    {
        return Team::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.assign-owner-widget');
    }
}
