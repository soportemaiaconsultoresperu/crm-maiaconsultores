<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadConversionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuotationController;
use Illuminate\Support\Facades\Route;

/*
 * Web routes — CRM Maia Consultores.
 *
 * B01 ships auth + dashboard; B02 adds the Leads module (RF-LEAD-001..012).
 */

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

Route::get('/', [DashboardController::class, 'index'])
            ->name('home');
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

    /*
     * B02 — Prospectos. Record-level authorization goes through
     * LeadPolicy in the controller ($this->authorize); destroy is a
     * POST deactivation, never a physical delete (RF-LEAD-011).
     */
    Route::controller(LeadController::class)->group(function (): void {
        Route::get('leads', 'index')->name('leads.index');
        Route::get('leads/create', 'create')->name('leads.create');
        Route::post('leads', 'store')->name('leads.store');
        // Static segments first so they don't get swallowed by {lead} below.
        Route::get('leads/template', 'template')->name('leads.template');
        Route::get('leads/{lead}/edit', 'edit')->name('leads.edit');
        Route::put('leads/{lead}', 'update')->name('leads.update');
        Route::get('leads/{lead}', 'show')->name('leads.show');
        Route::post('leads/{lead}', 'destroy')->name('leads.destroy');

        Route::post('leads/{lead}/assign', 'assign')->name('leads.assign');
        Route::post('leads/{lead}/duplicate-check', 'duplicateCheck')
            ->name('leads.duplicate-check');

        Route::get('leads-import', 'importForm')->name('leads.import');
        Route::post('leads-import', 'importProcess')->name('leads.import.process');
        Route::get('leads-export', 'export')->name('leads.export');

        /*
         * B03 — Clientes (RF-CLI-001..006) y contactos (RF-CON-001..003).
         * destroy es desactivación (POST), nunca borrado físico;
         * authorization via CustomerPolicy/ContactPolicy.
         */
        Route::controller(CustomerController::class)->group(function (): void {
            Route::get('customers', 'index')->name('customers.index');
            Route::get('customers/create', 'create')->name('customers.create');
            Route::post('customers', 'store')->name('customers.store');
            Route::get('customers/{customer}/edit', 'edit')->name('customers.edit');
            Route::put('customers/{customer}', 'update')->name('customers.update');
            Route::get('customers/{customer}', 'show')->name('customers.show');
            Route::post('customers/{customer}', 'destroy')->name('customers.destroy');

            Route::get('customers-export', 'export')->name('customers.export');
        });

        /*
         * Contactos: listado independiente (contacts.index) + operaciones
         * en el contexto de su cliente. La primariedad se reasigna
         * explícitamente vía set-primary (RF-CON-002).
         */
        Route::controller(ContactController::class)->group(function (): void {
            Route::get('contacts', 'index')->name('contacts.index');

            Route::get('contacts-import', 'importForm')->name('contacts.import');
            Route::post('contacts-import', 'importProcess')->name('contacts.import.process');
            Route::get('contacts-import/template', 'importTemplate')->name('contacts.import.template');

            // Standalone create: the user picks the customer from within
            // their own visibility scope (the in-ficha flow below stays
            // untouched).
            Route::get('contacts/create', 'create')->name('contacts.create');
            Route::post('contacts', 'storeStandalone')->name('contacts.store');

            Route::post('customers/{customer}/contacts', 'store')->name('customers.contacts.store');
            Route::get('customers/{customer}/products', [CustomerProductController::class, 'catalog'])->name('customers.products.catalog');
            Route::post('customers/{customer}/products', [CustomerProductController::class, 'store'])->name('customers.products.store');
            Route::put('customers/{customer}/products/{product}', [CustomerProductController::class, 'update'])->name('customers.products.update');
            Route::delete('customers/{customer}/products/{product}', [CustomerProductController::class, 'destroy'])->name('customers.products.destroy');
            Route::post('contacts/{contact}/update', 'update')->name('contacts.update');
            Route::post('contacts/{contact}/destroy', 'destroy')->name('contacts.destroy');
            Route::post('contacts/{contact}/set-primary', 'setPrimary')->name('contacts.set-primary');
        });

        /*
         * B03 — Conversión lead → cliente (RF-LEAD-013, ADR-001): el lead
         * se conserva; el cliente referencia su origen.
         */
        Route::controller(LeadConversionController::class)->group(function (): void {
            Route::get('leads/{lead}/convert', 'create')->name('leads.convert');
            Route::post('leads/{lead}/convert', 'store')->name('leads.convert.store');
        });

            Route::get('leads-ubigeo/{parent}', 'ubigeoChildren')->name('leads.ubigeo');

            /*
             * Inline activity creation from a subject's show page
             * (RF-ACT-006). The subject is fixed in the URL; the activity
             * controller validates the (subject_type, subject_id) pair.
             */
                Route::post('leads/{lead}/activities', [ActivityController::class, 'storeForSubject'])
                    ->name('leads.activities.store');
                Route::post('customers/{customer}/activities', [ActivityController::class, 'storeForSubject'])
                    ->name('customers.activities.store');
                Route::post('opportunities/{opportunity}/activities', [ActivityController::class, 'storeForSubject'])
                    ->name('opportunities.activities.store');

        /*
         * B09 — Documents (RF-DOC-001..005, ADR-011). Each morph subject
         * has its own upload endpoint; download and delete are global
         * (the document route model binds to App\Models\Document and
         * DocumentService enforces the per-actor rules). Authorization
         * happens inside DocumentController::storeFor* and
         * DocumentService::canDownload / canDelete.
         */
        Route::controller(\App\Http\Controllers\DocumentController::class)->group(function (): void {
            Route::post('leads/{lead}/documents', 'storeForLead')
                ->name('leads.documents.store');
            Route::post('customers/{customer}/documents', 'storeForCustomer')
                ->name('customers.documents.store');
            Route::post('contacts/{contact}/documents', 'storeForContact')
                ->name('contacts.documents.store');
            Route::post('opportunities/{opportunity}/documents', 'storeForOpportunity')
                ->name('opportunities.documents.store');
            Route::post('quotations/{quotation}/documents', 'storeForQuotation')
                ->name('quotations.documents.store');
            Route::post('activities/{activity}/documents', 'storeForActivity')
                ->name('activities.documents.store');

            Route::get('documents/{document}/download', 'download')
                ->name('documents.download');
            Route::delete('documents/{document}', 'destroy')
                ->name('documents.destroy');
        });
        });
    });

