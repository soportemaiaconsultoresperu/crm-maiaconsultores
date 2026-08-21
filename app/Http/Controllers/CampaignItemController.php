<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignItemActionRequest;
use App\Models\CampaignActionItem;
use App\Services\CampaignItemService;
use App\Services\CampaignOverrideService;
use App\Services\CampaignRescheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampaignItemController extends Controller
{
    public function __construct(
        private readonly CampaignItemService $items,
        private readonly CampaignRescheduleService $reschedules,
        private readonly CampaignOverrideService $overrides,
    ) {}

    public function start(CampaignActionItem $item, Request $request): RedirectResponse
    {
        Gate::authorize('markRealized', [$item]); // re-using the same gate
        $this->items->markInProcess($item, $request->user());
        return back()->with('status', 'Item iniciado.');
    }

    public function markRealized(CampaignActionItem $item, CampaignItemActionRequest $request): RedirectResponse
    {
        Gate::authorize('markRealized', [$item]);
        $data = $request->validated();
        $this->items->markRealized(
            $item,
            (string) $data['result'],
            $data['contact_response'] ?? null,
            $data['observations'] ?? null,
            $request->user(),
        );
        return back()->with('status', 'Item marcado como realizado.');
    }

    public function cancel(CampaignActionItem $item, CampaignItemActionRequest $request): RedirectResponse
    {
        Gate::authorize('cancel', [$item]);
        $this->items->cancel($item, (string) $request->validated()['cancellation_reason'], $request->user());
        return back()->with('status', 'Item cancelado.');
    }

    public function markNotApplicable(CampaignActionItem $item, CampaignItemActionRequest $request): RedirectResponse
    {
        Gate::authorize('markRealized', [$item]); // supervisor+ owner
        $this->items->markNotApplicable($item, (string) $request->validated()['not_applicable_reason'], $request->user());
        return back()->with('status', 'Item marcado como "No aplica".');
    }

    public function reopenCompleted(CampaignActionItem $item, Request $request): RedirectResponse
    {
        Gate::authorize('markRealized', [$item]); // override permission
        $reason = (string) $request->input('reason');
        $this->overrides->reopenCompleted($item, $reason, $request->user());
        return back()->with('status', 'Item reabierto. Ahora puedes reprogramarlo.');
    }

    public function reschedule(CampaignActionItem $item, Request $request): RedirectResponse
    {
        Gate::authorize('reschedule', [$item]);
        $this->reschedules->rescheduleIndividual(
            $item,
            (string) $request->input('new_scheduled_at'),
            (string) $request->input('reason'),
            $request->user(),
        );
        return back()->with('status', 'Item reprogramado.');
    }

    public function updateMetadata(CampaignActionItem $item, CampaignItemActionRequest $request): RedirectResponse
    {
        Gate::authorize('markRealized', [$item]);
        $this->items->updateMetadata($item, $request->validated());
        return back()->with('status', 'Datos del item actualizados.');
    }
}
