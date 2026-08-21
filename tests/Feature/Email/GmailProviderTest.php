<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Email\EmailMessage;
use App\Services\Email\Exceptions\NotImplementedException;
use App\Services\Email\GmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * B13 Pasada B — Gmail provider stub + webhook verifier contract.
 */
class GmailProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_returns_documented_error_envelope_when_credentials_missing(): void
    {
        // The Gmail stub never had a chance to read INTEGRATIONS_GMAIL_CLIENT_ID
        // because the env defaults are unset. Force a clean baseline.
        $original = (string) (env('INTEGRATIONS_GMAIL_CLIENT_ID') ?? '');
        putenv('INTEGRATIONS_GMAIL_CLIENT_ID');

        $message = new EmailMessage([
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'from_email' => 'sender@example.com',
            'subject' => 'Hi',
            'body_html' => ['<p>Hi</p>'],
            'body_text' => ['Hi'],
            'status' => EmailMessage::STATUS_QUEUED,
        ]);

        $provider = new GmailProvider(null);
        $result = $provider->send($message);

        $this->assertFalse($result['ok']);
        $this->assertSame(NotImplementedException::class, $result['error_class']);
        $this->assertStringContainsString('Gmail OAuth credentials not configured', $result['error_message']);

        if ($original !== '') {
            putenv('INTEGRATIONS_GMAIL_CLIENT_ID='.$original);
        }
    }

    public function test_fetch_inbound_returns_empty_in_stub_mode(): void
    {
        $provider = new GmailProvider(null);

        $this->assertSame([], $provider->fetchInbound());
    }

    public function test_webhook_signature_accepts_correct_hmac(): void
    {
        $secret = 'shared-secret-for-tests';
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc","ts":1700000000}';
        $sig = hash_hmac('sha256', $body, $secret);

        $request = Request::create('/webhooks/email/gmail', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $body);

        $provider = new GmailProvider(null);
        $this->assertTrue($provider->verifyWebhookSignature($request));

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
    }

    public function test_webhook_signature_rejects_tampered_body(): void
    {
        $secret = 'shared-secret-for-tests';
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET='.$secret);

        $body = '{"id":"abc"}';
        $sig = hash_hmac('sha256', $body, $secret);

        // Tamper with body but keep the same signature.
        $tamperedBody = '{"id":"xyz"}';

        $request = Request::create('/webhooks/email/gmail', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => $sig,
        ], $tamperedBody);

        $provider = new GmailProvider(null);
        $this->assertFalse($provider->verifyWebhookSignature($request));

        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');
    }

    public function test_webhook_signature_fails_closed_when_secret_is_missing(): void
    {
        putenv('INTEGRATIONS_GMAIL_WEBHOOK_SECRET');

        $request = Request::create('/webhooks/email/gmail', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GOOG_SIGNATURE' => 'whatever',
        ], '{}');

        $provider = new GmailProvider(null);
        $this->assertFalse($provider->verifyWebhookSignature($request));
    }
}
