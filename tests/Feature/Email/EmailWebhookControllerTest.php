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
        IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Gmail test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $secret = 'shared-secret';
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc"}';
        $goodSig = hash_hmac('sha256', $body, $secret);
        $tamperedBody = '{"id":"xyz"}';

        $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $goodSig,
        ], $tamperedBody)
            ->assertStatus(400)
            ->assertJson(['ok' => false]);

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
    }

    public function test_gmail_webhook_accepts_valid_signature(): void
    {
        IntegrationAccount::create([
            'provider' => 'gmail',
            'label' => 'Gmail test',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $secret = 'shared-secret';
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, $secret);

        $response = $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
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
        $secret = 'shared-secret';
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, $secret);

        $this->call('POST', '/webhooks/email/gmail', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body)
            ->assertStatus(503);

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
    }
}
