<?php

namespace App\Support;

/**
 * Summary of a lead Excel import run (RF-LEAD-007). Reasons are user-facing
 * Spanish strings: they are shown verbatim in the import error report.
 */
class ImportResult
{
    public int $created = 0;

    public int $skipped = 0;

    public int $invalid = 0;

    /**
     * Report rows for non-created input rows, in input order.
     *
     * @var list<array{row: int, status: string, reason: string, matched_lead_code: ?string}>
     */
    public array $rows = [];

    /**
     * Total data rows read from the file.
     */
    public int $total = 0;

    public function markCreated(): void
    {
        $this->created++;
    }

    public function markSkipped(int $row, string $reason, ?string $matchedLeadCode = null): void
    {
        $this->skipped++;

        $this->rows[] = [
            'row' => $row,
            'status' => 'skipped',
            'reason' => $reason,
            'matched_lead_code' => $matchedLeadCode,
        ];
    }

    public function markInvalid(int $row, string $reason): void
    {
        $this->invalid++;

        $this->rows[] = [
            'row' => $row,
            'status' => 'invalid',
            'reason' => $reason,
            'matched_lead_code' => null,
        ];
    }
}
