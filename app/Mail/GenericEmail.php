<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Email\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * B13 Pasada B — Generic mail transport for {@see \App\Services\Email\SmtpProvider}.
 *
 * Holds the rendered subject, HTML body and text body of an outbound message
 * so the SMTP transport can serialise them through Laravel's Mail facade. The
 * mailable is intentionally thin — content rendering is owned by
 * {@see \App\Services\Email\EmailTemplateRenderer}, and only SMTP re-uses it
 * here (Gmail + Outlook call vendor APIs directly).
 *
 * Attachments are exposed as a typed array shape to keep the Mail facade
 * integration ergonomic; the actual filenames + mime + storage paths come
 * from {@see \App\Models\Email\EmailAttachment} rows.
 */
class GenericEmail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $bodyHtml;

    public ?string $bodyText;

    /**
     * @param  list<array{path?: string, storage_path?: string, filename?: string, mime?: string}>  $attachments
     */
    public function __construct(public readonly EmailMessage $message, array $attachments = [])
    {
        $this->bodyHtml = self::flattenBody($message->body_html);
        $this->bodyText = self::flattenBody($message->body_text);
        $this->subject = (string) ($message->subject ?? '');
        $this->attachments = $attachments;
    }

    public function build(): self
    {
        if ($this->bodyHtml !== null && $this->bodyHtml !== '') {
            $this->html($this->bodyHtml);
        }

        if ($this->bodyText !== null && $this->bodyText !== '') {
            $this->text('plain.text', ['body' => $this->bodyText]);
        }

        return $this;
    }

    /**
     * Reduce the cast `array` payload (subject, body_html, body_text) into
     * the single string the mail transport expects. Older rows stored the
     * content as a string directly; the cast layer on EmailMessage always
     * produces an array of one entry.
     */
    private static function flattenBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        if (is_array($body) && $body !== []) {
            $first = reset($body);

            return is_string($first) ? $first : null;
        }

        return null;
    }
}
