<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, CashSession, CashEntry, Product, Refund, Sale, User};
use App\Services\Pos\{CashDrawer, Checkout, RefundSale};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class ConcurrentCashDrawerTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_open_requests_cannot_create_two_active_tills_for_one_owner(): void
    {
        $this->oneWinner($this->race('open'));
        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_duplicate_parallel_open_requests_return_the_same_till(): void
    {
        $this->sameRecord($this->race('duplicate-open'));
        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_parallel_cash_movement_retries_add_cash_exactly_once(): void
    {
        $this->sameRecord($this->race('duplicate-movement'));
        $this->assertDatabaseCount('cash_entries', 1);
        $this->assertSame(10500, CashSession::firstOrFail()->expected_minor);
        $this->assertSame(1, CashSession::firstOrFail()->version);
    }

    public function test_competing_close_requests_cannot_rewrite_a_closing_count(): void
    {
        $this->oneWinner($this->race('close'));
        $this->assertSame(10000, CashSession::firstOrFail()->counted_minor);
        $this->assertNotNull(CashSession::firstOrFail()->closed_at);
    }

    public function test_parallel_duplicate_close_requests_are_replayable(): void
    {
        $this->sameRecord($this->race('duplicate-close'));
        $this->assertSame(0, CashSession::firstOrFail()->variance_minor);
    }

    public function test_checkout_racing_close_is_either_included_or_rejected_without_missing_cash(): void
    {
        $this->oneWinner($this->race('sale'));
        $session = CashSession::firstOrFail();
        if ($session->closed_at) {
            $this->assertDatabaseCount('sales', 0);
            $this->assertDatabaseCount('cash_entries', 0);
            $this->assertSame(10000, $session->expected_minor);
            $this->assertSame(2, Product::firstOrFail()->stock);
        } else {
            $this->assertDatabaseCount('sales', 1);
            $this->assertDatabaseCount('cash_entries', 1);
            $this->assertSame(10100, $session->expected_minor);
            $this->assertSame(1, Product::firstOrFail()->stock);
        }
    }

    public function test_refund_racing_close_cannot_mutate_a_closed_till_or_lose_a_cash_payout(): void
    {
        $this->oneWinner($this->race('refund'));
        $session = CashSession::firstOrFail();
        if ($session->closed_at) {
            $this->assertDatabaseCount('refunds', 0);
            $this->assertDatabaseCount('cash_entries', 1);
            $this->assertSame(10100, $session->expected_minor);
            $this->assertSame(1, Product::firstOrFail()->stock);
        } else {
            $this->assertDatabaseCount('refunds', 1);
            $this->assertDatabaseCount('cash_entries', 2);
            $this->assertSame(10000, $session->expected_minor);
            $this->assertSame(2, Product::firstOrFail()->stock);
        }
    }

    private function oneWinner(array $results): void
    {
        $states = array_column($results, 'state');
        sort($states);
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
        $branch = Branch::create(['name' => 'Till branch', 'code' => 'TILL', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $drawer = app(CashDrawer::class);
        $drawer->enable($branch, $user);
        $session = in_array($mode, ['open', 'duplicate-open']) ? null : $drawer->open($branch, $user, ['opening' => '100.00', 'currency' => 'BDT', 'idempotency_key' => (string) Str::uuid()]);
        $product = $branch->products()->create(['name' => 'Test item', 'sku' => 'TILL', 'price_minor' => 100, 'stock' => 2, 'is_active' => true]);
        $payment = ['cash_session_id' => $session?->id, 'currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1, 'expected_price_minor' => 100]]];
        $sale = $mode === 'refund' ? app(Checkout::class)->complete($branch, $user, $payment) : null;
        $lineId = $sale?->items()->firstOrFail()->id;
        $key = (string) Str::uuid();
        DB::purge(); // Never share a PDO socket across forked processes.
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $file = tempnam(sys_get_temp_dir(), 'till-race-');
                $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($sockets === false) {
                    throw new \RuntimeException('Unable to create worker synchronization sockets.');
                }
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Unable to start concurrent till test.');
                }
                if ($pid === 0) {
                    fclose($sockets[0]);
                    fread($sockets[1], 1); // Both workers start only after fixtures are committed.
                    fclose($sockets[1]);
                    try {
                        $store = Branch::findOrFail($branch->id);
                        $actor = User::findOrFail($user->id);
                        $drawer = app(CashDrawer::class);
                        $keyForWorker = str_starts_with($mode, 'duplicate-') ? $key : (string) Str::uuid();
                        if (in_array($mode, ['open', 'duplicate-open'])) {
                            $record = $drawer->open($store, $actor, ['opening' => '100.00', 'currency' => 'BDT', 'idempotency_key' => $keyForWorker]);
                        } elseif ($mode === 'duplicate-movement') {
                            $record = $drawer->movement($store, $session, $actor, ['kind' => 'in', 'amount' => '5.00', 'reason' => 'Funding', 'expected_version' => 0, 'idempotency_key' => $keyForWorker]);
                        } elseif ($i === 0 || in_array($mode, ['close', 'duplicate-close'])) {
                            $record = $drawer->close($store, $session, $actor, ['counted' => $mode === 'refund' ? '101.00' : '100.00', 'reason' => 'Closing count', 'expected_version' => $mode === 'refund' ? 1 : 0, 'idempotency_key' => $keyForWorker]);
                        } elseif ($mode === 'sale') {
                            $record = app(Checkout::class)->complete($store, $actor, $payment);
                        } else {
                            $record = app(RefundSale::class)->record($store, $sale, $actor, ['cash_session_id' => $session->id, 'idempotency_key' => $keyForWorker, 'reason' => 'Return', 'items' => [['sale_item_id' => $lineId, 'quantity' => 1, 'expected_returned' => 0, 'restock' => true]]]);
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
