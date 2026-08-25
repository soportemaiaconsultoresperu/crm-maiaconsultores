# customer-payments-invoices — Apply Progress

## Slice 1 — Data, status catalog, and financial permissions

Status: completed for Work Unit 1 only. Work Units 2–6 were not implemented.

### Structured status consumed

- change: `customer-payments-invoices`
- artifact store: OpenSpec
- apply dependency: ready per parent runtime context
- actionContext: repo-local, workspace `C:/laragon/www/crm-maia-consultores`, allowed edit root same workspace
- strict TDD: active; runner `php artisan test` (executed with Laragon PHP path because `php` was not on PATH in Git Bash)
- delivery path: feature-branch-chain, slice `slice-1-data-status-catalog-permissions`, 400-line budget monitored

### Completed implementation tasks

- RED catalog tests added in `tests/Feature/InvoiceStatusCatalogTest.php`.
- RED permission/model tests added in `tests/Feature/CustomerInvoiceCrudTest.php`.
- GREEN foundation migration added for `customers.payment_modality`, `invoice_statuses`, and `customer_invoices`.
- GREEN models/factory added: `InvoiceStatus`, `CustomerInvoice`, `CustomerInvoiceFactory`; customer model/factory updated.
- GREEN catalog/permission plumbing updated for `invoice-statuses` and `customer-payments.view/manage`.
- TRIANGULATE coverage added for inactive/unknown status rejection and duplicate invoice number per customer.
- REFACTOR alignment: slugs/constants, fillables, audit traits, catalog label/icon/description, route regex, seeder idempotence.
- Evidence and rollback boundary recorded here.

### Files changed

- `app/Http/Controllers/CatalogController.php`
- `app/Http/Requests/CatalogStoreRequest.php`
- `app/Http/Requests/CatalogUpdateRequest.php`
- `app/Models/Customer.php`
- `app/Models/CustomerInvoice.php`
- `app/Models/InvoiceStatus.php`
- `app/Services/CatalogService.php`
- `database/factories/CustomerFactory.php`
- `database/factories/CustomerInvoiceFactory.php`
- `database/migrations/2026_08_21_000001_create_customer_payment_invoice_foundation.php`
- `database/seeders/CatalogSeeder.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `routes/web.php`
- `tests/Feature/CustomerInvoiceCrudTest.php`
- `tests/Feature/InvoiceStatusCatalogTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Catalog statuses/catalog route/no inline status creation | `tests/Feature/InvoiceStatusCatalogTest.php` | Feature | N/A (new behavior) | Written first; initial run failed on empty `invoice_statuses`, 404 catalog route, and status FK rejection | Passed after model/migration/seeder/catalog route implementation | Added no-inline-status rejection alongside seeded/status visibility scenarios | Constants/labels/icon/description aligned |
| Financial permissions/customer invoice model | `tests/Feature/CustomerInvoiceCrudTest.php` | Feature | N/A (new behavior) | Written before implementation; referenced missing `InvoiceStatus`/`CustomerInvoice` and missing permissions | Passed after permissions, models, relation, factory, and schema | Added inactive/unknown status and per-customer duplicate invoice number checks | Fillables/casts/scopes/audit traits aligned |

### Commands run

- `php artisan test --filter=InvoiceStatusCatalogTest` — failed because `php` was not on PATH in Git Bash.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceStatusCatalogTest` — RED failed as expected before GREEN; final result passed, 3 tests / 11 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerInvoiceCrudTest` — final result passed, 4 tests / 13 assertions.
- `git diff --stat`, `git diff --numstat`, `git status --short` — review budget/status evidence captured.

### Migration / seed / rollback notes

- Migration rollback boundary: `down()` drops `customer_invoices`, drops `invoice_statuses`, then drops `customers.payment_modality`.
- Functional rollback before dependent UI/calendar code: remove/disable the foundation migration/model/seeder/catalog/permission changes in this slice. No Work Unit 2–6 routes/controllers/UI/calendar processors depend on it yet.
- Seeder is idempotent for required statuses: `Pagado`, `Vencida`, `En proceso`, `Nota de crédito`.
- Role seeding grants `customer-payments.view` and `customer-payments.manage` to admin and supervisor; vendedor remains without financial permissions by default.

### Deviations from design

- To keep slice 1 near the 400-line review budget, the three schema changes were consolidated into one rollbackable foundation migration instead of three separate migration files. The schema content and rollback boundary remain aligned with design.
- No Work Unit 2 controller/request/service endpoints were implemented; status rejection tests use model/validator-level coverage appropriate to the foundation slice.

### Remaining tasks

Work Units 2–6 remain unchecked and out of scope for this run, plus parent lifecycle review gates remain deferred to parent.

### Workload / PR boundary

Current slice boundary: data/status catalog/permissions foundation only. Approximate diff remains slightly over budget when untracked files are counted (~400+ changed lines), but scope is a coherent single foundation unit and no UI/calendar/service endpoint work was added.

## Slice 2 — Invoice service, policy, validation, and CRUD endpoints

Status: completed for Work Unit 2 only. Work Units 3–6 were not implemented.

### Structured status consumed

