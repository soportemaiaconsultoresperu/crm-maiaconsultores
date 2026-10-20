<?php

declare(strict_types=1);

namespace App\Support\Storage;

/**
 * A2 — single source of truth for the automatic storage-mode switch.
 *
 * The whole CRM stores user files on AWS S3 only when the three credentials
 * are all present; otherwise it keeps the project's local storage exactly as
 * before. There is no separate flag to flip: `AWS_BUCKET`,
 * `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are the entire switch.
 *
 * The rule lives here once, as a pure function of the three values, so the
 * configuration and any future caller cannot drift into "two of three set"
 * behaviour, and so both directions can be exercised without booting an
 * application. A value counts as absent when it is missing (null), empty,
 * whitespace-only or boolean false.
 */
final class StorageMode
{
    public static function usesAws(mixed $bucket, mixed $accessKeyId, mixed $secretAccessKey): bool
    {
        return self::isPresent($bucket)
            && self::isPresent($accessKeyId)
            && self::isPresent($secretAccessKey);
    }

    private static function isPresent(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        return is_string($value) ? trim($value) !== '' : true;
    }
}
