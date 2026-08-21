<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations\ActionWidgets;

use Livewire\Component;

/**
 * B12-UI — PR 4 / Stage 4 — Abstract base for all 11 per-type action widgets.
 *
 * Each concrete widget extends this class and:
 *  - declares its own public typed payload properties (tag_slug, user_id, etc.)
 *  - hydrates them from the parent's $payload array via mount()
 *  - implements an `emit()` method that calls $this->dispatchUpdate(...)
 *
 * The widget does NOT know about the parent ActionEditor — it dispatches the
 * `action-payload-updated` Livewire event with `{ index, payload_json }` and
 * the host (ActionEditor -> RuleForm) consumes it.
 *
 * Spec   : openspec/changes/b12-ui/specs/admin-automations-actions.md
 *          REQ-ACT-01 (action list editor) + REQ-ACT-02 (per-type widget matrix)
 * Design : openspec/changes/b12-ui/design.md §3.2 (Livewire tree)
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AddTagWidget
 */
abstract class AbstractActionWidget extends Component
{
    /**
     * The index of this action row inside the parent RuleForm's $actions array.
     * Echoed back in the dispatched event so the parent can write to the right
     * slot.
     */
    public int $actionIndex = 0;

    /**
     * The id of the user authoring the rule (used for DataScope pre-filter
     * on user pickers — assigned_owner / create_activity / create_follow_up_activity).
     */
    public int $editorUserId = 0;

    /**
     * Initial hydrated payload from the parent. Subclasses hydrate their
     * typed properties from this array in mount().
     *
     * @var array<string, mixed>
     */
    public array $payload = [];

    /**
     * Mount the widget. Subclasses SHOULD override this and call parent::mount
     * to hydrate `$payload`, then populate their typed properties from it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function mount(int $actionIndex = 0, array $payload = [], int $editorUserId = 0): void
    {
        $this->actionIndex = $actionIndex;
        $this->editorUserId = $editorUserId;
        $this->payload = $payload;
    }

    /**
     * Subclasses MUST implement this — convert their typed properties into a
     * payload array and call $this->dispatchUpdate(...).
     */
    abstract public function emit(): void;

    /**
     * Dispatch the action-payload-updated event so the parent ActionEditor
     * (and ultimately RuleForm) can sync the array.
     *
     * @param  array<string, mixed>  $payloadJson
     */
    protected function dispatchUpdate(array $payloadJson): void
    {
        $this->dispatch('action-payload-updated', index: $this->actionIndex, payload_json: $payloadJson);
    }
}
