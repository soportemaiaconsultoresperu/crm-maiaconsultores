<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsApp\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;

/**
 * B14 Pasada B-2 — Manual sync of WhatsApp templates from Meta.
 *
 * Per docs/v2/01-roadmap.md §7 decision 15a: "Plantillas — Sincronización
 * desde Meta". The CRM never authors templates (decision 15d); this command
 * pulls the current approved catalogue for one or all accounts and upserts
 * it into `whatsapp_templates`. Only `status === 'APPROVED'` templates are
 * persisted (decision 15c).
 *
 * Usage:
 *   php artisan whatsapp:sync-templates --all
 *   php artisan whatsapp:sync-templates --account=1 --account=2
 *
 * The command calls {@see WhatsAppService::syncTemplates()} synchronously
 * (no queue job) so an operator running this from the admin UI gets
 * immediate feedback on the count of templates pulled.
 */
class SyncWhatsAppTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * `--account=` may be repeated; `--all` overrides and syncs every
     * {@see WhatsAppAccount} row.
     *
     * @var string
     */
    protected $signature = 'whatsapp:sync-templates
                            {--account=* : ID de la cuenta a sincronizar (repetible)}
                            {--all : Sincroniza todas las cuentas registradas}';

    protected $description = 'Sincroniza las plantillas aprobadas de Meta WhatsApp Cloud API.';

    public function handle(WhatsAppService $service): int
    {
        $all = (bool) $this->option('all');
        $accountIds = $this->option('account');

        if (! $all && $accountIds === []) {
            $this->error('Debe especificar --account=ID (repetible) o --all.');

            return self::INVALID;
        }

        if ($all) {
            $accounts = WhatsAppAccount::query()->orderBy('id')->get();
        } else {
            $accounts = WhatsAppAccount::query()
                ->whereIn('id', array_map('intval', $accountIds))
                ->orderBy('id')
                ->get();

            $expected = array_map('intval', $accountIds);
            $found = $accounts->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($expected, $found);
            if ($missing !== []) {
                $this->warn('Cuentas no encontradas: '.implode(', ', $missing));
            }
        }

        if ($accounts->isEmpty()) {
            $this->info('No hay cuentas para sincronizar.');

            return self::SUCCESS;
        }

        $totalSynced = 0;

        foreach ($accounts as $account) {
            $count = $service->syncTemplates($account);
            $totalSynced += $count;

            $this->line(sprintf(
                'Cuenta %d (%s) — %d plantilla(s) aprobada(s).',
                (int) $account->getKey(),
                (string) ($account->display_name ?: $account->phone_number),
                $count,
            ));
        }

        $this->info(sprintf('Sincronización completada. %d plantilla(s) en total.', $totalSynced));

        return self::SUCCESS;
    }
}