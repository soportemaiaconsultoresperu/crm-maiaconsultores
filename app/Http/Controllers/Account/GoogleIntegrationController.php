<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Google\GoogleOAuthService;
use App\Services\GoogleCalendarActivitySyncService;
use App\Services\GoogleCalendarWatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $google,
        private readonly GoogleCalendarActivitySyncService $calendarSync,
        private readonly GoogleCalendarWatchService $calendarWatch,
    ) {
    }

    public function index(Request $request): View
    {
        $google = $this->google->statusFor($request->user());

        return view('account.integrations.index', [
            'google' => $google,
            'calendarInitialSyncCount' => $google['services']['calendar']
                ? $this->calendarSync->countInitialSyncCandidates($request->user())
                : 0,
            'calendarWatch' => $google['services']['calendar']
                ? $this->calendarWatch->statusForUser($request->user())
                : null,
        ]);
    }

    public function connect(Request $request, string $service): RedirectResponse
    {
        try {
            return redirect()->away($this->google->authorizationRedirect($request->user(), $service));
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()
                ->route('account.integrations.index')
                ->with('error', 'Google authorization was cancelled or rejected.');
        }

        $validated = $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $account = $this->google->handleCallback(
                $request->user(),
                (string) $validated['state'],
                (string) $validated['code'],
            );

            $config = (array) ($account->config_json ?? []);
            $services = (array) ($config['services'] ?? []);
            if ((bool) ($services['calendar'] ?? false)) {
                $this->calendarWatch->startForAccount($account);
            }
        } catch (RuntimeException $e) {
            return redirect()
                ->route('account.integrations.index')
                ->with('error', $e->getMessage());
        }

        $initialSyncCount = $this->calendarSync->countInitialSyncCandidates($request->user());
        $status = $initialSyncCount > 0
            ? "Google account connected. Se encontraron {$initialSyncCount} actividades futuras para sincronizar con Google Calendar."
            : 'Google account connected.';

        return redirect()
            ->route('account.integrations.index')
            ->with('status', $status);
    }

    public function syncCalendar(Request $request): RedirectResponse
    {
        $queued = $this->calendarSync->queueInitialSyncCandidates($request->user());

        return redirect()
            ->route('account.integrations.index')
            ->with('status', $queued > 0
                ? "Se encolaron {$queued} actividades futuras para sincronizar con Google Calendar."
                : 'No hay actividades futuras pendientes para sincronizar con Google Calendar.');
    }

    public function disable(Request $request, string $service): RedirectResponse
    {
        try {
            if ($service === 'calendar') {
                $account = $this->google->accountFor($request->user());
                if ($account !== null) {
                    $this->calendarWatch->stopActiveForAccount($account);
                }
            }

            $this->google->disableService($request->user(), $service);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Google service disabled locally.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        try {
            $account = $this->google->accountFor($request->user());
            if ($account !== null) {
                $this->calendarWatch->stopActiveForAccount($account);
            }

            $this->google->disconnect($request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Google account disconnected.');
    }
}
