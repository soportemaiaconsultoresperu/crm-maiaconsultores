<?php

declare(strict_types=1);

namespace App\Enums\InternalProjects;

/**
 * Status of an internal project task (S3). Only `Done` counts towards the
 * progress percentage, and a done task is never overdue.
 *
 * Stored as a plain VARCHAR (`internal_project_tasks.status`); the cast lives
 * on the InternalProjectTask model.
 */
enum TaskStatus: string
{
    case Pending = 'pendiente';
    case InProgress = 'en_curso';
    case Blocked = 'bloqueada';
    case Done = 'terminada';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => Spanish label
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
            self::Pending => 'Pendiente',
            self::InProgress => 'En curso',
            self::Blocked => 'Bloqueada',
            self::Done => 'Terminada',
        };
    }

    /**
     * Bootstrap badge class for the status chip.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-secondary',
            self::InProgress => 'text-bg-primary',
            self::Blocked => 'text-bg-warning',
            self::Done => 'text-bg-success',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
