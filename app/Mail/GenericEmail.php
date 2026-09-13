<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Email\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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
 *
 * Every attachment MUST be registered through {@see Mailable::attach()}:
 * `Mailable::$attachments` holds `['file' => ..., 'options' => [...]]`
 * entries, so assigning the raw EmailAttachment shape to that property made
 * {@see Mailable::buildAttachments()} read an undefined `file` key and no
 * attachment ever reached the message.
 */
class GenericEmail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $bodyHtml;

    public ?string $bodyText;

    /**
     * @param  list<array{path?: string, storage_path?: string, filename?: string, mime?: string}>  $attachments
     *         `path` is an absolute filesystem path; `storage_path` is resolved
     *         against the `local` disk, the disk every EmailAttachment row uses.
     */
    public function __construct(public readonly EmailMessage $message, array $attachments = [])
    {
        $this->bodyHtml = self::flattenBody($message->body_html);
        $this->bodyText = self::flattenBody($message->body_text);
        $this->subject = (string) ($message->subject ?? '');

        foreach ($attachments as $attachment) {
            $this->attachEmailAttachment($attachment);
        }
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
     * Register one attachment with the Mail facade. Entries without a usable
     * path are skipped (an attachment row with an empty path cannot become a
     * part the transport could encode).
     *
     * @param  array{path?: string, storage_path?: string, filename?: string, mime?: string}  $attachment
     */
    private function attachEmailAttachment(array $attachment): void
    {
        $file = $attachment['path'] ?? null;

        if (($file === null || $file === '') && ! empty($attachment['storage_path'])) {
            $file = Storage::disk('local')->path($attachment['storage_path']);
        }

        if ($file === null || $file === '') {
            return;
        }

        $options = [];

        if (! empty($attachment['filename'])) {
            $options['as'] = $attachment['filename'];
        }

        if (! empty($attachment['mime'])) {
            $options['mime'] = $attachment['mime'];
        }

        $this->attach($file, $options);
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
