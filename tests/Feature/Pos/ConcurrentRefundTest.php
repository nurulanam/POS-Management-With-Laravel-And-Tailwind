<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, InventoryMovement, Product, Refund, Sale, User};
use App\Services\Pos\{Checkout, RefundSale};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrentRefundTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_full_returns_cannot_over_refund_or_double_restock(): void
    {
        $results = $this->race('full');
        $states = array_column($results, 'state');
        sort($states);
        $this->assertSame(['completed', 'rejected'], $states, json_encode($results));
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(200, Refund::firstOrFail()->total_minor);
        $this->assertSame(2, Product::firstOrFail()->stock);
    }

    public function test_parallel_duplicate_refunds_return_the_same_record(): void
    {
        $results = $this->race('duplicate');
        $this->assertSame(['completed', 'completed'], array_column($results, 'state'), json_encode($results));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('refund_items', 1);
        $this->assertDatabaseCount('inventory_movements', 2); // Original sale and one restock.
        $this->assertSame(2, Product::firstOrFail()->stock);
    }

    public function test_parallel_partial_returns_reject_a_stale_quote_even_with_units_remaining(): void
    {
        $results = $this->race('partial');
        $states = array_column($results, 'state');
        sort($states);
        $this->assertSame(['completed', 'rejected'], $states, json_encode($results));
        $this->assertSame(100, Refund::firstOrFail()->total_minor);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(1, Product::firstOrFail()->stock);
    }

    public function test_refund_restock_and_checkout_keep_inventory_consistent(): void
    {
        $results = $this->race('checkout');
        $this->assertSame('completed', $results[0]['state'], json_encode($results));
        $this->assertContains($results[1]['state'], ['completed', 'rejected'], json_encode($results));
        $soldAgain = $results[1]['state'] === 'completed';
        $this->assertDatabaseCount('sales', $soldAgain ? 2 : 1);
        $this->assertSame($soldAgain ? 1 : 2, Product::firstOrFail()->stock);
        $balance = 2;
        foreach (InventoryMovement::orderBy('id')->get() as $movement) {
            $balance += $movement->delta;
            $this->assertSame($balance, $movement->balance);
            $this->assertGreaterThanOrEqual(0, $balance);
        }
        $this->assertSame($balance, Product::firstOrFail()->stock);
    }

    private function race(string $mode): array
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real parallel transaction tests run in the MySQL CI job with pcntl.');
        }
        $branch = Branch::create(['name' => 'Return branch', 'code' => 'RACE', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Returned item', 'sku' => 'RETURN', 'price_minor' => 100, 'stock' => 2, 'is_active' => true]);
        $payment = ['currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => 100]]];
        $sale = app(Checkout::class)->complete($branch, $user, $payment);
        $lineId = $sale->items()->firstOrFail()->id;
        $key = (string) Str::uuid();
        DB::purge();
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'refund-race-');
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($sockets === false) throw new \RuntimeException('Unable to synchronize workers.');
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('Unable to start concurrent refund test.');
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fread($sockets[1], 1);
                    fclose($sockets[1]);
                    try {
                        $actor = User::findOrFail($user->id);
                        $store = Branch::findOrFail($branch->id);
                        if ($mode === 'checkout' && $i === 1) {
                            $payment['idempotency_key'] = (string) Str::uuid();
                            $payment['items'][0]['quantity'] = 1;
                            $record = app(Checkout::class)->complete($store, $actor, $payment);
                        } else {
                            $record = app(RefundSale::class)->record($store, Sale::findOrFail($sale->id), $actor, [
                                'idempotency_key' => $mode === 'duplicate' ? $key : (string) Str::uuid(), 'reason' => 'Concurrent return',
                                'items' => [['sale_item_id' => $lineId, 'quantity' => $mode === 'partial' ? 1 : 2, 'expected_returned' => 0, 'restock' => true]],
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
                if (is_resource($child['socket'])) fclose($child['socket']);
                @unlink($child['file']);
            }
        }
    }
}
