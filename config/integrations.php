<?php

declare(strict_types=1);

use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\Contracts\WhatsAppProvider;

/*
|--------------------------------------------------------------------------
| V2 — Integrations feature flags and adapter registry (B11)
|--------------------------------------------------------------------------
|
| This file is the single source of truth for which V2 integration
| channels are enabled, the default test_mode posture, the webhook
| signature header per provider, and the canonical class name to
| resolve through the AdapterFactory.
|
| NO real credentials live here; secrets stay in `integration_accounts`
| (encrypted) and only non-secret flags live here. Per C-04 the `settings`
| table is for non-secret parameters only.
|
| Provider slug naming (mirrors docs/v2/01-roadmap.md §2.2):
|   email        -> smtp|gmail|outlook        (EmailProvider)
|   whatsapp     -> meta                       (WhatsAppProvider)
|   calendar     -> google|outlook             (CalendarProvider)
|   webforms     -> turnstile                  (captcha provider; not a contract)
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master per-channel enabled flag
    |--------------------------------------------------------------------------
    |
    | Default false. Each channel can be flipped without changing code.
    | AdapterFactory returns null when the requested channel is disabled,
    | so disabled channels behave like a no-op rather than throwing.
    |
    | `integrations.enabled_default` (env) seeds every missing key when
    | the channel is omitted from this map; the per-key value always wins.
    |
    */
    'enabled' => [
        'email' => env('INTEGRATIONS_ENABLED_EMAIL', env('INTEGRATIONS_ENABLED_DEFAULT', false)),
        'whatsapp' => env('INTEGRATIONS_ENABLED_WHATSAPP', env('INTEGRATIONS_ENABLED_DEFAULT', false)),
        'google_calendar' => env('INTEGRATIONS_ENABLED_GOOGLE_CALENDAR', env('INTEGRATIONS_ENABLED_DEFAULT', false)),
        'outlook_calendar' => env('INTEGRATIONS_ENABLED_OUTLOOK_CALENDAR', env('INTEGRATIONS_ENABLED_DEFAULT', false)),
        'webforms' => env('INTEGRATIONS_ENABLED_WEBFORMS', env('INTEGRATIONS_ENABLED_DEFAULT', false)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default test mode posture
    |--------------------------------------------------------------------------
    |
    | When true, adapters default to test mode even if the integration_account
    | row forgets to set it. In test mode no external HTTP call is made; the
    | adapter reports a synthetic success that the Job layer can audit.
    |
    */
    'default_test_mode' => (bool) env('INTEGRATIONS_DEFAULT_TEST_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Webhook verification policy (per provider)
    |--------------------------------------------------------------------------
    |
    | `signature_header` is the HTTP header that carries the provider's
    | HMAC/ECDSA signature. `outlook` has no signature header (Outlook
    | webhook validation uses a client-state shared secret, not a header
    | HMAC) and is therefore marked as null.
    |
    | `timestamp_window_seconds` rejects signed payloads older than the
    | window (replay-attack guard). 300 s = 5 minutes.
    |
    | `max_payload_bytes` rejects oversized requests before hashing.
    | 1 MiB default.
    |
    */
    'webhook' => [
        'signature_header' => [
            'meta' => env('INTEGRATIONS_WEBHOOK_HEADER_META', 'X-Hub-Signature-256'),
            'google' => env('INTEGRATIONS_WEBHOOK_HEADER_GOOGLE', 'X-Goog-Signature'),
            'outlook' => env('INTEGRATIONS_WEBHOOK_HEADER_OUTLOOK', null),
        ],
        'timestamp_window_seconds' => (int) env('INTEGRATIONS_WEBHOOK_TIMESTAMP_WINDOW', 300),
        'max_payload_bytes' => (int) env('INTEGRATIONS_WEBHOOK_MAX_BYTES', 1048576),
    ],

    /*
    |--------------------------------------------------------------------------
    | Adapter registry
    |--------------------------------------------------------------------------
    |
    | For every V2 channel we map a "kind" (the literal value stored in
    | integration_accounts.provider) to the concrete FQCN that implements
    | the contract. AdapterFactory::make() reads these maps.
    |
    | Only contract interfaces are listed here. Concrete adapters are added
    | in B13..B17 as they are implemented.
    |
    */

    'providers' => [

        // SMTP, Gmail, Outlook (EmailProvider contract).
        'email' => [
            'smtp' => [
                'contract' => EmailProvider::class,
                'class' => env('INTEGRATIONS_EMAIL_SMTP_CLASS'),
            ],
            'gmail' => [
                'contract' => EmailProvider::class,
                'class' => env('INTEGRATIONS_EMAIL_GMAIL_CLASS'),
            ],
            'outlook' => [
                'contract' => EmailProvider::class,
                'class' => env('INTEGRATIONS_EMAIL_OUTLOOK_CLASS'),
            ],
        ],

        // Meta WhatsApp Cloud API (WhatsAppProvider contract).
        'whatsapp' => [
            'meta' => [
                'contract' => WhatsAppProvider::class,
                'class' => env('INTEGRATIONS_WHATSAPP_META_CLASS'),
            ],
        ],

        // Google Calendar, Outlook Calendar (CalendarProvider contract).
        'calendar' => [
            'google' => [
                'contract' => CalendarProvider::class,
                'class' => env('INTEGRATIONS_CALENDAR_GOOGLE_CLASS'),
            ],
            'outlook' => [
                'contract' => CalendarProvider::class,
                'class' => env('INTEGRATIONS_CALENDAR_OUTLOOK_CLASS'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook signature verifier registry
    |--------------------------------------------------------------------------
    |
    | Maps a provider slug to the FQCN of the verifier used by the
    | signed.webhook middleware. Outlook has no header-based verifier
    | yet — B16 will add `OutlookSignatureVerifier` when the provider
    | arrives.
    |
    */
    'webhook_verifiers' => [
        'meta' => env('INTEGRATIONS_WEBHOOK_VERIFIER_META', \App\Integrations\Verification\MetaSignatureVerifier::class),
        'google' => env('INTEGRATIONS_WEBHOOK_VERIFIER_GOOGLE'),
        'outlook' => env('INTEGRATIONS_WEBHOOK_VERIFIER_OUTLOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis deployment policy (D-22)
    |--------------------------------------------------------------------------
    |
    | Production Redis is auto-hosted via Docker (no managed services).
    | These flags drive the docker-compose.v2.yml template and the runtime
    | assertions in B11 acceptance. They are documentation-grade; runtime
    | still reads REDIS_* env vars directly via config/database.php.
    |
    */
    'redis' => [
        // Internal Docker network; Redis MUST NOT be reachable from the internet.
        'network' => (bool) env('INTEGRATIONS_REDIS_NETWORK', true),
        // maxmemory limit + allkeys-lru eviction policy.
        'maxmemory' => env('INTEGRATIONS_REDIS_MAXMEMORY', '512mb'),
        // appendonly yes with everysec fsync for crash-safe persistence.
        'appendonly' => (bool) env('INTEGRATIONS_REDIS_APPENDONLY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging — PII redaction (B11 / docs/v2/01-roadmap.md §4-c)
    |--------------------------------------------------------------------------
    |
    | Drives RedactPiiProcessor when it is wired through
    | config/logging.php into the daily / single channels.
    |
    | `redaction_marker` is the literal that replaces a PII match.
    | `preserve_length` keeps the marker followed by the original length
    | so log readers can sanity-check the redaction.
    |
    */
'logging' => [
        'enabled' => (bool) env('INTEGRATIONS_LOG_PII_REDACTION', true),
        'redaction_marker' => env('INTEGRATIONS_LOG_REDACTION_MARKER', '[REDACTED]'),
        'preserve_length' => (bool) env('INTEGRATIONS_LOG_PRESERVE_LENGTH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook outbound destinations (B12 / docs/v2/01-roadmap.md §3.8)
    |--------------------------------------------------------------------------
    |
    | The WebhookAction refuses to dispatch to any URL not in this
    | allow-list. Operators add HTTPS endpoints here as comma-separated
    | full URLs in INTEGRATIONS_WEBHOOK_ALLOWED. An empty list means NO
    | webhooks may be dispatched — that is the safe default for B12.
    |
    */
    'webhooks' => [
        'allowed_destinations' => array_values(array_filter(array_map(
'trim',
explode(',', (string) env('INTEGRATIONS_WEBHOOK_ALLOWED', '')),
        ))),
    ],
];