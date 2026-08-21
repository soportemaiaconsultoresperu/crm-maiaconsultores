<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for an AutomationExecution row.
 *
 * Final-class with constant string values (rather than a BackedEnum) so the
 * database stores plain VARCHAR values without case-sensitivity surprises —
 * a BackedEnum cast in the model serialises by name, and we want the DB
 * column to be portable across environments without renaming a case.
 *
 * Per C-03: NO MySQL ENUM. The column type is VARCHAR(16) and the values
 * below are the authoritative list.
 *
 * @see docs/v2/01-roadmap.md §2.1
 */
final class AutomationExecutionStatus
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const CIRCUIT_BROKEN = 'circuit-broken';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::QUEUED,
            self::RUNNING,
            self::SUCCESS,
            self::PARTIAL,
            self::FAILED,
            self::SKIPPED,
            self::CIRCUIT_BROKEN,
        ];
    }

    /**
     * UI-friendly Spanish label. Kept stable because the admin views (B12-UI)
     * will reuse the same strings.
     */
    public static function label(string $value): string
    {
        return match ($value) {
            self::QUEUED => 'En cola',
            self::RUNNING => 'Ejecutando',
            self::SUCCESS => 'Exitoso',
            self::PARTIAL => 'Parcial',
            self::FAILED => 'Fallido',
            self::SKIPPED => 'Omitido',
            self::CIRCUIT_BROKEN => 'Ciclo roto',
            default => $value,
        };
    }

    public static function isTerminal(string $value): bool
    {
        return in_array($value, [
            self::SUCCESS,
            self::PARTIAL,
            self::FAILED,
            self::SKIPPED,
            self::CIRCUIT_BROKEN,
        ], true);
    }
}