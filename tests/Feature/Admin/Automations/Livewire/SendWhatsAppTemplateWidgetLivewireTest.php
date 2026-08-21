<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\ActionWidgets\SendWhatsAppTemplateWidget;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — SendWhatsAppTemplateWidget Livewire component.
 *
 * Spec: REQ-ACT-02 (send_whatsapp_template row), REQ-ACT-06 (B14 stub banner).
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\SendWhatsAppTemplateWidget
 */
class SendWhatsAppTemplateWidgetLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
    }

    public function test_b14_banner_is_present(): void
    {
        Livewire::test(SendWhatsAppTemplateWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSee('Pendiente (B14)')
            ->assertSee('NotImplementedException');
    }

    public function test_default_state_has_empty_fields(): void
    {
        Livewire::test(SendWhatsAppTemplateWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->assertSet('template_name', null)
            ->assertSet('phone_number', null)
            ->assertSet('language', null)
            ->assertSet('variables', [])
            ->assertSet('account_id', null);
    }

    public function test_emit_dispatches_payload_event(): void
    {
        Livewire::test(SendWhatsAppTemplateWidget::class, [
            'actionIndex' => 0,
            'payload' => [],
        ])
            ->set('template_name', 'welcome')
            ->set('phone_number', '+51999999999')
            ->set('language', 'es')
            ->set('account_id', 'acc-1')
            ->call('emit')
            ->assertDispatched('action-payload-updated', function (string $name, array $params): bool {
                $payload = (array) ($params['payload_json'] ?? []);

                return ($payload['template_name'] ?? null) === 'welcome'
                    && ($payload['phone_number'] ?? null) === '+51999999999'
                    && ($payload['language'] ?? null) === 'es'
                    && ($payload['account_id'] ?? null) === 'acc-1';
            });
    }
}
