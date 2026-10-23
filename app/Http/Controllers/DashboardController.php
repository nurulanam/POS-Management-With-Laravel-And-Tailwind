<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $branch = $request->attributes->get('currentBranch');
        $stats = $request->user()->isAdmin() ? [
            'Active staff' => User::where('is_active', true)->count(),
            'Active branches' => Branch::where('is_active', true)->count(),
            'Managers' => User::where('role', UserRole::Manager)->where('is_active', true)->count(),
        ] : [
            'Assigned branches' => Branch::accessibleTo($request->user())->where('is_active', true)->count(),
            'Your role' => $request->user()->role->label(),
            'Account status' => 'Active',
        ];

        return view('dashboard', compact('branch', 'stats'));
    }
}
