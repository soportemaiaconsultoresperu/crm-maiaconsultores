<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogStoreRequest;
use App\Http\Requests\CatalogUpdateRequest;
use App\Models\ActivityType;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\InvoiceStatus;
use App\Models\LossReason;
use App\Models\PipelineStage;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Services\CatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin catalogs UI (B08 / RF-CFG-001, RF-CFG-002).
 *
 * The catalog is treated as a generic resource that dispatches on the
 * route `{kind}` placeholder (kebab-case slug, e.g. "lead-sources").
 *
 * All writes go through CatalogService so the catalog rows are never
 * physically deleted (RNF-DAT-001) — only deactivated. Activate/deactivate
 * have their own routes so each audit row carries the explicit event.
 */
class CatalogController extends Controller
{
    /**
     * Mapping of route `{kind}` slug → model class. Mirrors the keys used
     * in CatalogStoreRequest::resolvedModel() to keep validation in sync.
     *
     * @var array<string, class-string>
     */
    private const CATALOG_MAP = [
        'lead-sources' => LeadSource::class,
        'lead-statuses' => LeadStatus::class,
        'invoice-statuses' => InvoiceStatus::class,
        'loss-reasons' => LossReason::class,
        'activity-types' => ActivityType::class,
        'pipeline-stages' => PipelineStage::class,
        'product-categories' => ProductCategory::class,
        'currencies' => Currency::class,
        'taxes' => Tax::class,
    ];

    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

        /**
         * Display the list + per-row activate/deactivate forms + a "create new"
         * inline form (RF-CFG-001). The same view is reused for every catalog.
         */
        public function index(Request $request, string $kind): View
        {
            abort_unless($request->user()->can('catalogs.view'), 403);

            $model = $this->resolveCatalog($kind);

            $rows = $this->catalog->list($model, includeInactive: true);

            return view('admin.catalogs.index', [
                'kind' => $kind,
                'kindLabel' => $this->labelFor($kind),
                'model' => $model,
                'rows' => $rows,
                'kindConfig' => $this->configFor($kind),
            ]);
        }

        /**
         * Landing page for catalogs: a card-grid index of every catalog kind,
         * each one linking to its dedicated CRUD view. Lets admins navigate
         * to any catalog without typing the URL.
         */
        public function landing(Request $request): View
        {
            abort_unless($request->user()->can('catalogs.view'), 403);

            $catalogs = collect(self::CATALOG_MAP)
                ->map(function (string $modelClass, string $kind): array {
                    $active = (int) $modelClass::query()->where('is_active', true)->count();
                    $inactive = (int) $modelClass::query()->where('is_active', false)->count();

                    return [
                        'kind'        => $kind,
                        'label'       => $this->labelFor($kind),
                        'icon'        => $this->iconFor($kind),
                        'description' => $this->descriptionFor($kind),
                        'active'      => $active,
                        'inactive'    => $inactive,
                    ];
                })
                ->values()
                ->all();

            return view('admin.catalogs.landing', [
                'catalogs' => $catalogs,
            ]);
        }

    /**
     * POST /admin/catalogs/{kind} — create a new row.
     */
    public function store(CatalogStoreRequest $request, string $kind): RedirectResponse
    {
        if (! $request->user()->can('catalogs.manage')) {
            abort(403);
        }

        $model = $this->resolveCatalog($kind);

        // Mirror the route parameter onto the validated request so the form
        // request can resolve the target model class.
        $request->getRouteResolver();

        $row = $this->catalog->create($model, $request->validated(), $request->user());

        return redirect()
            ->route('admin.catalogs.index', ['kind' => $kind])
            ->with('status', 'Registro creado correctamente.')
            ->with('catalog_highlight', $row->getKey());
    }

    /**
     * POST /admin/catalogs/{kind}/{row} — update an existing row.
     */
    public function update(CatalogUpdateRequest $request, string $kind, string $row): RedirectResponse
    {
        if (! $request->user()->can('catalogs.manage')) {
            abort(403);
        }

        $model = $this->resolveCatalog($kind);
        $entry = $model::query()->findOrFail($row);

        $this->catalog->update($model, $entry, $request->validated(), $request->user());

        return redirect()
            ->route('admin.catalogs.index', ['kind' => $kind])
            ->with('status', 'Registro actualizado correctamente.')
            ->with('catalog_highlight', $entry->getKey());
    }

