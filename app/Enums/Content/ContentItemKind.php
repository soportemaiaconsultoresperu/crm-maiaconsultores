<?php

declare(strict_types=1);

namespace App\Enums\Content;

/**
 * Single source of truth for the content-item discriminator.
 *
 * A `Content` is a planned piece with an editorial date; an `Idea` is a bare
 * concept that may be saved with no planned instant and later converted into a
 * content. Nothing else in the module enumerates these two states, and the
 * conversion itself belongs to a later work unit.
 *
 * The stored value is a plain VARCHAR (`kind`), matching the repository
 * convention for vocabulary enums; the cast lives on the `ContentItem` model.
 */
enum ContentItemKind: string
{
    case Content = 'content';
    case Idea = 'idea';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Content => 'Contenido',
            self::Idea => 'Idea',
        };
    }
}
