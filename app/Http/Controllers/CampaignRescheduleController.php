<?php

namespace App\Http\Controllers;

use App\Http\Requests\CampaignRescheduleRequest;
use App\Models\CampaignRun;
use App\Services\CampaignRescheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampaignRescheduleController extends Controller
{
    public function __construct(private readonly CampaignRescheduleService $reschedules) {}

    public function rescheduleAll(CampaignRescheduleRequest $request, CampaignRun $run): RedirectResponse
    {
        Gate::authorize('reschedule', $run);
        $data = $request->validated();
        $count = $this->reschedules->rescheduleAll(
            $run,
            (string) $data['new_starts_at'],
            (string) $data['reason'],
            $request->user(),
            $data['strategy'] ?? [],
        );
        return redirect()->route('admin.campaign_runs.show', $run)
            ->with('status', "Reprogramación global aplicada a {$count} items.");
    }
}
