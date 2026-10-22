<?php

namespace App\Exports\Content;

/**
 * Spreadsheet-injection guard for exported cells (T10).
 *
 * A spreadsheet treats a cell as a formula when its first character is one of
 * the classic formula introducers `=`, `+`, `-`, `@`, or a leading tab (`\t`,
 * 0x09) / carriage return (`\r`, 0x0D), which can be used to smuggle a formula
 * past a naive prefix check. Anything a user typed must never execute when the
 * exported file is opened, so this guard prepends a single apostrophe (`'`) to
 * any string that starts with one of those characters. A leading apostrophe
 * forces the cell to be read as text; it is the OWASP-recommended
 * neutralisation for formula injection.
 *
 * Non-strings (numbers, nulls, booleans) pass through untouched, so a real
 * number stays a number in the sheet.
 */
final class ContentCellSanitizer
{
    /**
     * Characters that turn a leading position into formula syntax.
     *
     * @var list<string>
     */
    public const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'".$value;
        }

        return $value;
    }
}
