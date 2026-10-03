<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Support\Storage\StorageMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A2 — the automatic storage-mode rule.
 *
 * This is intentionally a pure PHPUnit test with no framework boot: the
 * decision must be provable in both directions from the three credential
 * values alone, before any application or disk is involved.
 */
class StorageModeTest extends TestCase
{
    public function test_all_three_credentials_present_enables_aws(): void
    {
        $this->assertTrue(StorageMode::usesAws('crm-files', 'AKIAEXAMPLE', 'secret'));
    }

    public function test_credentials_with_surrounding_whitespace_still_count_as_present(): void
    {
        $this->assertTrue(StorageMode::usesAws(' crm-files ', "\tAKIAEXAMPLE\n", ' secret '));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
     */
    public static function incompleteCredentials(): array
    {
        return [
            'missing bucket' => [null, 'AKIAEXAMPLE', 'secret'],
            'missing access key' => ['crm-files', null, 'secret'],
            'missing secret' => ['crm-files', 'AKIAEXAMPLE', null],
            'empty bucket' => ['', 'AKIAEXAMPLE', 'secret'],
            'empty access key' => ['crm-files', '', 'secret'],
            'empty secret' => ['crm-files', 'AKIAEXAMPLE', ''],
            'whitespace-only bucket' => ['   ', 'AKIAEXAMPLE', 'secret'],
            'whitespace-only access key' => ['crm-files', "\t\n", 'secret'],
            'whitespace-only secret' => ['crm-files', 'AKIAEXAMPLE', '  '],
            'all missing' => [null, null, null],
            'all empty' => ['', '', ''],
            'all whitespace-only' => ['  ', "\t", "\n"],
            'boolean false bucket' => [false, 'AKIAEXAMPLE', 'secret'],
        ];
    }

    #[DataProvider('incompleteCredentials')]
    public function test_any_missing_empty_or_blank_value_keeps_local_storage(
        mixed $bucket,
        mixed $accessKeyId,
        mixed $secretAccessKey,
    ): void {
        $this->assertFalse(StorageMode::usesAws($bucket, $accessKeyId, $secretAccessKey));
    }
}
