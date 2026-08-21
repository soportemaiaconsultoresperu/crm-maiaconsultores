<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B14 Pasada B-2 — whatsapp:sync-templates console command tests.
 *
 * Covers:
 *  - `--account=N` syncs only the requested account.
 *  - `--all` syncs every WhatsAppAccount row.
 *  - The command exits 0 on success.
 */
class SyncWhatsAppTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_option_syncs_only_that_account(): void
    {
        $accountA = $this->makeAccount('+15551111111', 'acct-a');
        $accountB = $this->makeAccount('+15552222222', 'acct-b');

        // Stub the Graph API so the provider returns one approved template per account.
        Http::fake([
            '*graph.facebook.com*' => Http::response([
                'data' => [
                    [
                        'id' => 'welcome_a',
                        'name' => 'welcome',
                        'language' => 'es_PE',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hola A'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('whatsapp:sync-templates', ['--account' => [$accountA->id]]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('1 plantilla(s) aprobada(s)', $output);
        $this->assertStringContainsString('Cuenta '.$accountA->id, $output);

        $this->assertSame(1, WhatsAppTemplate::query()->where('account_id', $accountA->id)->count());
        $this->assertSame(0, WhatsAppTemplate::query()->where('account_id', $accountB->id)->count());
    }

    public function test_all_option_syncs_every_account(): void
    {
        $accountA = $this->makeAccount('+15551111111', 'acct-a');
        $accountB = $this->makeAccount('+15552222222', 'acct-b');

        Http::fake([
            '*graph.facebook.com*' => Http::response([
                'data' => [
                    [
                        'id' => 'welcome',
                        'name' => 'welcome',
                        'language' => 'es_PE',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hola {{1}}'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('whatsapp:sync-templates', ['--all' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('2 plantilla(s) en total', $output);

        $this->assertSame(1, WhatsAppTemplate::query()->where('account_id', $accountA->id)->count());
        $this->assertSame(1, WhatsAppTemplate::query()->where('account_id', $accountB->id)->count());
    }

    public function test_command_exits_zero_on_success(): void
    {
        $this->makeAccount('+15551111111', 'acct-success');

        // Stub mode returns [] for fetchTemplates so the sync is a no-op,
        // but the command itself must still exit 0.
        $exitCode = Artisan::call('whatsapp:sync-templates', ['--all' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_exits_invalid_when_no_account_or_all_given(): void
    {
        $exitCode = Artisan::call('whatsapp:sync-templates');
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--account', $output);
    }

    private function makeAccount(string $phoneNumber, string $phoneNumberId): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'phone_number' => $phoneNumber,
            'phone_number_id' => $phoneNumberId,
            'business_id' => 'test-token',
            'display_name' => 'Account '.$phoneNumberId,
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);
        $account->save();

        return $account;
    }
}