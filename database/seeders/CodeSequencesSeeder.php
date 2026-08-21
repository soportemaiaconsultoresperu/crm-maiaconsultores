<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the correlative-sequence configuration defaults (ADR-002).
 *
 * No code_sequences rows are pre-created: they are created lazily by
 * CodeGeneratorService inside its locking transaction on first use of
 * each entity/year.
 */
class CodeSequencesSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'seq.lead.prefix', 'value' => 'LEAD', 'type' => 'string', 'group' => 'sequences'],
            ['key' => 'seq.customer.prefix', 'value' => 'CLI', 'type' => 'string', 'group' => 'sequences'],
            ['key' => 'seq.opportunity.prefix', 'value' => 'OPP', 'type' => 'string', 'group' => 'sequences'],
            ['key' => 'seq.quotation.prefix', 'value' => 'COT', 'type' => 'string', 'group' => 'sequences'],
            ['key' => 'seq.quotation.pad_length', 'value' => '5', 'type' => 'integer', 'group' => 'sequences'],
            ['key' => 'seq.product.prefix', 'value' => 'PROD', 'type' => 'string', 'group' => 'sequences'],
            ['key' => 'seq.product.pad_length', 'value' => '5', 'type' => 'integer', 'group' => 'sequences'],
            ['key' => 'seq.pad_length', 'value' => '5', 'type' => 'integer', 'group' => 'sequences'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'group' => $setting['group'],
                ]
            );
        }
    }
}
