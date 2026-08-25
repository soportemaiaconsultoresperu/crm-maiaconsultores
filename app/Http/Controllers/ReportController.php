<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\ReportsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reports controller (RF-REP-001..006).
 *
 * Thin layer over ReportsService. Each report is its own action so the route
 * table doubles as the canonical list of available reports. The 12 kinds are
 * registered in routes/web.php.
 *
 * Filter contract: every report accepts an optional subset of
 * {from, to, owner_id, source_id, status, currency}. The service is
 * filter-shape-agnostic; the controller normalizes the request input.
 *
 * Authorization: every action requires `reports.view` (RF-REP-006). Data
 * scope (ADR-006) is applied inside ReportsService::appliesTo().
 *
 * Export: ?export=xlsx triggers Excel::download with the same headings +
 * rows the HTML view shows. ArrayExport handles formatting (RF-REP-004).
 */
class ReportController extends Controller
{
    /**
     * Catalog of available reports: slug → [Spanish title, description].
     * All reports render through `reports.show` (the 12 actions only differ
     * in which ReportsService method they call and the tabular shape).
     *
     * @var array<string, array{title:string, description:string}>
     */
    private const FILTERS_BY_KIND = [
        'prospectos-origen' => ['from', 'to', 'owner_id'],
        'prospectos-vendedor' => ['from', 'to', 'source_id'],
        'conversion-prospectos' => ['from', 'to', 'owner_id'],
        'oportunidades-etapa' => ['from', 'to', 'owner_id', 'status'],
        'valor-embudo' => ['from', 'to', 'owner_id', 'currency'],
        'ventas-ganadas-perdidas' => ['from', 'to', 'owner_id'],
        'motivos-perdida' => ['from', 'to', 'owner_id'],
        'actividades-vendedor' => ['from', 'to', 'owner_id'],
        'actividades-vencidas' => ['from', 'to'],
        'cotizaciones' => ['from', 'to', 'owner_id', 'currency'],
        'cotizaciones-aceptadas-rechazadas' => ['from', 'to', 'owner_id'],
        'rendimiento-comercial' => ['from', 'to'],
    ];

    private const CATALOG = [
        'prospectos-origen' => [
            'title' => 'Prospectos por origen',
            'description' => 'Distribución de prospectos captados según el canal de origen, con conteo y porcentaje.',
        ],
        'prospectos-vendedor' => [
            'title' => 'Prospectos por vendedor',
            'description' => 'Prospectos agrupados por responsable, con el detalle de estados en cada uno.',
        ],
        'conversion-prospectos' => [
            'title' => 'Conversión de prospectos',
            'description' => 'Tasa de conversión lead → cliente agrupada por mes calendario.',
        ],
        'oportunidades-etapa' => [
            'title' => 'Oportunidades por etapa',
            'description' => 'Conteo y monto por etapa del pipeline, separados por moneda (sin conversión, ADR-004).',
        ],
        'valor-embudo' => [
            'title' => 'Valor del embudo',
            'description' => 'Suma del monto estimado de oportunidades abiertas por responsable y moneda.',
        ],
        'ventas-ganadas-perdidas' => [
            'title' => 'Ventas ganadas y perdidas',
            'description' => 'Comparativo mensual de oportunidades cerradas (ganadas/perdidas) con monto por moneda.',
        ],
        'motivos-perdida' => [
            'title' => 'Motivos de pérdida',
            'description' => 'Oportunidades perdidas agrupadas por motivo, con conteo y monto por moneda.',
        ],
        'actividades-vendedor' => [
            'title' => 'Actividades por vendedor',
            'description' => 'Cantidad de actividades por responsable, desglosadas por estado.',
        ],
        'actividades-vencidas' => [
            'title' => 'Actividades vencidas',
            'description' => 'Actividades atrasadas por responsable, con antigüedad en días.',
        ],
        'cotizaciones' => [
            'title' => 'Cotizaciones emitidas',
            'description' => 'Cotizaciones por estado y moneda: conteo y monto total.',
        ],
        'cotizaciones-aceptadas-rechazadas' => [
            'title' => 'Cotizaciones aceptadas y rechazadas',
            'description' => 'Comparativo de cotizaciones aceptadas vs rechazadas con totales por moneda.',
        ],
        'rendimiento-comercial' => [
            'title' => 'Rendimiento comercial',
            'description' => 'Indicadores por vendedor: leads, clientes, oportunidades y cotizaciones aceptadas.',
        ],
    ];

    public function __construct(private readonly ReportsService $reports) {}

