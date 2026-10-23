<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RequireOperationalBranch
{
    public function handle(Request $request, Closure $next)
    {
        $branch = $request->attributes->get('currentBranch');
        abort_unless($branch, 403, 'Select or request access to an active branch first.');
        if (! $request->isMethodSafe() || $request->has('branch_id')) {
            $data = $request->validate(['branch_id' => ['required', 'integer']]);
            if ((int) $data['branch_id'] !== $branch->id) {
                throw ValidationException::withMessages(['branch_id' => 'The active branch changed. Reload this page before continuing.']);
            }
        }

        return $next($request);
    }
}