    /**
     * POST /admin/catalogs/{kind}/{row}/deactivate — flip is_active = false
     * with a mandatory reason (RF-CFG-002).
     */
    public function deactivate(Request $request, string $kind, string $row): RedirectResponse
    {
        if (! $request->user()->can('catalogs.manage')) {
            abort(403);
        }

        $model = $this->resolveCatalog($kind);
        $entry = $model::query()->findOrFail($row);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->catalog->deactivate($model, $entry, $request->user(), (string) $data['reason']);
        } catch (\App\Exceptions\InvalidOperationException $e) {
            return redirect()
                ->route('admin.catalogs.index', ['kind' => $kind])
                ->withErrors(['catalog' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.catalogs.index', ['kind' => $kind])
            ->with('status', 'Registro desactivado.');
    }

    /**
     * POST /admin/catalogs/{kind}/{row}/activate — flip is_active = true
     * again.
     */
    public function activate(Request $request, string $kind, string $row): RedirectResponse
    {
        if (! $request->user()->can('catalogs.manage')) {
            abort(403);
        }

        $model = $this->resolveCatalog($kind);
        $entry = $model::query()->findOrFail($row);

        try {
            $this->catalog->activate($model, $entry, $request->user());
        } catch (\App\Exceptions\InvalidOperationException $e) {
            return redirect()
                ->route('admin.catalogs.index', ['kind' => $kind])
                ->withErrors(['catalog' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.catalogs.index', ['kind' => $kind])
            ->with('status', 'Registro activado.');
    }

    /**
     * Resolve the catalog model from the URL `{kind}` parameter or 404.
     */
    private function resolveCatalog(string $kind): string
    {
        if (! isset(self::CATALOG_MAP[$kind])) {
            abort(404, "Catálogo no encontrado: {$kind}");
        }

        return self::CATALOG_MAP[$kind];
    }

    /**
     * Spanish label for the catalog header.
     */
        private function labelFor(string $kind): string
        {
            return match ($kind) {
                'lead-sources' => 'Orígenes de prospectos',
                'lead-statuses' => 'Estados de prospectos',
                'invoice-statuses' => 'Estados de factura',
                'loss-reasons' => 'Motivos de pérdida',
                'activity-types' => 'Tipos de actividad',
                'pipeline-stages' => 'Etapas del pipeline',
                'product-categories' => 'Categorías de producto',
                'currencies' => 'Monedas',
                'taxes' => 'Impuestos',
                default => $kind,
            };
        }

        /**
         * Bootstrap icon class for the landing-card visual.
         */
        private function iconFor(string $kind): string
        {
            return match ($kind) {
                'lead-sources'       => 'bi-broadcast',
                'lead-statuses'      => 'bi-flag',
                'invoice-statuses'   => 'bi-receipt',
                'loss-reasons'       => 'bi-x-octagon',
                'activity-types'     => 'bi-calendar-event',
                'pipeline-stages'    => 'bi-kanban',
                'product-categories' => 'bi-tags',
                'currencies'         => 'bi-currency-exchange',
                'taxes'              => 'bi-percent',
                default              => 'bi-collection',
            };
        }

        /**
         * One-line description shown on the landing card.
         */
        private function descriptionFor(string $kind): string
        {
            return match ($kind) {
                'lead-sources'       => 'Origen del primer contacto (web, referido, campaña, etc.).',
                'lead-statuses'      => 'Estados del ciclo de vida de un prospecto.',
                'invoice-statuses'   => 'Estados de facturas de clientes usados por la tarjeta Pagos.',
                'loss-reasons'       => 'Motivos para marcar oportunidades como perdidas.',
                'activity-types'     => 'Tipos de actividad agendable en el calendario.',
                'pipeline-stages'    => 'Etapas del embudo de oportunidades.',
                'product-categories' => 'Categorías del catálogo de productos.',
                'currencies'         => 'Monedas disponibles para cotizaciones (ISO 4217).',
                'taxes'              => 'Impuestos aplicables (IGV, exonerado, inafecto, etc.).',
                default              => '',
            };
        }

    /**
     * Catalog-specific config that the view uses to render the right form
     * fields (extra columns like `rate`, `stage_type`, etc.).
     *
     * @return array<string, mixed>
     */
    private function configFor(string $kind): array
    {
        return match ($kind) {
            'lead-statuses' => ['extras' => ['is_final']],
            'pipeline-stages' => ['extras' => ['stage_type', 'default_probability']],
            'currencies' => ['extras' => ['code', 'symbol', 'decimals'], 'primary_label' => 'code'],
            'taxes' => ['extras' => ['rate']],
            default => ['extras' => []],
        };
    }
}