- Initial injected status was authoritative but ambiguous/blocked; supervisor provided freshly resolved authoritative status for `customer-payments-invoices` with `applyState: ready`, OpenSpec artifacts present, repo-local workspace `C:/laragon/www/crm-maia-consultores`, and allowed edit root same workspace.
- Runtime authority: existing parent token `sha256:6962c67e3f7c2fff240ef462a69699f60eb9e8e6220a0c052febd8a71e104dff`; work unit `slice-2-invoice-service-policy-validation-crud`.
- Strict TDD: active; runner `php artisan test`, executed with Laragon PHP path.
- Delivery path: feature-branch-chain, slice 2 only. 400-line budget was monitored; this slice exceeded the nominal 400-line budget because required CRUD + policy + four FormRequests + service + focused feature coverage are a cohesive endpoint slice.

### Completed implementation tasks

- RED: Expanded `tests/Feature/CustomerInvoiceCrudTest.php` before implementation for create, validation errors, update uniqueness, mark-paid, retire/anul non-destructive behavior, read-only 403s, retired edit blocking, missing paid status controlled error, and prohibited out-of-scope fields.
- GREEN: Added `CustomerInvoicePolicy` and registered it in `AuthServiceProvider`.
- GREEN: Added FormRequests for payment modality, invoice store, invoice update, and retire with design §8 rules and prohibited out-of-scope metadata.
- GREEN: Added `CustomerInvoiceService` for create/update/mark-paid/retire/modality updates, audit activity events, active invoice invariants, and paid status slug resolution.
- GREEN: Added `CustomerInvoiceController` and routes for modality update, invoice create/update, mark-paid, and retire.
- TRIANGULATE: Added tests for retired invoice edit rejection, missing base paid status returning controlled validation errors, and rejection of payment date/reference/proof/partial/tax/line-item fields.
- REFACTOR: Kept controller thin; business rules and audit properties live in `CustomerInvoiceService`.
- Evidence and rollback boundary recorded here.

### Persisted task checkbox updates

All Work Unit 2 implementation-owned checkboxes were updated from `- [ ]` to `- [x]` in `openspec/changes/customer-payments-invoices/tasks.md` after the focused test passed.

### Files changed for slice 2

- `app/Http/Controllers/CustomerInvoiceController.php`
- `app/Http/Requests/CustomerInvoiceRetireRequest.php`
- `app/Http/Requests/CustomerInvoiceStoreRequest.php`
- `app/Http/Requests/CustomerInvoiceUpdateRequest.php`
- `app/Http/Requests/CustomerPaymentModalityUpdateRequest.php`
- `app/Policies/CustomerInvoicePolicy.php`
- `app/Providers/AuthServiceProvider.php`
- `app/Services/CustomerInvoiceService.php`
- `routes/web.php`
- `tests/Feature/CustomerInvoiceCrudTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Invoice CRUD endpoints, validation, policy, and service | `tests/Feature/CustomerInvoiceCrudTest.php` | Feature HTTP | Slice 1 model/catalog tests already green | Initial focused run failed with undefined routes for invoice CRUD endpoints (4 errors) | Passed after adding policy/provider registration, FormRequests, service, controller, and routes | Added/kept assertions for retired edit 403, missing `pagado` catalog error on mark-paid, and prohibited payment metadata/tax/line-item fields | Controller remains thin; service owns audit/event properties and lifecycle invariants |

### Commands run

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerInvoiceCrudTest` — RED failed as expected before GREEN: 8 tests, 4 passed, 4 route-definition errors.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerInvoiceCrudTest` — intermediate failed: 8 tests, 6 passed, 2 errors while tightening retired/missing-status behavior.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerInvoiceCrudTest` — final passed: 8 tests / 58 assertions.
- `git diff --stat`, `git diff --numstat`, `git status --short`, `wc -l` on slice 2 files — review budget/status evidence captured.

### HTTP/status/database evidence

- Create invoice: POST `customers/{customer}/invoices` redirects to `customers.show` and persists `customer_id`, `invoice_number`, `total_amount`, and `status_id`.
- Invalid create: due date, zero amount, unknown status, and prohibited payment metadata/tax/line item fields all produce validation errors.
- Update duplicate invoice number within the same customer produces validation error; valid update redirects to `customers.show`.
- Retired invoice normal edit returns 403.
- Mark-paid with prohibited payment metadata returns validation errors; mark-paid with empty payload redirects and only changes `status_id` to `Pagado` while preserving number, amount, and notes.
- Missing active `Pagado` base status redirects to `customers.show` with controlled `status` validation error.
- Retire/anul redirects, sets `retired_at`, `retired_by`, `retire_reason`, and leaves `deleted_at` null.
- Read-only financial user attempts against modality update, create, update, mark-paid, and retire all return 403.

### Deviations from design

- No Work Unit 3 overdue processor invocation was added to `CustomerInvoiceService`; that is explicitly reserved for Work Unit 3.
- Because `AuthServiceProvider` has an admin Gate::before bypass, FormRequest/controller active-invariant checks explicitly block retired invoice normal edits/mark-paid before service mutation so admin cannot accidentally bypass the retired/anul lifecycle boundary.

### Remaining tasks

Work Units 3–6 remain unchecked and out of scope for this run, plus parent lifecycle review gates remain deferred to parent. Exact remaining unchecked implementation rows include:

