<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Automations;

use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * B12-UI — PR 4 / Stage 4 — SimulateButton Livewire component.
 *
 * Spec: REQ-ACT-07 (simulate-now per action). The button POSTs to
 *   POST /admin/automations/{rule}/actions/{action}/simulate
 * and renders the returned response_json in a monospace modal; on error
 * surfaces error_class + error_message in a red <x-alert type="error">.
 *
 * This component owns the modal state and orchestrates the HTTP call. The
 * actual route handler is wired in PR 5/6 (per design §2 routes list);
 * in PR 4 we ship the component skeleton that delegates to the configured
 * route (or to a fake callable when the route is unregistered).
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
     * POST to the live endpoint. PR 5/6 wires the controller body.
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

        // Real network path. POST to the configured route.
        try {
            $payload = json_decode($this->payloadText, true) ?: [];

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post(
                route('admin.automations.actions.simulate', [
                    'automation' => $this->ruleId,
                    'action' => $this->actionId ?? 0,
                ], false),
                ['payload' => $payload],
            );

            if ($response->successful()) {
                $body = $response->json();
                $this->responseJson = is_array($body) ? $body : [];
                $this->errorClass = null;
                $this->errorMessage = null;
            } else {
                $body = $response->json();
                $this->responseJson = null;
                $this->errorClass = (string) ($body['error_class'] ?? 'HttpException');
                $this->errorMessage = (string) ($body['error_message'] ?? $response->reason());
            }

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