/*
 * B04 — Oportunidades (RF-OPP-001..010). El pipeline Kanban es
 * opportunities-kanban (vista principal del módulo); la lista
 * tabular es opportunities (index). destroy es desactivación
 * (POST + motivo); las etapas ganada/perdida solo se alcanzan
 * vía win/lose; el cambio de etapa genérico (drag&drop y
 * fallback sin JS) es POST stage.
 */
Route::controller(OpportunityController::class)->group(function (): void {
    Route::get('opportunities', 'index')->name('opportunities.index');
    Route::get('opportunities-kanban', 'kanban')->name('opportunities.kanban');
    Route::get('opportunities/create', 'create')->name('opportunities.create');
    Route::post('opportunities', 'store')->name('opportunities.store');
    Route::get('opportunities/{opportunity}/edit', 'edit')->name('opportunities.edit');
    Route::put('opportunities/{opportunity}', 'update')->name('opportunities.update');
    Route::get('opportunities/{opportunity}', 'show')->name('opportunities.show');
    Route::post('opportunities/{opportunity}', 'destroy')->name('opportunities.destroy');

    Route::post('opportunities/{opportunity}/stage', 'stage')->name('opportunities.stage');
    Route::post('opportunities/{opportunity}/win', 'win')->name('opportunities.win');
    Route::post('opportunities/{opportunity}/lose', 'lose')->name('opportunities.lose');

    Route::get('opportunities-export', 'export')->name('opportunities.export');
});

