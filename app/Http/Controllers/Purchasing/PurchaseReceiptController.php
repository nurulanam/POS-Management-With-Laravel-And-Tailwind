<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\{PurchaseOrder, PurchaseReceipt};
use App\Services\Purchasing\Purchasing;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PurchaseReceiptController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $receipts = $branch->purchaseReceipts()
            ->when(isset($filter['q']), fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%')->orWhere('delivery_reference', 'like', '%'.$filter['q'].'%')->orWhereHas('order', fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%'))))
            ->when(isset($filter['from']), fn ($q) => $q->where('received_at', '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc()))
            ->when(isset($filter['to']), fn ($q) => $q->where('received_at', '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc()))
            ->with('order')->orderByDesc('id')->paginate(20)->withQueryString();
        return view('purchases.receipts', compact('receipts'));
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder)
    {
        $order = $request->attributes->get('currentBranch')->purchaseOrders()->with('items')->findOrFail($purchaseOrder->id);
        return view('purchases.receive', [
            'order' => $order, 'receiptKey' => (string) Str::uuid(),
            'config' => ['currency' => $order->currency, 'precision' => Money::precision($order->currency), 'lines' => $order->items->map(fn ($item) => $item->only(['id', 'quantity', 'received_quantity', 'unit_cost_minor']))],
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder, Purchasing $service)
    {
        $order = $request->attributes->get('currentBranch')->purchaseOrders()->findOrFail($purchaseOrder->id);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'delivery_reference' => ['required', 'string', 'max:100'], 'notes' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:0,10000'],
        ]);
        $receipt = $service->receive($request->attributes->get('currentBranch'), $order, $request->user(), $data);
        return redirect()->route('purchase-receipts.show', $receipt)->with('status', 'Goods received. Stock and receiving records were saved together. No cash payment was recorded.');
    }

    public function show(Request $request, PurchaseReceipt $purchaseReceipt)
    {
        $receipt = $request->attributes->get('currentBranch')->purchaseReceipts()->with(['order', 'items.orderItem'])->findOrFail($purchaseReceipt->id);
        return view('purchases.receipt', compact('receipt'));
    }
}
