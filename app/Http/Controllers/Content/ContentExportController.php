<?php

namespace App\Http\Controllers\Content;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContentGridFilterRequest;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentGridExportService;
use App\Support\Content\PeruTime;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Content scheduling module — grid exports (T10).
 *
 * Two additive read endpoints that answer exactly the grid's filters. The route
 * binding already resolved the addressed project and answered 404 for one the
 * user may not reach; on top of that both actions ask the policy for `view`,
 * because an export is a read.
 *
 * The rows come from ContentGridExportService, which reuses the very same
 * filtered query the screen uses, so the exported set cannot drift from the
 * on-screen set. The filter payload is read through ContentGridFilterRequest,
 * the same request the grid uses, so no second interpretation of the filters
 * exists. Excel goes through the repository's generic ArrayExport; the PDF goes
 * through Dompdf in landscape, mirroring the existing PDF surfaces.
 */
class ContentExportController extends Controller
{
    public function __construct(
        private readonly ContentGridExportService $export,
    ) {
    }

    public function excel(ContentGridFilterRequest $request, ContentProject $contentProject): BinaryFileResponse
    {
        Gate::authorize('view', $contentProject);

        return Excel::download(
            new ArrayExport(
                $this->export->headings($contentProject),
                $this->export->rows($contentProject, $request->filters()),
            ),
            $this->filename($contentProject, 'xlsx'),
        );
    }

    public function pdf(ContentGridFilterRequest $request, ContentProject $contentProject): Response
    {
        Gate::authorize('view', $contentProject);

        $filters = $request->filters();

        return DomPdf::loadView('content.exports.pdf', [
            'project' => $contentProject,
            'headings' => $this->export->headings($contentProject),
            'rows' => $this->export->rows($contentProject, $filters),
            'filterSummary' => $this->export->filterSummary($contentProject, $filters),
            'generatedAt' => PeruTime::toPeru(CarbonImmutable::now())->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape')->download($this->filename($contentProject, 'pdf'));
    }

    private function filename(ContentProject $project, string $extension): string
    {
        $slug = Str::slug((string) $project->slug);

        if ($slug === '') {
            $slug = 'proyecto';
        }

        return 'contenidos-'.$slug.'-'.PeruTime::toPeru(CarbonImmutable::now())->format('Ymd').'.'.$extension;
    }
}