/*
 * B05 — Activities (RF-ACT-001..008) + calendar (RF-CAL-001..003).
 *
 * Activity routes are split: the resource lives in the auth+active group
 * because the controllers are protected; the calendar only needs the
 * "calendar.view" permission (same level as activities.view.*).
 *
 * destroy is a soft deactivation (POST), not a physical delete (RNF-DAT-001).
 * The "complete" and "start" actions are dedicated POST endpoints so the
 * service-level state-machine guards remain the single source of truth.
 */
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::controller(ActivityController::class)->group(function (): void {
        Route::get('activities', 'index')->name('activities.index');
        Route::get('activities/create', 'create')->name('activities.create');
        Route::post('activities', 'store')->name('activities.store');
        Route::get('activities/{activity}/edit', 'edit')->name('activities.edit');
        Route::put('activities/{activity}', 'update')->name('activities.update');
        Route::get('activities/{activity}', 'show')->name('activities.show');
        Route::post('activities/{activity}', 'destroy')->name('activities.destroy');

        Route::post('activities/{activity}/start', 'start')->name('activities.start');
        Route::post('activities/{activity}/complete', 'complete')->name('activities.complete');
        Route::post('activities/{activity}/cancel', 'cancel')->name('activities.cancel');
    });
});

/*
 * B10 — Campaigns (RF-CAMP-001..015). Templates + Runs + Items.
 * Independent from the Activity module: campaign_action_items has its
 * own status/date fields (no FK to activities).
 */
Route::middleware(['auth', 'active'])->prefix('admin')->name('admin.')->group(function (): void {
    // Campaign Templates.
    Route::controller(\App\Http\Controllers\CampaignTemplateController::class)->group(function (): void {
        Route::get('campaign-templates', 'index')->name('campaign_templates.index');
        Route::get('campaign-templates/create', 'create')->name('campaign_templates.create');
        Route::post('campaign-templates', 'store')->name('campaign_templates.store');
        Route::get('campaign-templates/{template}', 'show')->name('campaign_templates.show');
        Route::get('campaign-templates/{template}/edit', 'edit')->name('campaign_templates.edit');
        Route::put('campaign-templates/{template}', 'update')->name('campaign_templates.update');
        Route::delete('campaign-templates/{template}', 'destroy')->name('campaign_templates.destroy');
        Route::post('campaign-templates/{template}/duplicate', 'duplicate')->name('campaign_templates.duplicate');
    });

    // Campaign Runs.
    Route::controller(\App\Http\Controllers\CampaignRunController::class)->group(function (): void {
Route::get('campaign-runs', 'index')->name('campaign_runs.index');
        Route::get('campaign-runs/create', 'create')->name('campaign_runs.create');
        Route::post('campaign-runs', 'store')->name('campaign_runs.store');
        Route::get('campaign-runs/search-contacts', 'searchContacts')->name('campaign_runs.search-contacts');
        Route::get('campaign-runs/{run}', 'show')->name('campaign_runs.show');
        Route::post('campaign-runs/{run}/schedule', 'schedule')->name('campaign_runs.schedule');
        Route::post('campaign-runs/{run}/pause', 'pause')->name('campaign_runs.pause');
        Route::post('campaign-runs/{run}/resume', 'resume')->name('campaign_runs.resume');
        Route::post('campaign-runs/{run}/cancel', 'cancel')->name('campaign_runs.cancel');
        Route::post('campaign-runs/{run}/complete', 'complete')->name('campaign_runs.complete');
        Route::post('campaign-runs/{run}/duplicate', 'duplicate')->name('campaign_runs.duplicate');
        Route::post('campaign-runs/{run}/reschedule-all', [\App\Http\Controllers\CampaignRescheduleController::class, 'rescheduleAll'])->name('campaign_runs.reschedule-all');
    });

    // Campaign Items (per-row actions).
    Route::controller(\App\Http\Controllers\CampaignItemController::class)->group(function (): void {
        Route::post('campaign-items/{item}/start', 'start')->name('campaign_items.start');
        Route::post('campaign-items/{item}/mark-realized', 'markRealized')->name('campaign_items.mark-realized');
        Route::post('campaign-items/{item}/cancel', 'cancel')->name('campaign_items.cancel');
        Route::post('campaign-items/{item}/mark-not-applicable', 'markNotApplicable')->name('campaign_items.mark-not-applicable');
        Route::post('campaign-items/{item}/reopen-completed', 'reopenCompleted')->name('campaign_items.reopen-completed');
        Route::post('campaign-items/{item}/reschedule', 'reschedule')->name('campaign_items.reschedule');
        Route::post('campaign-items/{item}/update-metadata', 'updateMetadata')->name('campaign_items.update-metadata');
    });
});

