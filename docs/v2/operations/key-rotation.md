# V2 — APP_KEY rotation procedure (B11)

> **Status**: documentation only. The `Reencrypt` artisan command referenced
> below is **not yet implemented**; it lands in B12 along with the automation
> engine. This document is the playbook to follow when B12 ships.

## When to rotate

- Compromise suspected: rotate within 24 hours.
- Scheduled rotation: at least once per 12 months.
- After migrating secrets out of a staging environment that shared a key.

## Pre-flight checklist

- [ ] `php artisan down --secret=rotate-{timestamp}` is in effect.
- [ ] A full DB backup is verified (e.g. `mysqldump`/`pg_dump`).
- [ ] `APP_KEY` current value is saved in the password manager.
- [ ] A maintenance window is announced.

## Procedure

1. **Generate a new key**

   ```bash
   php artisan key:generate --show
   ```

   The flag prevents Laravel from overwriting `.env`. Capture the output
   (`base64:...`) into the password manager.

2. **Set the new key in `.env`**

   ```env
   APP_KEY=base64:NEW_KEY_HERE
   ```

   Do **not** commit `.env`. Reload PHP-FPM / php artisan queue:work
   workers so they pick up the new key.

3. **Re-encrypt stored credentials**

   B12 will provide:

   ```bash
   php artisan integrations:reencrypt --old=<old_key> --new=<new_key>
   ```

   This command walks every row of `integration_accounts` and re-encrypts
   the `credentials_encrypted` column. Until B12 lands, perform a manual
   round-trip with the temporary SQL harness:

   ```php
   // pseudo-flow (NOT production code)
   foreach (IntegrationAccount::withTrashed()->cursor() as $row) {
       $plain = Crypt::decryptString($row->credentials_encrypted);
       config(['app.key' => $newKey]);
       Crypt::storeConfigurationKey();
       $row->credentials_encrypted = Crypt::encryptString($plain);
       $row->saveQuietly();
   }
   ```

4. **Verify**

   - `php artisan tinker` and `IntegrationAccount::first()->getCredentials()`
     returns the original plaintext.
   - Decrypting the OLD ciphertext under the NEW key fails (DecryptException).
   - The webhook smoke test (`POST /webhooks/meta` with a known signature)
     still returns 200 OK.

5. **Bring the application back up**

   ```bash
   php artisan up
   ```

6. **Rotate the OLD key out of the password manager**

   Mark the previous APP_KEY entry as retired. Do not delete immediately;
   keep it for 30 days in case a forgotten row still references it.

## Rollback

If the `reencrypt` step fails mid-run, restore the database backup taken in
the pre-flight, then revert `.env` to the previous `APP_KEY`. The original
state is fully recoverable.

## What is NOT encrypted by APP_KEY

- `settings.value` (the value column is plain text). Rotating APP_KEY does
  NOT affect the `settings` table.
- `webhook_events.payload_hash` (SHA-256 of the raw body, not encrypted).
- `oauth_states.payload_json` (operator-visible audit metadata).

## Related

- `app/Integrations/Services/CredentialCipher.php` — wrap layer.
- `docs/v2/01-roadmap.md` §4 (B11 decisions 3a..3d).
- ADR-001 in `docs/adr/`.
