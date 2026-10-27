<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\Pos\RefundSale;
use App\Services\Pos\ReturnQuote;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RefundController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $query = $branch->refunds()->visibleTo($request->user())
            ->when(isset($filter['q']), fn ($query) => $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%')->orWhereHas('sale', fn ($sales) => $sales->where('reference', 'like', '%'.$filter['q'].'%'))))
            ->when(isset($filter['from']), fn ($q) => $q->where('refunded_at', '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc()))
            ->when(isset($filter['to']), fn ($q) => $q->where('refunded_at', '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc()));
        $total = (int) (clone $query)->sum('total_minor');
        $refunds = $query->with('sale')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('refunds.index', compact('refunds', 'total'));
    }

    public function create(Request $request, Sale $sale, ReturnQuote $quote)
    {
        $sale = $request->attributes->get('currentBranch')->sales()->findOrFail($sale->id);
        $branch = $request->attributes->get('currentBranch');
        $cashSession = $branch->cashSessions()->where('active_owner_id', $request->user()->id)->first();
        $lines = $quote->lines($sale);
        $config = ['cashSessionRequired' => $branch->cash_tracking_enabled, 'cashSessionId' => $cashSession?->id, 'lines' => array_values($lines), 'currency' => $sale->currency, 'precision' => Money::precision($sale->currency)];

        return view('refunds.create', ['cashSession' => $cashSession, 'sale' => $sale, 'lines' => $lines, 'config' => $config, 'refundKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, Sale $sale, RefundSale $service)
    {
        abort_unless($sale->branch_id === $request->attributes->get('currentBranch')->id, 404);
        $data = $request->validate([
            'cash_session_id' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirmed' => ['required', 'accepted'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:0,10000'],
            'items.*.restock' => ['sometimes', 'boolean'], 'items.*.expected_returned' => ['required', 'integer', 'between:0,10000'],
        ]);
        $refund = $service->record($request->attributes->get('currentBranch'), $sale, $request->user(), $data);

        return redirect()->route('refunds.show', $refund)->with('status', 'Cash refund recorded. Only items explicitly marked for restocking were added to inventory.');
    }

    public function show(Request $request, Refund $refund)
    {
        $refund = $request->attributes->get('currentBranch')->refunds()->visibleTo($request->user())
            ->with(['sale', 'items.saleItem'])->findOrFail($refund->id);

        return view('refunds.receipt', compact('refund'));
    }
}
