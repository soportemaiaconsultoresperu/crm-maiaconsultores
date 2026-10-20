<?php

declare(strict_types=1);

namespace App\Support\Content;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Explicit America/Lima <-> UTC conversion for content scheduling.
 *
 * The application is fixed to UTC (`config/app.php` hardcodes it, and
 * `APP_TIMEZONE` has no effect) while the editorial team enters and reads
 * wall-clock publication times in Peru. This helper is the single conversion
 * point of the module, so the model, services, tests and later grids all agree
 * on one interpretation of a planned instant.
 *
 * Both the offset and the weekday are derived from the IANA timezone rather
 * than from a hardcoded `-05:00`: Peru observes no daylight saving today, but
 * this class never relies on that as a hidden assumption and stays correct if
 * the rules ever change.
 */
final class PeruTime
{
    public const TIMEZONE = 'America/Lima';

    /**
     * Interpret a wall-clock moment as Peru local time and return the UTC
     * instant that is stored in the database.
     */
    public static function toUtc(DateTimeInterface|string $peruMoment): CarbonImmutable
    {
        return CarbonImmutable::parse($peruMoment, self::TIMEZONE)->utc();
    }

    /**
     * Interpret a stored UTC instant and return it on the Peru clock.
     */
    public static function toPeru(DateTimeInterface|string $utcMoment): CarbonImmutable
    {
        return CarbonImmutable::parse($utcMoment)->setTimezone(self::TIMEZONE);
    }

    /**
     * ISO-8601 weekday of the Peru calendar day: 1 = Monday .. 7 = Sunday.
     */
    public static function weekday(DateTimeInterface|string $utcMoment): int
    {
        return self::toPeru($utcMoment)->dayOfWeekIso;
    }

    /**
     * Spanish weekday name of the Peru calendar day.
     */
    public static function weekdayLabel(DateTimeInterface|string $utcMoment): string
    {
        return match (self::weekday($utcMoment)) {
            1 => 'lunes',
            2 => 'martes',
            3 => 'miércoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sábado',
            7 => 'domingo',
        };
    }

    /**
     * Calendar date (Y-m-d) the stored instant falls on in Peru.
     */
    public static function date(DateTimeInterface|string $utcMoment): string
    {
        return self::toPeru($utcMoment)->toDateString();
    }

    /**
     * Display date (d/m/Y) of the Peru calendar day, the format every screen
     * and export of the module shows.
     */
    public static function displayDate(DateTimeInterface|string $utcMoment): string
    {
        return self::toPeru($utcMoment)->format('d/m/Y');
    }

    /**
     * Display date and time (d/m/Y H:i) on the Peru clock.
     */
    public static function displayDateTime(DateTimeInterface|string $utcMoment): string
    {
        return self::toPeru($utcMoment)->format('d/m/Y H:i');
    }
}
