<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DemoDataGenerateRequest;
use App\Models\Customer;
use App\Models\DemoDataBatch;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Services\DemoData\DemoDataDependencyPreview;
use App\Services\DemoData\DemoDataGenerator;
use App\Services\DemoData\DemoDataGuard;
use App\Services\DemoData\DemoDataPurger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DemoDataController extends Controller
{
    public function __construct(
        private readonly DemoDataDependencyPreview $preview,
        private readonly DemoDataGenerator $generator,
        private readonly DemoDataPurger $purger,
        private readonly DemoDataGuard $guard,
    ) {}

    public function index(Request $request): View
    {
        $activeBatch = DemoDataBatch::query()->active()->latest('id')->first();
        $preview = $this->preview->preview((array) $request->input('modules', []));

        return view('admin.demo-data.index', [
            'activeBatch' => $activeBatch,
            'batches' => DemoDataBatch::query()->withCount('records')->latest('id')->paginate(10),
            'modules' => DemoDataDependencyPreview::ALL_MODULES,
            'preview' => $preview,
            'realDataCount' => $this->realDataCount(),
        ]);
    }

    public function load(DemoDataGenerateRequest $request): RedirectResponse
    {
        $this->generator->generate(DemoDataDependencyPreview::ALL_MODULES, $request->user());

        return redirect()->route('admin.demo-data.index')->with('status', 'Datos de demostración cargados correctamente.');
    }

    public function loadModules(DemoDataGenerateRequest $request): RedirectResponse
    {
        $modules = $request->modules();
        $this->generator->generate($modules, $request->user());

        return redirect()->route('admin.demo-data.index')->with('status', 'Módulos demo cargados con dependencias seguras.');
    }

    public function reset(Request $request, DemoDataBatch $batch): RedirectResponse
    {
        abort_unless($request->user()?->can('demo-data.manage'), 403);
        $this->purger->reset($batch, $request->user());

        return redirect()->route('admin.demo-data.index')->with('status', 'Lote demo restablecido correctamente.');
    }

    public function destroy(Request $request, DemoDataBatch $batch): RedirectResponse
    {
        abort_unless($request->user()?->can('demo-data.manage'), 403);
        $this->purger->delete($batch);

        return redirect()->route('admin.demo-data.index')->with('status', 'Lote demo eliminado correctamente.');
    }

    private function realDataCount(): int
    {
        $total = 0;
        foreach ([Lead::class, Customer::class, Opportunity::class, Quotation::class] as $class) {
            $demoIds = \App\Models\DemoDataRecord::query()
                ->where('model_type', $class)
                ->pluck('record_id');

            $total += $class::query()
                ->when($demoIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $demoIds))
                ->count();
        }

        return $total;
    }
}
