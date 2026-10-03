<?php

declare(strict_types=1);

namespace App\Enums\Content;

/**
 * Single source of truth for the editorial status vocabulary.
 *
 * Exactly the four states the specification lists, in its order, with their
 * Spanish labels. No transition graph is defined here because the
 * specification does not state one: later work units decide when a status may
 * change. `isPublished()` is the only classification the grid and the overdue
 * rule need.
 *
 * The stored value is a plain VARCHAR (`status`), matching the repository
 * convention for vocabulary enums; the cast lives on the `ContentItem` model.
 */
enum ContentItemStatus: string
{
    case Incomplete = 'incomplete';
    case InProgress = 'in_progress';
    case CompletePendingPublish = 'complete_pending_publish';
    case Published = 'published';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value => Spanish label, in specification order. Ready for select inputs.
     *
     * @return array<string, string>
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
            self::Incomplete => 'Incompleto',
            self::InProgress => 'En proceso',
            self::CompletePendingPublish => 'Completo pero falta publicar',
            self::Published => 'Publicado',
        };
    }

    /**
     * Whether this status counts as published. The overdue criterion relies on
     * it so a published content never shows as late.
     */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
