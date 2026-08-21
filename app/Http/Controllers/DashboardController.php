<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Dashboard controller (RF-DASH-001..005).
 *
 * Thin: DashboardService::forUser already returns the aggregated
 * 12-key payload, scoped by the requester's data visibility
 * (ADR-006) and grouped by currency (ADR-004). The controller
 * resolves the viewer and hands the payload to the Blade view.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Render the dashboard for the authenticated user.
     */
    public function index(Request $request): View
    {
        $payload = $this->dashboard->forUser($request->user());

        return view('dashboard.index', $payload);
    }
}