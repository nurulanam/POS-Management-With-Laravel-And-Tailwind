<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            $discountType = $data['discount_type'] ?? 'none';
            $discountValue = $discountType === 'none' ? 0 : Money::parse($data['discount_value'], $discountType === 'percent' ? 'BDT' : $branch->currency, 'discount_value', $discountType === 'percent' ? 10000 : 1_000_000_000_000);
            $hashData = [
                $items->all(), (int) ($data['customer_id'] ?? 0), $tendered,
                $data['currency'], (int) $data['expected_tax_basis_points'],
            ];
            // Preserve pre-discount idempotency hashes for old forms / receipts.
            if ($discountType !== 'none') {
                $hashData[] = [$discountType, $discountValue, $data['discount_reason']];
            }
            if (! empty($data['cash_session_id'])) $hashData[] = ['cash_session', (int) $data['cash_session_id']];
            $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
            $existing = $branch->sales()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $actor->id, 403);
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This checkout key was already used for a different sale.');

                return $existing;
            }
            $drawer = app(CashDrawer::class);
            $cashSession = $drawer->forPayment($branch, $actor, $data);
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
            $discount = 0;
            if ($discountType !== 'none') {
                Gate::forUser($actor)->authorize('manage-adjustments');
                $discount = $discountType === 'percent' ? intdiv($subtotal * $discountValue + 5000, 10000) : $discountValue;
                if ($discountValue <= 0 || $discount > $subtotal) {
                    throw ValidationException::withMessages(['discount_value' => 'Use a positive discount that does not exceed the subtotal (or 100%).']);
                }
            }
            // Discounts reduce the tax base; tax is rounded once on the discounted subtotal.
            $tax = intdiv(($subtotal - $discount) * $taxRate + 5_000, 10_000);
            $total = $subtotal - $discount + $tax;
            if ($tendered < $total) {
                throw ValidationException::withMessages(['tendered' => 'Cash received must cover the total of '.Money::format($total, $branch->currency).'.']);
            }
            $sale = $branch->sales()->create([
                'cash_session_id' => $cashSession?->id,
                'user_id' => $actor->id, 'customer_id' => $customer?->id,
                'reference' => 'S-'.Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'currency' => $branch->currency, 'timezone' => $branch->timezone, 'tax_basis_points' => $taxRate,
                'discount_minor' => $discount, 'discount_type' => $discountType,
                'discount_value' => $discountType === 'none' ? null : Money::decimal($discountValue, $discountType === 'percent' ? 'BDT' : $branch->currency),
                'discount_reason' => $discountType === 'none' ? null : $data['discount_reason'],
                'discount_authorized_by' => $discountType === 'none' ? null : $actor->id,
                'discount_authorizer_name' => $discountType === 'none' ? null : $actor->name,
                'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $total,
                'tendered_minor' => $tendered, 'change_minor' => $tendered - $total, 'payment_method' => 'cash',
                'branch_snapshot' => $branch->only(['name', 'code', 'address', 'phone', 'email', 'receipt_footer']),
                'customer_snapshot' => $customer?->only(['name', 'phone', 'email', 'address']),
                'cashier_name' => $actor->name, 'paid_at' => now('UTC'),
            ]);
            if ($cashSession) $drawer->append($cashSession, $actor, $total, 'sale', 'Sale '.$sale->reference, ['sale_id' => $sale->id]);
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
