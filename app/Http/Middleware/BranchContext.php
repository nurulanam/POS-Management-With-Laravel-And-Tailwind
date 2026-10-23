<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class BranchContext
{
    public function handle(Request $request, Closure $next)
    {
        // Always re-query membership: a session branch id is never an authorization grant.
        $branches = Branch::accessibleTo($request->user())->where('is_active', true)->orderBy('name')->get();
        $branch = $branches->firstWhere('id', $request->session()->get('branch_id')) ?? $branches->first();
        if ($branch) {
            $request->session()->put('branch_id', $branch->id);
        } else {
            $request->session()->forget('branch_id');
        }
        $request->attributes->set('currentBranch', $branch);
        View::share(['currentBranch' => $branch, 'availableBranches' => $branches]);

        return $next($request);
    }
}
