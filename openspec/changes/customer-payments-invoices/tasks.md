# customer-payments-invoices — Implementation Tasks

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 1,800–2,700 additions/deletions across ~35–50 files |
| Estimated files touched | migrations, models/factories, seeders, routes, policies, requests, controllers, services, console command/schedule, customer/calendar/catalog views, feature/unit tests |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1: data/catalog/permissions foundation → PR 2: invoice domain + overdue processor → PR 3: customer Pagos UI + CRUD → PR 4: calendar projection + cleanup |
| Delivery strategy | split-by-work-unit |
| Chain strategy | feature-branch-chain |

Decision needed before apply: No — user approved implementing by parts.
Chained PRs recommended: Yes
Chain strategy: feature-branch-chain
400-line budget risk: High

## Dependencies and sequencing

- Strict TDD is active in `openspec/config.yaml`: each work unit below must capture RED evidence before GREEN implementation, then TRIANGULATE edge cases, then REFACTOR/cleanup.
- Do not implement payments, payment references, partial payments, taxes, invoice lines, accounting integrations, customer-facing notifications, or hard-delete UI flows.
- `Vencida` must be persisted in DB by `OverdueInvoiceProcessor` + Artisan command `invoices:mark-overdue`; GET views for `/customers/{id}` and Calendario must not perform hidden writes.

## Work Unit 1 — Data, status catalog, and financial permissions

Start: current schema has no `customers.payment_modality`, `invoice_statuses`, `customer_invoices`, or `customer-payments.*` permissions.  
Finish: migrations, seeders, catalog plumbing, permissions, models, factories, and baseline tests exist and are rollbackable as one foundation unit.

- [x] RED: Add failing tests in `tests/Feature/InvoiceStatusCatalogTest.php` for required seeded statuses, `/admin/catalogs/invoice-statuses` visibility, and rejection of inline status creation from payments flows. <!-- sdd-owner: implementation -->
- [x] RED: Add failing permission/model tests in `tests/Feature/CustomerPaymentsCardTest.php` or `tests/Feature/CustomerInvoiceCrudTest.php` proving `customer-payments.view` and `customer-payments.manage` gates are required before financial data/actions are exposed. <!-- sdd-owner: implementation -->
- [x] GREEN: Create migrations under `database/migrations/` for `customers.payment_modality`, `invoice_statuses`, and `customer_invoices` with indexes/constraints from `openspec/changes/customer-payments-invoices/design.md`. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Models/InvoiceStatus.php`, `app/Models/CustomerInvoice.php`, `database/factories/CustomerInvoiceFactory.php`, and update `app/Models/Customer.php` plus `database/factories/CustomerFactory.php` for modality and invoice relations. <!-- sdd-owner: implementation -->
- [x] GREEN: Update `database/seeders/CatalogSeeder.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `app/Http/Controllers/CatalogController.php`, `app/Http/Requests/CatalogStoreRequest.php`, `app/Http/Requests/CatalogUpdateRequest.php`, `app/Services/CatalogService.php`, and `routes/web.php` for `invoice-statuses`. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Cover inactive/unknown invoice status rejection and duplicate invoice number constraints per customer in `tests/Feature/CustomerInvoiceCrudTest.php`. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Align names, slugs, fillables, audit traits, and catalog labels/icons with existing catalog conventions discovered in `app/Models/*Status.php` and `resources/views/admin/catalogs/landing.blade.php`. <!-- sdd-owner: implementation -->
- [x] Evidence: Run `php artisan test --filter=InvoiceStatusCatalogTest` and the focused permission/model tests; record migration/seed rollback notes. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: revert the foundation migration/model/seeder/catalog/permission changes before any invoice UI or calendar code depends on them. <!-- sdd-owner: implementation -->

## Work Unit 2 — Invoice service, policy, validation, and CRUD endpoints

Start: data/catalog/permissions foundation is available.  
Finish: manual invoice lifecycle works through authorized write endpoints without hard delete or out-of-scope payment metadata.

