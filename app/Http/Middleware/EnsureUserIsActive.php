<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects authenticated-but-inactive users on every request (RF-USR-002).
 *
 * The login form already blocks inactive credentials; this middleware is
 * the second gate for users deactivated AFTER logging in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('error', 'Su cuenta ha sido desactivada. Contacte al administrador.');
        }

        return $next($request);
    }
}