/*
 * Calendar (RF-CAL-001..003). Month view by default; week|day|list available
 * via ?view=. Anchor and filters drive the DateRange projection in
 * ActivityService::calendarEvents().
 */
Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('calendar', [CalendarController::class, 'index'])
        ->name('calendar.index');
});

/*
 * Notificaciones internas (RF-NOT-001): lista + marcar leídas.
 */
Route::controller(NotificationController::class)->group(function (): void {
    Route::get('notifications', 'index')->name('notifications.index');
    Route::post('notifications/mark-read', 'markRead')->name('notifications.mark-read');
});

/*
 * B06 — Productos y servicios (RF-PROD-001..003). Global catalog,
 * no owner-based data scope (ProductPolicy). destroy = deactivation
 * (POST with reason), never a physical delete (RNF-DAT-001).
 */
Route::controller(ProductController::class)->group(function (): void {
    Route::get('products', 'index')->name('products.index');
    Route::get('products/create', 'create')->name('products.create');
    Route::post('products', 'store')->name('products.store');
    // Static segments first so they don't get swallowed by {product} below.
    Route::get('products/template', 'template')->name('products.template');
    Route::post('products/import', 'import')->name('products.import');
    Route::get('products/{product}/edit', 'edit')->name('products.edit');
    Route::put('products/{product}', 'update')->name('products.update');
    Route::get('products/{product}', 'show')->name('products.show');
    Route::post('products/{product}', 'destroy')->name('products.destroy');

    Route::get('products-export', 'export')->name('products.export');
});

/*
 * B06 — Cotizaciones (RF-COT-001..011). Standard resource + dedicated
 * state-machine POST endpoints (send/accept/reject/duplicate), PDF
 * generation and Excel export. destroy = anulación (void) via POST
 * with reason; quotations are never physically deleted.
 *
 * The specific "/{subject}/{subject}/quotations/create" routes below
 * mirror the customer/lead/opportunity ficha pattern: pre-filling the
 * subject id (and derived customer/lead when the subject is an
 * opportunity) so the quotation create form lands with sensible
 * defaults from the originating context (RF-COT-001).
 */
