<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsApp\WhatsAppAccount;
use App\Services\WhatsApp\Exceptions\NotImplementedException;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * B14 Pasada B-1 — MetaWhatsAppProvider stub-mode behaviour.
 *
 * Until A5 ships, no real Meta credentials are configured. The provider
 * MUST therefore return the canonical `NotImplementedException` error
 * envelope for every send and an empty list for fetchTemplates — so the
 * rest of the pipeline can rely on the envelope shape uniformly.
 *
 * Webhook verification MUST return `false` when `webhook_secret` is
 * not configured (NULL → never accept inbound webhooks). With a secret
 * set, the HMAC over the raw body must equal the `sha256=...` header.
 */
class MetaWhatsAppProviderTest extends TestCase
{
    public function test_send_template_message_returns_stub_error_envelope_when_credentials_missing(): void
    {
        $account = $this->makeAccount(businessId: null);
        $provider = new MetaWhatsAppProvider($account);

        $message = new \App\Models\WhatsApp\WhatsAppMessage([
            'direction' => 'outbound',
            'type' => 'template',
            'status' => 'queued',
        ]);
        $template = new \App\Models\WhatsApp\WhatsAppTemplate([
            'name' => 'welcome',
            'language' => 'es_PE',
            'status' => 'approved',
        ]);

        $result = $provider->sendTemplateMessage($message, $template, '+15551234567', ['name' => 'Acme']);

        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
        $this->assertSame(NotImplementedException::class, $result['error_class']);
        $this->assertStringContainsString('Meta WhatsApp Cloud API credentials not configured', $result['error_message']);
    }

    public function test_send_freeform_message_returns_stub_error_envelope_when_credentials_missing(): void
    {
        $account = $this->makeAccount(businessId: null);
        $provider = new MetaWhatsAppProvider($account);

        $message = new \App\Models\WhatsApp\WhatsAppMessage([
            'direction' => 'outbound',
            'type' => 'freeform',
            'status' => 'queued',
        ]);

        $result = $provider->sendFreeFormMessage($message, '+15551234567');

        $this->assertFalse($result['ok']);
        $this->assertSame(NotImplementedException::class, $result['error_class']);
    }

    public function test_fetch_templates_returns_empty_list_when_credentials_missing(): void
    {
        $account = $this->makeAccount(businessId: null);
        $provider = new MetaWhatsAppProvider($account);

        $templates = $provider->fetchTemplates($account);

        $this->assertSame([], $templates);
    }

    public function test_verify_webhook_signature_returns_false_when_secret_is_null(): void
    {
        $account = $this->makeAccount(webhookSecret: null);
        $provider = new MetaWhatsAppProvider($account);

        $request = Request::create('/webhooks/whatsapp/meta', 'POST', [], [], [], [], '{"entry":[]}');

        $this->assertFalse($provider->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_returns_true_with_valid_hmac(): void
    {
        $account = $this->makeAccount(webhookSecret: 'shhh-test-secret');
        $provider = new MetaWhatsAppProvider($account);

        $body = '{"entry":[{"id":"123"}]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'shhh-test-secret');

        $request = Request::create(
            '/webhooks/whatsapp/meta',
            'POST',
            [], [], [],
            ['HTTP_X_HUB_SIGNATURE_256' => $signature],
            $body,
        );

        $this->assertTrue($provider->verifyWebhookSignature($request));
    }

    public function test_verify_webhook_signature_returns_false_with_tampered_body(): void
    {
        $account = $this->makeAccount(webhookSecret: 'shhh-test-secret');
        $provider = new MetaWhatsAppProvider($account);

        $body = '{"entry":[{"id":"123"}]}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'shhh-test-secret');

        $tampered = '{"entry":[{"id":"tampered"}]}';
        $request = Request::create(
            '/webhooks/whatsapp/meta',
            'POST',
            [], [], [],
            ['HTTP_X_HUB_SIGNATURE_256' => $signature],
            $tampered,
        );

        $this->assertFalse($provider->verifyWebhookSignature($request));
    }

    /**
     * Build an unsaved WhatsAppAccount (no DB persistence — provider is
     * a pure adapter over the column data).
     */
    private function makeAccount(?string $businessId = null, ?string $webhookSecret = null): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => $businessId,
            'display_name' => 'Test Account',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);

        // Allow tests to inject a webhook secret without touching the DB schema.
        $reflection = new \ReflectionProperty(WhatsAppAccount::class, 'attributes');
        $reflection->setAccessible(true);
        $attrs = $reflection->getValue($account);
        $attrs['webhook_secret'] = $webhookSecret;
        $reflection->setValue($account, $attrs);

        return $account;
    }
}