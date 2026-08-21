<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\ActionWidgets\AddTagWidget;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — AddTagWidget Livewire component.
 *
 * Spec: REQ-ACT-02 (add_tag row).
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 *
 * @see \App\Livewire\Admin\Automations\ActionWidgets\AddTagWidget
 */
class AddTagWidgetLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
    }

    public function test_default_state_has_null_payload_fields(): void
    {
        Livewire::test(AddTagWidget::class, ['actionIndex' => 0, 'payload' => []])
            ->assertSet('tag_slug', null)
            ->assertSet('tag_name', null)
            ->assertSet('color', null);
    }

    public function test_fill_tag_slug_and_emit_dispatches_payload(): void
    {
        Livewire::test(AddTagWidget::class, ['actionIndex' => 0, 'payload' => []])
            ->set('tag_slug', 'hot-lead')
            ->set('tag_name', 'Hot Lead')
            ->set('color', '#ff0000')
            ->call('emit')
            ->assertDispatched('action-payload-updated', function (string $name, array $params): bool {
                $payload = (array) ($params['payload_json'] ?? []);

                return ($params['index'] ?? null) === 0
                    && ($payload['tag_slug'] ?? null) === 'hot-lead'
                    && ($payload['tag_name'] ?? null) === 'Hot Lead'
                    && ($payload['color'] ?? null) === '#ff0000';
            });
    }

    public function test_emit_with_empty_tag_slug_still_dispatches(): void
    {
        // The widget does not gate emit() on empty tag_slug; validation
        // happens server-side via ActionPayloadValidator. The widget should
        // be able to emit a payload even if tag_slug is missing (so admin
        // can see the missing field flagged by the form).
        Livewire::test(AddTagWidget::class, ['actionIndex' => 0, 'payload' => []])
            ->call('emit')
            ->assertDispatched('action-payload-updated');
    }

    public function test_existing_payload_is_loaded_on_mount(): void
    {
        Livewire::test(AddTagWidget::class, [
            'actionIndex' => 0,
            'payload' => ['tag_slug' => 'warm', 'tag_name' => 'Warm', 'color' => 'orange'],
        ])
            ->assertSet('tag_slug', 'warm')
            ->assertSet('tag_name', 'Warm')
            ->assertSet('color', 'orange');
    }
}
