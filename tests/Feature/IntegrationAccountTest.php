<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationAccount;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IntegrationAccount — persistence + encryption round-trip + scope query.
 */
class IntegrationAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_create_persists_a_row_and_encrypts_credentials(): void
    {
        $owner = User::factory()->create(['is_active' => true]);

        $account = IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Soporte — Gmail',
            'owner_id' => $owner->id,
            'is_shared' => false,
            'is_active' => true,
            'test_mode' => true,
        ]);

        $account->setCredentials([
            'client_id' => 'cid-1234',
            'client_secret' => 'shhh-secret',
            'refresh_token' => 'rt-9999',
        ]);
        $account->refresh();

        // Stored ciphertext must not contain the plaintext tokens.
        $raw = $account->getRawOriginal('credentials_encrypted');
        $this->assertStringNotContainsString('shhh-secret', (string) $raw);
        $this->assertStringNotContainsString('rt-9999', (string) $raw);

        // Cast round-trips via Laravel's `encrypted` cast.
        $decoded = $account->credentials_encrypted;
        $this->assertIsArray($decoded);
        $this->assertSame('shhh-secret', $decoded['client_secret']);
        $this->assertSame('rt-9999', $decoded['refresh_token']);
    }

    public function test_active_scope_filters_out_disabled_accounts(): void
    {
        IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'Active',
            'is_active' => true,
            'test_mode' => true,
        ]);
        IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'Disabled',
            'is_active' => false,
            'test_mode' => true,
        ]);

        $rows = IntegrationAccount::query()->active()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Active', $rows->first()->label);
    }

    public function test_team_relationship_is_wired(): void
    {
        $team = Team::create([
            'name' => 'Equipo Comercial',
            'supervisor_id' => User::factory()->create(['is_active' => true])->id,
            'is_active' => true,
        ]);

        $account = IntegrationAccount::create([
            'provider' => 'google_calendar',
            'label' => 'Compartido Comercial',
            'is_shared' => true,
            'team_id' => $team->id,
            'is_active' => true,
            'test_mode' => true,
        ]);

        $this->assertSame($team->id, $account->team->id);
        $this->assertSame('Equipo Comercial', $account->team->name);
    }

    public function test_set_credentials_accepts_plain_string(): void
    {
        $account = IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'SMTP',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $account->setCredentials('raw-token-string');
        $account->refresh();

        $this->assertSame('raw-token-string', $account->credentials_encrypted);
    }
}