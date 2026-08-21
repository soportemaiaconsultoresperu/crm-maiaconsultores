<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Verify that the `signed.webhook` middleware enforces HMAC verification
 * for the `meta` provider. Outlook has no header verifier in B11 and is
 * covered separately in B16.
 */
class WebhookSignatureTest extends TestCase
{
    private string $appSecret = 'unit-test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.enabled.whatsapp' => true]);
        config(['integrations.webhook_verifiers.meta' => \App\Integrations\Verification\MetaSignatureVerifier::class]);
        // Inject the test app secret through container override.
        $this->app->bind(\App\Integrations\Verification\MetaSignatureVerifier::class, fn () => new \App\Integrations\Verification\MetaSignatureVerifier($this->appSecret));
    }

    public function test_valid_hmac_signature_returns_200(): void
    {
        $body = '{"event":"messages","phone":"51987654321"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, $this->appSecret);

        $response = $this->call(
            'POST',
            '/webhooks/meta',
            [], // parameters
            [], // cookies
            [], // files
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertOk();
        $response->assertJson(['received' => true, 'provider' => 'meta']);
    }

    public function test_invalid_signature_returns_400(): void
    {
        $body = '{"event":"messages","phone":"51987654321"}';
        $bogus = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');

        $response = $this->call(
            'POST',
            '/webhooks/meta',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $bogus,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(400);
    }

    public function test_missing_signature_header_returns_400(): void
    {
        $body = '{"event":"messages"}';

        $response = $this->call(
            'POST',
            '/webhooks/meta',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        );

        $response->assertStatus(400);
    }

    public function test_malformed_signature_returns_400(): void
    {
        $body = '{"event":"messages"}';

        $response = $this->call(
            'POST',
            '/webhooks/meta',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => 'not-a-real-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(400);
    }

    public function test_oversized_payload_returns_400(): void
    {
        $body = str_repeat('A', 2 * 1048576); // 2 MiB > 1 MiB default
        $signature = 'sha256='.hash_hmac('sha256', $body, $this->appSecret);

        $response = $this->call(
            'POST',
            '/webhooks/meta',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );

        $response->assertStatus(400);
    }
}