- [ ] RED: Add failing `tests/Feature/OverdueInvoiceProcessorTest.php` or `tests/Unit/OverdueInvoiceProcessorTest.php` for En proceso past due transition, Pagado protection, Nota de crédito protection, retired protection, idempotent repeated processing, and deterministic `--date`. <!-- sdd-owner: implementation -->
- [ ] GREEN: Add `app/Services/OverdueInvoiceProcessor.php` and result object/discovery target `app/Support/Invoices/OverdueInvoiceResult.php` or nearest project support namespace. <!-- sdd-owner: implementation -->
- [ ] GREEN: Add Artisan command at `app/Console/Commands/MarkOverdueInvoicesCommand.php` with signature `invoices:mark-overdue {--date=}` and register/schedule it in the project console scheduling location (`app/Console/Kernel.php` or Laravel 13 equivalent). <!-- sdd-owner: implementation -->
- [ ] GREEN: Invoke `OverdueInvoiceProcessor::processInvoice()` only after explicit invoice writes in `app/Services/CustomerInvoiceService.php` when due date/status changes could make an invoice immediately overdue. <!-- sdd-owner: implementation -->
- [ ] TRIANGULATE: Add tests or assertions proving `CustomerController::show()` and calendar GET requests do not mutate invoice statuses. <!-- sdd-owner: implementation -->
- [ ] REFACTOR: Centralize status slug constants in `app/Models/InvoiceStatus.php` and query scopes in `app/Models/CustomerInvoice.php` to avoid label/string drift. <!-- sdd-owner: implementation -->
- [ ] Evidence: Run `php artisan test --filter=OverdueInvoiceProcessorTest` and `php artisan invoices:mark-overdue --date=2026-09-16` in a safe test/local DB context if available. <!-- sdd-owner: implementation -->
- [ ] Rollback boundary: disable scheduled command and remove processor invocation; invoice CRUD remains usable with manual statuses. <!-- sdd-owner: implementation -->
- Work Units 4–6 rows remain unchecked in `tasks.md`.

### Workload / PR boundary

Current slice boundary: invoice service, policy, validation, and CRUD endpoints only. No UI card, overdue processor/command, calendar projection, verify/sync/archive, or commit was performed. Slice 2 alone is above the 400-line nominal review budget (new/changed slice files total roughly 700+ lines, dominated by required feature coverage and service/request/controller code), so reviewer should treat this as a size-risk slice or split tests/code further in parent packaging if required.

### Rollback boundary

Remove invoice CRUD routes/controller/service/requests/policy and the provider policy mapping while leaving the slice 1 foundation schema/catalog/permissions intact.

## Slice 3 — Persisted automatic overdue processing

Status: completed for Work Unit 3 only. Work Units 4–6 were not implemented.

### Structured status consumed

- Parent provided fresh authoritative status for exact change `customer-payments-invoices`: OpenSpec store, change root `C:/laragon/www/crm-maia-consultores/openspec/changes/customer-payments-invoices`, apply ready, no blockers, repo-local workspace and allowed edit root `C:/laragon/www/crm-maia-consultores`.
- Runtime authority: attempt state `proceed`; work unit `slice-3-persisted-automatic-overdue-processing`; parent retained token `sha256:1750be473b18eb94f1aadb92fcf0b7e881e02bf5bd86d18f5e315b0daabb792a`.
- Strict TDD: active; runner `php artisan test`, executed with Laragon PHP path.
- Delivery path: feature-branch-chain, slice 3 only. Work Units 4–6, verify, sync, archive, and commits were out of scope.

### Completed implementation tasks

- RED: Added `tests/Feature/OverdueInvoiceProcessorTest.php` covering En proceso past-due transition, Pagado protection, Nota de crédito protection, retired protection, idempotent repeated processing/audit, deterministic `--date`, immediate processing after explicit invoice writes, and no mutation from customer/calendar GETs.
- GREEN: Added `app/Services/OverdueInvoiceProcessor.php` and `app/Support/Invoices/OverdueInvoiceResult.php`.
- GREEN: Added `app/Console/Commands/MarkOverdueInvoicesCommand.php` with signature `invoices:mark-overdue {--date=}` and scheduled it in `routes/console.php` at 00:10 America/Lima with `withoutOverlapping()`.
- GREEN: Updated `app/Services/CustomerInvoiceService.php` to call `OverdueInvoiceProcessor::processInvoice()` only after explicit create/update writes.
- TRIANGULATE: Added HTTP assertions that `CustomerController::show()` and `calendar.index` GET requests leave overdue En proceso invoices unchanged.
- REFACTOR: Centralized overdue-excluded status slugs in `InvoiceStatus::overdueExcludedSlugs()` and added `CustomerInvoice::eligibleForOverdueProcessing()` / `isEligibleForOverdueProcessing()` query/domain helpers.
- Evidence and rollback boundary recorded here.

### Persisted task checkbox updates

All Work Unit 3 implementation-owned checkboxes were updated from `- [ ]` to `- [x]` in `openspec/changes/customer-payments-invoices/tasks.md` after focused tests passed. Work Units 4–6 and parent lifecycle rows remain unchecked/deferred.

### Files changed for slice 3

