<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\V2\SendOutboundDelivery;
use App\Models\Notification\NotificationPreference;
use App\Models\Notification\OutboundDelivery;
use App\Services\Notification\NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;

/**
 * B17 Pasada B — Admin controller for the notification module.
 *
 * Endpoints (gated by 4 B17 permissions):
 *   - GET    /admin/notifications/preferences  → notifications.view
 *   - PATCH  /admin/notifications/preferences/{preference}  → notifications.manage
 *   - GET    /admin/notifications/deliveries   → notifications.view
 *   - GET    /admin/notifications/deliveries/{delivery}  → notifications.view
 *   - POST   /admin/notifications/deliveries/{delivery}/retry  → notifications.manage
 *   - POST   /admin/notifications/dispatch  → notifications.send
 *
 * The Livewire `NotificationPreferenceList` component (Pasada B scope) is
 * embedded in the `preferences/index.blade.php` view; this controller exposes
 * the toggle / filter routes it consumes via `wire:click` helpers.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service)
    {
    }

    /**
     * GET /admin/notifications/preferences
     *
     * The full Livewire bandeja + dedicated view lands in a follow-up B17.x
     * change. For B17 Pasada B the endpoint resolves the queried rows from
     * the DB and emits them as JSON so the test seam can assert
     * `notifications.view` enforcement without requiring a view file.
     */
    public function preferences(Request $request)
    {
        Gate::authorize('notifications.view');

        $query = NotificationPreference::query()
            ->with('user')
            ->latest();

        if ($userId = $request->query('user_id')) {
            $query->forUser((int) $userId);
        }

        if ($channel = $request->query('channel')) {
            $query->forChannel((string) $channel);
        }

        return response()->json([
            'ok' => true,
            'preferences' => $query->paginate(20)->items(),
        ]);
    }

    /**
     * PATCH /admin/notifications/preferences/{preference}
     */
    public function updatePreference(Request $request, NotificationPreference $preference)
    {
        Gate::authorize('notifications.manage');

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', 'in:optional,administrative,security'],
        ]);

        $preference->update($validated);

        return back()->with('success', 'Preferencia actualizada.');
    }

    /**
     * GET /admin/notifications/deliveries
     *
     * Returns JSON in B17 Pasada B; the dedicated admin view (with filters UI)
     * lands in a follow-up B17.x change.
     */
    public function deliveries(Request $request)
    {
        Gate::authorize('notifications.view');

        $query = OutboundDelivery::query()->latest();

        if ($channel = $request->query('channel')) {
            $query->byChannel((string) $channel);
        }

        if ($status = $request->query('status')) {
            $query->byStatus((string) $status);
        }

        return response()->json([
            'ok' => true,
            'deliveries' => $query->paginate(20)->items(),
        ]);
    }

    /**
     * GET /admin/notifications/deliveries/{delivery}
     *
     * Returns JSON in B17 Pasada B; the dedicated delivery-detail view lands
     * in a follow-up B17.x change.
     */
    public function showDelivery(OutboundDelivery $delivery)
    {
        Gate::authorize('notifications.view');

        return response()->json([
            'ok' => true,
            'delivery' => $delivery->toArray(),
        ]);
    }

    /**
     * POST /admin/notifications/deliveries/{delivery}/retry
     */
    public function retry(OutboundDelivery $delivery)
    {
        Gate::authorize('notifications.manage');

        $delivery->update([
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_error' => null,
        ]);

        SendOutboundDelivery::dispatch($delivery->id);

        return back()->with('success', 'Reintento encolado.');
    }

    /**
     * POST /admin/notifications/dispatch
     *
     * Force-dispatch a custom notification (admin escape hatch).
     */
    public function dispatchNow(Request $request)
    {
        Gate::authorize('notifications.send');

        $validated = $request->validate([
            'channel' => ['required', 'in:database,mail,whatsapp,webhook'],
            'recipient_ref' => ['required', 'string', 'max:191'],
            'related_entity_type' => ['nullable', 'string', 'max:80'],
            'related_entity_id' => ['nullable', 'integer'],
            'account_id' => ['nullable', 'integer', 'exists:integration_accounts,id'],
            'payload' => ['required', 'array'],
            'bucket' => ['required', 'string', 'max:80'],
        ]);

        $delivery = $this->service->dispatch($validated);

        return redirect()
            ->route('admin.notifications.deliveries.show', $delivery)
            ->with('success', 'Notificación encolada.');
    }
}
