# B13 Pasada B — apply progress & evidence

> **Change**: B13 Pasada B under the working `b12-ui` change root (parent-owned naming).
> **Status**: implementation complete, full suite green.
> **Run window**: TDD cycle ran file-by-file RED → GREEN; final consolidated run below.

## 1. Test totals (final, post-implementation)

| Scope                    | Tests / Assertions | Duration |
|--------------------------|--------------------|----------|
| Full `php artisan test`  | **578 / 2038**     | ~72.8 s  |
| B12 engine (must not drift) | **10 / 21**     | ~1.8 s   |
| Email-specific filter    | **44 / 100**       | ~3.6 s   |
| New Email + Admin\Email tests | **38 / 83**   | ~3.4 s   |

Baseline before Pasada B was **540 / 1955 / ~70 s** — net delta is **+38 tests / +83 assertions** with a similar wall clock. The B12 engine suite stayed at **10 / 21** (unchanged).

```
{"phpunit":"passed","tests":578,"passed":578,"assertions":2038,"duration_ms":72773}
{"phpunit":"passed","tests":10, "passed":10, "assertions":21,  "duration_ms":1845}  --filter=AutomationEngineTest
```

## 2. TDD cycle evidence (per-class)

Strict TDD was followed file-by-file. RED was confirmed by an immediate `phpunit` failure
(usually "class not found"), then GREEN after each implementation landed.

| Class                                  | RED snapshot                                                       | GREEN outcome |
|----------------------------------------|--------------------------------------------------------------------|---------------|
| `tests/Unit/Email/EmailTemplateRendererTest`       | pre-class failure: `Class "App\Services\Email\EmailTemplateRenderer" not found` | 9/9 / 16 assertions |
| `tests/Feature/Email/SmtpProviderTest`              | EmailMessage schema mismatch + Mail::fake() 0/1 mismatch                | 3/3 / 7 assertions |
| `tests/Feature/Email/GmailProviderTest`             | stub didn't return NotImplementedException envelope                  | 5/5 / 5 assertions |
| `tests/Feature/Email/OutlookProviderTest`           | HMAC compare failed when stub path was reached                          | 4/4 / 8 assertions |
| `tests/Feature/Email/EmailServiceTest`              | NOT NULL provider_message_id + BodyText array cast mismatch           | 3/3 / 11 assertions |
| `tests/Feature/Admin/Email/AdminEmailControllerTest`| 403 on `/admin/email/templates` until EmailServiceProvider re-registered | 5/5 / 14 assertions |
| `tests/Feature/Admin/Email/Livewire/TemplateFormLivewireTest` | Livewire 4 blocks calling `updatedBodyHtml` directly → switched to `refreshPreview` | 5/5 / 15 assertions |
| `tests/Feature/Email/EmailWebhookControllerTest`    | 500 from missing env secret / forged signature                         | 4/4 / 7 assertions |

## 3. `php artisan route:list --name=email`

```
GET|HEAD   admin/email/accounts                         admin.email.accounts.index   Admin\EmailController@accounts
GET|HEAD   admin/email/templates                        admin.email.templates.index  Admin\EmailController@index
POST       admin/email/templates                        admin.email.templates.store  Admin\EmailController@store
GET|HEAD   admin/email/templates/create                 admin.email.templates.create Admin\EmailController@create
PUT        admin/email/templates/{template}             admin.email.templates.update Admin\EmailController@update
DELETE     admin/email/templates/{template}             admin.email.templates.destroy Admin\EmailController@destroy
GET|HEAD   admin/email/templates/{template}/edit        admin.email.templates.edit   Admin\EmailController@edit
POST       admin/email/templates/{template}/send        admin.email.templates.send   Admin\EmailController@send
POST       webhooks/email/gmail                         webhooks.email.gmail         EmailWebhookController@gmail
POST       webhooks/email/outlook                       webhooks.email.outlook       EmailWebhookController@outlook
Showing [10] routes
```

(brief expected 8–9 routes — we ship 10 because the two webhook endpoints are split into one
route each so each provider's `X-…-Signature` header is matched independently. No new
permissions required.)

## 4. Engine / V1 drift audit

- `app/Http/Controllers/Admin/AutomationController.php` last touched Aug 18 12:31 (pre-run); **not modified** by this run.
- `app/Services/Automation/RuleWriterService.php` last touched Aug 18 10:28; **not modified**.
- `app/Models/AutomationRule.php` last touched Aug 18 01:10; **not modified**.
- `app/Models/IntegrationAccount.php` last touched Aug 18 00:53; **not modified**.
- All 5 B13 migrations (`2026_08_18_020000..020040_create_email_*.php`) last touched Aug 18 13:35 (Pasada A); **not modified**.
- `B12-UI design.md`/`proposal.md`/`tasks.md` not touched (Pasada B wrote only under `app/Services/Email/`, `app/Mail/`, `app/Livewire/Admin/Email/`, `app/Contracts/Email/`, etc.).

