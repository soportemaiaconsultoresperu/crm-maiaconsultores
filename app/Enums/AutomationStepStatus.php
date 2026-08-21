<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for an AutomationExecutionStep row.
 *
 * @see AutomationExecutionStatus for the same plain-VARCHAR rationale.
 */
final class AutomationStepStatus
{
    public const PENDING = 'pending';
    public const SIMULATED = 'simulated';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PENDING,
            self::SIMULATED,
            self::RUNNING,
            self::SUCCESS,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    public static function label(string $value): string
    {
        return match ($value) {
            self::PENDING => 'Pendiente',
            self::SIMULATED => 'Simulado',
            self::RUNNING => 'Ejecutando',
            self::SUCCESS => 'Exitoso',
            self::FAILED => 'Fallido',
            self::SKIPPED => 'Omitido',
            default => $value,
        };
    }

    public static function isTerminal(string $value): bool
    {
        return in_array($value, [
            self::SIMULATED,
            self::SUCCESS,
            self::FAILED,
            self::SKIPPED,
        ], true);
    }
}