<?php

namespace App\Services\Inventory;

use App\Models\{Branch, InventoryMovement, StockTransfer, TransferReceipt, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferStock
{
    // All callers begin here: lock BOTH branches in ascending ID order before any product/transfer lock.
    // Never acquire the current branch first: opposite-direction transfers would deadlock.
    private function branches(int $sourceId, int $destinationId, User $actor, bool $dispatch): array
    {
        if ($sourceId === $destinationId) $this->invalid('destination_branch_id', 'Choose a different destination branch.');
        $branches = Branch::whereIn('id', [$sourceId, $destinationId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $version = $actor->session_version;
        $actor->refresh();
        abort_unless($actor->is_active && ! $actor->must_change_password && $actor->session_version === $version, 403);
        Gate::forUser($actor)->authorize('manage-transfers');
        $required = $dispatch ? [$sourceId, $destinationId] : [$destinationId];
        abort_unless(Branch::accessibleTo($actor)->whereIn('id', $required)->count() === count($required), 403);
        if ($branches->count() !== 2 || ! $branches[$sourceId]->is_active || ! $branches[$destinationId]->is_active) {
            $this->invalid('destination_branch_id', 'Both branches must be active for this operation.');
        }
        return [$branches[$sourceId], $branches[$destinationId]];
    }

    public function dispatch(Branch $source, User $actor, array $data): StockTransfer
    {
        return DB::transaction(function () use ($source, $actor, $data) {
            [$source, $destination] = $this->branches($source->id, (int) $data['destination_branch_id'], $actor, true);
            $items = collect($data['items'])->map(fn ($item) => [
                'source_product_id' => (int) $item['source_product_id'], 'destination_product_id' => (int) $item['destination_product_id'], 'quantity' => (int) $item['quantity'],
            ])->sortBy('source_product_id')->values();
            $hash = $this->hash([$destination->id, $data['notes'], $items->all()]);
            $existing = StockTransfer::where('source_branch_id', $source->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->request_hash, $hash);
                return $existing;
            }
            $from = $source->products()->whereIn('id', $items->pluck('source_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $to = $destination->products()->whereIn('id', $items->pluck('destination_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($items as $item) {
                $product = $from->get($item['source_product_id']);
                $target = $to->get($item['destination_product_id']);
                if (! $product || ! $target || ! $product->is_active || ! $target->is_active || $product->sku !== $target->sku) {
                    $this->invalid('items', 'Choose active products with matching SKUs in the selected branches. Reload and verify product identities.');
                }
                if ($product->stock < $item['quantity']) $this->invalid('items', 'Insufficient source stock. Reload and inspect the available quantities.');
                $lines[] = [...$item, 'source_name' => $product->name, 'destination_name' => $target->name, 'sku' => $product->sku,
                    'received_quantity' => 0, 'damaged_quantity' => 0, 'missing_quantity' => 0];
            }
            $transfer = StockTransfer::create([
                'source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'user_id' => $actor->id, 'staff_name' => $actor->name,
                'source_snapshot' => $source->only(['name', 'code', 'address']), 'destination_snapshot' => $destination->only(['name', 'code', 'address']),
                'reference' => 'X-'.Str::ulid(), 'status' => 'in_transit', 'version' => 0, 'notes' => $data['notes'], 'timezone' => $source->timezone,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'dispatched_at' => now('UTC'),
            ]);
            $transfer->items()->createMany($lines);
            foreach ($items as $item) {
                $product = $from[$item['source_product_id']];
                $product->decrement('stock', $item['quantity']);
                InventoryMovement::create(['branch_id' => $source->id, 'product_id' => $product->id, 'user_id' => $actor->id,
                    'stock_transfer_id' => $transfer->id, 'delta' => -$item['quantity'], 'balance' => $product->stock, 'reason' => 'Transfer dispatched '.$transfer->reference]);
            }
            return $transfer;
        }, 3);
    }

    public function receive(Branch $context, StockTransfer $transfer, User $actor, array $data): TransferReceipt
    {
        // Resolve immutable routing outside the transaction so MySQL does not establish
        // a repeatable-read snapshot before waiting for the branch locks.
        $transfer = StockTransfer::findOrFail($transfer->id);
        abort_unless($context->id === $transfer->destination_branch_id, 403);
        return DB::transaction(function () use ($context, $transfer, $actor, $data) {
            [, $destination] = $this->branches($transfer->source_branch_id, $transfer->destination_branch_id, $actor, false);
            $transfer = StockTransfer::lockForUpdate()->findOrFail($transfer->id);
            $items = collect($data['items'])->map(fn ($item) => [
                'stock_transfer_item_id' => (int) $item['stock_transfer_item_id'], 'received_quantity' => (int) $item['received_quantity'],
                'damaged_quantity' => (int) $item['damaged_quantity'], 'missing_quantity' => (int) $item['missing_quantity'],
            ])->filter(fn ($item) => $item['received_quantity'] + $item['damaged_quantity'] + $item['missing_quantity'] > 0)->sortBy('stock_transfer_item_id')->values();
            if ($items->isEmpty()) $this->invalid('items', 'Select at least one unit to account for.');
            $hash = $this->hash([$transfer->id, (int) $data['expected_version'], $data['delivery_reference'], $data['notes'], $items->all()]);
            $existing = TransferReceipt::where('branch_id', $destination->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->request_hash, $hash);
                return $existing;
            }
            if ($transfer->status !== 'in_transit' || $transfer->version !== (int) $data['expected_version']) {
                $this->invalid('expected_version', 'This transfer changed or is complete. Reload and review its receiving history.');
            }
            $lines = $transfer->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $products = $destination->products()->whereIn('id', $lines->pluck('destination_product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $line = $lines->get($item['stock_transfer_item_id']);
                $quantity = $item['received_quantity'] + $item['damaged_quantity'] + $item['missing_quantity'];
                if (! $line || $quantity > $line->remaining()) $this->invalid('items', 'Accounted quantities exceed units in transit or include a foreign line.');
                $product = $products->get($line->destination_product_id);
                if (! $product || $product->stock + $item['received_quantity'] > 1_000_000_000) $this->invalid('items', 'Accepted units would exceed the destination stock limit.');
            }
            $receipt = $transfer->receipts()->create([
                'branch_id' => $destination->id, 'user_id' => $actor->id, 'staff_name' => $actor->name, 'reference' => 'Y-'.Str::ulid(),
                'delivery_reference' => $data['delivery_reference'], 'notes' => $data['notes'], 'timezone' => $destination->timezone,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'received_at' => now('UTC'),
            ]);
            $receipt->items()->createMany($items->all());
            foreach ($items as $item) {
                $line = $lines[$item['stock_transfer_item_id']];
                $line->update([
                    'received_quantity' => $line->received_quantity + $item['received_quantity'],
                    'damaged_quantity' => $line->damaged_quantity + $item['damaged_quantity'],
                    'missing_quantity' => $line->missing_quantity + $item['missing_quantity'],
                ]);
                if ($item['received_quantity'] > 0) {
                    $product = $products[$line->destination_product_id];
                    $product->increment('stock', $item['received_quantity']);
                    InventoryMovement::create(['branch_id' => $destination->id, 'product_id' => $product->id, 'user_id' => $actor->id,
                        'stock_transfer_id' => $transfer->id, 'transfer_receipt_id' => $receipt->id, 'delta' => $item['received_quantity'],
                        'balance' => $product->stock, 'reason' => 'Transfer received '.$receipt->reference]);
                }
            }
            $complete = $lines->every(fn ($line) => $line->remaining() === 0);
            $transfer->update(['version' => $transfer->version + 1, 'status' => $complete ? 'completed' : 'in_transit', 'completed_at' => $complete ? now('UTC') : null]);
            return $receipt;
        }, 3);
    }

    private function hash(array $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
    private function replay(int $owner, User $actor, string $stored, string $hash): void
    {
        abort_unless($owner === $actor->id, 403);
        abort_unless(hash_equals($stored, $hash), 409, 'Transfer key already used for a different request.');
    }
    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
