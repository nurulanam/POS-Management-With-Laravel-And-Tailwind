<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RefundSale
{
    public function record(Branch $branch, Sale $sale, User $actor, array $data): Refund
    {
        return DB::transaction(function () use ($branch, $sale, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            Gate::forUser($actor)->authorize('manage-adjustments');
            $sale = $branch->sales()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->payment_method !== 'cash') {
                throw ValidationException::withMessages(['sale' => 'Only cash sales can be refunded through this workflow.']);
            }
            $items = collect($data['items'])->filter(fn ($item) => (int) $item['quantity'] > 0)->map(fn ($item) => [
                'sale_item_id' => (int) $item['sale_item_id'], 'quantity' => (int) $item['quantity'],
                'restock' => (bool) ($item['restock'] ?? false), 'expected_returned' => (int) $item['expected_returned'],
            ])->sortBy('sale_item_id')->values();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Choose at least one unit to return.']);
            }
            $hash = hash('sha256', json_encode([$sale->id, $data['reason'], $items->all()], JSON_THROW_ON_ERROR));
            $existing = $branch->refunds()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $actor->id, 403);
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This refund key was already used for a different request.');

                return $existing;
            }
            $quote = app(ReturnQuote::class)->lines($sale);
            $products = Product::where('branch_id', $branch->id)->whereIn('id', array_column($quote, 'product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $base = 0;
            $tax = 0;
            $restocks = [];
            foreach ($items as $item) {
                $line = $quote[$item['sale_item_id']] ?? null;
                if (! $line || $item['quantity'] > $line['sold'] - $line['returned']) {
                    throw ValidationException::withMessages(['items' => 'A return quantity exceeds the remaining units or does not belong to this sale.']);
                }
                if ($line['returned'] !== $item['expected_returned']) {
                    throw ValidationException::withMessages(['items' => 'This sale was refunded while the form was open. Reload and review the remaining units.']);
                }
                $amounts = ReturnQuote::portion($line, $item['quantity']);
                $base += $amounts['subtotal_minor'];
                $tax += $amounts['tax_minor'];
                $lines[] = [...$amounts, 'sale_item_id' => $line['id'], 'quantity' => $item['quantity'], 'restocked' => $item['restock']];
                if ($item['restock']) {
                    $restocks[$line['product_id']] = ($restocks[$line['product_id']] ?? 0) + $item['quantity'];
                }
            }
            foreach ($restocks as $id => $quantity) {
                if (! $products->has($id) || $products[$id]->stock + $quantity > 1_000_000_000) {
                    throw ValidationException::withMessages(['items' => 'Restocking would exceed the inventory limit. Review the return disposition.']);
                }
            }
            $refund = $branch->refunds()->create([
                'sale_id' => $sale->id, 'user_id' => $actor->id, 'staff_name' => $actor->name,
                'reference' => 'R-'.Str::ulid(), 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'reason' => $data['reason'], 'currency' => $sale->currency, 'timezone' => $branch->timezone,
                'subtotal_minor' => $base, 'tax_minor' => $tax, 'total_minor' => $base + $tax, 'refunded_at' => now('UTC'),
            ]);
            $refund->items()->createMany($lines);
            foreach ($restocks as $id => $quantity) {
                $product = $products[$id];
                $product->increment('stock', $quantity);
                InventoryMovement::create([
                    'branch_id' => $branch->id, 'product_id' => $id, 'user_id' => $actor->id,
                    'sale_id' => $sale->id, 'refund_id' => $refund->id, 'delta' => $quantity,
                    'balance' => $product->stock, 'reason' => 'Return '.$refund->reference,
                ]);
            }

            return $refund;
        }, 3);
    }
}