- `app/Console/Commands/MarkOverdueInvoicesCommand.php`
- `app/Models/CustomerInvoice.php`
- `app/Models/InvoiceStatus.php`
- `app/Services/CustomerInvoiceService.php`
- `app/Services/OverdueInvoiceProcessor.php`
- `app/Support/Invoices/OverdueInvoiceResult.php`
- `routes/console.php`
- `tests/Feature/OverdueInvoiceProcessorTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Persist automatic Vencida processing | `tests/Feature/OverdueInvoiceProcessorTest.php` | Feature/service/command | Work Units 1–2 foundation and CRUD tests | Initial focused run failed: missing `OverdueInvoiceProcessor`, missing `invoices:mark-overdue` command, and explicit-write invoice remained `en-proceso` | Passed after processor/result, command, schedule, and explicit write invocation were added | Added protections for Pagado, Nota de crédito, retired invoices; idempotent repeated processing/audit; deterministic `--date`; customer/calendar GET no-write assertions | Moved excluded slug list into `InvoiceStatus` and overdue eligibility into `CustomerInvoice` helpers |

### Commands run

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=OverdueInvoiceProcessorTest` — RED failed as expected before GREEN: 5 tests, 0 passed, 4 errors and 1 failure for missing processor/command/write invocation.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=OverdueInvoiceProcessorTest` — final passed: 5 tests / 21 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan invoices:mark-overdue --date=2026-09-16` — attempted in local DB context; failed because local MySQL on `127.0.0.1:3306` refused connection. The command behavior itself is covered by the passing deterministic command test on the test database.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerInvoiceCrudTest` — passed: 8 tests / 58 assertions after adding processor invocation to invoice writes.
- `git diff --stat`, `git diff --numstat`, `git diff --cached --stat`, `git status --short`, and `wc -l` on slice 3 files — review/status evidence captured.

### Behavior evidence

- `En proceso` invoices with `due_date < today` and active/non-retired state persist `status_id` to the catalog `Vencida` status.
- `Pagado`, `Nota de crédito`, already `Vencida`, and retired/anulled invoices are excluded from automatic transition.
- Re-running the processor returns zero additional updates and does not duplicate the `customer-invoice-marked-overdue` audit event.
- `--date=2026-09-16` marks invoices due before that date and leaves same-day invoices unchanged.
- Explicit invoice create/update writes can immediately persist `Vencida` when the saved invoice is already overdue.
- GET requests for customer detail and calendar do not mutate invoice statuses.

### Deviations from design

- Scheduling was registered in Laravel's project scheduling file `routes/console.php` rather than `app/Console/Kernel.php`, matching this Laravel 13 project structure.
- The direct local Artisan command could not run against MySQL because the DB server was unavailable; the same command path is tested through Laravel's in-memory test database.
- Existing Work Units 1–2 files were untracked in this checkout, so slice 3 changes are layered on top of prior uncommitted slice files.

### Remaining tasks

Work Units 4–6 remain unchecked and out of scope for this run, plus parent lifecycle review gates remain deferred to parent. Exact remaining unchecked implementation rows include:

- [ ] RED: Add failing `tests/Feature/CustomerPaymentsCardTest.php` scenarios for authorized card visibility, unauthorized hiding, modality display/empty state, writer modality update, read-only rejection, invoice list rows, no-invoices empty state, and v1 no-goal fields absent. <!-- sdd-owner: implementation -->
- [ ] GREEN: Update `app/Http/Controllers/CustomerController.php` to load `invoices.status`, active `invoiceStatuses`, and `canViewPayments`/`canManagePayments` only when gates allow. <!-- sdd-owner: implementation -->
- [ ] GREEN: Create `resources/views/customers/_payments_card.blade.php` with modality, invoice list, empty state, permitted CTAs/actions, and no partial-payment/accounting fields. <!-- sdd-owner: implementation -->
- [ ] GREEN: Include the partial in `resources/views/customers/show.blade.php` near existing financial/commercial cards without degrading `Datos del cliente`, `Contactos`, `Historial comercial`, `Actividades`, `Cotizaciones`, `Documentos`, or `customers._products_card`. <!-- sdd-owner: implementation -->
- [ ] TRIANGULATE: Update `tests/Feature/CustomerHttpTest.php` to prove existing customer detail content still renders and Pagos appears only with `customer-payments.view`. <!-- sdd-owner: implementation -->
- [ ] REFACTOR: Extract repeated formatting/authorization snippets inside the Blade partial only if it reduces complexity without broad layout churn. <!-- sdd-owner: implementation -->
- [ ] Evidence: Run `php artisan test --filter=CustomerPaymentsCardTest` and the updated `CustomerHttpTest` filter. <!-- sdd-owner: implementation -->
- [ ] Rollback boundary: remove `_payments_card` include and financial loads/flags; keep backend routes/data intact for later re-enable. <!-- sdd-owner: implementation -->
- Work Units 5–6 rows remain unchecked in `tasks.md`.

### Workload / PR boundary

Current s

## Slice 4 — Customer detail Pagos card and modality UI

Status: completed for Work Unit 4 only. Work Units 5–6 were not implemented.

### Structured status consumed

- Parent provided authoritative status for exact change `customer-payments-invoices`: OpenSpec store, proposal/specs/design/tasks/apply-progress present, apply ready, no blockers, repo-local workspace and allowed edit root `C:/laragon/www/crm-maia-consultores`.
- Runtime authority: attempt state `proceed`; work unit `slice-4-customer-detail-payments-card-ui`; parent retained token `sha256:fc1a5b9804a9b3e791251d2cea79fe2b916fc282926393ca87e1922813bd6d6b`.
- Strict TDD: active; runner `php artisan test`, executed with Laragon PHP path.
- Delivery path: feature-branch-chain, slice 4 only. Work Units 5–6, verify, sync, archive, and commits were out of scope.

### Completed implementation tasks

- RED: Added `tests/Feature/CustomerPaymentsCardTest.php` scenarios for authorized visibility, unauthorized hiding, modality display/empty state, writer modality update, read-only rejection, invoice rows, no-invoices empty state, and v1 no-goal fields absent.
- GREEN: Updated `app/Http/Controllers/CustomerController.php` to set `canViewPayments`/`canManagePayments`, load `invoices.status` only for authorized financial readers, and load active `invoiceStatuses` only for financial managers.
- GREEN: Created `resources/views/customers/_payments_card.blade.php` for modality display/update, invoice table/empty state, permission-gated CTAs/actions, and no partial-payment/accounting fields.
- GREEN/REFACTOR: Created `resources/views/customers/_invoice_form.blade.php` to keep invoice create/edit form markup local to the payments card slice and reduce repeated Blade inside the card partial.
- GREEN: Included the payments partial in `resources/views/customers/show.blade.php` between the commercial quotation/product area without removing existing cards.
- TRIANGULATE: Updated `tests/Feature/CustomerHttpTest.php` to prove existing customer detail cards still render and `Pagos` appears only for `customer-payments.view` users.
- Evidence and rollback boundary recorded here.

### Persisted task checkbox updates

All Work Unit 4 implementation-owned checkboxes were updated from `- [ ]` to `- [x]` in `openspec/changes/customer-payments-invoices/tasks.md` after focused tests passed. Work Units 5–6 and parent lifecycle rows remain unchecked/deferred.

### Files changed for slice 4

- `app/Http/Controllers/CustomerController.php`
- `resources/views/customers/_payments_card.blade.php`
- `resources/views/customers/_invoice_form.blade.php`
- `resources/views/customers/show.blade.php`
- `tests/Feature/CustomerPaymentsCardTest.php`
- `tests/Feature/CustomerHttpTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Customer Pagos card visibility, modality, invoice list, and v1 scope | `tests/Feature/CustomerPaymentsCardTest.php` | Feature HTTP/UI | Work Units 1–3 foundation/CRUD/overdue tests existed | Initial focused run failed because the customer detail did not contain `Pagos`, modality empty state, or invoice rows | Passed after controller financial flags/loads and payments card partials were added | Covered unauthorized users not seeing card/invoice data, read-only users seeing no write controls and receiving 403 on modality writes, no-invoice empty state, invoice row fields/actions, and no partial/reference/reconciliation/accounting UI copy | Extracted reusable local invoice form partial to keep repeated create/edit markup out of the card partial |
| Existing customer detail regression and financial read gate | `tests/Feature/CustomerHttpTest.php` | Feature HTTP/UI | Existing customer detail HTTP tests | Updated regression after GREEN to assert existing cards and financial visibility boundary | Passed with payments include near commercial cards | Proved salesperson without `customer-payments.view` keeps existing detail content but does not see `Pagos`/modality; admin sees `Pagos` and existing cards | No broad layout refactor |