- [x] RED: Add failing `tests/Feature/CustomerInvoiceCrudTest.php` coverage for create, validation errors, update uniqueness, mark-paid status-only behavior, retire/anul non-destructive behavior, and read-only 403s. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Policies/CustomerInvoicePolicy.php` and register it in the project policy/provider location discovered from existing policies. <!-- sdd-owner: implementation -->
- [x] GREEN: Add FormRequests `app/Http/Requests/CustomerPaymentModalityUpdateRequest.php`, `CustomerInvoiceStoreRequest.php`, `CustomerInvoiceUpdateRequest.php`, and `CustomerInvoiceRetireRequest.php` with rules from design §8. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Services/CustomerInvoiceService.php` for create/update/mark-paid/retire/modality updates, audit activity events, active invoice invariants, and catalog slug resolution. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Http/Controllers/CustomerInvoiceController.php` and routes in `routes/web.php` for `POST customers/{customer}/payment-modality`, `POST customers/{customer}/invoices`, `PUT customer-invoices/{invoice}`, `POST customer-invoices/{invoice}/mark-paid`, and `POST customer-invoices/{invoice}/retire`. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Add tests proving retired invoices cannot be normally edited, missing base status returns a controlled error, and no request accepts payment date/reference/proof/partials/tax/line-item fields. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Keep controller methods thin and move business rules/audit properties into `CustomerInvoiceService.php` following `app/Services/QuotationService.php` patterns. <!-- sdd-owner: implementation -->
- [x] Evidence: Run `php artisan test --filter=CustomerInvoiceCrudTest`; record HTTP statuses, redirects, and database assertions. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: remove invoice routes/controller/service/requests/policy while leaving foundation schema/catalog safe if already migrated. <!-- sdd-owner: implementation -->

## Work Unit 3 — Persisted automatic overdue processing

Start: invoices can be persisted and status slugs exist.  
Finish: overdue eligible invoices persist `status_id = Vencida` through service/command/scheduler, idempotently, never from GET views.

- [x] RED: Add failing `tests/Feature/OverdueInvoiceProcessorTest.php` or `tests/Unit/OverdueInvoiceProcessorTest.php` for En proceso past due transition, Pagado protection, Nota de crédito protection, retired protection, idempotent repeated processing, and deterministic `--date`. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Services/OverdueInvoiceProcessor.php` and result object/discovery target `app/Support/Invoices/OverdueInvoiceResult.php` or nearest project support namespace. <!-- sdd-owner: implementation -->
- [x] GREEN: Add Artisan command at `app/Console/Commands/MarkOverdueInvoicesCommand.php` with signature `invoices:mark-overdue {--date=}` and register/schedule it in the project console scheduling location (`app/Console/Kernel.php` or Laravel 13 equivalent). <!-- sdd-owner: implementation -->
- [x] GREEN: Invoke `OverdueInvoiceProcessor::processInvoice()` only after explicit invoice writes in `app/Services/CustomerInvoiceService.php` when due date/status changes could make an invoice immediately overdue. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Add tests or assertions proving `CustomerController::show()` and calendar GET requests do not mutate invoice statuses. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Centralize status slug constants in `app/Models/InvoiceStatus.php` and query scopes in `app/Models/CustomerInvoice.php` to avoid label/string drift. <!-- sdd-owner: implementation -->
- [x] Evidence: Run `php artisan test --filter=OverdueInvoiceProcessorTest` and `php artisan invoices:mark-overdue --date=2026-09-16` in a safe test/local DB context if available. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: disable scheduled command and remove processor invocation; invoice CRUD remains usable with manual statuses. <!-- sdd-owner: implementation -->

## Work Unit 4 — Customer detail Pagos card and modality UI

Start: invoice CRUD services/endpoints and overdue processor are available.  
Finish: `/customers/{id}` displays financial information only to authorized users and preserves existing customer cards.

- [x] RED: Add failing `tests/Feature/CustomerPaymentsCardTest.php` scenarios for authorized card visibility, unauthorized hiding, modality display/empty state, writer modality update, read-only rejection, invoice list rows, no-invoices empty state, and v1 no-goal fields absent. <!-- sdd-owner: implementation -->
- [x] GREEN: Update `app/Http/Controllers/CustomerController.php` to load `invoices.status`, active `invoiceStatuses`, and `canViewPayments`/`canManagePayments` only when gates allow. <!-- sdd-owner: implementation -->
- [x] GREEN: Create `resources/views/customers/_payments_card.blade.php` with modality, invoice list, empty state, permitted CTAs/actions, and no partial-payment/accounting fields. <!-- sdd-owner: implementation -->
- [x] GREEN: Include the partial in `resources/views/customers/show.blade.php` near existing financial/commercial cards without degrading `Datos del cliente`, `Contactos`, `Historial comercial`, `Actividades`, `Cotizaciones`, `Documentos`, or `customers._products_card`. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Update `tests/Feature/CustomerHttpTest.php` to prove existing customer detail content still renders and Pagos appears only with `customer-payments.view`. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Extract repeated formatting/authorization snippets inside the Blade partial only if it reduces complexity without broad layout churn. <!-- sdd-owner: implementation -->
- [x] Evidence: Run `php artisan test --filter=CustomerPaymentsCardTest` and the updated `CustomerHttpTest` filter. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: remove `_payments_card` include and financial loads/flags; keep backend routes/data intact for later re-enable. <!-- sdd-owner: implementation -->

