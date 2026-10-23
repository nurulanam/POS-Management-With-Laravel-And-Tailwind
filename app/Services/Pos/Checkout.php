<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Checkout
{
    public function complete(Branch $branch, User $actor, array $data): Sale
    {
        return DB::transaction(function () use ($branch, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            $items = collect($data['items'])->map(fn ($item) => [
                'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'],
                'expected_price_minor' => (int) $item['expected_price_minor'],
            ])->sortBy('product_id')->values();
            $tendered = Money::parse($data['tendered'], $branch->currency, 'tendered', 2_000_000_000_000);
            $taxRate = Money::basisPoints($branch->tax_rate);
            $hash = hash('sha256', json_encode([
                $items->all(), (int) ($data['customer_id'] ?? 0), $tendered,
                $data['currency'], (int) $data['expected_tax_basis_points'],
            ], JSON_THROW_ON_ERROR));
            $existing = $branch->sales()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $actor->id, 403);
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This checkout key was already used for a different sale.');

                return $existing;
            }
            if ($branch->currency !== $data['currency'] || $taxRate !== (int) $data['expected_tax_basis_points']) {
                throw ValidationException::withMessages(['items' => 'Branch pricing settings changed. Reload checkout and review the new totals.']);
            }
            $customer = empty($data['customer_id']) ? null : $branch->customers()->where('is_active', true)->find($data['customer_id']);
            if (! empty($data['customer_id']) && ! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Select an active customer from this branch.']);
            }
            $products = $branch->products()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subtotal = 0;
            $lines = [];
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['items' => 'A product is unavailable in this branch. Refresh the catalog.']);
                }
                if ($product->price_minor !== $item['expected_price_minor']) {
                    throw ValidationException::withMessages(['items' => "The price of {$product->name} changed. Remove it and add it again to review the new price."]);
                }
                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Insufficient stock for {$product->name}. Available: {$product->stock}."]);
                }
                $lineTotal = $product->price_minor * $item['quantity'];
                $subtotal += $lineTotal;
                $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => $item['quantity'], 'unit_price_minor' => $product->price_minor, 'line_total_minor' => $lineTotal];
            }
            if ($subtotal > 1_000_000_000_000) {
                throw ValidationException::withMessages(['items' => 'This sale exceeds the supported value limit.']);
            }
            // Tax-exclusive pricing; round the aggregate tax half-up to the currency's minor unit.
            $tax = intdiv($subtotal * $taxRate + 5_000, 10_000);
            $total = $subtotal + $tax;
            if ($tendered < $total) {
                throw ValidationException::withMessages(['tendered' => 'Cash received must cover the total of '.Money::format($total, $branch->currency).'.']);
            }
            $sale = $branch->sales()->create([
                'user_id' => $actor->id, 'customer_id' => $customer?->id,
                'reference' => 'S-'.Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'currency' => $branch->currency, 'timezone' => $branch->timezone, 'tax_basis_points' => $taxRate,
                'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $total,
                'tendered_minor' => $tendered, 'change_minor' => $tendered - $total, 'payment_method' => 'cash',
                'branch_snapshot' => $branch->only(['name', 'code', 'address', 'phone', 'email', 'receipt_footer']),
                'customer_snapshot' => $customer?->only(['name', 'phone', 'email', 'address']),
                'cashier_name' => $actor->name, 'paid_at' => now(),
            ]);
            $sale->items()->createMany($lines);
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $product->decrement('stock', $item['quantity']);
                InventoryMovement::create([
                    'branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $actor->id, 'sale_id' => $sale->id,
                    'delta' => -$item['quantity'], 'balance' => $product->stock, 'reason' => 'Sale '.$sale->reference,
                ]);
            }

            return $sale;
        }, 3);
    }
}