### Commands run

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerPaymentsCardTest` — RED failed as expected before GREEN: missing `Pagos`, `Modalidad pendiente`, and invoice row content on customer detail.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerPaymentsCardTest` — final passed: 6 tests / 41 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerHttpTest` — passed: 9 tests / 61 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerPaymentsCardTest && /c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CustomerHttpTest` — final combined focused rerun passed: 6 tests / 41 assertions and 9 tests / 61 assertions.
- `git diff --stat`, `git diff --numstat`, `git diff --cached --stat`, `git status --short`, `git ls-files --others --exclude-standard`, and `wc -l` for slice 4 new files — review/status evidence captured.

### Behavior evidence

- Users with customer access plus `customer-payments.view` see the `Pagos` card with modality and invoice content.
- Users without financial read permission can still view allowed customer detail content but do not see `Pagos`, invoice numbers, amounts, or statuses.
- Financial writers can update customer-level `payment_modality`; read-only financial users see no modality/invoice write controls and POST attempts are forbidden.
- Invoice rows show invoice number, due date, total amount, persisted catalog status, notes, and writer actions.
- Customers without invoices show a useful empty state; the create CTA appears only for writers.
- V1 no-goal UI copy/controls for partial payments, payment references, reconciliation, invoice lines, and accounting integration are absent from the card/forms.

### Deviations from design

- The card includes compact Bootstrap modal forms for invoice create/edit to support permitted CTAs/actions, but no new backend behavior beyond existing Work Unit 2 routes/services was added.
- `CustomerController::show()` uses gate checks only after the normal customer `view` authorization succeeds; it does not perform any invoice/status loading for users without `customer-payments.view`.

### Remaining tasks

Work Units 5–6 remain unchecked and out of scope for this run, plus parent lifecycle review gates remain deferred to parent. Exact remaining unchecked implementation rows are the Work Unit 5 calendar projection rows and Work Unit 6 integration/full-regression rows still visible in `tasks.md`.

### Workload / PR boundary