## Work Unit 5 — Calendar invoice projection

Start: customer invoices, statuses, permissions, and overdue persistence are functional.  
Finish: Calendario combines existing activities with idempotent invoice due-date projection while respecting financial permissions.

- [x] RED: Add failing `tests/Feature/InvoiceCalendarAlertsTest.php` for En proceso/Vencida visibility, persisted Vencida after command, due-date move, no duplicates after repeated saves, Pagado/Nota de crédito/retired suppression, customer/invoice links, and no external notifications. <!-- sdd-owner: implementation -->
- [x] RED: Add or update `tests/Feature/CalendarQueryTest.php` to preserve existing activity calendar behavior while introducing non-Activity event items. <!-- sdd-owner: implementation -->
- [x] GREEN: Add `app/Support/Calendar/CalendarEventItem.php` and `app/Services/CalendarEventService.php` to combine `ActivityService::calendarEvents()` with active chargeable invoice events authorized by `customer-payments.view`. <!-- sdd-owner: implementation -->
- [x] GREEN: Update `app/Http/Controllers/CalendarController.php` to consume `CalendarEventService` without changing calendar routes. <!-- sdd-owner: implementation -->
- [x] GREEN: Refactor `resources/views/calendar/month.blade.php`, `week.blade.php`, `day.blade.php`, and `list.blade.php` to use DTO fields (`url`, `title`, `status`, `typeLabel`, `subjectLabel`, `ownerName`, `scheduled_at`) instead of assuming `Activity`. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Cover filters: `type_id` applies only to activities, `owner_id` and `subject_type=customer` can include customer invoice events, and users with `calendar.view` but without `customer-payments.view` do not see financial events/details. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Keep invoice calendar events as query-time projections only; do not create persisted calendar/task rows or notification dispatches. <!-- sdd-owner: implementation -->
- [x] Evidence: Run `php artisan test --filter=InvoiceCalendarAlertsTest` and `php artisan test --filter=CalendarQueryTest`. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: switch `CalendarController` back to `ActivityService::calendarEvents()` and remove invoice DTO projection without touching invoice CRUD. <!-- sdd-owner: implementation -->

## Work Unit 6 — Integration, full regression, and cleanup

Start: all behavior slices are green individually.  
Finish: implementation is coherent, tested, reviewable, and ready for bounded review or chained PR packaging.

- [x] RED: Add any missing regression tests discovered during integration under `tests/Feature/CustomerPaymentsCardTest.php`, `tests/Feature/CustomerInvoiceCrudTest.php`, `tests/Feature/OverdueInvoiceProcessorTest.php`, `tests/Feature/InvoiceCalendarAlertsTest.php`, or `tests/Feature/CalendarQueryTest.php` before fixing defects. <!-- sdd-owner: implementation -->
- [x] GREEN: Fix integration failures across migrations, seeders, permissions, customer UI, invoice writes, overdue command, and calendar projection without widening v1 scope. <!-- sdd-owner: implementation -->
- [x] TRIANGULATE: Run targeted filters plus `php artisan test` if budget/time permits; if full suite cannot run, document skipped validation and rationale in apply evidence. <!-- sdd-owner: implementation -->
- [x] REFACTOR: Review changed-line budget and split work-unit commits according to `work-unit-commits` skill; keep tests with the behavior they verify. <!-- sdd-owner: implementation -->
- [x] Evidence: Capture `git diff --stat` or equivalent file/line summary, commands run, residual risks, and manual notes for reviewer. <!-- sdd-owner: implementation -->
- [x] Rollback boundary: each prior work unit can be reverted independently; calendar projection and customer UI are safe functional rollback points before schema rollback. <!-- sdd-owner: implementation -->

## Parent review and lifecycle gates

- [x] Before sdd-apply, obtain delivery decision because forecast exceeds the 400-line review budget and chained PRs are recommended; user chose implementation **por partes** with feature-branch-chain style slices. <!-- sdd-owner: parent -->
- [ ] After apply, start or reuse bounded review for each PR/work-unit slice, with special focus on hidden GET writes, financial authorization leaks, and calendar Activity regression. <!-- sdd-owner: parent -->
- [ ] Do not advance to verify/sync/archive until apply artifacts and reviewer gates are complete for the chosen delivery strategy. <!-- sdd-owner: parent -->
