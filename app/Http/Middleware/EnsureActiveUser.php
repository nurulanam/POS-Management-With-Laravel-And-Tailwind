<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $hasWebSession = $request->hasSession() && Auth::guard('web')->check();
        $expired = $hasWebSession && $request->session()->get('user_version') !== $user->session_version;

        if (! $user->is_active || $expired) {
            if ($hasWebSession) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            if ($request->expectsJson()) {
                abort(403, 'Your account is inactive or your session has expired.');
            }

            return redirect()->route('login')->withErrors(['email' => 'Please sign in again. Your account or access may have changed.']);
        }

        return $next($request);
    }
}
