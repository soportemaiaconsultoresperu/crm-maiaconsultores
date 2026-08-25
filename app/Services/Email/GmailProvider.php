<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Contracts\Email\EmailProvider;
use App\Models\Email\EmailAttachment;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GmailProvider implements EmailProvider
{
    public function __construct(
        public readonly ?IntegrationAccount $account = null,
    ) {
    }

    /**
     * @return array{ok: bool, provider_message_id?: string, thread_id?: string, indeterminate?: bool, retryable?: bool, error_class?: string, error_message?: string}
     */
    public function send(EmailMessage $message): array
    {
        if ($this->account === null) {
            return $this->failure('NoBoundAccount', 'Gmail send requires a Google integration account.');
        }

        $config = (array) ($this->account->config_json ?? []);
        $services = (array) ($config['services'] ?? []);
        $scopes = array_values(array_filter((array) ($this->account->scopes ?? []), 'is_string'));

        if (($services['gmail'] ?? false) !== true
            || ! in_array('https://www.googleapis.com/auth/gmail.send', $scopes, true)) {
            return $this->failure('GmailNotAuthorized', 'Conectá Gmail para enviar esta cotización desde el sistema.');
        }

        try {
            $accessToken = app(GoogleOAuthService::class)->accessTokenFor($this->account);
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw' => $this->base64UrlEncode($this->buildMime($message)),
                ]);
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'indeterminate' => true,
                'error_class' => $exception::class,
                'error_message' => 'No se pudo confirmar si Gmail aceptó el mensaje.',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'retryable' => $response->status() === 429 || $response->serverError(),
                'error_class' => 'GmailApiError',
                'error_message' => 'Gmail rechazó el envío con estado HTTP '.$response->status().'.',
            ];
        }

        $payload = $response->json();

        return [
            'ok' => true,
            'provider_message_id' => (string) ($payload['id'] ?? $message->provider_message_id),
            'thread_id' => isset($payload['threadId']) ? (string) $payload['threadId'] : null,
        ];
    }

    /**
     * @param  string|null  $since
     * @return list<EmailMessage>
     */
    public function fetchInbound(?string $since = null): array
    {
        return [];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = (string) (env('INTEGRATIONS_GMAIL_WEBHOOK_SECRET') ?? '');
        if ($secret === '') {
            return false;
        }

        $provided = (string) $request->header('X-Goog-Signature', '');
        if ($provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', (string) $request->getContent(), $secret), $provided);
    }

    private function buildMime(EmailMessage $message): string
    {
        $message->loadMissing(['participants', 'attachments']);
        $boundary = '=_crm_maia_'.bin2hex(random_bytes(12));
        $to = $this->emailsFor($message, EmailParticipant::KIND_TO);
        $cc = $this->emailsFor($message, EmailParticipant::KIND_CC);
        $bcc = $this->emailsFor($message, EmailParticipant::KIND_BCC);
        $from = $message->from_name
            ? sprintf('%s <%s>', $this->encodeHeader($message->from_name), $message->from_email)
            : $message->from_email;

        $headers = [
            'From: '.$from,
            'To: '.implode(', ', $to),
            'Subject: '.$this->encodeHeader((string) $message->subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
        ];
        if ($cc !== []) {
            $headers[] = 'Cc: '.implode(', ', $cc);
        }
        if ($bcc !== []) {
            $headers[] = 'Bcc: '.implode(', ', $bcc);
        }

        $body = implode("\r\n", $headers)."\r\n\r\n";
        $body .= '--'.$boundary."\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($this->flatten($message->body_text)), 76, "\r\n")."\r\n";

        foreach ($message->attachments as $attachment) {
            /** @var EmailAttachment $attachment */
            $contents = Storage::disk('local')->get($attachment->storage_path);
            $body .= '--'.$boundary."\r\n";
            $body .= 'Content-Type: '.$attachment->mime.'; name="'.$attachment->filename."\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="'.$attachment->filename."\"\r\n\r\n";
            $body .= chunk_split(base64_encode($contents), 76, "\r\n")."\r\n";
        }

        return $body.'--'.$boundary."--\r\n";
    }

    /** @return list<string> */
    private function emailsFor(EmailMessage $message, string $kind): array
    {
        return $message->participants
            ->where('kind', $kind)
            ->pluck('email')
            ->filter()
            ->values()
            ->all();
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map('strval', $value));
        }

        return (string) $value;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{ok: false, error_class: string, error_message: string} */
    private function failure(string $class, string $message): array
    {
        return ['ok' => false, 'error_class' => $class, 'error_message' => $message];
    }
}
