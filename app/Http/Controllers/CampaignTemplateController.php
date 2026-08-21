<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignTemplateStoreRequest;
use App\Models\CampaignTemplate;
use App\Services\CampaignTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampaignTemplateController extends Controller
{
    public function __construct(private readonly CampaignTemplateService $templates) {}

    public function index(Request $request): View
    {
        $query = CampaignTemplate::query()
            ->with(['owner', 'team'])
            ->forOwner($request->user())
            ->orderByDesc('updated_at');
        $templates = $query->paginate(25)->withQueryString();

        return view('admin.campaign_templates.index', [
            'templates' => $templates,
        ]);
    }

    public function create(): View
    {
        return view('admin.campaign_templates.create', [
            'objectives' => CampaignTemplate::OBJECTIVES,
        ]);
    }

    public function store(CampaignTemplateStoreRequest $request): RedirectResponse
    {
        $template = $this->templates->create($request->validated(), $request->user());
        return redirect()->route('admin.campaign_templates.show', $template)
            ->with('status', "Plantilla \"{$template->name}\" creada.");
    }

    public function show(CampaignTemplate $template): View
    {
        Gate::authorize('view', $template);
        $template->load(['steps', 'owner', 'team']);
        return view('admin.campaign_templates.show', [
            'template' => $template,
        ]);
    }

    public function edit(CampaignTemplate $template): View
    {
        Gate::authorize('update', $template);
        $template->load('steps');
        return view('admin.campaign_templates.edit', [
            'template' => $template,
            'objectives' => CampaignTemplate::OBJECTIVES,
        ]);
    }

    public function update(CampaignTemplateStoreRequest $request, CampaignTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);
        $this->templates->update($template, $request->validated());
        return redirect()->route('admin.campaign_templates.show', $template)
            ->with('status', "Plantilla \"{$template->name}\" actualizada.");
    }

    public function destroy(CampaignTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);
        $template->delete();
        return redirect()->route('admin.campaign_templates.index')
            ->with('status', "Plantilla \"{$template->name}\" desactivada.");
    }

    public function duplicate(Request $request, CampaignTemplate $template): RedirectResponse
    {
        Gate::authorize('duplicate', $template);
        $newName = trim((string) $request->input('new_name', $template->name . ' (copia)'));
        $clone = $this->templates->duplicate($template, $newName, $request->user());
        return redirect()->route('admin.campaign_templates.edit', $clone)
            ->with('status', "Plantilla duplicada como \"{$clone->name}\".");
    }
}