Route::middleware(['auth', 'active'])->controller(QuotationController::class)->group(function (): void {
    Route::get('quotations', 'index')->name('quotations.index');
    Route::get('quotations/create', 'create')->name('quotations.create');
    Route::post('quotations', 'store')->name('quotations.store');
    Route::get('quotations/{quotation}/edit', 'edit')->name('quotations.edit');
    Route::put('quotations/{quotation}', 'update')->name('quotations.update');
    Route::get('quotations/{quotation}', 'show')->name('quotations.show');
    Route::post('quotations/{quotation}', 'destroy')->name('quotations.destroy');

    Route::post('quotations/{quotation}/duplicate', 'duplicate')->name('quotations.duplicate');
    Route::post('quotations/{quotation}/send', 'send')->name('quotations.send');
    Route::get('quotations/{quotation}/accept-confirm', 'acceptConfirm')->name('quotations.accept-confirm');
    Route::post('quotations/{quotation}/accept', 'accept')->name('quotations.accept');
    Route::post('quotations/{quotation}/reject', 'reject')->name('quotations.reject');
    Route::get('quotations/{quotation}/pdf', 'pdf')->name('quotations.pdf');

    Route::get('quotations-export', 'export')->name('quotations.export');

    // Specific create routes from the ficha of each subject.
    Route::get('customers/{customer}/quotations/create', 'createFromCustomer')
->name('customers.quotations.create');
Route::get('leads/{lead}/quotations/create', 'createFromLead')
    ->name('leads.quotations.create');
        Route::get('opportunities/{opportunity}/quotations/create', 'createFromOpportunity')
    ->name('opportunities.quotations.create');
    });

    /*
     * B07 — Reportes (RF-REP-001..006). All 12 report kinds dispatch to
     * ReportController::show which routes by kind. Authorization is enforced
     * inside the controller (`reports.view`); data scope and multimoneda are
     * handled by ReportsService::appliesTo().
     */
