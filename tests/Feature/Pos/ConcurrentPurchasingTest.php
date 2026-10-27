<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, InventoryMovement, Product, PurchaseOrder, PurchaseReceipt, User};
use App\Services\Pos\Checkout;
use App\Services\Purchasing\Purchasing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrentPurchasingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_duplicate_concurrent_order_creation_creates_one_order(): void
    {
        $this->sameRecord($this->race('create'));
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_duplicate_concurrent_receipts_add_stock_once(): void
    {
        $this->sameRecord($this->race('duplicate'));
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(5, Product::firstOrFail()->stock);
    }

    public function test_parallel_receipts_cannot_exceed_ordered_units(): void
    {
        $this->oneWinner($this->race('full'));
        $this->assertSame(5, Product::firstOrFail()->stock);
        $this->assertSame('received', PurchaseOrder::firstOrFail()->status);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    public function test_parallel_partial_receipts_reject_a_stale_order_version(): void
    {
        $this->oneWinner($this->race('partial'));
        $this->assertSame(2, Product::firstOrFail()->stock);
        $this->assertSame('partial', PurchaseOrder::firstOrFail()->status);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    public function test_closing_racing_receiving_never_loses_or_reverses_stock(): void
    {
        $this->oneWinner($this->race('close'));
        $closed = PurchaseOrder::firstOrFail()->status === 'closed';
        $this->assertSame($closed ? 0 : 2, Product::firstOrFail()->stock);
        $this->assertDatabaseCount('purchase_receipts', $closed ? 0 : 1);
    }

    public function test_different_orders_receiving_same_product_cannot_exceed_stock_limit(): void
    {
        $this->oneWinner($this->race('limit'));
        $this->assertSame(1000000000, Product::firstOrFail()->stock);
        $this->assertDatabaseCount('purchase_receipts', 1);
    }

    public function test_receiving_and_checkout_preserve_stock_ledger_balances(): void
    {
        $results = $this->race('checkout');
        $this->assertSame('completed', $results[0]['state'], json_encode($results));
        $this->assertContains($results[1]['state'], ['completed', 'rejected'], json_encode($results));
        $sold = $results[1]['state'] === 'completed';
        $this->assertSame($sold ? 1 : 2, Product::firstOrFail()->stock);
        $balance = 0;
        foreach (InventoryMovement::orderBy('id')->get() as $movement) {
            $balance += $movement->delta;
            $this->assertSame($balance, $movement->balance);
            $this->assertGreaterThanOrEqual(0, $balance);
        }
        $this->assertSame($balance, Product::firstOrFail()->stock);
    }

    private function oneWinner(array $results): void
    {
        $states = array_column($results, 'state'); sort($states);
        $this->assertSame(['completed', 'rejected'], $states, json_encode($results));
    }

    private function sameRecord(array $results): void
    {
        $this->assertSame(['completed', 'completed'], array_column($results, 'state'), json_encode($results));
        $this->assertSame($results[0]['id'], $results[1]['id']);
    }

    private function race(string $mode): array
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real parallel transaction tests run in the MySQL CI job with pcntl.');
        }
        $branch = Branch::create(['name' => 'Purchasing branch', 'code' => 'BUY', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $supplier = $branch->suppliers()->create(['name' => 'Supplier', 'code' => 'SUP', 'is_active' => true]);
        $product = $branch->products()->create(['name' => 'Goods', 'sku' => 'GOODS', 'price_minor' => 200, 'stock' => $mode === 'limit' ? 999999999 : 0, 'is_active' => true]);
        $orderData = ['supplier_id' => $supplier->id, 'currency' => 'BDT', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '1.00']]];
        $order = $mode === 'create' ? null : app(Purchasing::class)->create($branch, $user, $orderData);
        $otherOrder = $mode === 'limit' ? app(Purchasing::class)->create($branch, $user, [...$orderData, 'idempotency_key' => (string) Str::uuid()]) : null;
        $firstLineId = $order?->items()->firstOrFail()->id;
        $otherLineId = $otherOrder?->items()->firstOrFail()->id;
        $key = (string) Str::uuid();
        DB::purge(); // Never share a PDO socket across forked processes.
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'purchase-race-');
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($sockets === false) {
                    throw new \RuntimeException('Unable to create worker synchronization sockets.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to start concurrent purchasing test.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fread($sockets[1], 1); // Both workers start only after fixtures are committed.
                    fclose($sockets[1]);
                    try {
                        $store = Branch::findOrFail($branch->id);
                        $actor = User::findOrFail($user->id);
                        $service = app(Purchasing::class);
                        if ($mode === 'create') {
                            $record = $service->create($store, $actor, $orderData);
                        } elseif ($i === 1 && $mode === 'close') {
                            $record = $service->close($store, $order, $actor, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 0, 'reason' => 'Supplier cancellation']);
                        } elseif ($i === 1 && $mode === 'checkout') {
                            $record = app(Checkout::class)->complete($store, $actor, ['currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_price_minor' => 200]]]);
                        } else {
                            $target = $i === 1 && $mode === 'limit' ? $otherOrder : $order;
                            $lineId = $i === 1 && $mode === 'limit' ? $otherLineId : $firstLineId;
                            $record = $service->receive($store, $target, $actor, [
                                'idempotency_key' => $mode === 'duplicate' ? $key : (string) Str::uuid(), 'expected_version' => 0,
                                'delivery_reference' => 'DELIVERY', 'notes' => 'Physically inspected',
                                'items' => [['purchase_order_item_id' => $lineId, 'quantity' => in_array($mode, ['duplicate', 'full']) ? 5 : ($mode === 'limit' ? 1 : 2)]],
                            ]);
                        }
                        $result = ['state' => 'completed', 'id' => $record->id];
                    } catch (ValidationException $error) {
                        $result = ['state' => 'rejected', 'message' => $error->getMessage()];
                    } catch (Throwable $error) {
                        $result = ['state' => 'error', 'message' => $error->getMessage()];
                    }
                    file_put_contents($file, json_encode($result));
                    DB::disconnect();
                    exit(0);
                }
                fclose($sockets[1]);
                $children[] = ['pid' => $pid, 'file' => $file, 'socket' => $sockets[0]];
            }
            foreach ($children as $child) {
                fwrite($child['socket'], '1');
                fclose($child['socket']);
            }
            foreach ($children as $child) {
                pcntl_waitpid($child['pid'], $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();

            return array_map(fn ($child) => json_decode(file_get_contents($child['file']), true, flags: JSON_THROW_ON_ERROR), $children);
        } finally {
            foreach ($children as $child) {
                if (is_resource($child['socket'])) {
                    fclose($child['socket']);
                }
                @unlink($child['file']);
            }
        }
    }
}
