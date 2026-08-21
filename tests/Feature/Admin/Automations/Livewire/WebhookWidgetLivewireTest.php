<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\ActionWidgets\WebhookWidget;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — WebhookWidget Livewire component.
 *
 * Spec: REQ-ACT-05 (webhook allow-list surface), REQ-ACT-06 (B14 stub banner),
 * REQ-ACT-08 (retry_policy_json hidden).
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\WebhookWidget
 */
class WebhookWidgetLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
    }

    public function test_b14_banner_is_present(): void
    {
        Livewire::test(WebhookWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSee('Pendiente (B14)')
            ->assertSee('NotImplementedException');
    }

    public function test_retry_policy_json_is_not_in_rendered_dom(): void
    {
        Livewire::test(WebhookWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertDontSee('retry_policy_json')
            ->assertDontSee('retry_policy');
    }

    public function test_allow_list_is_rendered_as_select_options(): void
    {
        config()->set('integrations.webhooks.allowed_destinations', [
            'https://hooks.example.com/incoming',
            'https://internal.example.net/webhook',
        ]);

        Livewire::test(WebhookWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSee('https://hooks.example.com/incoming')
            ->assertSee('https://internal.example.net/webhook');
    }

    public function test_empty_allow_list_shows_warning_message(): void
    {
        config()->set('integrations.webhooks.allowed_destinations', []);

        Livewire::test(WebhookWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSee('Configure INTEGRATIONS_WEBHOOK_ALLOWED');
    }

    public function test_default_state_loads_empty_payload(): void
    {
        Livewire::test(WebhookWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSet('url', null)
            ->assertSet('method', 'POST')
            ->assertSet('body', null)
            ->assertSet('headers', []);
    }
}
