<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rule execution mode.
 *
 * - live: actions are dispatched to RunAutomationAction jobs and external
 *   side effects (emails, webhooks) actually fire.
 * - test: actions are recorded with status=simulated and the would-be payload
 *   is stored in response_json. No external call is made.
 */
final class AutomationMode
{
    public const LIVE = 'live';
    public const TEST = 'test';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::LIVE, self::TEST];
    }
}