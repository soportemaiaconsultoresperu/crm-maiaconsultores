<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use App\Services\Email\SmtpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * B13 Pasada B — Smoke tests for {@see SmtpProvider}.
 *
 * Confirms the SMTP provider hands the rendered payload to Laravel's Mail
 * facade and returns the documented envelope shape on success.
 */
class SmtpProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_returns_ok_envelope_and_routes_through_mail_facade(): void
    {
        Mail::fake();

        $account = IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'Soporte — SMTP',
            'owner_id' => null,
            'is_shared' => false,
            'is_active' => true,
            'test_mode' => true,
        ]);

        $message = EmailMessage::create([
            'account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'pending-'.$account->id,
            'from_email' => 'sender@example.com',
            'from_name' => 'Sender',
            'subject' => 'Hola',
            'body_html' => ['<p>Hola</p>'],
            'body_text' => ['Hola'],
            'status' => EmailMessage::STATUS_QUEUED,
        ]);

        $message->participants()->create([
            'kind' => EmailParticipant::KIND_TO,
            'email' => 'recipient@example.com',
            'name' => 'Recipient',
        ]);

        $provider = new SmtpProvider($account);

        $result = $provider->send($message->fresh(['participants']));

        $this->assertIsArray($result);
        $this->assertSame(true, $result['ok']);
        $this->assertArrayHasKey('provider_message_id', $result);

        // Verify the Mail::fake() saw at least one outbound send. Mail::raw
        // / html callbacks captured by send() produce an empty $view.
        Mail::assertSentCount(1);
    }

    public function test_fetch_inbound_returns_empty_list(): void
    {
        $provider = new SmtpProvider(null);

        $this->assertSame([], $provider->fetchInbound());
        $this->assertSame([], $provider->fetchInbound('2026-08-18T00:00:00Z'));
    }

    public function test_verify_webhook_signature_returns_true_by_default(): void
    {
        $provider = new SmtpProvider(null);
        $request = \Illuminate\Http\Request::create('/webhooks/email/smtp', 'POST');

        $this->assertTrue($provider->verifyWebhookSignature($request));
    }
}
