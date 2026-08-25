<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\ActionEditor;
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
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — ActionEditor Livewire host.
 *
 * Covers ACT-01 (action list editor), ACT-02 (per-type widget matrix),
 * ACT-04 (DataScope on assign_owner), ACT-06 (B14 stub banners),
 * ACT-07 (simulate-now wiring), ACT-08 (retry_policy_json hidden).
 *
 * Spec   : openspec/changes/b12-ui/specs/admin-automations-actions.md
 * Design : openspec/changes/b12-ui/design.md §3.2 (Livewire tree)
 * Tasks  : openspec/changes/b12-ui/tasks.md §A.Chunk 4a (PR 4)
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 * Running it before the GREEN step proves the missing-class failure (RED).
 *
 * @see \App\Livewire\Admin\Automations\ActionEditor
 */
class ActionEditorLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PERM-08 — explicit provider boot.
        app()->register(AutomationServiceProvider::class, force: true);
    }

    /**
     * Test data factory — return an editor user + a default action array.
     *
     * @return array{user: User, action: array<string, mixed>}
     */
    private function makeFixture(string $type = 'add_tag'): array
    {
        $user = User::factory()->create();

        $action = [
            'type' => $type,
            'channel' => null,
            'recipient_strategy' => null,
            'payload_json' => [],
            'is_active' => true,
            'position' => 1,
        ];

        return ['user' => $user, 'action' => $action];
    }

    // ---------------------------------------------------------------------
    // ACT-02 — every type slug renders its dedicated widget.
    // ---------------------------------------------------------------------
    public function test_renders_add_tag_widget_when_type_is_add_tag(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('add_tag');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'add_tag')
            ->assertSeeLivewire(AddTagWidget::class);
    }

    public function test_renders_assign_owner_widget_when_type_is_assign_owner(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('assign_owner');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'assign_owner')
            ->assertSeeLivewire(AssignOwnerWidget::class);
    }

    public function test_renders_change_status_widget_when_type_is_change_status(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('change_status');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'change_status')
            ->assertSeeLivewire(ChangeStatusWidget::class);
    }

    public function test_renders_change_stage_widget_when_type_is_change_stage(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('change_stage');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'change_stage')
            ->assertSeeLivewire(ChangeStageWidget::class);
    }

    public function test_renders_create_activity_widget_when_type_is_create_activity(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('create_activity');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'create_activity')
            ->assertSeeLivewire(CreateActivityWidget::class);
    }

    public function test_renders_create_follow_up_activity_widget_when_type_is_create_follow_up_activity(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('create_follow_up_activity');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'create_follow_up_activity')
            ->assertSeeLivewire(CreateFollowUpActivityWidget::class);
    }

    public function test_renders_add_note_widget_when_type_is_add_note(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('add_note');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'add_note')
            ->assertSeeLivewire(AddNoteWidget::class);
    }

    public function test_renders_send_email_widget_when_type_is_send_email(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('send_email');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'send_email')
            ->assertSeeLivewire(SendEmailWidget::class);
    }

    public function test_renders_send_notification_widget_when_type_is_send_notification(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('send_notification');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'send_notification')
            ->assertSeeLivewire(SendNotificationWidget::class);
    }

    public function test_renders_send_whatsapp_template_widget_when_type_is_send_whatsapp_template(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('send_whatsapp_template');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'send_whatsapp_template')
            ->assertSeeLivewire(SendWhatsAppTemplateWidget::class);
    }

    public function test_renders_webhook_widget_when_type_is_webhook(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('webhook');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSet('actionType', 'webhook')
            ->assertSeeLivewire(WebhookWidget::class);
    }

    // ---------------------------------------------------------------------
    // ACT-03 — getActionTypeProperty returns the action['type'] from props.
    // ---------------------------------------------------------------------
    public function test_get_action_type_property_returns_action_type(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('change_stage');

        $component = Livewire::test(ActionEditor::class, [
            'actionIndex' => 5,
            'action' => $action,
            'editorUserId' => $user->id,
        ]);

        $instance = $component->instance();

        $this->assertSame('change_stage', $instance->getActionTypeProperty());
    }

    public function test_widget_payload_decodes_json_string_payloads(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('add_tag');
        $action['payload_json'] = '{"tag_slug":"warm"}';

        $component = Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ]);

        $this->assertSame(['tag_slug' => 'warm'], $component->instance()->widgetPayload());
    }

    // ---------------------------------------------------------------------
    // ACT-08 — retry_policy_json MUST NOT appear in the rendered DOM.
    // ---------------------------------------------------------------------
    public function test_retry_policy_json_is_not_in_rendered_dom(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('webhook');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertDontSee('retry_policy_json')
            ->assertDontSee('retry_policy');
    }

    // ---------------------------------------------------------------------
    // ACT-06 — B14 banner text is rendered above the webhook widget.
    // ---------------------------------------------------------------------
    public function test_webhook_action_renders_b14_banner(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('webhook');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSee('Pendiente (B14)');
    }

    public function test_send_whatsapp_template_action_renders_b14_banner(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('send_whatsapp_template');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 0,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->assertSee('Pendiente (B14)');
    }

    // ---------------------------------------------------------------------
    // ACT-09 placeholder — ActionEditor emits payload-updated event with
    // the actionIndex when its emitPayloadUpdated method is invoked.
    // ---------------------------------------------------------------------
    public function test_emit_payload_updated_dispatches_event_with_index_and_payload(): void
    {
        ['user' => $user, 'action' => $action] = $this->makeFixture('add_tag');

        Livewire::test(ActionEditor::class, [
            'actionIndex' => 3,
            'action' => $action,
            'editorUserId' => $user->id,
        ])
            ->call('emitPayloadUpdated', ['tag_slug' => 'hot-lead', 'color' => 'red'])
            ->assertDispatched('action-payload-updated');
    }
}
