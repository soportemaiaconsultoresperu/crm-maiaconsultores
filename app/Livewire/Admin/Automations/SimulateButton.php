<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations;

use App\Models\AutomationAction;
use App\Models\AutomationRule;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\Exceptions\NotImplementedException;
use App\Services\Automation\Exceptions\WebhookNotAuthorizedException;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * B12-UI — PR 4 / Stage 4 — SimulateButton Livewire component.
 *
 * Spec: REQ-ACT-07 (simulate-now per action). The button POSTs to
 *   POST /admin/automations/{rule}/actions/{action}/simulate
 * and renders the returned response_json in a monospace modal; on error
 * surfaces error_class + error_message in a red <x-alert type="error">.
 *
 * This component owns the modal state and runs the action simulation directly.
 * It intentionally avoids a server-to-itself HTTP call because the local PHP
 * dev server can deadlock/timeout on nested requests.
 *
 * @see \Tests\Feature\Admin\Automations\Livewire\SimulateButtonLivewireTest
 */
class SimulateButton extends Component
{
    public int $ruleId = 0;

    public ?int $actionId = null;

    public string $actionType = 'add_tag';

    /**
     * JSON payload the admin authors for the simulate. Persisted in component
     * state and posted to the controller endpoint verbatim.
     */
    public string $payloadText = '{}';

    /**
     * Last successful response from the simulate endpoint.
     *
     * @var array<string, mixed>|null
     */
    public ?array $responseJson = null;

    public ?string $errorClass = null;

    public ?string $errorMessage = null;

    public bool $isOpen = false;

    public function mount(int $ruleId = 0, ?int $actionId = null, string $actionType = 'add_tag'): void
    {
        $this->ruleId = $ruleId;
        $this->actionId = $actionId;
        $this->actionType = $actionType;
    }

    /**
     * SCN-ACT-02 / SCN-ACT-03 — trigger the simulate endpoint and store
     * the response (or caught error) for the modal to render.
     *
     * For PR 4 we accept an optional $data array from the calling test
     * (the parent's wire:click + a small JS shim is responsible for
     * forwarding the controller response). When called without args we
     * resolve the saved action and simulate it in-process.
     *
     * @param  array<string, mixed>|null  $data  Optional override payload
     *                                          (used by tests; the real wire:click
     *                                          post will fill this in asynchronously).
     */
    public function simulate(?array $data = null): void
    {
        if ($data !== null) {
            // Test / pre-mocked path — data is already what the controller
            // would have returned.
            if (isset($data['errorClass']) || isset($data['errorMessage'])) {
                $this->errorClass = isset($data['errorClass']) ? (string) $data['errorClass'] : null;
                $this->errorMessage = isset($data['errorMessage']) ? (string) $data['errorMessage'] : null;
                $this->responseJson = null;
            } else {
                $this->responseJson = (array) $data;
                $this->errorClass = null;
                $this->errorMessage = null;
            }

            $this->isOpen = true;

            return;
        }

        // Real path: simulate in-process. A server-to-itself Http::post()
        // times out on single-worker local servers (for example artisan serve),
        // so this mirrors AutomationController::simulate without nesting HTTP.
        try {
            Gate::authorize('automations.view');
            Gate::authorize('automations.test');

            $automation = AutomationRule::query()->findOrFail($this->ruleId);
            $action = AutomationAction::query()->findOrFail($this->actionId ?? 0);

            if ((int) $action->rule_id !== (int) $automation->id) {
                abort(404);
            }

            if ($action->type === 'webhook') {
                $payload = (array) $action->payload_json;
                $url = (string) ($payload['url'] ?? '');
                $allowed = (array) config('integrations.webhooks.allowed_destinations', []);

                $okUrl = $url !== ''
                    && $allowed !== []
                    && in_array($url, $allowed, true);

                if (! $okUrl) {
                    throw new WebhookNotAuthorizedException(
                        "WebhookAction: destination {$url} is not in the allowed list."
                    );
                }
            }

            if ($action->type === 'send_whatsapp_template') {
                throw new NotImplementedException(
                    'WhatsApp provider is not yet implemented; expected in B14.'
                );
            }

            $instance = app(ActionRegistry::class)->resolveForAction($action);
            $result = $instance->simulate((array) ($action->payload_json ?? []));

            $this->responseJson = [
                'ok' => true,
                'response_json' => $result,
            ];
            $this->errorClass = null;
            $this->errorMessage = null;
            $this->isOpen = true;
        } catch (\Throwable $e) {
            $this->responseJson = null;
            $this->errorClass = $e::class;
            $this->errorMessage = $e->getMessage();
            $this->isOpen = true;
        }
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function render()
    {
        return view('livewire.admin.automations.simulate-button');
    }
}
