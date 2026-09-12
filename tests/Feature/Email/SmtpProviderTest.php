<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use App\Services\Email\SmtpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

    /**
     * End-to-end send through a REAL transport (no Mail::fake()).
     *
     * The test above passes against a mailable whose plain-text view does not
     * exist because MailFake never renders the message. This one renders it:
     * the text part must contain the body and the stored EmailAttachment must
     * travel as an attachment with its bytes.
     */
    public function test_send_renders_the_message_and_attaches_the_stored_attachment(): void
    {
        Storage::fake('local');

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
            'provider_message_id' => 'pending-real-'.$account->id,
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

        $bytes = 'PDF-ATTACHMENT-BYTES-0123456789';
        $path = 'email-attachments/quotations/'.$message->id.'/cotizacion.pdf';
        Storage::disk('local')->put($path, $bytes);

        $message->attachments()->create([
            'filename' => 'cotizacion.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'storage_path' => $path,
            'sha256' => hash('sha256', $bytes),
        ]);

        $provider = new SmtpProvider($account);

        $result = $provider->send($message->fresh(['participants', 'attachments']));

        $this->assertTrue($result['ok'], 'SMTP send failed: '.($result['error_message'] ?? 'unknown error'));

        $email = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertStringContainsString('Hola', (string) $email->getTextBody());
        $this->assertStringContainsString('<p>Hola</p>', (string) $email->getHtmlBody());

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('cotizacion.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getContentType());
        $this->assertSame($bytes, $attachments[0]->getBody());
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
