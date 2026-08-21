<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations\Livewire;

use App\Livewire\Admin\Automations\SimulateButton;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B12-UI — PR 4 / Stage 4 — SimulateButton Livewire component.
 *
 * Spec: REQ-ACT-07 (simulate-now per action). The component owns the modal
 * state and exposes a `simulate()` method that records the last response or
 * error. The test asserts the component state transitions; route-level
 * coverage (real POST to /admin/automations/{rule}/actions/{action}/simulate)
 * is exercised end-to-end in PR 5 + 6.
 *
 * TDD discipline: this file exists BEFORE the production code is authored.
 *
 * @see \App\Livewire\Admin\Automations\SimulateButton
 */
class SimulateButtonLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
    }

    public function test_default_state_has_no_response_or_error(): void
    {
        Livewire::test(SimulateButton::class, [
            'ruleId' => 1,
            'actionId' => null,
            'actionType' => 'add_tag',
        ])
            ->assertSet('responseJson', null)
            ->assertSet('errorClass', null)
            ->assertSet('errorMessage', null)
            ->assertSet('isOpen', false);
    }

    public function test_simulate_method_records_response_json_and_opens_modal(): void
    {
        Livewire::test(SimulateButton::class, [
            'ruleId' => 1,
            'actionId' => null,
            'actionType' => 'add_tag',
        ])
            ->call('simulate', [
                'would_create_tag' => 'hot-lead',
                'color' => 'red',
            ])
            ->assertSet('isOpen', true)
            ->assertSet('responseJson', [
                'would_create_tag' => 'hot-lead',
                'color' => 'red',
            ])
            ->assertSet('errorClass', null);
    }

    public function test_simulate_method_records_error_when_called_with_error(): void
    {
        Livewire::test(SimulateButton::class, [
            'ruleId' => 1,
            'actionId' => null,
            'actionType' => 'webhook',
        ])
            ->call('simulate', [
                'errorClass' => 'WebhookNotAuthorizedException',
                'errorMessage' => 'destination not allowed',
            ])
            ->assertSet('isOpen', true)
            ->assertSet('errorClass', 'WebhookNotAuthorizedException')
            ->assertSet('errorMessage', 'destination not allowed')
            ->assertSet('responseJson', null);
    }
}
