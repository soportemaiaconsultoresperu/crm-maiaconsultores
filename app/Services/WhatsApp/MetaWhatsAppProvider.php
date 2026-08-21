<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProvider;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use App\Services\WhatsApp\Exceptions\NotImplementedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * B14 Pasada B-1 — Meta WhatsApp Cloud API adapter.
 *
 * Default transport per decision 12a. Implements
 * {@see \App\Contracts\WhatsApp\WhatsAppProvider}.
 *
 * Stub mode (default for v1): when `whatsapp_accounts.business_id` is
 * null/empty, every send method returns the canonical
 * `NotImplementedException` error envelope and `fetchTemplates()` returns
 * `[]`. `verifyWebhookSignature()` returns `false` when the webhook
 * secret is not configured (refusing every inbound webhook until A5).
 *
 * Real mode: when `business_id` is populated, send methods POST to
 * `https://graph.facebook.com/v18.0/{phone_number_id}/messages` using
 * `Http::withToken($account->business_id)`. The access token is stored
 * in the `business_id` column for v1 simplicity (decision 12a; B14.x
 * could move it to `integration_accounts.config_json`).
 *
 * @see docs/v2/01-roadmap.md §7 decisions 12a/12b/15a/15c.
 */
class MetaWhatsAppProvider implements WhatsAppProvider
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v18.0';

    public function __construct(
        public readonly WhatsAppAccount $account,
    ) {
    }

    /**
     * @param  array<string, string|int>  $vars
     * @return array{ok: bool, wamid?: string, error_class?: string, error_message?: string}
     */
    public function sendTemplateMessage(
        WhatsAppMessage $message,
        WhatsAppTemplate $template,
        string $phoneNumber,
        array $vars,
    ): array {
        if ($this->isStubMode()) {
            return $this->stubEnvelope();
        }

        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $template->name,
                    'language' => ['code' => $template->language],
                    'components' => $this->buildComponents($vars),
                ],
            ];

            $response = Http::withToken((string) $this->account->business_id)
                ->acceptJson()
                ->post($this->messagesUrl(), $payload);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'error_class' => RuntimeException::class,
                    'error_message' => sprintf(
                        'Meta WhatsApp Cloud API returned %d: %s',
                        $response->status(),
                        (string) $response->body(),
                    ),
                ];
            }

            $body = $response->json();
            $wamid = $body['messages'][0]['id'] ?? null;

            return [
                'ok' => true,
                'wamid' => $wamid !== null ? (string) $wamid : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('MetaWhatsAppProvider::sendTemplateMessage failed', [
                'account_id' => $this->account->id,
                'template_id' => $template->id,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, wamid?: string, error_class?: string, error_message?: string}
     */
    public function sendFreeFormMessage(WhatsAppMessage $message, string $phoneNumber): array
    {
        if ($this->isStubMode()) {
            return $this->stubEnvelope();
        }

        try {
            $response = Http::withToken((string) $this->account->business_id)
                ->acceptJson()
                ->post($this->messagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'to' => $phoneNumber,
                    'type' => 'text',
                    'text' => [
                        'body' => (string) $message->body,
                    ],
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'error_class' => RuntimeException::class,
                    'error_message' => sprintf(
                        'Meta WhatsApp Cloud API returned %d: %s',
                        $response->status(),
                        (string) $response->body(),
                    ),
                ];
            }

            $body = $response->json();
            $wamid = $body['messages'][0]['id'] ?? null;

            return [
                'ok' => true,
                'wamid' => $wamid !== null ? (string) $wamid : null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify the HMAC-SHA256 signature of a Meta WhatsApp webhook payload.
     * Meta's standard is `X-Hub-Signature-256: sha256=<hex>` over the raw
     * body, keyed by the per-account webhook secret (or the App Secret).
     *
     * Returns `false` when the webhook secret is not configured — refusing
     * every inbound webhook is the safe default for v1 (per Pasada B-1).
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->resolveWebhookSecret();
        if ($secret === null || $secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if ($header === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Fetch the templates currently approved for the account from Meta.
     * The adapter normalises the raw Meta response into the payload shape
     * consumed by {@see \App\Services\WhatsApp\WhatsAppService::syncTemplates()}.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchTemplates(WhatsAppAccount $account): array
    {
        if ($this->isStubMode()) {
            return [];
        }

        try {
            $businessId = $this->resolveBusinessId();
            if ($businessId === null) {
                return [];
            }

            $response = Http::withToken((string) $this->account->business_id)
                ->acceptJson()
                ->get(self::GRAPH_BASE.'/'.$businessId.'/message_templates', [
                    'limit' => 200,
                ]);

            if (! $response->successful()) {
                Log::warning('MetaWhatsAppProvider::fetchTemplates failed', [
                    'account_id' => $this->account->id,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $payload = $response->json();
            $raw = $payload['data'] ?? [];
            if (! is_array($raw)) {
                return [];
            }

            return array_values(array_map(
                fn (array $row): array => $this->normaliseTemplate($row),
                array_filter($raw, 'is_array'),
            ));
        } catch (\Throwable $e) {
            Log::warning('MetaWhatsAppProvider::fetchTemplates exception', [
                'account_id' => $this->account->id,
                'error_class' => $e::class,
            ]);

            return [];
        }
    }

    private function isStubMode(): bool
    {
        $businessId = $this->resolveBusinessId();
        $phoneNumberId = (string) ($this->account->phone_number_id ?? '');

        return $businessId === null || $businessId === '' || $phoneNumberId === '';
    }

    /**
     * @return array{ok: bool, error_class: string, error_message: string}
     */
    private function stubEnvelope(): array
    {
        return [
            'ok' => false,
            'error_class' => NotImplementedException::class,
            'error_message' => NotImplementedException::credentialsNotConfigured()->getMessage(),
        ];
    }

    private function messagesUrl(): string
    {
        return self::GRAPH_BASE.'/'.$this->account->phone_number_id.'/messages';
    }

    private function resolveWebhookSecret(): ?string
    {
        // The webhook secret column (`whatsapp_accounts.webhook_secret`)
        // is part of the planned v1 schema but the v1 migration shipped
        // without it; Pasada B-1 reads from the model's attribute bag
        // (set in-memory by tests) and falls back to config / env.
        $attribute = $this->account->getAttributes()['webhook_secret'] ?? null;
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        return config('integrations.whatsapp.webhook_secret')
            ?: env('INTEGRATIONS_WHATSAPP_WEBHOOK_SECRET');
    }

    private function resolveBusinessId(): ?string
    {
        $value = (string) ($this->account->business_id ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Convert raw Meta template payload into the normalised shape consumed
     * by WhatsAppService::syncTemplates().
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseTemplate(array $row): array
    {
        $components = $row['components'] ?? [];
        $body = null;
        $headerKind = null;
        $headerText = null;
        $footerText = null;
        $variables = [];

        if (is_array($components)) {
            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $type = $component['type'] ?? null;
                if ($type === 'BODY' && isset($component['text']) && is_string($component['text'])) {
                    $body = $component['text'];
                    // Meta exposes variables as `{{1}}`, `{{2}}`, ... in the
                    // body text. Collect them into the allow-list.
                    if (preg_match_all('/\{\{(\d+)\}\}/', $component['text'], $matches)) {
                        $variables = array_values(array_unique($matches[1]));
                    }
                } elseif ($type === 'HEADER') {
                    $headerKind = isset($component['format']) ? (string) $component['format'] : null;
                    $headerText = isset($component['text']) ? (string) $component['text'] : null;
                } elseif ($type === 'FOOTER' && isset($component['text'])) {
                    $footerText = (string) $component['text'];
                }
            }
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'language' => (string) ($row['language'] ?? 'es_PE'),
            'status' => (string) ($row['status'] ?? 'DRAFT'),
            'category' => isset($row['category']) ? (string) $row['category'] : null,
            'body' => $body,
            'header_kind' => $headerKind,
            'header_text' => $headerText,
            'footer_text' => $footerText,
            'variables' => $variables,
        ];
    }

    /**
     * Convert the variable substitution map into the Meta `components`
     * shape (`type=BODY`, `parameters=[{type=text, text=...}]`).
     *
     * @param  array<string, string|int>  $vars
     * @return list<array<string, mixed>>
     */
    private function buildComponents(array $vars): array
    {
        if ($vars === []) {
            return [];
        }

        $parameters = [];
        foreach ($vars as $key => $value) {
            $parameters[] = [
                'type' => 'text',
                'parameter_name' => is_string($key) ? $key : (string) $key,
                'text' => (string) $value,
            ];
        }

        return [
            [
                'type' => 'body',
                'parameters' => $parameters,
            ],
        ];
    }
}