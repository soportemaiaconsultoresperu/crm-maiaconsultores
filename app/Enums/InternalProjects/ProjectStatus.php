<?php

declare(strict_types=1);

namespace App\Enums\InternalProjects;

/**
 * Lifecycle of an internal project (S2). Informative only: the progress
 * percentage comes from the task statuses, never from this value.
 *
 * Stored as a plain VARCHAR (`internal_projects.status`); the cast lives on
 * the InternalProject model.
 */
enum ProjectStatus: string
{
    case Planned = 'planificado';
    case InProgress = 'en_curso';
    case OnHold = 'en_pausa';
    case Finished = 'terminado';
    case Cancelled = 'cancelado';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => Spanish label, in lifecycle order
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planificado',
            self::InProgress => 'En curso',
            self::OnHold => 'En pausa',
            self::Finished => 'Terminado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Bootstrap badge class for the status chip.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Planned => 'text-bg-secondary',
            self::InProgress => 'text-bg-primary',
            self::OnHold => 'text-bg-warning',
            self::Finished => 'text-bg-success',
            self::Cancelled => 'text-bg-dark',
        };
    }
}
