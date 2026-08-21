<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Email\EmailMessage;
use App\Services\Email\Exceptions\NotImplementedException;
use App\Services\Email\OutlookProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * B13 Pasada B — Outlook provider stub + webhook verifier contract.
 */
class OutlookProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_returns_documented_error_envelope_when_credentials_missing(): void
    {
        putenv('INTEGRATIONS_OUTLOOK_TENANT_ID');

        $message = new EmailMessage([
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'from_email' => 'sender@example.com',
            'subject' => 'Hi',
            'body_html' => ['<p>Hi</p>'],
            'body_text' => ['Hi'],
            'status' => EmailMessage::STATUS_QUEUED,
        ]);

        $provider = new OutlookProvider(null);
        $result = $provider->send($message);

        $this->assertFalse($result['ok']);
        $this->assertSame(NotImplementedException::class, $result['error_class']);
        $this->assertStringContainsString('Outlook OAuth credentials not configured', $result['error_message']);
    }

    public function test_fetch_inbound_returns_empty_in_stub_mode(): void
    {
        $provider = new OutlookProvider(null);
        $this->assertSame([], $provider->fetchInbound());
    }

    public function test_webhook_signature_accepts_correct_hmac(): void
    {
        $secret = 'shared-outlook-secret';
        putenv('INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, $secret);

        $request = Request::create('/webhooks/email/outlook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OFFICE_SIGNATURE' => $sig,
        ], $body);

        $provider = new OutlookProvider(null);
        $this->assertTrue($provider->verifyWebhookSignature($request));

        putenv('INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET');
    }

    public function test_webhook_signature_rejects_missing_header(): void
    {
        $secret = 'shared-outlook-secret';
        putenv('INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET='.$secret);

        $request = Request::create('/webhooks/email/outlook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $provider = new OutlookProvider(null);
        $this->assertFalse($provider->verifyWebhookSignature($request));

        putenv('INTEGRATIONS_OUTLOOK_WEBHOOK_SECRET');
    }
}