    /**
     * Catalog page (RF-REP-001): one row per report with its description
     * and a link to the show action.
     */
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('reports.view'), 403);

        $reports = [];

        foreach (self::CATALOG as $slug => $meta) {
            $reports[$slug] = [
                'title' => $meta['title'],
                'description' => $meta['description'],
                'url' => route('reports.show', ['kind' => $slug]),
                'export_url' => route('reports.show', ['kind' => $slug, 'export' => 'xlsx']),
            ];
        }

        return view('reports.index', ['reports' => $reports]);
    }

    /**
     * Dispatcher: routes `reports/{kind}` to the corresponding concrete
     * action. Keeps routes/web.php short while every report remains its own
     * dedicated method (clearer unit-of-behavior, easier to extend).
     *
     * @return View|BinaryFileResponse
     */
    public function show(Request $request, string $kind)
    {
        abort_unless($request->user()->can('reports.view'), 403);

        if (! array_key_exists($kind, self::CATALOG)) {
            abort(404);
        }

        $method = 'show' . str_replace('-', '', ucwords($kind, '-'));

        if (! method_exists($this, $method)) {
            abort(404);
        }

        return $this->{$method}($request);
    }

    /** ------------------------------------------------------------------ */
    /* Concrete report actions. Each renders its view or streams an Excel. */
    /** ------------------------------------------------------------------ */

    private function showProspectosOrigen(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->prospectosPorOrigen($request->user(), $filters);

        $headings = ['Origen', 'Cantidad', 'Porcentaje'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['source'],
            $r['count'],
            $r['percentage'] . '%',
        ], $rows);

        return $this->respond($request, $kind = 'prospectos-origen', $headings, $tabularRows, $rows, $filters);
    }

    private function showProspectosVendedor(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'source_id']);
        $rows = $this->reports->prospectosPorVendedor($request->user(), $filters);

        $headings = ['Vendedor', 'Total', 'Estados'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['owner'],
            $r['count'],
            collect($r['statuses'])
                ->map(fn ($s) => $s['status'] . ' (' . $s['count'] . ')')
                ->implode(', '),
        ], $rows);

        return $this->respond($request, 'prospectos-vendedor', $headings, $tabularRows, $rows, $filters);
    }

    private function showConversionProspectos(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->conversionDeProspectos($request->user(), $filters);

        $headings = ['Mes', 'Prospectos', 'Convertidos', 'Tasa'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['month'],
            $r['leads'],
            $r['converted'],
            $r['rate'] . '%',
        ], $rows);

        return $this->respond($request, 'conversion-prospectos', $headings, $tabularRows, $rows, $filters);
    }

    private function showOportunidadesEtapa(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id', 'status']);
        $rows = $this->reports->oportunidadesPorEtapa($request->user(), $filters);

        $headings = ['Etapa', 'Tipo', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['stage'],
            $r['stage_type'] ?? '',
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'oportunidades-etapa', $headings, $tabularRows, $rows, $filters);
    }

    private function showValorEmbudo(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id', 'currency']);
        $rows = $this->reports->valorDelEmbudo($request->user(), $filters);

        $headings = ['Vendedor', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['owner'],
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'valor-embudo', $headings, $tabularRows, $rows, $filters);
    }

    private function showVentasGanadasPerdidas(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->ventasGanadasYPerdidas($request->user(), $filters);

        $headings = ['Resultado', 'Etapa', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['outcome'],
            $r['stage'],
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'ventas-ganadas-perdidas', $headings, $tabularRows, $rows, $filters);
    }

    private function showMotivosPerdida(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->motivosDePerdida($request->user(), $filters);

        $headings = ['Motivo', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['loss_reason'],
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'motivos-perdida', $headings, $tabularRows, $rows, $filters);
    }

    private function showActividadesVendedor(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->actividadesPorVendedor($request->user(), $filters);

        $headings = ['Vendedor', 'Total', 'Estados'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['owner'],
            $r['count'],
            collect($r['statuses'])
                ->map(fn ($s) => $s['status'] . ' (' . $s['count'] . ')')
                ->implode(', '),
        ], $rows);

        return $this->respond($request, 'actividades-vendedor', $headings, $tabularRows, $rows, $filters);
    }

    private function showActividadesVencidas(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to']);
        $rows = $this->reports->actividadesVencidas($request->user(), $filters);

        $headings = ['Vendedor', 'Cantidad', 'Más antigua', 'Días vencidos', 'Rango'];
        $tabularRows = array_map(static function (array $r): array {
            $oldest = ! empty($r['oldest_scheduled_at'])
                ? \Carbon\Carbon::parse($r['oldest_scheduled_at'])->format('d/m/Y H:i')
                : '—';

            return [
                $r['owner'],
                $r['count'],
                $oldest,
                $r['age_days'],
                $r['age_bucket'],
            ];
        }, $rows);

        return $this->respond($request, 'actividades-vencidas', $headings, $tabularRows, $rows, $filters);
    }

    private function showCotizaciones(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id', 'currency']);
        $rows = $this->reports->cotizacionesEmitidas($request->user(), $filters);

        $headings = ['Estado', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['status'],
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'cotizaciones', $headings, $tabularRows, $rows, $filters);
    }

    private function showCotizacionesAceptadasRechazadas(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to', 'owner_id']);
        $rows = $this->reports->cotizacionesAceptadasYRechazadas($request->user(), $filters);

        $headings = ['Resultado', 'Moneda', 'Cantidad', 'Monto'];
        $tabularRows = array_map(static fn (array $r): array => [
            $r['outcome'],
            $r['currency_code'],
            $r['count'],
            number_format($r['amount'], 2, '.', ','),
        ], $rows);

        return $this->respond($request, 'cotizaciones-aceptadas-rechazadas', $headings, $tabularRows, $rows, $filters);
    }

    private function showRendimientoComercial(Request $request)
    {
        $filters = $this->filters($request, ['from', 'to']);
        $rows = $this->reports->rendimientoComercial($request->user(), $filters);

        $headings = ['Vendedor', 'Prospectos', 'Clientes', 'Oport. abiertas', 'Ganadas', 'Perdidas', 'Cotiz. aceptadas', 'Monto ganado por moneda'];
        $tabularRows = array_map(static function (array $r): array {
            $wonPretty = collect($r['won_amount_by_currency'] ?? [])
                ->map(fn ($bucket, $code) => $code . ' ' . number_format($bucket['amount'], 2, '.', ',') . ' (' . $bucket['count'] . ')')
                ->implode(' / ');

            return [
                $r['owner'],
                $r['leads_count'],
                $r['customers_count'],
                $r['opportunities_open'],
                $r['opportunities_won'],
                $r['opportunities_lost'],
                $r['quotations_accepted_count'],
                $wonPretty,
            ];
        }, $rows);

        return $this->respond($request, 'rendimiento-comercial', $headings, $tabularRows, $rows, $filters);
    }

    /**
     * Single shared HTML/Excel switch. Every report goes through here so
     * the export vs render behavior stays consistent.
     *
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $tabularRows
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return View|BinaryFileResponse
     */
    private function respond(Request $request, string $kind, array $headings, array $tabularRows, array $rows, array $filters)
    {
        $meta = self::CATALOG[$kind];

        if ($request->query('export') === 'xlsx') {
            return Excel::download(
                new ArrayExport($headings, $tabularRows),
                'reporte-' . $kind . '-' . now()->format('Ymd') . '.xlsx',
            );
        }

        $filterKeys = self::FILTERS_BY_KIND[$kind] ?? [];

        return view('reports.show', [
            'title' => $meta['title'],
            'description' => $meta['description'],
            'kind' => $kind,
            'headings' => $headings,
            'rows' => $tabularRows,
            'filters' => $filters,
            'filterKeys' => $filterKeys,
            'filterOptions' => $this->filterOptions($filterKeys),
            'exportUrl' => route('reports.show', array_filter(array_merge(['kind' => $kind, 'export' => 'xlsx'], $filters))),
        ]);
    }


    /**
     * Options for the report filter form. Keep this UI-only: each concrete
     * report still allow-lists its accepted query keys before calling the
     * service layer.
     *
     * @param  list<string>  $filterKeys
     * @return array<string, mixed>
     */
    private function filterOptions(array $filterKeys): array
    {
        $options = [
            'status' => [
                'open' => 'Abiertas',
                'won' => 'Ganadas',
                'lost' => 'Perdidas',
            ],
        ];

        if (in_array('owner_id', $filterKeys, true)) {
            $options['owners'] = User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        if (in_array('source_id', $filterKeys, true)) {
            $options['sources'] = LeadSource::query()
                ->where('is_active', true)
                ->orderBy('sort')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        if (in_array('currency', $filterKeys, true)) {
            $options['currencies'] = Currency::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->pluck('code', 'code')
                ->all();
        }

        return $options;
    }

    /**
     * Pull only the allow-listed filter keys from the request so reports
     * are not contaminated with arbitrary query params.
     *
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $allowed): array
    {
        $out = [];

        foreach ($allowed as $key) {
            $value = $request->query($key);

            if ($value === null || $value === '') {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}