Current slice boundary: customer detail Pagos card UI and focused UI/HTTP regression tests only. No Work Unit 5 calendar projection, Work Unit 6 integration, verify/sync/archive, commit, or delivery gate validation was performed. Slice 4 code/test/view changes are near the 400-line review budget; OpenSpec task/progress artifact edits are additional lifecycle evidence.

### Rollback boundary

Remove the `_payments_card` include from `resources/views/customers/show.blade.php`, remove the local payments/invoice Blade partials, and revert the financial loads/flags in `CustomerController::show()`. Keep backend routes/data/services from Work Units 1–3 intact for later re-enable.

## Slice 5 — Calendar invoice projection

Status: completed for Work Unit 5 only. Work Unit 6 was not implemented; verify/sync/archive and commits were not performed.

### Structured status consumed

- Parent provided authoritative status for exact change `customer-payments-invoices`: OpenSpec store, proposal/specs/design/tasks/apply-progress present, apply ready, no blockers, repo-local workspace and allowed edit root `C:/laragon/www/crm-maia-consultores`.
- Runtime authority: attempt state `proceed`; work unit `slice-5-calendar-invoice-projection`; parent retained token `sha256:6373faceb47d0c8c7c2eed1a2d91c236c3f062f0e3235794934ecbf3d8a5d766`.
- Strict TDD: active; runner `php artisan test`, executed with Laragon PHP path.
- Delivery path: feature-branch-chain, slice 5 only. Max changed lines authorized: 500 for forecasted calendar refactor; scope kept to calendar projection/tests plus one date-query scope fix required for SQLite/date compatibility.

### Completed implementation tasks

- RED: Added `tests/Feature/InvoiceCalendarAlertsTest.php` covering En proceso/Vencida calendar visibility, persisted Vencida after command, due-date move, duplicate prevention after repeated saves, Pagado/Nota de crédito/retired suppression, customer/invoice links, unauthorized financial visibility suppression, and no external notifications.
- RED: Updated `tests/Feature/CalendarQueryTest.php` to preserve activity calendar behavior while adding `CalendarEventService` coverage for non-Activity invoice event DTOs.
- GREEN: Added `app/Support/Calendar/CalendarEventItem.php` and `app/Services/CalendarEventService.php` to combine `ActivityService::calendarEvents()` with query-time active chargeable invoice due-date projections gated by `customer-payments.view`.
- GREEN: Updated `app/Http/Controllers/CalendarController.php` to consume `CalendarEventService` without changing the `calendar.index` route.
- GREEN: Refactored calendar month/week/day/list views to consume DTO fields (`url`, `title`, `status`, `typeLabel`, `subjectLabel`, `ownerName`, `scheduled_at`) instead of `Activity` model relations/routes.
- TRIANGULATE: Covered `type_id` applying only to activities, `owner_id` and `subject_type=customer` including invoice events, and users with `calendar.view` but without `customer-payments.view` not seeing invoice events/details.
- REFACTOR: Invoice calendar items are query-time DTO projections only; no persisted calendar/task rows or notification dispatches were added.
- Evidence and rollback boundary recorded here.

### Persisted task checkbox updates

All Work Unit 5 implementation-owned checkboxes were updated from `- [ ]` to `- [x]` in `openspec/changes/customer-payments-invoices/tasks.md` after focused tests passed. Work Unit 6 and parent lifecycle rows remain unchecked/deferred.

### Files changed for slice 5

- `app/Http/Controllers/CalendarController.php`
- `app/Models/CustomerInvoice.php`
- `app/Services/CalendarEventService.php`
- `app/Support/Calendar/CalendarEventItem.php`
- `resources/views/calendar/month.blade.php`
- `resources/views/calendar/week.blade.php`
- `resources/views/calendar/day.blade.php`
- `resources/views/calendar/list.blade.php`
- `tests/Feature/InvoiceCalendarAlertsTest.php`
- `tests/Feature/CalendarQueryTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Invoice due-date calendar projection | `tests/Feature/InvoiceCalendarAlertsTest.php` | Feature HTTP/command/UI | Work Units 1–4 invoice CRUD/overdue/customer UI coverage existed | Initial focused run failed because calendar rendered 0 invoice events and did not expose invoice numbers/links | Passed after DTO/service/controller/view projection implementation and due-date query-scope fix | Covered paid/credit-note/retired suppression, unauthorized financial user suppression, duplicate prevention, due-date move, persisted Vencida after command, and no notification side effects | Kept projection query-time only; no persisted calendar rows/tasks/notifications |
| Activity calendar compatibility with non-Activity DTOs | `tests/Feature/CalendarQueryTest.php` | Feature/service | Existing ActivityService calendar query tests | New service assertions initially failed because only activity events were returned and Eloquent collection merge expected models | Passed after service returned base DTO collections and combined invoice items | Covered type/owner/subject filters and calendar-only user financial visibility boundary | ActivityService remains the activity query source; CalendarEventService owns mixed DTO composition |

### Commands run

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceCalendarAlertsTest` — RED failed as expected before GREEN: invoice calendar pages rendered no invoice events/details.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CalendarQueryTest::test_calendar_event_service_preserves_activities_and_adds_invoice_event_items` — RED/GREEN intermediate failed because mixed events initially returned only activities; later exposed a date-scope issue for SQLite date columns.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CalendarQueryTest` — first full run timed out at 120s once; rerun with 180s passed: 10 tests / 27 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceCalendarAlertsTest` — passed: 4 tests / 31 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceCalendarAlertsTest && /c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=CalendarQueryTest` — final combined focused rerun passed: 4 tests / 31 assertions and 10 tests / 27 assertions.
- `git status --short`, `git diff --stat`, `git diff --numstat` — review/status evidence captured.

