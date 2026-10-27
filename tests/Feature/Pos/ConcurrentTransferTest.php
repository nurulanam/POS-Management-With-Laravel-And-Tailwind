<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, InventoryMovement, StockTransfer, TransferReceipt, User};
use App\Services\Pos\Checkout;
use App\Services\Inventory\TransferStock;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrentTransferTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_dispatches_cannot_oversell_source_stock(): void
    {
        $this->oneWinner($this->race('dispatch'));
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame(0, \App\Models\Product::where('sku', 'MATCH')->orderBy('id')->first()->stock);
    }

    public function test_duplicate_dispatch_requests_deduct_stock_once(): void
    {
        $this->sameRecord($this->race('duplicate-dispatch'));
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_opposite_direction_transfers_use_consistent_branch_lock_order(): void
    {
        $results = $this->race('opposite');
        $this->assertSame(['completed', 'completed'], array_column($results, 'state'), json_encode($results));
        $this->assertDatabaseCount('stock_transfers', 2);
        $this->assertSame([0, 0], \App\Models\Product::orderBy('id')->pluck('stock')->all());
    }

    public function test_duplicate_concurrent_receipts_return_the_same_record(): void
    {
        $this->sameRecord($this->race('duplicate-receive'));
        $this->assertDatabaseCount('transfer_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_parallel_receiving_cannot_account_for_units_twice(): void
    {
        $this->oneWinner($this->race('receive'));
        $this->assertSame('completed', StockTransfer::firstOrFail()->status);
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_partial_receipts_reject_stale_forms_with_units_remaining(): void
    {
        $this->oneWinner($this->race('partial'));
        $this->assertSame(1, StockTransfer::firstOrFail()->items()->first()->remaining());
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_two_transfers_receiving_same_product_obey_stock_limit(): void
    {
        $this->oneWinner($this->race('limit'));
        $this->assertSame(1000000000, \App\Models\Product::orderByDesc('id')->first()->stock);
        $this->assertDatabaseCount('transfer_receipts', 1);
    }

    public function test_receiving_racing_checkout_preserves_destination_balances(): void
    {
        $results = $this->race('checkout');
        $this->assertSame('completed', $results[0]['state'], json_encode($results));
        $this->assertContains($results[1]['state'], ['completed', 'rejected'], json_encode($results));
        $product = \App\Models\Product::orderByDesc('id')->firstOrFail();
        $balance = 0;
        foreach (InventoryMovement::where('product_id', $product->id)->orderBy('id')->get() as $movement) {
            $balance += $movement->delta;
            $this->assertSame($balance, $movement->balance);
            $this->assertGreaterThanOrEqual(0, $balance);
        }
        $this->assertSame($results[1]['state'] === 'completed' ? 1 : 2, $balance);
        $this->assertSame($balance, $product->stock);
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
        $source = Branch::create(['name' => 'Source', 'code' => 'SOURCE', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $destination = Branch::create(['name' => 'Destination', 'code' => 'DEST', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $from = $source->products()->create(['name' => 'Goods', 'sku' => 'MATCH', 'stock' => $mode === 'limit' ? 4 : 2, 'price_minor' => 200, 'is_active' => true]);
        $to = $destination->products()->create(['name' => 'Goods', 'sku' => 'MATCH', 'stock' => $mode === 'opposite' ? 2 : ($mode === 'limit' ? 999999999 : 0), 'price_minor' => 200, 'is_active' => true]);
        $key = (string) Str::uuid();
        $dispatch = ['destination_branch_id' => $destination->id, 'idempotency_key' => $key, 'notes' => 'Dispatched goods', 'items' => [['source_product_id' => $from->id, 'destination_product_id' => $to->id, 'quantity' => 2]]];
        $reverse = ['destination_branch_id' => $source->id, 'idempotency_key' => (string) Str::uuid(), 'notes' => 'Reverse direction', 'items' => [['source_product_id' => $to->id, 'destination_product_id' => $from->id, 'quantity' => 2]]];
        $transfer = in_array($mode, ['dispatch', 'duplicate-dispatch', 'opposite']) ? null : app(TransferStock::class)->dispatch($source, $user, $dispatch);
        $other = $mode === 'limit' ? app(TransferStock::class)->dispatch($source, $user, [...$dispatch, 'idempotency_key' => (string) Str::uuid()]) : null;
        $lineId = $transfer?->items()->firstOrFail()->id;
        $otherLine = $other?->items()->firstOrFail()->id;
        DB::purge(); // Never share a PDO socket across forked processes.
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'transfer-race-');
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($sockets === false) {
                    throw new \RuntimeException('Unable to create worker synchronization sockets.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to start concurrent transfer test.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fread($sockets[1], 1); // Both workers start only after fixtures are committed.
                    fclose($sockets[1]);
                    try {
                        $actor = User::findOrFail($user->id);
                        $service = app(TransferStock::class);
                        if ($mode === 'opposite') {
                            $record = $service->dispatch($i === 0 ? $source : $destination, $actor, $i === 0 ? $dispatch : $reverse);
                        } elseif (in_array($mode, ['dispatch', 'duplicate-dispatch'])) {
                            $dispatch['idempotency_key'] = $mode === 'duplicate-dispatch' ? $key : (string) Str::uuid();
                            $record = $service->dispatch($source, $actor, $dispatch);
                        } elseif ($mode === 'checkout' && $i === 1) {
                            $record = app(Checkout::class)->complete($destination, $actor, ['currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $to->id, 'quantity' => 1, 'expected_price_minor' => 200]]]);
                        } else {
                            $target = $mode === 'limit' && $i === 1 ? $other : $transfer;
                            $targetLine = $mode === 'limit' && $i === 1 ? $otherLine : $lineId;
                            $record = $service->receive($destination, $target, $actor, [
                                'idempotency_key' => $mode === 'duplicate-receive' ? $key : (string) Str::uuid(), 'expected_version' => 0,
                                'delivery_reference' => 'COURIER', 'notes' => 'Inspected delivery',
                                'items' => [['stock_transfer_item_id' => $targetLine, 'received_quantity' => $mode === 'partial' || $mode === 'limit' ? 1 : 2, 'damaged_quantity' => 0, 'missing_quantity' => 0]],
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
