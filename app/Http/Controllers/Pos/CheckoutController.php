<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\Pos\Checkout;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function index(Request $request)
    {
        $branch = $request->attributes->get('currentBranch');
        $old = collect($request->old('items', []))->filter(fn ($item) => is_array($item) && isset($item['product_id'], $item['quantity']));
        $restored = $branch->products()->where('is_active', true)->whereIn('id', $old->pluck('product_id')->filter(fn ($id) => is_scalar($id))->take(100))->get()->keyBy('id');
        $cart = $old->filter(fn ($item) => is_scalar($item['product_id']) && $restored->has($item['product_id']) && is_numeric($item['quantity']))->map(function ($item) use ($restored) {
            $product = $restored->get($item['product_id']);

            return [...$product->only(['id', 'name', 'sku', 'price_minor', 'stock']), 'quantity' => min(10000, max(1, (int) $item['quantity']))];
        })->values();
        $config = [
            'currency' => $branch->currency, 'precision' => Money::precision($branch->currency),
            'taxBasisPoints' => Money::basisPoints($branch->tax_rate), 'branchId' => $branch->id,
            'catalogUrl' => route('pos.catalog', [], false), 'cart' => $cart,
        ];

        return view('pos.checkout', [
            'config' => $config, 'customers' => $branch->customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'checkoutKey' => (string) Str::uuid(),
        ]);
    }

    public function catalog(Request $request)
    {
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $products = $request->attributes->get('currentBranch')->products()->where('is_active', true)
            ->when(isset($filter['q']), fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$filter['q'].'%')->orWhere('sku', 'like', '%'.$filter['q'].'%')->orWhere('barcode', $filter['q'])))
            ->orderBy('name')->paginate(24, ['id', 'name', 'sku', 'category', 'price_minor', 'stock']);

        return response()->json(['products' => $products->items(), 'next_page' => $products->hasMorePages() ? $products->currentPage() + 1 : null]);
    }

    public function store(Request $request, Checkout $checkout)
    {
        $data = $request->validate([
            'discount_type' => ['nullable', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => [Rule::requiredIf(in_array($request->input('discount_type'), ['fixed', 'percent'], true)), 'nullable', 'string', 'max:20'],
            'discount_reason' => [Rule::requiredIf(in_array($request->input('discount_type'), ['fixed', 'percent'], true)), 'nullable', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'], 'currency' => ['required', 'string', 'size:3'],
            'expected_tax_basis_points' => ['required', 'integer', 'between:0,10000'],
            'customer_id' => ['nullable', 'integer'], 'tendered' => ['required', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,10000'],
            'items.*.expected_price_minor' => ['required', 'integer', 'between:1,1000000000'],
        ]);
        $sale = $checkout->complete($request->attributes->get('currentBranch'), $request->user(), $data);

        return redirect()->route('sales.show', $sale)->with('status', 'Cash sale completed. Stock and receipt have been recorded.');
    }
}