### Behavior evidence

- Authorized calendar users with `customer-payments.view` see active chargeable invoice due-date events alongside existing activities.
- En proceso and Vencida invoices are visible; after `invoices:mark-overdue --date=...`, calendar reads the persisted Vencida status.
- Pagado, Nota de crédito, and retired/anulled invoices are suppressed.
- Re-saving an invoice without alert-relevant changes does not create duplicates; changing due date moves the query-time event without leaving an orphan on the old date.
- Invoice event title/subject/link include customer/invoice context and route back to `customers.show` with an invoice anchor.
- `type_id` filters only activities and suppresses invoice events for that type-specific view; `owner_id` filters invoice events through the customer owner; `subject_type=customer` includes invoice events while other subject types do not.
- Users with `calendar.view` but without `customer-payments.view` keep activity calendar access and do not receive financial invoice events/details.
- Mail, Notification, and Queue fakes assert calendar projection dispatches no external/customer notifications.

### Deviations from design

- `CustomerInvoice::scopeDueBetween()` was tightened to use `whereDate >=` / `whereDate <=` rather than raw `whereBetween` strings so date-cast values stored as datetime-compatible strings in SQLite/test DB match full-day ranges. This preserves the design contract and avoids hidden GET writes.
- Existing calendar comments still reference ActivityService as the underlying activity source, but mixed-event composition is now owned by `CalendarEventService`.

### Remaining tasks

Work Unit 6 remains unchecked and out of scope for this run, plus parent lifecycle review gates remain deferred to parent. Exact remaining unchecked implementation rows include:

- [ ] RED: Add any missing regression tests discovered during integration under `tests/Feature/CustomerPaymentsCardTest.php`, `tests/Feature/CustomerInvoiceCrudTest.php`, `tests/Feature/OverdueInvoiceProcessorTest.php`, `tests/Feature/InvoiceCalendarAlertsTest.php`, or `tests/Feature/CalendarQueryTest.php` before fixing defects. <!-- sdd-owner: implementation -->
- [ ] GREEN: Fix integration failures across migrations, seeders, permissions, customer UI, invoice writes, overdue command, and calendar projection without widening v1 scope. <!-- sdd-owner: implementation -->
- [ ] TRIANGULATE: Run targeted filters plus `php artisan test` if budget/time permits; if full suite cannot run, document skipped validation and rationale in apply evidence. <!-- sdd-owner: implementation -->
- [ ] REFACTOR: Review changed-line budget and split work-unit commits according to `work-unit-commits` skill; keep tests with the behavior they verify. <!-- sdd-owner: implementation -->
- [ ] Evidence: Capture `git diff --stat` or equivalent file/line summary, commands run, residual risks, and manual notes for reviewer. <!-- sdd-owner: implementation -->
- [ ] Rollback boundary: each prior work unit can be reverted independently; calendar projection and customer UI are safe functional rollback points before schema rollback. <!-- sdd-owner: implementation -->

### Workload / PR boundary

Current slice boundary: calendar invoice projection only. No Work Unit 6 integration/full regression, verify/sync/archive, commit, or delivery gate validation was performed. Slice 5 touches calendar service/controller/views plus focused tests and one invoice date-scope helper; review budget risk remains medium-high because prior slice files are still uncommitted/untracked in this checkout and `git diff --stat` includes earlier work-unit modifications.

### Rollback boundary

Switch `CalendarController` back to `ActivityService::calendarEvents()`, remove `CalendarEventService` / `CalendarEventItem`, and revert calendar views to Activity assumptions. Invoice CRUD, overdue processing, and customer Pagos UI remain intact.

## Slice 6 — Integration, full regression, and cleanup

Status: completed for Work Unit 6 only. Verify/sync/archive, bounded review, commits, and PR packaging were not performed.

### Structured status consumed

- User provided exact change `customer-payments-invoices` overriding the inherited ambiguous native status; OpenSpec store, repo-local workspace `C:/laragon/www/crm-maia-consultores`, allowed edit root same workspace.
- Runtime authority: `sdd-attempt acquire` state `proceed`; work unit `slice-6-integration-regression-cleanup`; parent retained token `sha256:cfb1537f58dec5d40fb75c3c89f9125250d60f8f6d1b8da1253ec4caafe649b2`.
- Strict TDD: active; runner `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test`.
- Delivery path: feature-branch-chain, slice 6 only; max changed lines 600 for integration fixes/evidence.

### Completed implementation tasks

- RED: Full-suite integration run exposed stale permission/seeder regression expectations after the customer-payments permission additions. Existing failing `RolesAndPermissionsTest` and `SeedersTest` assertions served as RED coverage before fixes.
- GREEN: Updated permission/seeder regression expectations to include `customer-payments.view` and `customer-payments.manage`, assert their presence, and align current permission/settings idempotence counts without adding product scope.
- TRIANGULATE: Re-ran all requested slice 1–5 focused filters plus updated permission/seeder filters. Re-ran the full suite after fixes.
- REFACTOR: Reviewed diff/stat and staged state; no commits were made. Work unit 6 changes are limited to integration test expectation updates plus OpenSpec task/progress evidence.
- Evidence and rollback boundary recorded here.

