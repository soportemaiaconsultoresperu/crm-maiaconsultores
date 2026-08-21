<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the system parameters (docs/BASE_DATOS.md §3.1). Sequence
 * defaults live in CodeSequencesSeeder.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General parameters.
            ['key' => 'prices_include_tax', 'value' => '0', 'type' => 'boolean', 'group' => 'general'],
            ['key' => 'currency_default', 'value' => 'PEN', 'type' => 'string', 'group' => 'general'],
            ['key' => 'date_format', 'value' => 'd/m/Y', 'type' => 'string', 'group' => 'general'],
            ['key' => 'pagination_size', 'value' => '25', 'type' => 'integer', 'group' => 'general'],
            ['key' => 'quote_validity_days', 'value' => '15', 'type' => 'integer', 'group' => 'quotations'],
            // Company parameters (used by quotation PDF header).
            ['key' => 'company.name', 'value' => 'Maia Consultores', 'type' => 'string', 'group' => 'company'],
            ['key' => 'company.tax_id', 'value' => '', 'type' => 'string', 'group' => 'company'],
            ['key' => 'company.address', 'value' => '', 'type' => 'string', 'group' => 'company'],
            ['key' => 'company.phone', 'value' => '', 'type' => 'string', 'group' => 'company'],
            ['key' => 'company.email', 'value' => '', 'type' => 'string', 'group' => 'company'],
            ['key' => 'company.logo_path', 'value' => '', 'type' => 'string', 'group' => 'company'],
            // Outbound notification channel toggles (global gates, checked before per-user prefs).
            // Default false until credentials are wired in .env / OAuth providers.
            ['key' => 'notifications.mail.enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'notifications'],
            ['key' => 'notifications.whatsapp.enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'notifications'],
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
