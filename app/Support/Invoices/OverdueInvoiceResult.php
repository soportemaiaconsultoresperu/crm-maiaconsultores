<?php

namespace App\Support\Invoices;

final readonly class OverdueInvoiceResult
{
    public function __construct(
        public int $scanned,
        public int $updated,
        public int $skipped = 0,
    ) {}
}
