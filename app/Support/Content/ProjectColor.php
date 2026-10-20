<?php

declare(strict_types=1);

namespace App\Support\Content;

/**
 * The content project colour as visual identity (S1).
 *
 * A project stores an optional `#rrggbb` hex. Every surface that paints with it
 * goes through this helper, so a project without colour (or a legacy value the
 * current validation would reject) falls back to one neutral default instead of
 * producing broken inline styles, and the text drawn on top of the colour is
 * always the readable one by WCAG contrast.
 */
final class ProjectColor
{
    /** Neutral colour for projects without one. */
    public const DEFAULT = '#6c757d';

    /** Text colour used on light backgrounds (Bootstrap body colour). */
    public const DARK_TEXT = '#212529';

    public const LIGHT_TEXT = '#ffffff';

    /** Share of the project colour mixed into white for soft backgrounds. */
    public const TINT_RATIO = 0.15;

    /**
     * Preset palette offered by the project form, hex => Spanish label.
     *
     * @var array<string, string>
     */
    public const PALETTE = [
        '#1d4ed8' => 'Azul',
        '#0891b2' => 'Celeste',
        '#0d9488' => 'Turquesa',
        '#16a34a' => 'Verde',
        '#ca8a04' => 'Mostaza',
        '#ea580c' => 'Naranja',
        '#dc2626' => 'Rojo',
        '#db2777' => 'Rosado',
        '#7c3aed' => 'Morado',
        '#475569' => 'Pizarra',
    ];

    /**
     * The lowercase `#rrggbb` form of a valid colour, or null for anything else.
     */
    public static function normalize(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = strtolower(trim($color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    /**
     * The colour to paint with: the project's own, or the neutral default.
     */
    public static function resolve(mixed $color): string
    {
        return self::normalize($color) ?? self::DEFAULT;
    }

    /**
     * White or dark text, whichever contrasts more with the given background.
     */
    public static function textColor(mixed $background): string
    {
        $luminance = self::luminance(self::resolve($background));

        $contrastWithWhite = 1.05 / ($luminance + 0.05);
        $contrastWithDark = ($luminance + 0.05) / (self::luminance(self::DARK_TEXT) + 0.05);

        return $contrastWithWhite >= $contrastWithDark ? self::LIGHT_TEXT : self::DARK_TEXT;
    }

    /**
     * A light tint of the colour (mixed into white) for soft backgrounds.
     */
    public static function tint(mixed $color, float $ratio = self::TINT_RATIO): string
    {
        $ratio = max(0.0, min(1.0, $ratio));

        $channels = array_map(
            fn (int $channel): int => (int) round($channel * $ratio + 255 * (1 - $ratio)),
            self::channels(self::resolve($color)),
        );

        return vsprintf('#%02x%02x%02x', $channels);
    }

    /**
     * Inline style for a solid colour chip (badge, marker) with readable text.
     */
    public static function solidStyle(mixed $color): string
    {
        $resolved = self::resolve($color);

        return 'background-color: '.$resolved.'; color: '.self::textColor($resolved).';';
    }

    /**
     * Inline style for a soft card: tinted background, coloured left border and
     * readable text on the tint.
     */
    public static function softStyle(mixed $color): string
    {
        $resolved = self::resolve($color);
        $tint = self::tint($resolved);

        return 'background-color: '.$tint.'; border-left-color: '.$resolved.'; color: '.self::textColor($tint).';';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function channels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * WCAG relative luminance of a `#rrggbb` colour.
     */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