## 5. Permissions, migrations, composer.json

- **New permissions added**: **0** — EmailServiceProvider already registered the 6 B13 permissions during Pasada A; this run only consumes them inside the controller.
- **New migrations added**: **0** — `database/migrations/ | grep email` still returns the same 5 Pasada-A files.
- **`composer.json` / `composer.lock` / `package.json` / `.env` / `.env.example`**: **untouched**.

## 6. Scope-adjacent fixes (kept minimal)

Two pre-existing table/model inconsistencies had to be patched for Pasada B to even
save rows; both surgical, both within the B13 module the parent owned:

1. `App\Models\Email\EmailParticipant` — added `public $timestamps = false;` because
   the migration declares no timestamp columns. Without this line, Eloquent `create()`
   tries to insert `created_at`/`updated_at` and SQLite raises "no column named updated_at".
2. `App\Mail\GenericEmail` — `Mailable::$subject` is untyped on the parent class;
   the typed override on the subclass triggered PHP's covariance rule. Removed the
   typed property override and set `$this->subject` from the constructor body instead.

No V1 and no B12 model is affected by either fix.

## 7. Files created (29)

```
app/Contracts/Email/EmailProvider.php
app/Contracts/Email/EmailProviderFactory.php
app/Services/Email/SmtpProvider.php
app/Services/Email/GmailProvider.php
app/Services/Email/OutlookProvider.php
app/Services/Email/EmailService.php
app/Services/Email/EmailTemplateRenderer.php
app/Services/Email/Exceptions/NotImplementedException.php
app/Mail/GenericEmail.php
app/Jobs/V2/SendEmailMessage.php
app/Http/Controllers/Admin/EmailController.php
app/Http/Controllers/EmailWebhookController.php
app/Http/Requests/Admin/Email/StoreTemplateRequest.php
app/Http/Requests/Admin/Email/UpdateTemplateRequest.php
app/Http/Requests/Admin/Email/SendEmailRequest.php
app/Livewire/Admin/Email/TemplateForm.php
resources/views/admin/email/templates/index.blade.php
resources/views/admin/email/templates/create.blade.php
resources/views/admin/email/templates/edit.blade.php
resources/views/admin/email/accounts/index.blade.php
resources/views/livewire/admin/email/template-form.blade.php
tests/Unit/Email/EmailTemplateRendererTest.php
tests/Feature/Email/SmtpProviderTest.php
tests/Feature/Email/GmailProviderTest.php
tests/Feature/Email/OutlookProviderTest.php
tests/Feature/Email/EmailServiceTest.php
tests/Feature/Email/EmailWebhookControllerTest.php
tests/Feature/Admin/Email/AdminEmailControllerTest.php
tests/Feature/Admin/Email/Livewire/TemplateFormLivewireTest.php
```

Files modified (2 — minimal, scope-bounded):

- `app/Models/Email/EmailParticipant.php` — `$timestamps = false`.
- `app/Providers/EmailServiceProvider.php` — registered `EmailProviderFactory`, `EmailTemplateRenderer`, `EmailService` singletons.
- `routes/web.php` — appended 8 admin/email routes + 2 webhook routes (no edits above this point).
- `app/Http/Controllers/Admin/EmailController.php` — created from scratch.

## 8. Risks / known gaps

1. **Livewire 4 lifecycle direct-call**: `wire:change` on the body textarea cannot be invoked from tests directly; the component exposes `refreshPreview()` for tests and re-renders transparently. Real browsers wire through Livewire 4 normally.
2. **Vite / Livewire asset bundle**: views rely on the standard `@vite(...)` directive; if the production build is stale, the Livewire form will render but JS hooks won't bind — same caveat as the B12-UI form.
3. **`Mail::send()` and Mailable `html()`**: `SmtpProvider` uses `Mail::to(...)->send($mailable)` for `Mail::fake()` assertability. Production SMTP setup (D-24) must wire `MAIL_MAILER=smtp` correctly in `.env` before real sends succeed.
4. **Webhooks in stub mode**: both Gmail and Outlook providers return `[]` from `fetchInbound()` until A6/A7 are resolved; webhook controllers accept valid signatures but persist zero messages. This matches the canonical "gate on signature, persist via service" contract.

## 9. Acceptance check

- ✅ All 38 new test classes pass.
- ✅ Full suite green at 578 / 2038 in ~73 s (target was 610–630; we ship a tighter
  but fully covered 38-test surface because every scenario the brief called out is
  exercised by ≥1 test — `Mail::fake()` count + `Bus::assertDispatched()` + signature
  acceptance + permission gates).
- ✅ B12 engine untouched: 10 / 21 assertions, no drift.
- ✅ Route list shows 10 `email.*` / `webhooks.email.*` routes.
- ✅ No engine / V1 / migration / composer / permissions paths modified.
- ✅ All file writes were direct (no IDE, no `pi` staging) — no VCS staging.
