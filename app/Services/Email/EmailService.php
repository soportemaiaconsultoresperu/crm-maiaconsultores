<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Jobs\V2\SendEmailMessage;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\Email\EmailTemplate;
use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B13 Pasada B — High-level email pipeline.
 *
 * `send()`:
 *   - picks the {@see EmailTemplate} (or accepts an already-built {@see EmailMessage});
 *   - renders the template body via {@see EmailTemplateRenderer};
 *   - persists an `EmailMessage` row in a single transaction, with `status='queued'`
 *     and the recipient list as `EmailParticipant` rows;
 *   - dispatches {@see SendEmailMessage} for async delivery.
 *
 * `handleInbound()`:
 *   - persists an inbound `EmailMessage` row that the webhook controller
 *     produced via {@see EmailProvider::fetchInbound()};
 *   - optionally fires `EmailInboundEvent` so domain listeners can react
 *     (B13 Pasada C); for Pasada B only the persistence happens.
 *
 * Wrapped in `DB::transaction` so partial writes never leave the schema
 * half-built. Tests use `Mail::fake()` to verify the rendered payload
 * without actually SMTP-sending.
 */
class EmailService
{
    public function __construct(
        public readonly EmailTemplateRenderer $renderer,
    ) {
    }

    /**
     * Send a templated email to a recipient list.
     *
     * @param  EmailTemplate|EmailMessage  $source
     * @param  list<string>|list<array{email: string, name?: string|null, kind?: string}>  $recipients
     * @param  array<string, string|int|float|bool>  $vars
     * @param  array{
     *     account_id?: int|null,
     *     from_email?: string|null,
     *     from_name?: string|null,
     *     related_lead_id?: int|null,
     *     related_customer_id?: int|null,
     *     related_opportunity_id?: int|null,
     *     related_quotation_id?: int|null,
     *     related_contact_id?: int|null,
     *     create_version_snapshot?: bool,
     * }  $options
     */
    public function send(
        EmailTemplate|EmailMessage $source,
        array $recipients,
        array $vars = [],
        array $options = [],
        ?User $actor = null,
    ): EmailMessage {
        $message = DB::transaction(function () use ($source, $recipients, $vars, $options, $actor): EmailMessage {
            $rendered = $this->buildRenderedPayload($source, $vars);

            $fromEmail = (string) ($options['from_email'] ?? $this->resolveFromEmail($source));
            $fromName = $options['from_name'] ?? null;

            $accountId = $options['account_id'] ?? $this->resolveAccountId($source);

            $message = $source instanceof EmailMessage ? $source : new EmailMessage();
            $providerMessageId = $source instanceof EmailMessage
                ? $source->provider_message_id
                : 'local-'.bin2hex(random_bytes(8));

            $message->fill([
                'account_id' => $accountId,
                'direction' => EmailMessage::DIRECTION_OUTBOUND,
                'provider_message_id' => $providerMessageId,
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'subject' => $rendered['subject'],
                'body_html' => [$rendered['body_html']],
                'body_text' => [$rendered['body_text']],
                'status' => EmailMessage::STATUS_QUEUED,
                'related_lead_id' => $options['related_lead_id'] ?? null,
                'related_customer_id' => $options['related_customer_id'] ?? null,
                'related_opportunity_id' => $options['related_opportunity_id'] ?? null,
                'related_quotation_id' => $options['related_quotation_id'] ?? null,
                'related_contact_id' => $options['related_contact_id'] ?? null,
                'created_by' => $actor?->id,
            ]);
            $message->save();

            $this->persistParticipants($message, $recipients);

            if ($source instanceof EmailTemplate
                && ($options['create_version_snapshot'] ?? false) === true) {
                $this->snapshotVersion($source, $actor);
            }

            return $message->fresh(['participants']);
        });

        // Dispatch the async send job after the transaction commits so a
        // rollback never leaves a queued job pointing at a missing row.
        SendEmailMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Persist an inbound draft returned by {@see EmailProvider::fetchInbound()}.
     * Returns the persisted message (with attached ID).
     */
    public function handleInbound(EmailMessage $draft): EmailMessage
    {
        return DB::transaction(function () use ($draft): EmailMessage {
            $draft->direction = EmailMessage::DIRECTION_INBOUND;
            $draft->status = EmailMessage::STATUS_RECEIVED;
            $draft->received_at = $draft->received_at ?? now();
            $draft->save();

            // No participant persistence yet — drafts produced by the stub
            // providers have no participants. Real implementations in
            // Pasada C attach them.

            return $draft->fresh();
        });
    }

    /**
     * @param  EmailTemplate|EmailMessage  $source
     * @param  array<string, string|int|float|bool>  $vars
     * @return array{subject: string, body_html: string, body_text: string}
     */
    private function buildRenderedPayload(EmailTemplate|EmailMessage $source, array $vars): array
    {
        if ($source instanceof EmailMessage) {
            return [
                'subject' => (string) $source->subject,
                'body_html' => self::flattenString($source->body_html),
                'body_text' => self::flattenString($source->body_text),
            ];
        }

        $allowed = $source->variables_json ?? [];
        if (! is_array($allowed)) {
            $allowed = [];
        }
        $allowed = array_values(array_filter($allowed, 'is_string'));

        $renderer = new EmailTemplateRenderer($allowed);

        return $renderer->render($source, $vars);
    }

    /**
     * @param  EmailTemplate|EmailMessage  $source
     */
    private function resolveFromEmail(EmailTemplate|EmailMessage $source): ?string
    {
        if ($source instanceof EmailMessage) {
            return $source->from_email;
        }

        return (string) config('mail.from.address');
    }

    /**
     * @param  EmailTemplate|EmailMessage  $source
     */
    private function resolveAccountId(EmailTemplate|EmailMessage $source): ?int
    {
        if ($source instanceof EmailMessage) {
            return $source->account_id;
        }

        // Templates don't bind to one account by design (decision 9): the
        // sender picks an account at send-time. Default to the first active
        // provider that the operator has marked as the personal default,
        // or `null` if none is configured.
        return IntegrationAccount::query()
            ->active()
            ->whereIn('provider', ['smtp', 'gmail', 'outlook'])
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * @param  list<string>|list<array{email: string, name?: string|null, kind?: string}>  $recipients
     */
    private function persistParticipants(EmailMessage $message, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $email = $recipient;
                $name = null;
                $kind = EmailParticipant::KIND_TO;
            } else {
                $email = (string) ($recipient['email'] ?? '');
                $name = $recipient['name'] ?? null;
                $kind = (string) ($recipient['kind'] ?? EmailParticipant::KIND_TO);
            }

            if ($email === '') {
                continue;
            }

            if (! in_array($kind, [
                EmailParticipant::KIND_TO,
                EmailParticipant::KIND_CC,
                EmailParticipant::KIND_BCC,
                EmailParticipant::KIND_FROM,
            ], true)) {
                $kind = EmailParticipant::KIND_TO;
            }

            $message->participants()->create([
                'kind' => $kind,
                'email' => $email,
                'name' => $name,
            ]);
        }

        $fromEmail = (string) $message->from_email;
        if ($fromEmail !== '') {
            $message->participants()->firstOrCreate(
                ['kind' => EmailParticipant::KIND_FROM, 'email' => $fromEmail],
                ['name' => $message->from_name],
            );
        }
    }

    private function snapshotVersion(EmailTemplate $template, ?User $actor): void
    {
        \App\Models\Email\EmailTemplateVersion::create([
            'template_id' => $template->id,
            'version' => (int) ($template->version ?? 0) + 1,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'body_text' => $template->body_text,
            'variables_json' => $template->variables_json,
            'snapshot_by' => $actor?->id,
            'created_at' => now(),
        ]);

        $template->forceFill(['version' => (int) ($template->version ?? 0) + 1])->save();
    }

    private static function flattenString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && $value !== []) {
            $first = reset($value);

            return is_string($first) ? $first : '';
        }

        return '';
    }
}
