<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Pos\Checkout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrentCheckoutTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_checkouts_cannot_oversell_the_last_unit(): void
    {
        $results = $this->race(false);
        $states = array_column($results, 'state');
        sort($states);
        $this->assertSame(['completed', 'rejected'], $states, json_encode($results));
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, Product::firstOrFail()->stock);
        $this->assertSame(-1, InventoryMovement::firstOrFail()->delta);
    }

    public function test_concurrent_duplicate_submissions_create_exactly_one_sale(): void
    {
        $results = $this->race(true);
        $this->assertSame(['completed', 'completed'], array_column($results, 'state'), json_encode($results));
        $this->assertSame($results[0]['sale_id'], $results[1]['sale_id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(0, Product::firstOrFail()->stock);
    }

    private function race(bool $sameRequest): array
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real parallel transaction tests run in the MySQL CI job with pcntl.');
        }
        $branch = Branch::create(['name' => 'Concurrent branch', 'code' => 'RACE', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Last unit', 'sku' => 'LAST', 'price_minor' => 100, 'stock' => 1, 'is_active' => true]);
        $key = (string) Str::uuid();
        DB::purge(); // Never share a PDO socket across forked processes.
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'pos-race-');
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pid = pcntl_fork();
                if ($pid === -1 || $sockets === false) {
                    throw new \RuntimeException('Unable to start concurrent checkout test.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fread($sockets[1], 1); // Both workers start only after fixtures are committed.
                    fclose($sockets[1]);
                    try {
                        $sale = app(Checkout::class)->complete(Branch::findOrFail($branch->id), User::findOrFail($user->id), [
                            'currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '1.00',
                            'idempotency_key' => $sameRequest ? $key : (string) Str::uuid(),
                            'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_price_minor' => 100]],
                        ]);
                        $result = ['state' => 'completed', 'sale_id' => $sale->id];
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
