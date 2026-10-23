<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Pos\BranchLock;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive', 'low'])]]);
        $products = $request->attributes->get('currentBranch')->products()
            ->when(isset($filters['q']), fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%')->orWhere('barcode', $filters['q'])))
            ->when(($filters['status'] ?? null) === 'low', fn ($q) => $q->where('is_active', true)->whereColumn('stock', '<=', 'reorder_level'))
            ->when(in_array($filters['status'] ?? null, ['active', 'inactive']), fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('products.index', compact('products'));
    }

    public function create()
    {
        return view('products.form', ['product' => new Product(['is_active' => true, 'reorder_level' => 5, 'price_minor' => 0])]);
    }

    public function edit(Request $request, Product $product)
    {
        abort_unless($product->branch_id === $request->attributes->get('currentBranch')->id, 404);

        return view('products.form', compact('product'));
    }

    public function store(Request $request)
    {
        return $this->save($request);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($product->branch_id === $request->attributes->get('currentBranch')->id, 404);

        return $this->save($request, $product);
    }

    private function save(Request $request, ?Product $product = null)
    {
        DB::transaction(function () use ($request, $product) {
            $branch = BranchLock::acquire($request->attributes->get('currentBranch'), $request->user());
            Gate::authorize('manage-inventory');
            if (is_string($request->input('sku'))) {
                $request->merge(['sku' => strtoupper(trim($request->input('sku')))]);
            }
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'sku' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('products')->where('branch_id', $branch->id)->ignore($product)],
                'barcode' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products')->where('branch_id', $branch->id)->ignore($product)],
                'category' => ['nullable', 'string', 'max:60'],
                'price' => ['required', 'string', 'max:20'],
                'currency' => ['required', Rule::in([$branch->currency])],
                'is_active' => ['required', 'boolean'],
                'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000000'],
                ...($product ? [] : ['opening_stock' => ['required', 'integer', 'min:0', 'max:1000000000']]),
            ]);
            $price = Money::parse($data['price'], $branch->currency);
            if ($price < 1) {
                throw ValidationException::withMessages(['price' => 'The selling price must be greater than zero.']);
            }
            $attributes = collect($data)->only(['name', 'sku', 'barcode', 'category', 'is_active', 'reorder_level'])->all();
            $attributes['price_minor'] = $price;
            if ($product) {
                $product->update($attributes);
            } else {
                $product = $branch->products()->create([...$attributes, 'stock' => $data['opening_stock']]);
                if ($product->stock > 0) {
                    InventoryMovement::create([
                        'branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $request->user()->id,
                        'delta' => $product->stock, 'balance' => $product->stock, 'reason' => 'Opening stock',
                    ]);
                }
            }
        }, 3);

        return redirect()->route('products.index')->with('status', 'Product saved. Stock changes are recorded separately in the inventory ledger.');
    }

    public function show(Request $request, Product $product)
    {
        abort_unless($product->branch_id === $request->attributes->get('currentBranch')->id, 404);

        return view('products.stock', [
            'product' => $product, 'movements' => $product->movements()->with('user')->orderByDesc('id')->paginate(20),
            'requestKey' => (string) Str::uuid(),
        ]);
    }

    public function adjust(Request $request, Product $product)
    {
        abort_unless($product->branch_id === $request->attributes->get('currentBranch')->id, 404);
        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
            'expected_stock' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'],
        ]);
        DB::transaction(function () use ($request, $product, $data) {
            $branch = BranchLock::acquire($request->attributes->get('currentBranch'), $request->user());
            Gate::authorize('manage-inventory');
            $product->refresh();
            $existing = InventoryMovement::where('branch_id', $branch->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->product_id === $product->id && $existing->user_id === $request->user()->id && $existing->delta === (int) $data['delta'] && $existing->reason === $data['reason'], 409, 'Adjustment key already used for a different request.');

                return;
            }
            if ($product->stock !== (int) $data['expected_stock']) {
                throw ValidationException::withMessages(['delta' => 'Stock changed while this page was open. Reload before adjusting it.']);
            }
            $balance = $product->stock + (int) $data['delta'];
            if ($balance < 0 || $balance > 1_000_000_000) {
                throw ValidationException::withMessages(['delta' => 'The resulting stock must be between 0 and 1,000,000,000 units.']);
            }
            $product->update(['stock' => $balance]);
            InventoryMovement::create([
                'branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $request->user()->id,
                'delta' => (int) $data['delta'], 'balance' => $balance, 'reason' => $data['reason'], 'request_key' => $data['request_key'],
            ]);
        }, 3);

        return redirect()->route('products.show', $product)->with('status', 'Stock adjustment recorded.');
    }
}