### Persisted task checkbox updates

All Work Unit 6 implementation-owned checkboxes were updated from `- [ ]` to `- [x]` in `openspec/changes/customer-payments-invoices/tasks.md`. Parent lifecycle rows remain unchecked/deferred.

### Files changed for slice 6

- `tests/Feature/RolesAndPermissionsTest.php`
- `tests/Feature/SeedersTest.php`
- `openspec/changes/customer-payments-invoices/tasks.md`
- `openspec/changes/customer-payments-invoices/apply-progress.md`

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Permission/seeder integration counts after customer financial permissions | `tests/Feature/RolesAndPermissionsTest.php`, `tests/Feature/SeedersTest.php` | Feature/seeder regression | Slice 1–5 focused tests were green before full-suite run | Full `artisan test` failed on stale permission counts: 89 vs 67 baseline, 106 vs 84 additional/full-seed counts, admin grants 69/81 vs older expectations | Passed after updating assertions/comments and adding explicit checks for `customer-payments.view/manage` | Requested invoice/customer/calendar filters plus RolesAndPermissionsTest and SeedersTest all passed; full suite was re-run | No production code refactor; test updates are integration evidence only |

### Commands run

- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceStatusCatalogTest && ... --filter=CalendarQueryTest` — initial requested focused filters passed: InvoiceStatusCatalogTest 3/11, CustomerInvoiceCrudTest 8/58, OverdueInvoiceProcessorTest 5/21, CustomerPaymentsCardTest 6/41, CustomerHttpTest 9/61, InvoiceCalendarAlertsTest 4/31, CalendarQueryTest 10/27.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test` — RED/integration run failed: 702 tests, 682 passed, 8 failures and 12 errors. Payment-related failures were stale RolesAndPermissionsTest/SeedersTest counts; remaining settings-audit and campaign-admin errors were unrelated pre-existing/broader-suite failures.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=RolesAndPermissionsTest` — passed after integration update: 10 tests / 69 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=SeedersTest` — passed after integration update: 2 tests / 29 assertions.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=InvoiceStatusCatalogTest && ... --filter=SeedersTest` — final focused integration chain passed: 57 tests / 348 assertions across requested filters plus permission/seeder regression filters.
- `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test` — final full suite still failed outside this feature scope: 702 tests, 688 passed, 2 settings-audit failures and 12 campaign setup errors where tests resolve `env('ADMIN_EMAIL')` to no user after only seeding roles/catalogs.
- `git diff --stat`, `git diff --numstat`, `git diff --cached --stat`, `git status --short`, and `git ls-files --others --exclude-standard | xargs -r wc -l` — review/status evidence captured.

### Diff / workload evidence

- Tracked diff after slice 6 and prior slices: 23 tracked files, 291 insertions / 99 deletions. This tracked stat includes prior uncommitted slice 1–5 tracked modifications and OpenSpec task edits.
- Untracked prior-slice files total 2,355 lines by `wc -l`, including new invoice models/services/controllers/views/tests and apply-progress evidence.
- Slice 6 code/test delta is narrowly the two regression test files plus OpenSpec evidence; no production code was changed in this slice.
- `git diff --cached --stat` produced no output; no files are staged.

### Residual risks / manual notes

- Full suite remains red for pre-existing/out-of-scope areas: settings audit rows are not emitted in `AdminHttpTest`/`SettingsServiceTest`, and campaign tests construct an admin from `env('ADMIN_EMAIL')` without seeding/creating that user. These are not customer-payments/invoices scope and were left unchanged.
- Repository working tree still contains all uncommitted/untracked work from slices 1–6; parent should package/review by the feature-branch-chain work-unit boundaries.
- No new product scope was added: no payments, references, partials, taxes, invoice lines, accounting integrations, notifications, or hard-delete UI flows.

### Remaining tasks

No implementation-owned Work Unit 1–6 rows remain unchecked. Parent lifecycle rows remain deferred:

- [ ] After apply, start or reuse bounded review for each PR/work-unit slice, with special focus on hidden GET writes, financial authorization leaks, and calendar Activity regression. <!-- sdd-owner: parent -->
- [ ] Do not advance to verify/sync/archive until apply artifacts and reviewer gates are complete for the chosen delivery strategy. <!-- sdd-owner: parent -->

### Workload / PR boundary

Current slice boundary: `slice-6-integration-regression-cleanup`. Keep this as an integration evidence/test-cleanup work unit; tests stay with the behavior they verify. Prior slice rollback boundaries remain intact: foundation schema/catalog/permissions, invoice CRUD service/routes, overdue processor/command, customer Pagos UI, and calendar projection can be reviewed/reverted independently.

### Rollback boundary

To roll back slice 6 only, revert the permission/seeder regression expectation updates in `tests/Feature/RolesAndPermissionsTest.php` and `tests/Feature/SeedersTest.php`, plus the Work Unit 6 task/progress artifact edits. Functional rollback points for prior slices remain: calendar projection and customer UI are safe functional rollback points before schema rollback.
