<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $query = $branch->sales()->visibleTo($request->user())
            ->when(isset($filter['q']), fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%'))
            ->when(isset($filter['from']), fn ($q) => $q->where('paid_at', '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc()))
            ->when(isset($filter['to']), fn ($q) => $q->where('paid_at', '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc()));
        $total = (int) (clone $query)->sum('total_minor');
        $sales = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('sales.index', compact('sales', 'total'));
    }

    public function show(Request $request, Sale $sale)
    {
        $sale = $request->attributes->get('currentBranch')->sales()->visibleTo($request->user())->with('items')->findOrFail($sale->id);

        return view('sales.receipt', compact('sale'));
    }
}
