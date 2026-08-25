<?php

namespace App\Console\Commands;

use App\Services\OverdueInvoiceProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:mark-overdue {--date= : Process invoices overdue before this YYYY-MM-DD date}';

    protected $description = 'Persist active chargeable past-due customer invoices as overdue';

    public function handle(OverdueInvoiceProcessor $processor): int
    {
        $date = $this->option('date');
        $today = null;

        if ($date !== null && $date !== '') {
            try {
                $today = CarbonImmutable::createFromFormat('!Y-m-d', (string) $date)->startOfDay();
            } catch (\Throwable) {
                $this->error('The --date option must use YYYY-MM-DD.');

                return self::FAILURE;
            }
        }

        $result = $processor->process(today: $today);

        $this->info("Customer invoices marked as overdue: {$result->updated}");

        return self::SUCCESS;
    }
}
