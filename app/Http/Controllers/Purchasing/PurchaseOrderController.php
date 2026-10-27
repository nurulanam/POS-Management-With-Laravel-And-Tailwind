<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\Purchasing;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['open', 'partial', 'received', 'closed'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $orders = $branch->purchaseOrders()
            ->when(isset($filter['q']), fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%')->orWhere('supplier_reference', 'like', '%'.$filter['q'].'%')))
            ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->when(isset($filter['from']), fn ($q) => $q->where('ordered_at', '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc()))
            ->when(isset($filter['to']), fn ($q) => $q->where('ordered_at', '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc()))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        return view('purchases.index', compact('orders'));
    }

    public function create(Request $request)
    {
        $branch = $request->attributes->get('currentBranch');
        $old = collect($request->old('items', []))->filter(fn ($row) => is_array($row) && isset($row['product_id']) && is_scalar($row['product_id']))->take(100);
        $products = $branch->products()->where('is_active', true)->whereIn('id', $old->pluck('product_id'))->get()->keyBy('id');
        $items = $old->filter(fn ($row) => $products->has($row['product_id']))->map(fn ($row) => [
            ...$products[$row['product_id']]->only(['id', 'name', 'sku']),
            'quantity' => is_numeric($row['quantity'] ?? null) ? min(10000, max(1, (int) $row['quantity'])) : 1,
            'unit_cost' => is_string($row['unit_cost'] ?? null) ? $row['unit_cost'] : '',
        ])->values();
        return view('purchases.create', [
            'suppliers' => $branch->suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'orderKey' => (string) Str::uuid(),
            'config' => ['currency' => $branch->currency, 'precision' => Money::precision($branch->currency), 'branchId' => $branch->id, 'catalogUrl' => route('pos.catalog', [], false), 'items' => $items],
        ]);
    }

    public function store(Request $request, Purchasing $service)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'supplier_id' => ['required', 'integer'], 'currency' => ['required', 'string', 'size:3'],
            'supplier_reference' => ['nullable', 'string', 'max:100'], 'expected_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10000'], 'items.*.unit_cost' => ['required', 'string', 'max:20'],
        ]);
        $order = $service->create($request->attributes->get('currentBranch'), $request->user(), $data);
        return redirect()->route('purchases.show', $order)->with('status', 'Purchase order created. No stock or cash changed; receive inspected goods when they arrive.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $order = $request->attributes->get('currentBranch')->purchaseOrders()->with('items')->findOrFail($purchaseOrder->id);
        $receipts = $order->receipts()->orderByDesc('id')->paginate(10);
        $receivedValue = (int) $order->receipts()->sum('total_minor');
        return view('purchases.show', ['order' => $order, 'receipts' => $receipts, 'receivedValue' => $receivedValue, 'closeKey' => (string) Str::uuid()]);
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder, Purchasing $service)
    {
        $order = $request->attributes->get('currentBranch')->purchaseOrders()->findOrFail($purchaseOrder->id);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['required', 'accepted'],
        ]);
        $service->close($request->attributes->get('currentBranch'), $order, $request->user(), $data);
        return redirect()->route('purchases.show', $order)->with('status', 'Outstanding quantities closed. Existing receipts and stock are unchanged.');
    }
}
