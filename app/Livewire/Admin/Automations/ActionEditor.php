<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations;

use App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget;
use App\Livewire\Admin\Automations\ActionWidgets\AddNoteWidget;
use App\Livewire\Admin\Automations\ActionWidgets\AddTagWidget;
use App\Livewire\Admin\Automations\ActionWidgets\AssignOwnerWidget;
use App\Livewire\Admin\Automations\ActionWidgets\ChangeStageWidget;
use App\Livewire\Admin\Automations\ActionWidgets\ChangeStatusWidget;
use App\Livewire\Admin\Automations\ActionWidgets\CreateActivityWidget;
use App\Livewire\Admin\Automations\ActionWidgets\CreateFollowUpActivityWidget;
use App\Livewire\Admin\Automations\ActionWidgets\SendEmailWidget;
use App\Livewire\Admin\Automations\ActionWidgets\SendNotificationWidget;
use App\Livewire\Admin\Automations\ActionWidgets\SendWhatsAppTemplateWidget;
use App\Livewire\Admin\Automations\ActionWidgets\WebhookWidget;
use App\Providers\AutomationServiceProvider;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * B12-UI — PR 4 / Stage 4 — ActionEditor Livewire host component.
 *
 * Renders a single AutomationAction row inside the rule form. The host:
 *  - exposes $actionIndex, $action (the row array), $editorUserId
 *  - computes the action type via #[Computed]
 *  - renders ONE of the 11 per-type widgets via @switch
 *  - listens for the widget's `action-payload-updated` event and re-dispatches
 *    it to the parent RuleForm (so the parent can sync the array).
 *
 * Spec   : openspec/changes/b12-ui/specs/admin-automations-actions.md
 *          REQ-ACT-01, REQ-ACT-02, REQ-ACT-03, REQ-ACT-04, REQ-ACT-06,
 *          REQ-ACT-07, REQ-ACT-08, REQ-ACT-09.
 * Design : openspec/changes/b12-ui/design.md §3.2 (Livewire tree).
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AbstractActionWidget
 * @see \Tests\Feature\Admin\Automations\Livewire\ActionEditorLivewireTest
 */
class ActionEditor extends Component
{
    /**
     * Index of this action row in the parent $actions array.
     */
    public int $actionIndex = 0;

    /**
     * Full action row — `type`, `channel`, `recipient_strategy`, `payload_json`
     * (array), `is_active`, `position`. retry_policy_json is intentionally
     * NOT passed in / NOT rendered (REQ-ACT-08).
     *
     * @var array<string, mixed>
     */
    public array $action = [];

    /**
     * User authoring the rule. Used by AssignOwnerWidget / CreateActivityWidget
     * / CreateFollowUpActivityWidget for DataScope pre-filter (REQ-ACT-04).
     */
    public int $editorUserId = 0;

    /**
     * Static map: action-type slug -> widget class.
     * All 11 widget classes are wired here (one per existing action slug in
     * AutomationServiceProvider::ACTION_TYPES).
     *
     * @var array<string, class-string<AbstractActionWidget>>
     */
    public const WIDGET_MAP = [
        'add_tag' => AddTagWidget::class,
        'assign_owner' => AssignOwnerWidget::class,
        'change_status' => ChangeStatusWidget::class,
        'change_stage' => ChangeStageWidget::class,
        'create_activity' => CreateActivityWidget::class,
        'create_follow_up_activity' => CreateFollowUpActivityWidget::class,
        'add_note' => AddNoteWidget::class,
        'send_email' => SendEmailWidget::class,
        'send_notification' => SendNotificationWidget::class,
        'send_whatsapp_template' => SendWhatsAppTemplateWidget::class,
        'webhook' => WebhookWidget::class,
    ];

    public function mount(int $actionIndex = 0, array $action = [], int $editorUserId = 0): void
    {
        $this->actionIndex = $actionIndex;
        $this->action = $action;
        $this->editorUserId = $editorUserId;
    }

    /**
     * REQ-ACT-02 — return the action['type'] so the view can switch on it.
     * Falls back to the first registered action slug if `type` is missing.
     */
    #[Computed]
    public function getActionTypeProperty(): string
    {
        $type = (string) ($this->action['type'] ?? '');

        if ($type === '') {
            return array_key_first(AutomationServiceProvider::ACTION_TYPES) ?? 'add_tag';
        }

        return $type;
    }

    /**
     * Resolve the widget class for the current action type.
     *
     * @return class-string<AbstractActionWidget>
     */
    public function widgetClass(): string
    {
        $type = $this->getActionTypeProperty();

        return self::WIDGET_MAP[$type] ?? AddTagWidget::class;
    }

    /**
     * Resolve the payload slice for the widget. We pass through
     * `payload_json` (decoded from its cast form) plus the action's
     * `recipient_strategy` column for the AssignOwnerWidget.
     *
     * @return array<string, mixed>
     */
    public function widgetPayload(): array
    {
        $rawPayload = $this->action['payload_json'] ?? [];
        if (is_string($rawPayload)) {
            $decodedPayload = json_decode($rawPayload, true);
            $rawPayload = is_array($decodedPayload) ? $decodedPayload : [];
        }
        $payload = is_array($rawPayload) ? $rawPayload : [];

        if (
            ! isset($payload['recipient_strategy'])
            && isset($this->action['recipient_strategy'])
            && $this->action['recipient_strategy'] !== null
        ) {
            $payload['recipient_strategy'] = (string) $this->action['recipient_strategy'];
        }

        return $payload;
    }

    /**
     * Method on the host invoked by the parent (or by the test) to sync the
     * action-payload-updated event. Re-dispatches with the merged state so the
     * parent RuleForm can consume it.
     *
     * @param  array<string, mixed>|string  $newPayload  Either a payload array
     *                                                   or a JSON string.
     */
    public function emitPayloadUpdated(array|string $newPayload): void
    {
        $payloadArray = is_array($newPayload)
            ? $newPayload
            : (array) json_decode((string) $newPayload, true);

        $this->dispatch('action-payload-updated', index: $this->actionIndex, payload_json: $payloadArray);
    }

    /**
     * Re-emit the child widget's payload-updated so the parent RuleForm picks it up.
     * Listens for `action-payload-updated` from any widget in the subtree.
     */
    #[On('action-payload-updated')]
    public function handlePayloadUpdated(int $index, array $payload_json): void
    {
        // Re-dispatch upward so the parent can sync the array.
        $this->dispatch('action-payload-updated', index: $index, payload_json: $payload_json);
    }

    public function render()
    {
        return view('livewire.admin.automations.action-editor');
    }
}
