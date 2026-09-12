<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Mail\GenericEmail;
use App\Models\Email\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * {@see GenericEmail} has to be renderable by a REAL transport.
 *
 * Mail::fake() records the mailable without calling build()/render(), which is
 * why a call to a non-existent plain-text view (and a detached attachment)
 * stayed invisible while the envelope reported `ok: true`. These tests go
 * through the real "array" transport (the phpunit `MAIL_MAILER` default) so
 * rendering the message is part of the assertion.
 */
class GenericEmailRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function outboundMessage(): EmailMessage
    {
        return EmailMessage::create([
            'account_id' => null,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'pending-generic-email',
            'from_email' => 'sender@example.com',
            'from_name' => 'Sender',
            'subject' => 'Cotización',
            'body_html' => ['<p>Maia &amp; Consultores</p>'],
            'body_text' => ['Maia & Consultores'],
            'status' => EmailMessage::STATUS_QUEUED,
        ]);
    }

    public function test_build_renders_both_html_and_plain_text_parts(): void
    {
        $mailable = new GenericEmail($this->outboundMessage());

        $this->assertStringContainsString('Maia &amp; Consultores', $mailable->render());

        $mailable->assertSeeInHtml('Maia & Consultores');
        $mailable->assertSeeInText('Maia & Consultores');
    }

    /**
     * Triangulation: a message with no HTML body still renders its plain-text
     * part (Mailable::buildView() takes the text-only branch).
     */
    public function test_message_without_html_renders_the_plain_text_part_only(): void
    {
        $message = $this->outboundMessage();
        $message->forceFill(['body_html' => null, 'body_text' => ['Solo texto plano']])->save();

        $mailable = new GenericEmail($message->fresh());

        $mailable->assertSeeInText('Solo texto plano');
    }

    public function test_supplied_attachment_is_attached_and_carries_the_file_bytes(): void
    {
        Storage::fake('local');

        $bytes = 'PDF-ATTACHMENT-BYTES-0123456789';
        $path = 'email-attachments/quotations/1/cotizacion.pdf';
        Storage::disk('local')->put($path, $bytes);

        $mailable = new GenericEmail($this->outboundMessage(), [[
            'storage_path' => $path,
            'filename' => 'cotizacion.pdf',
            'mime' => 'application/pdf',
        ]]);

        Mail::to('recipient@example.com')->send($mailable);

        $email = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $attachments = $email->getAttachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('cotizacion.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getContentType());
        $this->assertSame($bytes, $attachments[0]->getBody());
        $this->assertStringContainsString(base64_encode($bytes), $email->toString());
    }
}
