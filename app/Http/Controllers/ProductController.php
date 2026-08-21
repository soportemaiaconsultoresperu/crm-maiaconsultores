<?php

namespace App\Http\Controllers;

use App\Exports\ProductsExport;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Imports\ProductsImport;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\ProductService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * B06 Product UI layer (RF-PROD-001..003). Thin controller: validation in
 * FormRequests, business logic in ProductService, authorization in
 * ProductPolicy. The catalog is global (no owner-based data scope); only
 * module permissions gate access.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    /**
     * Filtered list (RF-PROD-001): search, product_type, category, currency,
     * tax and active flag. Products have no owner_id so there is no team/own
     * data scope (ProductPolicy::viewAny already enforces products.view.any).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $query = Product::query()
            ->with(['category', 'tax', 'currency']);

        if ($search = trim((string) $request->query('search'))) {
            $term = '%'.str_replace('%', '\%', $search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        foreach (['product_type', 'category_id', 'currency_code', 'tax_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->query($column));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        $query->orderBy('code')->orderBy('id');

        $pageSize = (int) Setting::query()->where('key', 'pagination_size')->value('value');
        $products = $query->paginate(max(1, $pageSize ?: 25))->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('sort')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'taxes' => Tax::query()->where('is_active', true)->orderBy('sort')->get(),
            'filters' => $request->only(['search', 'product_type', 'category_id', 'currency_code', 'tax_id', 'is_active']),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('products.create', $this->formContext());
    }

    public function store(ProductStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        // Strip the optional auto-generated code (server assigns it inside
        // the service transaction, ADR-002).
        $data = $request->validated();
        unset($data['code']);

        $product = $this->products->create($data, $request->user());

        return redirect()
            ->route('products.show', $product)
            ->with('status', "Producto {$product->code} creado correctamente.");
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', [
            'product' => $product,
            ...$this->formContext(),
        ]);
    }

    public function update(ProductUpdateRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $this->products->update($product, $request->validated(), $request->user());

        return redirect()
            ->route('products.show', $product)
            ->with('status', "Producto {$product->code} actualizado correctamente.");
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        return view('products.show', [
            'product' => $product->load(['category', 'tax', 'currency']),
        ]);
    }

    /**
     * POST destroy = deactivation with a mandatory reason (RF-PROD-002).
     */
    public function destroy(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['reason' => 'motivo']);

        $this->products->deactivate($product, $request->user(), $validated['reason']);

        return redirect()
            ->route('products.index')
            ->with('status', "Producto {$product->code} desactivado.");
    }

    /**
     * Export honoring the current list filters (RF-PROD-001). The actor's
     * scope is applied inside the export query — products have no owner_id,
     * so the scope is effectively a no-op (ProductService::exportQuery).
     */
        public function export(Request $request): BinaryFileResponse
        {
            abort_unless($request->user()->can('products.export'), 403);

            $filters = $request->only(['search', 'product_type', 'category_id', 'currency_code', 'tax_id', 'is_active']);

            return Excel::download(
                new ProductsExport($filters, $request->user()),
                'productos-'.now()->format('Ymd').'.xlsx',
            );
        }

        /**
         * GET /products/template — downloads a blank Excel template with the
         * expected column headers so operators can fill it before uploading.
         * The single example row uses the first active category and tax so the
         * operator can see what real values look like.
         */
        public function template(Request $request): BinaryFileResponse
        {
            abort_unless($request->user()->can('products.import'), 403);

            $headings = ['Código', 'Tipo', 'Nombre', 'Categoría', 'Descripción', 'Precio', 'Moneda', 'Impuesto', 'Activo'];

            return Excel::download(
                new class implements \Maatwebsite\Excel\Concerns\FromArray, WithHeadings {
                    /**
                     * @return list<list<string>>
                     */
                    public function array(): array
                    {
                        return [[
                            'PROD-2026-00001',
                            'producto',
                            'Cuaderno A4',
                            (string) (ProductCategory::query()->where('is_active', true)->value('name') ?? 'General'),
                            'Cuaderno universitario 100 hojas',
                            '12.50',
                            'PEN',
                            (string) (Tax::query()->where('is_active', true)->value('name') ?? 'Gravado IGV'),
                            'Sí',
                        ]];
                    }

                    /**
                     * @return list<string>
                     */
                    public function headings(): array
                    {
                        return ['Código', 'Tipo', 'Nombre', 'Categoría', 'Descripción', 'Precio', 'Moneda', 'Impuesto', 'Activo'];
                    }
                },
                'plantilla-productos.xlsx',
            );
        }

        /**
         * POST /products/import — reads an uploaded Excel file, runs the
         * ProductsImport pipeline and redirects with a flash summarising how
         * many rows were created, skipped (duplicate code) or reported as
         * invalid. The import itself runs in a single HTTP request; for very
         * large files consider queueing (out of scope here).
         */
        public function import(Request $request): RedirectResponse
        {
            abort_unless($request->user()->can('products.import'), 403);

            $request->validate([
                'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
            ], [
                'file.required' => 'Seleccioná un archivo Excel.',
                'file.max'      => 'El archivo no puede superar los 10 MB.',
                'file.mimes'    => 'El archivo debe ser XLSX, XLS o CSV.',
            ]);

            $import = new ProductsImport($request->user());
            Excel::import($import, $request->file('file'));

            $r = $import->result;
            $message = "Importación finalizada: {$r->created} creados"
                . ($r->skipped > 0 ? ", {$r->skipped} duplicados omitidos" : '')
                . ($r->invalid > 0 ? ", {$r->invalid} con errores" : '')
                . '.';

            return redirect()
                ->route('products.index')
                ->with('status', $message)
                ->with('import_rows', $r->rows);
        }

        /**
         * @return array<string, mixed>
         */
        private function formContext(): array
    {
        return [
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('sort')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'taxes' => Tax::query()->where('is_active', true)->orderBy('sort')->get(),
        ];
    }
}