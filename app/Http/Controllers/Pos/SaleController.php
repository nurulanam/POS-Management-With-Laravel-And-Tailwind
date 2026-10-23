<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\RefundItem;
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
        $refunded = (int) $branch->refunds()->whereIn('sale_id', (clone $query)->select('sales.id'))->sum('total_minor');
        $sales = $query->withSum('refunds', 'total_minor')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('sales.index', compact('sales', 'total', 'refunded'));
    }

    public function show(Request $request, Sale $sale)
    {
        $sale = $request->attributes->get('currentBranch')->sales()->visibleTo($request->user())->with('items')->findOrFail($sale->id);

        $refunds = $sale->refunds()->orderByDesc('id')->paginate(10, ['*'], 'refund_page');
        $refundTotal = (int) $sale->refunds()->sum('total_minor');
        $returned = (int) RefundItem::whereIn('sale_item_id', $sale->items->pluck('id'))->sum('quantity');
        $remaining = $sale->items->sum('quantity') - $returned;

        return view('sales.receipt', compact('sale', 'refunds', 'refundTotal', 'returned', 'remaining'));
    }
}
