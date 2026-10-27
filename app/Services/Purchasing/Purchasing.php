<?php

namespace App\Services\Purchasing;

use App\Models\{Branch, InventoryMovement, PurchaseOrder, PurchaseReceipt, User};
use App\Services\Pos\BranchLock;
use App\Support\Money;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Purchasing
{
    public function create(Branch $branch, User $actor, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($branch, $actor, $data) {
            $branch = $this->lock($branch, $actor);
            $items = collect($data['items'])->map(fn ($item) => [
                'product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity'],
                'unit_cost_minor' => Money::parse($item['unit_cost'], $branch->currency, 'items'),
            ])->sortBy('product_id')->values();
            $hash = $this->hash([(int) $data['supplier_id'], $data['currency'], $data['supplier_reference'] ?? null, $data['expected_on'] ?? null, $data['notes'] ?? null, $items->all()]);
            $existing = $branch->purchaseOrders()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->request_hash, $hash);
                return $existing;
            }
            if ($data['currency'] !== $branch->currency) $this->invalid('currency', 'Branch currency changed. Reload and review costs.');
            $supplier = $branch->suppliers()->where('is_active', true)->find($data['supplier_id']);
            if (! $supplier) $this->invalid('supplier_id', 'Choose an active supplier from this branch.');
            $products = $branch->products()->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $total = 0;
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if (! $product || ! $product->is_active) $this->invalid('items', 'Choose active products from this branch.');
                $lineTotal = $item['quantity'] * $item['unit_cost_minor'];
                $total += $lineTotal;
                $lines[] = [...$item, 'name' => $product->name, 'sku' => $product->sku, 'total_minor' => $lineTotal, 'received_quantity' => 0];
            }
            if ($total > 1_000_000_000_000) $this->invalid('items', 'The order exceeds the supported goods-value limit.');
            $order = $branch->purchaseOrders()->create([
                'supplier_id' => $supplier->id, 'user_id' => $actor->id, 'staff_name' => $actor->name, 'reference' => 'P-'.Str::ulid(),
                'currency' => $branch->currency, 'timezone' => $branch->timezone, 'supplier_reference' => $data['supplier_reference'] ?? null,
                'expected_on' => $data['expected_on'] ?? null, 'notes' => $data['notes'] ?? null, 'total_minor' => $total,
                'supplier_snapshot' => $supplier->only(['name', 'code', 'contact_name', 'email', 'phone', 'address']),
                'branch_snapshot' => $branch->only(['name', 'code', 'address', 'phone', 'email']),
                'status' => 'open', 'version' => 0, 'ordered_at' => now('UTC'),
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ]);
            $order->items()->createMany($lines);
            return $order;
        }, 3);
    }

    public function receive(Branch $branch, PurchaseOrder $order, User $actor, array $data): PurchaseReceipt
    {
        return DB::transaction(function () use ($branch, $order, $actor, $data) {
            $branch = $this->lock($branch, $actor);
            $order = $branch->purchaseOrders()->lockForUpdate()->findOrFail($order->id);
            $items = collect($data['items'])->filter(fn ($item) => (int) $item['quantity'] > 0)
                ->map(fn ($item) => ['purchase_order_item_id' => (int) $item['purchase_order_item_id'], 'quantity' => (int) $item['quantity']])
                ->sortBy('purchase_order_item_id')->values();
            if ($items->isEmpty()) $this->invalid('items', 'Choose at least one unit to receive.');
            $hash = $this->hash([$order->id, (int) $data['expected_version'], $data['delivery_reference'], $data['notes'], $items->all()]);
            $existing = $branch->purchaseReceipts()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->request_hash, $hash);
                return $existing;
            }
            $this->fresh($order, $data);
            $lines = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $products = $branch->products()->whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $received = [];
            $total = 0;
            foreach ($items as $item) {
                $line = $lines->get($item['purchase_order_item_id']);
                if (! $line || $item['quantity'] > $line->quantity - $line->received_quantity) {
                    $this->invalid('items', 'A quantity exceeds outstanding units or the line does not belong to this order.');
                }
                $product = $products->get($line->product_id);
                if (! $product || $product->stock + $item['quantity'] > 1_000_000_000) $this->invalid('items', 'Receiving would exceed the supported stock limit.');
                $lineTotal = $line->unit_cost_minor * $item['quantity'];
                $total += $lineTotal;
                $received[] = [...$item, 'total_minor' => $lineTotal];
            }
            $receipt = $branch->purchaseReceipts()->create([
                'purchase_order_id' => $order->id, 'user_id' => $actor->id, 'staff_name' => $actor->name, 'reference' => 'G-'.Str::ulid(),
                'delivery_reference' => $data['delivery_reference'], 'notes' => $data['notes'], 'total_minor' => $total,
                'currency' => $order->currency, 'timezone' => $branch->timezone, 'received_at' => now('UTC'),
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ]);
            $receipt->items()->createMany($received);
            foreach ($items as $item) {
                $line = $lines[$item['purchase_order_item_id']];
                $product = $products[$line->product_id];
                $line->increment('received_quantity', $item['quantity']);
                $product->increment('stock', $item['quantity']);
                InventoryMovement::create([
                    'branch_id' => $branch->id, 'product_id' => $product->id, 'user_id' => $actor->id,
                    'purchase_receipt_id' => $receipt->id, 'delta' => $item['quantity'], 'balance' => $product->stock, 'reason' => 'Goods received '.$receipt->reference,
                ]);
            }
            $order->update(['version' => $order->version + 1, 'status' => $lines->every(fn ($line) => $line->quantity === $line->received_quantity) ? 'received' : 'partial']);
            return $receipt;
        }, 3);
    }

    public function close(Branch $branch, PurchaseOrder $order, User $actor, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($branch, $order, $actor, $data) {
            $branch = $this->lock($branch, $actor);
            $order = $branch->purchaseOrders()->lockForUpdate()->findOrFail($order->id);
            $hash = $this->hash([$order->id, (int) $data['expected_version'], $data['reason']]);
            $existing = $branch->purchaseOrders()->where('close_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->closed_by, $actor, $existing->close_hash, $hash);
                return $existing;
            }
            $this->fresh($order, $data);
            $order->update([
                'status' => 'closed', 'version' => $order->version + 1, 'closed_at' => now('UTC'),
                'closed_by' => $actor->id, 'closer_name' => $actor->name, 'close_reason' => $data['reason'],
                'close_key' => $data['idempotency_key'], 'close_hash' => $hash,
            ]);
            return $order;
        }, 3);
    }

    private function lock(Branch $branch, User $actor): Branch
    {
        $branch = BranchLock::acquire($branch, $actor);
        Gate::forUser($actor)->authorize('manage-purchasing');
        return $branch;
    }

    private function fresh(PurchaseOrder $order, array $data): void
    {
        if (! in_array($order->status, ['open', 'partial'], true) || $order->version !== (int) $data['expected_version']) {
            $this->invalid('expected_version', 'This order changed or is no longer open. Reload and review receiving history before submitting again.');
        }
    }

    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }

    private function replay(int $userId, User $actor, string $stored, string $hash): void
    {
        abort_unless($userId === $actor->id, 403);
        abort_unless(hash_equals($stored, $hash), 409, 'This purchasing key was already used for a different request.');
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
