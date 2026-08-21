<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\ActionWidgets\AssignOwnerWidget;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — AssignOwnerWidget Livewire component.
 *
 * Spec: REQ-ACT-02 (assign_owner row), REQ-ACT-03 (unified recipient_strategy
 * control), REQ-ACT-04 (DataScope pre-filter).
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AssignOwnerWidget
 */
class AssignOwnerWidgetLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
    }

    public function test_unrestricted_user_sees_all_users_in_picker(): void
    {
        $editor = User::factory()->create(['name' => 'Editor']);
        $other = User::factory()->create(['name' => 'Other User']);

        // Register the permission + grant it so the editor becomes unrestricted.
        Permission::findOrCreate('leads.view.any', 'web');
        $editor->givePermissionTo('leads.view.any');

        $component = Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
            'editorUserId' => $editor->id,
        ])->instance();

        $visible = (array) $component->getVisibleUsersProperty();

        $this->assertArrayHasKey($editor->id, $visible);
        $this->assertArrayHasKey($other->id, $visible);
    }

    public function test_vendor_user_only_sees_self_plus_data_scope(): void
    {
        $editor = User::factory()->create(['name' => 'Restricted Editor']);
        $other = User::factory()->create(['name' => 'Some Other']);

        // No special perms — defaults to vendor scope = only self.

        $component = Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
            'editorUserId' => $editor->id,
        ])->instance();

        $visible = (array) $component->getVisibleUsersProperty();

        $this->assertSame([$editor->id => 'Restricted Editor'], $visible);
        $this->assertArrayNotHasKey($other->id, $visible);
    }

    public function test_default_strategy_is_current(): void
    {
        $editor = User::factory()->create();

        Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
            'editorUserId' => $editor->id,
        ])
            ->assertSet('recipient_strategy', 'current');
    }

    public function test_current_strategy_ignores_user_id_on_emit(): void
    {
        $editor = User::factory()->create();

        Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => ['recipient_strategy' => 'current', 'user_id' => 999],
            'editorUserId' => $editor->id,
        ])
            ->assertSet('recipient_strategy', 'current')
            ->call('emit')
            ->assertDispatched('action-payload-updated', function (string $name, array $params): bool {
                $payload = (array) ($params['payload_json'] ?? []);

                // user_id MUST NOT be present when strategy=current.
                return ! array_key_exists('user_id', $payload);
            });
    }

    public function test_round_robin_strategy_ignores_user_id_on_emit(): void
    {
        $editor = User::factory()->create();

        Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => ['recipient_strategy' => 'round_robin', 'team_id' => 1],
            'editorUserId' => $editor->id,
        ])
            ->assertSet('recipient_strategy', 'round_robin')
            ->call('emit')
            ->assertDispatched('action-payload-updated', function (string $name, array $params): bool {
                $payload = (array) ($params['payload_json'] ?? []);

                return ! array_key_exists('user_id', $payload);
            });
    }

    public function test_user_strategy_includes_user_id_on_emit(): void
    {
        $editor = User::factory()->create();

        Livewire::test(AssignOwnerWidget::class, [
            'actionIndex' => 0,
            'payload' => ['recipient_strategy' => 'user', 'user_id' => 42],
            'editorUserId' => $editor->id,
        ])
            ->assertSet('recipient_strategy', 'user')
            ->assertSet('user_id', 42)
            ->call('emit')
            ->assertDispatched('action-payload-updated', function (string $name, array $params): bool {
                $payload = (array) ($params['payload_json'] ?? []);

                return ($payload['user_id'] ?? null) === 42;
            });
    }
}
