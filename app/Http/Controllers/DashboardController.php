<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use App\Support\Money;
use Carbon\CarbonImmutable;

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

        $salesStats = [];
        if ($branch) {
            $start = CarbonImmutable::now($branch->timezone)->startOfDay();
            $sales = $branch->sales()->visibleTo($request->user())
                ->where('paid_at', '>=', $start->utc())->where('paid_at', '<', $start->addDay()->utc());
            $salesTotal = (int) (clone $sales)->sum('total_minor');
            $refundTotal = (int) $branch->refunds()->visibleTo($request->user())
                ->where('refunded_at', '>=', $start->utc())->where('refunded_at', '<', $start->addDay()->utc())->sum('total_minor');
            $salesStats = [
                'Completed sales today' => (clone $sales)->count(),
                'Sales total today (incl. tax)' => Money::format($salesTotal, $branch->currency),
                'Refunds processed today' => Money::format($refundTotal, $branch->currency),
                'Net activity today' => Money::format($salesTotal - $refundTotal, $branch->currency),
            ];
            if ($request->user()->can('manage-inventory')) {
                $salesStats['Low-stock products'] = $branch->products()->where('is_active', true)->whereColumn('stock', '<=', 'reorder_level')->count();
            }
        }

        return view('dashboard', compact('branch', 'stats', 'salesStats'));
    }
}
