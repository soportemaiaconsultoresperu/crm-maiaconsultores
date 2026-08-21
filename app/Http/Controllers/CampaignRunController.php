<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRunStoreRequest;
use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Services\CampaignContactSearchService;
use App\Services\CampaignMetricsService;
use App\Services\CampaignRunService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampaignRunController extends Controller
{
    public function __construct(
        private readonly CampaignRunService $runs,
        private readonly CampaignMetricsService $metrics,
        private readonly CampaignContactSearchService $search,
    ) {}

    public function index(Request $request): View
    {
        $query = CampaignRun::query()
            ->with(['template', 'owner', 'team'])
            ->forOwner($request->user())
            ->orderByDesc('starts_at');
        $runs = $query->paginate(25)->withQueryString();

        return view('admin.campaign_runs.index', [
            'runs' => $runs,
        ]);
    }

    public function create(Request $request): View
    {
        $templateId = (int) $request->query('template_id');
        $template = $templateId
            ? \App\Models\CampaignTemplate::query()->findOrFail($templateId)
            : null;

        return view('admin.campaign_runs.create', [
            'template' => $template,
        ]);
    }

    public function store(CampaignRunStoreRequest $request): RedirectResponse
    {
        $template = \App\Models\CampaignTemplate::query()->findOrFail($request->input('template_id'));
        $run = $this->runs->createFromTemplate($template, $request->validated(), $request->user());
        return redirect()->route('admin.campaign_runs.show', $run)
            ->with('status', "Ejecución \"{$run->name}\" ({$run->code}) creada.");
    }

    public function show(CampaignRun $run, Request $request): View
    {
        Gate::authorize('view', $run);
        $run->load(['template', 'owner', 'team']);
        $participants = $run->participants()->where('status', \App\Models\CampaignParticipant::STATUS_ACTIVE)->get();
        $items = $run->items()->with(['participant', 'step'])->orderBy('scheduled_at')->paginate(200);
        $metrics = $this->metrics->compute($run);

        return view('admin.campaign_runs.show', [
            'run' => $run,
            'participants' => $participants,
            'items' => $items,
            'metrics' => $metrics,
        ]);
    }

    public function schedule(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('schedule', $run);
        $this->runs->schedule($run, $request->user());
        return redirect()->route('admin.campaign_runs.show', $run)->with('status', 'Ejecución programada.');
    }

    public function pause(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('pause', $run);
        $reason = $request->input('reason', 'Pausa solicitada');
        $this->runs->pause($run, $reason, $request->user());
        return redirect()->route('admin.campaign_runs.show', $run)->with('status', 'Ejecución pausada.');
    }

    public function resume(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('start', $run);
        $this->runs->resume($run, $request->user());
        return redirect()->route('admin.campaign_runs.show', $run)->with('status', 'Ejecución reanudada.');
    }

    public function cancel(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('cancel', $run);
        $reason = $request->input('reason', 'Cancelada');
        $this->runs->cancel($run, $reason, $request->user());
        return redirect()->route('admin.campaign_runs.index')->with('status', "Ejecución \"{$run->name}\" cancelada.");
    }

    public function complete(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('complete', $run);
        $reason = $request->input('reason');
        $this->runs->complete($run, $reason, $request->user());
        return redirect()->route('admin.campaign_runs.show', $run)->with('status', 'Ejecución cerrada.');
    }

    /**
     * Search endpoint (JSON) used by the mass-selector in the wizard / show page.
     */
    public function searchContacts(Request $request): JsonResponse
    {
        $results = $this->search->search($request->all(), page: (int) $request->query('page', 1), perPage: 25);
        return response()->json(['results' => $results]);
    }

    public function duplicate(CampaignRun $run, Request $request): RedirectResponse
    {
        Gate::authorize('duplicate', $run);
        $newName = trim((string) $request->input('new_name', $run->name . ' (copia)'));
        $template = \App\Models\CampaignTemplate::query()->findOrFail($run->template_id);
        $clone = app(\App\Services\CampaignTemplateService::class)->duplicate($template, $newName . ' (ejecución)', $request->user());
        return redirect()->route('admin.campaign_runs.create', ['template_id' => $clone->id])
            ->with('status', "Ejecución duplicada como \"{$clone->name}\".");
    }
}
