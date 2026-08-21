<?php

declare(strict_types=1);

namespace App\Contracts\Automation;

use App\Models\AutomationExecutionStep;

/**
 * Contract every automation action must implement.
 *
 * `execute()` runs in a ShouldQueue Job (B12). `simulate()` returns a
 * descriptive array for test mode — the listener stores it in
 * `response_json` of the execution_step.
 */
interface ActionContract
{
    /**
     * Perform the action against the subject of the event.
     *
     * @param  array<string, mixed>  $payload  action-specific configuration
     *                                        stored in automation_actions.payload_json
     *
     * @throws \Throwable on any failure — the listener catches and records
     *                   the error in the execution_step.
     */
    public function execute(array $payload, AutomationExecutionStep $step): void;

    /**
     * Dry-run description of the action. Returned to the engine when the
     * rule is in `mode=test`. MUST NOT trigger any external side effect.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function simulate(array $payload): array;
}