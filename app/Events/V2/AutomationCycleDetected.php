<?php

declare(strict_types=1);

namespace App\Events\V2;

/**
 * B17 / D-21c — Mandatory trigger: the B12 automation engine's `CycleDetector`
 * recorded a cycle on a rule. Emitted from B12's `CycleDetector::recordBreak()`.
 *
 * Listeners decide what to do (default: email all admin users; v1 does not
 * ship a UI listener, only the typed event contract).
 */
final class AutomationCycleDetected
{
    public function __construct(
        public readonly int $ruleId,
        public readonly int $cycleBreakCount,
    ) {
    }
}
