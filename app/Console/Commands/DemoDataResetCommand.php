<?php

namespace App\Console\Commands;

use App\Models\DemoDataBatch;
use App\Services\DemoData\DemoDataPurger;
use Illuminate\Console\Command;

class DemoDataResetCommand extends Command
{
    protected $signature = 'demo-data:reset {uuid? : Batch UUID. Defaults to latest active batch} {--delete : Delete instead of reset/regenerate}';

    protected $description = 'Reset or delete a safe CRM demonstration data batch.';

    public function handle(DemoDataPurger $purger): int
    {
        $uuid = $this->argument('uuid');
        $query = DemoDataBatch::query();
        $batch = $uuid ? $query->where('uuid', $uuid)->first() : $query->active()->latest('id')->first();

        if (! $batch instanceof DemoDataBatch) {
            $this->error('No demo data batch found.');
            return self::FAILURE;
        }

        if ((bool) $this->option('delete')) {
            $purger->delete($batch);
            $this->info('Demo data batch deleted: '.$batch->uuid);
            return self::SUCCESS;
        }

        $newBatch = $purger->reset($batch);
        $this->info('Demo data batch reset. New batch: '.$newBatch->uuid);

        return self::SUCCESS;
    }
}
