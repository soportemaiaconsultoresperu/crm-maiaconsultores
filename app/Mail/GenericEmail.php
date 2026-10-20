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
 * integration ergonomic; the actual filenames + mime + storage keys come
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
     *         `path` is an absolute filesystem path; `storage_path` is read as
     *         bytes from the `local` disk, the disk every EmailAttachment row uses.
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
        $filename = (string) ($attachment['filename'] ?? '');
        $mime = (string) ($attachment['mime'] ?? '');

        // A caller that already holds an absolute filesystem path (a generated
        // artifact, never a stored user file) keeps the path-based attach.
        $file = $attachment['path'] ?? null;

        if ($file !== null && $file !== '') {
            $options = [];

            if ($filename !== '') {
                $options['as'] = $filename;
            }

            if ($mime !== '') {
                $options['mime'] = $mime;
            }

            $this->attach($file, $options);

            return;
        }

        if (empty($attachment['storage_path'])) {
            return;
        }

        // Stored attachments are read as bytes from the disk and handed to the
        // transport. Resolving the stored key to a filesystem path with
        // Storage::disk()->path() only works while the disk is local and breaks
        // as soon as the disk is backed by S3.
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment['storage_path'])) {
            return;
        }

        $this->attachData(
            (string) $disk->get($attachment['storage_path']),
            $filename !== '' ? $filename : basename($attachment['storage_path']),
            $mime !== '' ? ['mime' => $mime] : [],
        );
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
