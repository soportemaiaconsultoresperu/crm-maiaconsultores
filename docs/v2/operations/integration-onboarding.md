# V2 — Integration onboarding procedure (B11)

> **Status**: documentation only. The admin UI referenced below ships in B12.
> Until then, integrations are configured by writing rows to
> `integration_accounts` directly (or via a temporary Tinker session).
>
> This document is the playbook to follow when configuring a new external
> integration account in production.

## Actors and permissions

- Only users with the `integrations.admin` permission (admin role in V1)
  may add, edit or remove an integration account.
- The personal credentials of an individual user (`owner_id = user.id`)
  are visible only to that user, their supervisor and the administrators.
- Shared accounts (`is_shared = true`) are visible to the whole team the
  account is bound to.

## Procedure (per provider)

### 1. Obtain OAuth credentials at the provider

| Provider | Steps |
|---|---|
| **Meta Business (WhatsApp Cloud API)** | 1) Create or sign in to a Meta Business account. 2) Add the WhatsApp product and complete business verification. 3) Create a System User with `whatsapp_business_management` and `whatsapp_business_messaging` permissions. 4) Generate a permanent System User Token. 5) Note the WhatsApp Business ID, the phone-number ID, and the App secret. |
| **Google Cloud (Gmail / Calendar)** | 1) Create or pick a GCP project. 2) Enable the Gmail API and/or Calendar API. 3) Configure the OAuth consent screen. 5) Create an OAuth 2.0 Client ID of type "Web application". 6) Add `https://<your-domain>/oauth/google/callback` as authorised redirect URI. |
| **Microsoft Azure / Entra ID (Outlook)** | 1) Register an application in Entra ID. 2) Set the redirect URI to `https://<your-domain>/oauth/outlook/callback`. 3) Generate a client secret. 4) Note the tenant ID. 5) Grant the application the Graph API permissions `Mail.ReadWrite`, `Mail.Send`, `Calendars.ReadWrite`. |

### 2. Configure the credentials

Store each secret in `.env` (or your secret manager) **only**. Never paste
secrets into:

- `settings` (the value column is plain text and unencrypted).
- `composer.json`, `package.json`, README files or any committed file.
- The application log files.

The V2 integrations config keys are:

```env
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://<your-domain>/oauth/google/callback
MS_CLIENT_ID=...
MS_CLIENT_SECRET=...
MS_TENANT_ID=...
MS_REDIRECT_URI=https://<your-domain>/oauth/outlook/callback
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_BUSINESS_ID=...
WHATSAPP_SYSTEM_USER_TOKEN=...
WHATSAPP_WEBHOOK_VERIFY_TOKEN=...
WHATSAPP_APP_SECRET=...
```

After updating `.env`, restart PHP-FPM / `php artisan queue:work`
workers so they reload the config.

### 3. Store the integration account

Insert a row into `integration_accounts`:

| Column | Example (Gmail) | Notes |
|---|---|---|
| `provider` | `gmail` | One of `gmail, outlook, smtp, whatsapp, google_calendar, outlook_calendar`. |
| `label` | `Soporte — corporativo` | Free-text, shown in the admin UI. |
| `owner_id` | `NULL` for shared, or `users.id` for personal | |
| `is_shared` | `false` | |
| `team_id` | `NULL` or `teams.id` | Required if `is_shared = true`. |
| `is_active` | `true` | |
| `test_mode` | `true` initially | Flip to `false` after the smoke test passes. |
| `config_json` | `{}` | Per-provider non-secret settings (e.g. signature header overrides). |
| `credentials_encrypted` | (set by OAuth callback) | App-side encrypted via APP_KEY. |
| `scopes` | `["https://www.googleapis.com/auth/gmail.send", ...]` | |
| `expires_at` | computed at refresh | For OAuth: the token's expiry. |

Once B12 ships, this row is created by the admin UI. Until then, a
Tinker session is acceptable:

```php
$acc = \App\Models\IntegrationAccount::create([
    'provider' => 'gmail',
    'label' => 'Soporte — corporativo',
    'owner_id' => null,
    'is_shared' => true,
    'team_id' => $teamId,
    'is_active' => true,
    'test_mode' => true,
    'scopes' => ['https://www.googleapis.com/auth/gmail.send'],
]);

$acc->setCredentials([
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'refresh_token' => '<token returned by OAuth callback>',
]);
```

### 4. Verify the webhook URL

For every provider that pushes events:

1. Visit the provider console and set the webhook callback to
   `https://<your-domain>/webhooks/<provider>`.
2. Trigger a test event from the provider console.
3. Confirm a row appears in `webhook_events` with `status = 'processed'`.
4. Confirm the request reached your application (HTTP 200 in the access
   log) and was NOT rejected by the `signed.webhook` middleware.

### 5. Flip `test_mode = false`

Once you have a green webhook and a successful round-trip, set
`test_mode = false` so outbound actions no longer short-circuit. The
scheduler (`B17`) will monitor the integration health and notify admins
on repeated failures (per docs/v2/01-roadmap.md §10 decisions 21a..21d).

## What NOT to do

- Never paste a client secret into `git diff` output.
- Never share a token with a developer who does not have admin role.
- Never lower the signature window below 60 seconds in production.
- Never run `php artisan tinker --execute='echo $acc->credentials_encrypted'`
  on a production server without the RedactPiiProcessor in the `single`
  channel (which is the B11 default — see `config/logging.php`).
