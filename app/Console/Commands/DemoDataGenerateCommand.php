<?php

namespace App\Console\Commands;

use App\Services\DemoData\DemoDataDependencyPreview;
use App\Services\DemoData\DemoDataGenerator;
use Illuminate\Console\Command;

class DemoDataGenerateCommand extends Command
{
    protected $signature = 'demo-data:generate {--module=* : Module(s) to generate}';

    protected $description = 'Generate safe CRM demonstration data registered in the demo ledger.';

    public function handle(DemoDataGenerator $generator): int
    {
        $modules = (array) $this->option('module');
        $batch = $generator->generate($modules ?: DemoDataDependencyPreview::ALL_MODULES);

        $this->info('Demo data batch generated: '.$batch->uuid);

        return self::SUCCESS;
    }
}
