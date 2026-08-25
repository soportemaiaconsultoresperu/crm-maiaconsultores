<?php

namespace Tests\Feature;

use App\Models\IntegrationAccount;
use App\Models\OAuthState;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIntegrationOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('integrations.google_oauth.client_id', 'google-client-id');
        config()->set('integrations.google_oauth.client_secret', 'google-client-secret');
        config()->set('integrations.google_oauth.redirect_uri', 'https://crm.test/account/integrations/google/callback');
        config()->set('integrations.google_oauth.auth_uri', 'https://accounts.google.test/o/oauth2/v2/auth');
        config()->set('integrations.google_oauth.token_uri', 'https://oauth2.google.test/token');
        config()->set('integrations.google_oauth.userinfo_uri', 'https://googleapis.test/oauth2/v3/userinfo');
        config()->set('integrations.google_oauth.revoke_uri', 'https://oauth2.google.test/revoke');
    }

    public function test_integrations_page_shows_google_services_not_connected(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get('/account/integrations')
            ->assertOk()
            ->assertSee('Gmail')
            ->assertSee('Google Calendar')
            ->assertSee('Conectar Gmail')
            ->assertSee('Conectar Google Calendar')
            ->assertSee('No conectado');
    }

    public function test_oauth_start_creates_state_and_redirects_to_google(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)
            ->post('/account/integrations/google/gmail/connect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.test/o/oauth2/v2/auth?', (string) $location);
        $this->assertStringContainsString('scope=openid%20email%20profile%20https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fgmail.send', (string) $location);
        $this->assertStringContainsString('access_type=offline', (string) $location);
        $this->assertDatabaseCount('oauth_states', 1);

        $state = OAuthState::query()->firstOrFail();
        $this->assertSame('google', $state->provider);
        $this->assertSame($user->id, $state->payload_json['user_id']);
        $this->assertSame('gmail', $state->payload_json['service']);
        $this->assertNull($state->consumed_at);
    }

    public function test_callback_rejects_invalid_expired_and_consumed_state(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get('/account/integrations/google/callback?state=missing&code=abc')
            ->assertRedirect('/account/integrations')
            ->assertSessionHas('error');

        $expired = $this->stateFor($user, ['expires_at' => now()->subMinute()]);
        $this->actingAs($user)
            ->get('/account/integrations/google/callback?state='.$expired->state.'&code=abc')
            ->assertRedirect('/account/integrations')
            ->assertSessionHas('error');

        $consumed = $this->stateFor($user, ['consumed_at' => now()]);
        $this->actingAs($user)
            ->get('/account/integrations/google/callback?state='.$consumed->state.'&code=abc')
            ->assertRedirect('/account/integrations')
            ->assertSessionHas('error');
    }

    public function test_successful_callback_creates_one_google_account_with_encrypted_credentials_and_service_flag(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $state = $this->stateFor($user, ['payload_json' => [
            'user_id' => $user->id,
            'service' => 'gmail',
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.send'],
        ]]);

        Http::fake([
            'https://oauth2.google.test/token' => Http::response([
                'access_token' => 'access-token-1',
                'refresh_token' => 'refresh-token-1',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid email profile https://www.googleapis.com/auth/gmail.send',
            ]),
            'https://googleapis.test/oauth2/v3/userinfo' => Http::response([
                'email' => 'seller@example.com',
                'name' => 'Seller Example',
            ]),
        ]);

        $this->actingAs($user)
            ->get('/account/integrations/google/callback?state='.$state->state.'&code=code-1')
            ->assertRedirect('/account/integrations')
            ->assertSessionHas('status');

        $account = IntegrationAccount::query()->where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('google', $account->provider);
        $this->assertTrue($account->is_active);
        $this->assertSame($user->id, $account->google_active_owner_id);
        $this->assertSame('seller@example.com', $account->config_json['google_account_email']);
        $this->assertTrue($account->config_json['services']['gmail']);
        $this->assertFalse($account->config_json['services']['calendar']);
        $this->assertContains('https://www.googleapis.com/auth/gmail.send', $account->scopes);
        $this->assertSame('refresh-token-1', $account->credentials_encrypted['refresh_token']);

        $raw = DB::table('integration_accounts')->where('id', $account->id)->value('credentials_encrypted');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('refresh-token-1', $raw);
        $this->assertNotNull($state->refresh()->consumed_at);
    }

    public function test_incremental_auth_preserves_existing_refresh_token_when_google_omits_one(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Google Workspace — seller@example.com',
            'owner_id' => $user->id,
            'is_active' => true,
            'test_mode' => false,
            'config_json' => [
                'google_account_email' => 'seller@example.com',
                'services' => ['gmail' => true, 'calendar' => false],
                'status' => 'connected',
            ],
            'credentials_encrypted' => [
                'access_token' => 'old-access',
                'refresh_token' => 'old-refresh',
            ],
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.send'],
        ]);
        $state = $this->stateFor($user, ['payload_json' => [
            'user_id' => $user->id,
            'service' => 'calendar',
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.events'],
        ]]);

        Http::fake([
            'https://oauth2.google.test/token' => Http::response([
                'access_token' => 'new-access',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid email profile https://www.googleapis.com/auth/calendar.events',
            ]),
            'https://googleapis.test/oauth2/v3/userinfo' => Http::response([
                'email' => 'seller@example.com',
                'name' => 'Seller Example',
            ]),
        ]);

        $this->actingAs($user)
            ->get('/account/integrations/google/callback?state='.$state->state.'&code=code-2')
            ->assertRedirect('/account/integrations');

        $account = IntegrationAccount::query()->where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('old-refresh', $account->credentials_encrypted['refresh_token']);
        $this->assertSame('new-access', $account->credentials_encrypted['access_token']);
        $this->assertTrue($account->config_json['services']['gmail']);
        $this->assertTrue($account->config_json['services']['calendar']);
        $this->assertContains('https://www.googleapis.com/auth/gmail.send', $account->scopes);
        $this->assertContains('https://www.googleapis.com/auth/calendar.events', $account->scopes);
    }

    public function test_local_disable_toggles_service_flag_without_revoking_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $account = $this->googleAccountFor($user, ['gmail' => true, 'calendar' => true]);

        $this->actingAs($user)
            ->patch('/account/integrations/google/gmail/disable')
            ->assertRedirect();

        $account->refresh();
        $this->assertTrue($account->is_active);
        $this->assertFalse($account->config_json['services']['gmail']);
        $this->assertTrue($account->config_json['services']['calendar']);
        $this->assertNotNull($account->credentials_encrypted);
    }

    public function test_disconnect_clears_credentials_and_marks_account_inactive_disconnected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $account = $this->googleAccountFor($user, ['gmail' => true, 'calendar' => true]);
        Http::fake(['https://oauth2.google.test/revoke' => Http::response([], 200)]);

        $this->actingAs($user)
            ->delete('/account/integrations/google')
            ->assertRedirect();

        $account->refresh();
        $this->assertFalse($account->is_active);
        $this->assertNull($account->google_active_owner_id);
        $this->assertNull($account->credentials_encrypted);
        $this->assertSame('disconnected', $account->config_json['status']);
        $this->assertFalse($account->config_json['services']['gmail']);
        $this->assertFalse($account->config_json['services']['calendar']);
    }

    public function test_database_prevents_two_active_google_connections_for_one_owner(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->googleAccountFor($user, ['gmail' => true, 'calendar' => false]);

        $this->expectException(QueryException::class);

        IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Duplicate Google',
            'owner_id' => $user->id,
            'is_active' => true,
            'config_json' => ['services' => ['gmail' => false, 'calendar' => true], 'status' => 'connected'],
            'credentials_encrypted' => ['access_token' => 'x', 'refresh_token' => 'y'],
            'scopes' => ['openid'],
        ]);
    }

    public function test_unique_guard_allows_reconnect_after_soft_delete(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $account = $this->googleAccountFor($user, ['gmail' => true, 'calendar' => false]);
        $account->delete();

        $replacement = $this->googleAccountFor($user, ['gmail' => false, 'calendar' => true]);

        $this->assertNotSame($account->id, $replacement->id);
        $this->assertSame($user->id, $replacement->google_active_owner_id);
    }

    public function test_user_cannot_use_another_users_google_connection(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $account = $this->googleAccountFor($owner, ['gmail' => true, 'calendar' => false]);

        $this->actingAs($other)
            ->get('/account/integrations')
            ->assertOk()
            ->assertDontSee('owner@example.com')
            ->assertSee('Conectar Gmail');

        $this->actingAs($other)
            ->patch('/account/integrations/google/gmail/disable')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($account->refresh()->config_json['services']['gmail']);
    }

    private function stateFor(User $user, array $overrides = []): OAuthState
    {
        return OAuthState::query()->create(array_merge([
            'provider' => 'google',
            'state' => 'state-'.bin2hex(random_bytes(8)),
            'redirect_after' => '/account/integrations',
            'payload_json' => [
                'user_id' => $user->id,
                'service' => 'gmail',
                'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.send'],
            ],
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ], $overrides));
    }

    /**
     * @param array{gmail: bool, calendar: bool} $services
     */
    private function googleAccountFor(User $user, array $services): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Google Workspace — owner@example.com',
            'owner_id' => $user->id,
            'is_active' => true,
            'test_mode' => false,
            'config_json' => [
                'google_account_email' => 'owner@example.com',
                'services' => $services,
                'status' => 'connected',
            ],
            'credentials_encrypted' => [
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ],
            'scopes' => ['openid', 'email', 'profile'],
        ]);
    }
}
