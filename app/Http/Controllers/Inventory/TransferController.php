<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\{Branch, StockTransfer, TransferReceipt};
use App\Services\Inventory\TransferStock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['in_transit', 'completed'])], 'direction' => ['nullable', Rule::in(['incoming', 'outgoing'])],
            'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $transfers = StockTransfer::forBranch($branch->id)
            ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
            ->when(isset($filter['direction']), fn ($q) => $q->where($filter['direction'] === 'incoming' ? 'destination_branch_id' : 'source_branch_id', $branch->id))
            ->when(isset($filter['q']), fn ($q) => $q->where('reference', 'like', '%'.$filter['q'].'%'))
            ->when(isset($filter['from']), fn ($q) => $q->where('dispatched_at', '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc()))
            ->when(isset($filter['to']), fn ($q) => $q->where('dispatched_at', '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc()))
            ->withSum('items', 'quantity')->withSum('items', 'received_quantity')->withSum('items', 'damaged_quantity')->withSum('items', 'missing_quantity')
            ->orderByDesc('id')->paginate(20)->withQueryString();
        return view('transfers.index', compact('transfers'));
    }

    public function create(Request $request)
    {
        return view('transfers.create', [
            'destinations' => Branch::accessibleTo($request->user())->where('is_active', true)->where('id', '!=', $request->attributes->get('currentBranch')->id)->orderBy('name')->get(['id', 'name', 'code']),
            'dispatchKey' => (string) Str::uuid(),
        ]);
    }

    public function catalog(Request $request)
    {
        $data = $request->validate(['destination_branch_id' => ['required', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $source = $request->attributes->get('currentBranch');
        $destination = Branch::accessibleTo($request->user())->where('is_active', true)->where('id', '!=', $source->id)->findOrFail($data['destination_branch_id']);
        $products = $source->products()->where('is_active', true)
            ->when(isset($data['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%')->orWhere('sku', 'like', '%'.$data['q'].'%')))
            ->orderBy('name')->paginate(24, ['id', 'name', 'sku', 'stock']);
        $targets = $destination->products()->where('is_active', true)->whereIn('sku', $products->pluck('sku'))->get(['id', 'name', 'sku'])->keyBy('sku');
        return response()->json(['products' => collect($products->items())->map(fn ($p) => [
            ...$p->only(['id', 'name', 'sku', 'stock']), 'target' => $targets->get($p->sku)?->only(['id', 'name']),
        ]), 'more' => $products->hasMorePages()]);
    }

    public function store(Request $request, TransferStock $service)
    {
        $data = $request->validate([
            'destination_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'notes' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['required', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.source_product_id' => ['required', 'integer', 'distinct'], 'items.*.destination_product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10000'],
        ]);
        $transfer = $service->dispatch($request->attributes->get('currentBranch'), $request->user(), $data);
        return redirect()->route('transfers.show', $transfer)->with('status', 'Transfer dispatched. Source stock deducted; units remain in transit until the destination accounts for them.');
    }

    public function show(Request $request, StockTransfer $stockTransfer)
    {
        $transfer = $this->scoped($request, $stockTransfer)->load('items');
        $receipts = $transfer->receipts()->orderByDesc('id')->paginate(10);
        return view('transfers.show', compact('transfer', 'receipts'));
    }

    public function receiveForm(Request $request, StockTransfer $stockTransfer)
    {
        $transfer = $this->scoped($request, $stockTransfer)->load('items');
        abort_unless($transfer->destination_branch_id === $request->attributes->get('currentBranch')->id, 403);
        return view('transfers.receive', ['transfer' => $transfer, 'receiptKey' => (string) Str::uuid()]);
    }

    public function receive(Request $request, StockTransfer $stockTransfer, TransferStock $service)
    {
        $transfer = $this->scoped($request, $stockTransfer);
        abort_unless($transfer->destination_branch_id === $request->attributes->get('currentBranch')->id, 403);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'delivery_reference' => ['required', 'string', 'max:100'], 'notes' => ['required', 'string', 'min:3', 'max:500'], 'confirmed' => ['required', 'accepted'],
            'discrepancy_confirmed' => ['sometimes', 'accepted'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.stock_transfer_item_id' => ['required', 'integer', 'distinct'],
            'items.*.received_quantity' => ['required', 'integer', 'between:0,10000'],
            'items.*.damaged_quantity' => ['required', 'integer', 'between:0,10000'], 'items.*.missing_quantity' => ['required', 'integer', 'between:0,10000'],
        ]);
        if (collect($data['items'])->sum(fn ($i) => $i['damaged_quantity'] + $i['missing_quantity']) > 0) {
            $request->validate(['discrepancy_confirmed' => ['required', 'accepted']]);
        }
        $receipt = $service->receive($request->attributes->get('currentBranch'), $transfer, $request->user(), $data);
        return redirect()->route('transfers.receipt', $receipt)->with('status', 'Transfer receipt recorded. Only accepted units were added to destination stock.');
    }

    public function receipt(Request $request, TransferReceipt $transferReceipt)
    {
        $receipt = TransferReceipt::whereHas('transfer', fn ($q) => $q->forBranch($request->attributes->get('currentBranch')->id))
            ->with(['transfer', 'items.transferItem'])->findOrFail($transferReceipt->id);
        return view('transfers.receipt', compact('receipt'));
    }

    private function scoped(Request $request, StockTransfer $transfer): StockTransfer
    {
        return StockTransfer::forBranch($request->attributes->get('currentBranch')->id)->findOrFail($transfer->id);
    }
}