Route::middleware(['auth', 'active'])->controller(\App\Http\Controllers\ReportController::class)->group(function (): void {
            Route::get('reports', 'index')->name('reports.index');
                Route::get('reports/{kind}', 'show')
                    ->where('kind', 'prospectos-origen|prospectos-vendedor|conversion-prospectos|oportunidades-etapa|valor-embudo|ventas-ganadas-perdidas|motivos-perdida|actividades-vendedor|actividades-vencidas|cotizaciones|cotizaciones-aceptadas-rechazadas|rendimiento-comercial')
                    ->name('reports.show');
            });

    /*
     * B08 — Administración (RF-USR-005..008, RF-CFG-001..005, ADR-008).
     *
     * The admin UI is split into six logical sections; each one is gated
     * by Spatie permissions inside its controller (so a vendedor trying to
     * hit /admin/users gets 403 even if the route is reachable). destroy
     * endpoints are POSTs that flip an `is_active` flag instead of
     * physically deleting rows (RF-CFG-002 / RNF-DAT-001).
     */
    Route::middleware(['auth', 'active'])->prefix('admin')->name('admin.')->group(function (): void {

        // Users (RF-USR-001, RF-USR-005, RF-USR-006, RF-USR-008).
        Route::controller(\App\Http\Controllers\UserController::class)->group(function (): void {
            Route::get('users', 'index')->name('users.index');
            Route::get('users/create', 'create')->name('users.create');
            Route::post('users', 'store')->name('users.store');
            Route::get('users/{user}', 'show')->name('users.show');
            Route::get('users/{user}/edit', 'edit')->name('users.edit');
            Route::put('users/{user}', 'update')->name('users.update');
            Route::post('users/{user}', 'destroy')->name('users.destroy');
            Route::post('users/{user}/set-active', 'setActive')->name('users.set-active');
            Route::post('users/{user}/reset-password', 'resetPassword')->name('users.reset-password');
        });

        // Teams (RF-USR-004 follow-up, ADR-006).
        Route::controller(\App\Http\Controllers\TeamController::class)->group(function (): void {
            Route::get('teams', 'index')->name('teams.index');
            Route::get('teams/create', 'create')->name('teams.create');
            Route::post('teams', 'store')->name('teams.store');
            Route::get('teams/{team}', 'show')->name('teams.show');
            Route::get('teams/{team}/edit', 'edit')->name('teams.edit');
            Route::put('teams/{team}', 'update')->name('teams.update');
            Route::post('teams/{team}', 'destroy')->name('teams.destroy');
            Route::post('teams/{team}/add-member', 'addMember')->name('teams.add-member');
            Route::post('teams/{team}/remove-member/{user}', 'removeMember')->name('teams.remove-member');
            Route::post('teams/{team}/set-supervisor/{user}', 'setSupervisor')->name('teams.set-supervisor');
        });

        // Roles & permissions (RF-USR-008) — Spatie roles CRUD.
        Route::controller(\App\Http\Controllers\RoleController::class)->group(function (): void {
            Route::get('roles', 'index')->name('roles.index');
            Route::get('roles/create', 'create')->name('roles.create');
            Route::post('roles', 'store')->name('roles.store');
            Route::get('roles/{role}', 'show')->name('roles.show');
            Route::get('roles/{role}/edit', 'edit')->name('roles.edit');
            Route::put('roles/{role}', 'update')->name('roles.update');
            Route::post('roles/{role}', 'destroy')->name('roles.destroy');
        });

        // Catalogs (RF-CFG-001, RF-CFG-002). Generic route keyed by kind.
        Route::controller(\App\Http\Controllers\CatalogController::class)->group(function (): void {
            Route::get('catalogs', 'landing')->name('catalogs.landing');
            Route::get('catalogs/{kind}', 'index')
                ->where('kind', 'lead-sources|lead-statuses|loss-reasons|activity-types|pipeline-stages|product-categories|currencies|taxes')
                ->name('catalogs.index');
            Route::post('catalogs/{kind}', 'store')
                ->where('kind', 'lead-sources|lead-statuses|loss-reasons|activity-types|pipeline-stages|product-categories|currencies|taxes')
                ->name('catalogs.store');
            Route::post('catalogs/{kind}/{row}', 'update')
                ->where('kind', 'lead-sources|lead-statuses|loss-reasons|activity-types|pipeline-stages|product-categories|currencies|taxes')
                ->name('catalogs.update');
            Route::post('catalogs/{kind}/{row}/deactivate', 'deactivate')
                ->where('kind', 'lead-sources|lead-statuses|loss-reasons|activity-types|pipeline-stages|product-categories|currencies|taxes')
                ->name('catalogs.deactivate');
            Route::post('catalogs/{kind}/{row}/activate', 'activate')
                ->where('kind', 'lead-sources|lead-statuses|loss-reasons|activity-types|pipeline-stages|product-categories|currencies|taxes')
                ->name('catalogs.activate');
        });

        // Settings (RF-CFG-004, RF-CFG-005).
        Route::controller(\App\Http\Controllers\SettingController::class)->group(function (): void {
            Route::get('settings', 'index')->name('settings.index');
            Route::put('settings', 'update')->name('settings.update');
            // Company logo upload — multipart form, separate from bulk PUT.
            Route::post('settings/logo', 'uploadLogo')->name('settings.logo.upload');
            Route::post('settings/logo/remove', 'removeLogo')->name('settings.logo.remove');
            Route::get('settings/logo/preview', 'previewLogo')->name('settings.logo.preview');
        });

// Audit viewer (RF-USR-007, ADR-008).
            Route::controller(\App\Http\Controllers\AuditController::class)->group(function (): void {
                Route::get('audit', 'index')->name('audit.index');
                Route::get('audit/{activity}', 'show')->name('audit.show');
            });

// B12 — Automation engine admin (placeholder views, full UI in B12-UI).
                Route::controller(\App\Http\Controllers\Admin\AutomationController::class)->group(function (): void {
                    Route::get('automations', 'index')->name('automations.index');
                    Route::get('automations/trash', 'trash')->name('automations.trash');

                    // Read surface (restored after PR1 worker incident):
                    // B12 placeholder views for show / showExecution MUST be reachable
                    // because the current V1 views live there and the
                    // sidebar entry links to automations.index (this is the only
                    // route a permissioned user actually clicks).
                    Route::get('automations/{automation}', 'show')
                        ->name('automations.show')
                        ->whereNumber('automation');
                    Route::get('automations/{automation}/executions/{execution}', 'showExecution')
                        ->name('automations.executions.show')
                        ->whereNumber(['automation', 'execution']);

                    // CRUD — writes gated `automations.manage` (PERM-03).
                Route::get('automations/create', 'create')
                    ->name('automations.create')
                    ->middleware('can:automations.manage');
                Route::post('automations', 'store')
                    ->name('automations.store')
                    ->middleware('can:automations.manage');
                Route::get('automations/{automation}/edit', 'edit')
                    ->name('automations.edit')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');
                Route::put('automations/{automation}', 'update')
                    ->name('automations.update')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');
                Route::patch('automations/{automation}', 'update')
                    ->name('automations.update')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');
                Route::delete('automations/{automation}', 'destroy')
                    ->name('automations.destroy')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');

                // Irregular verbs — writes gated `automations.manage`.
                Route::patch('automations/{automation}/toggle', 'toggle')
                    ->name('automations.toggle')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');
                Route::post('automations/{id}/restore', 'restore')
                    ->name('automations.restore')
                    ->middleware('can:automations.manage')
                    ->whereNumber('id');
                Route::patch('automations/reorder', 'reorder')
                    ->name('automations.reorder')
                    ->middleware('can:automations.manage');

                // Clone (CRUD-04) — duplicates a rule + children with new
                // IDs and an " (copia)" suffix. Gated `automations.manage`.
                Route::post('automations/{automation}/clone', 'clone')
                    ->name('automations.clone')
                    ->middleware('can:automations.manage')
                    ->whereNumber('automation');

                // Simulate sub-route — gated `automations.test` (PERM-04, ACT-07).
                Route::post('automations/{automation}/actions/{action}/simulate', 'simulate')
                    ->name('automations.actions.simulate')
                    ->middleware('can:automations.test')
                    ->whereNumber('automation')
                    ->whereNumber('action');

                    // Audit-only feed — gated `automations.audit` (PERM-05, HIST-08, AC-9).
                    Route::get('automations/{automation}/audit', 'audit')
                        ->name('automations.audit')
                        ->middleware('can:automations.audit')
                        ->whereNumber('automation');
                });
            });

    /*
     * B13 — Email module (Pasada B).
     *
     * Auth-protected admin routes for the email surface. Gated by the
     * B13 Spatie permissions (email.view, email.template.manage,
     * email.account.manage, email.send) registered by
     * {@see \App\Providers\EmailServiceProvider}.
     *
     * Inbound webhooks (gmail/outlook) live OUTSIDE the auth group —
     * they're signed by the per-provider shared secret, not by a user.
     */
    Route::middleware(['auth', 'active'])->prefix('admin/email')->name('admin.email.')->group(function (): void {
        Route::controller(\App\Http\Controllers\Admin\EmailController::class)->group(function (): void {
            Route::get('templates', 'index')->name('templates.index');
            Route::get('templates/create', 'create')->name('templates.create');
            Route::post('templates', 'store')->name('templates.store');
            Route::get('templates/{template}/edit', 'edit')->name('templates.edit');
            Route::put('templates/{template}', 'update')->name('templates.update');
            Route::delete('templates/{template}', 'destroy')->name('templates.destroy');
            Route::get('accounts', 'accounts')->name('accounts.index');
            Route::post('templates/{template}/send', 'send')
                ->name('templates.send')
                ->middleware('can:email.send')
                ->whereNumber('template');
        });
    });

    // B17 Pasada B — admin notification module (gate-by-permission, see spec).
    Route::middleware(['auth', 'active'])->prefix('admin/notifications')->name('admin.notifications.')->group(function (): void {
        Route::controller(\App\Http\Controllers\Admin\NotificationController::class)->group(function (): void {
            Route::get('preferences', 'preferences')->name('preferences.index');
            Route::patch('preferences/{preference}', 'updatePreference')->name('preferences.update')->whereNumber('preference');
            Route::get('deliveries', 'deliveries')->name('deliveries.index');
            Route::get('deliveries/{delivery}', 'showDelivery')->name('deliveries.show')->whereNumber('delivery');
            Route::post('deliveries/{delivery}/retry', 'retry')->name('deliveries.retry')->whereNumber('delivery');
            Route::post('dispatch', 'dispatchNow')->name('dispatch')->middleware('can:notifications.send');
        });
    });

    /*
         * B13 — Inbound webhook endpoints (Pasada B).
         *
         * NOT inside the auth group. Each provider's signature is the gate.
         */
        Route::post('webhooks/email/gmail', [\App\Http\Controllers\EmailWebhookController::class, 'gmail'])
            ->name('webhooks.email.gmail');
        Route::post('webhooks/email/outlook', [\App\Http\Controllers\EmailWebhookController::class, 'outlook'])
            ->name('webhooks.email.outlook');

        /*
         * B14 — Inbound webhook endpoint (Pasada B-3).
         *
         * Meta WhatsApp Cloud API webhooks. NOT inside the auth group:
         * the HMAC signature (verified twice — provider + B11 verifier —
         * defense in depth) is the gate. `account` is the WhatsAppAccount
         * primary key, route-model bound to {@see \App\Models\WhatsApp\WhatsAppAccount}.
         */
        Route::post('webhooks/whatsapp/{account}', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])
            ->name('webhooks.whatsapp')
            ->whereNumber('account');

        /*
         * B14 — WhatsApp module (Pasada B-2).
         *
         * Auth-protected admin routes for the WhatsApp inbox + catalogue.
         * Gated by the B14 Spatie permissions (whatsapp.view, whatsapp.send,
         * whatsapp.template.manage, whatsapp.conversation.assign) registered
         * by {@see \App\Providers\WhatsAppServiceProvider}.
         *
         * Inbound webhooks (meta) land in B14 Pasada B-3 — they live outside
         * the auth group and are signed by the per-account webhook secret.
         */
        Route::middleware(['auth', 'active'])->prefix('admin/whatsapp')->group(function (): void {
            Route::controller(\App\Http\Controllers\Admin\WhatsAppController::class)->group(function (): void {
                Route::get('accounts', 'accounts')->name('whatsapp.accounts.index');
                Route::get('accounts/{account}', 'showAccount')
                    ->name('whatsapp.accounts.show')
                    ->whereNumber('account');
                Route::post('accounts/{account}/sync-templates', 'triggerTemplateSync')
                    ->name('whatsapp.accounts.sync')
                    ->middleware('can:whatsapp.template.manage')
                    ->whereNumber('account');

                Route::get('conversations', 'conversations')->name('whatsapp.conversations.index');
                Route::get('conversations/{conversation}', 'showConversation')
                    ->name('whatsapp.conversations.show')
                    ->whereNumber('conversation');
                Route::post('conversations/{conversation}/send', 'sendMessage')
                    ->name('whatsapp.conversations.send')
                    ->middleware('can:whatsapp.send')
                    ->whereNumber('conversation');
                Route::post('conversations/{conversation}/assign', 'assignConversation')
                    ->name('whatsapp.conversations.assign')
                    ->middleware('can:whatsapp.conversation.assign')
                    ->whereNumber('conversation');
                Route::post('conversations/{conversation}/close', 'closeConversation')
                    ->name('whatsapp.conversations.close')
                    ->whereNumber('conversation');
                Route::post('conversations/{conversation}/opt-out', 'markOptOut')
                    ->name('whatsapp.conversations.opt_out')
                    ->whereNumber('conversation');

                Route::get('templates', 'templates')->name('whatsapp.templates.index');
                Route::get('templates/{template}', 'showTemplate')
                    ->name('whatsapp.templates.show')
                    ->whereNumber('template');
            });
        });
