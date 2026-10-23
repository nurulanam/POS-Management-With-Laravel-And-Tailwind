<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->must_change_password) {
            if ($request->expectsJson()) {
                abort(403, 'Change your temporary password before accessing the application.');
            }

            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
