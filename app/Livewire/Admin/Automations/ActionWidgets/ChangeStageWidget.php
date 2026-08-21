<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use App\Models\PipelineStage;

/**
 * B12-UI — PR 4 / Stage 4 — change_stage action widget.
 *
 * Spec: REQ-ACT-02 (change_stage row). Subject is implicit (Opportunity).
 *
 * Payload keys: stage_slug (required), note? (optional).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 */
class ChangeStageWidget extends AbstractActionWidget
{
    public ?string $stage_slug = null;

    public ?string $note = null;

    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        parent::mount($actionIndex, $payload, $editorUserId);

        $this->stage_slug = isset($payload['stage_slug']) ? (string) $payload['stage_slug'] : null;
        $this->note = isset($payload['note']) ? (string) $payload['note'] : null;
    }

    public function emit(): void
    {
        $payload = array_filter([
            'stage_slug' => $this->stage_slug,
            'note' => $this->note !== null && $this->note !== '' ? $this->note : null,
        ], fn ($v) => $v !== null && $v !== '');

        $this->dispatchUpdate($payload);
    }

    /**
     * Available PipelineStage rows as [slug => name] for the <x-select> options.
     *
     * @return array<string, string>
     */
    public function getStagesProperty(): array
    {
        return PipelineStage::query()
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    public function render()
    {
        return view('livewire.admin.automations.widgets.change-stage-widget');
    }
}
