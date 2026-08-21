<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Internal notifications (RF-NOT-001, database channel): list + mark as
 * read (single or all). Every user sees only their own notifications —
 * Laravel scopes the notifiable morph relation by the authenticated user.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one notification (id) or all unread notifications as read.
     */
    public function markRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($id = $request->input('id')) {
            $notification = $user->notifications()->whereKey($id)->first();

            if ($notification !== null) {
                $notification->markAsRead();
            }

            $message = 'Notificación marcada como leída.';
        } else {
            $user->unreadNotifications->markAsRead();

            $message = 'Todas las notificaciones fueron marcadas como leídas.';
        }

        return redirect()
            ->route('notifications.index')
            ->with('status', $message);
    }
}
