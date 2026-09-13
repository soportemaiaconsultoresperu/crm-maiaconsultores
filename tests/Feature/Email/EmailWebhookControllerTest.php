<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\IntegrationAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B13 Pasada B — EmailWebhookController signature verification tests.
 */
class EmailWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_gmail_webhook_rejects_invalid_signature_with_400(): void
    {
        $this->configureGmailSecret('shared-secret');

        IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Gmail test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $body = '{"id":"abc"}';
        $goodSig = hash_hmac('sha256', $body, 'shared-secret');
        $tamperedBody = '{"id":"xyz"}';

        $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $goodSig,
        ], $tamperedBody)
            ->assertStatus(400)
            ->assertJson(['ok' => false]);
    }

    public function test_gmail_webhook_accepts_valid_signature(): void
    {
        $this->configureGmailSecret('shared-secret');

        IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Gmail test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, 'shared-secret');

        $response = $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    /**
     * E-5 — the deployable path. Renders the shipped config file with the env
     * variable exported (what `config:cache` does), clears the process
     * variable to mirror the cached runtime where `env()` is null, and drives
     * a correctly-signed webhook. It must be verified through the config layer.
     */
    public function test_gmail_webhook_secret_resolves_through_the_deployment_config_path(): void
    {
        $secret = 'deploy-gmail-secret';

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);
        $integrations = require config_path('integrations.php');
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
        config(['integrations' => $integrations]);

        IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Gmail test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, $secret);

        $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(
            $secret,
            $integrations['email']['gmail']['webhook_secret'] ?? null,
            'config/integrations.php must resolve integrations.email.gmail.webhook_secret from INTEGRATIONS_GMAIL_WEBHOOK_SECRET.',
        );
    }

    public function test_outlook_webhook_rejects_invalid_signature(): void
    {
        IntegrationAccount::create([
            'provider' => 'outlook',
            'label' => 'Outlook test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $response = $this->call('POST', '/webhooks/email/outlook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $response->assertStatus(400);
        $response->assertJson(['ok' => false]);
    }

    public function test_gmail_webhook_returns_503_when_no_account_configured(): void
    {
        $this->configureGmailSecret('shared-secret');

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, 'shared-secret');

        $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body)
            ->assertStatus(503);
    }

    /**
     * Set the Gmail webhook secret through the configuration layer — the same
     * source the deployment renders from INTEGRATIONS_GMAIL_WEBHOOK_SECRET.
     */
    private function configureGmailSecret(string $secret): void
    {
        config(['integrations.email.gmail.webhook_secret' => $secret]);
    }
}
